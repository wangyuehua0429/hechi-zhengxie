#!/usr/bin/env php
<?php

/**
 * 阶段 D 迁移第二段：把 articles.final.jsonl 灌进新库（cms_article 等）。
 *
 *   php tools/migrate/legacy_import.php --in=tools/migrate/out/articles.final.jsonl --dry-run
 *   php tools/migrate/legacy_import.php --in=tools/migrate/out/articles.final.jsonl --commit
 *
 * 口径见 docs/旧库迁移说明.md：稿件号沿用旧库 ID；只迁主站口径；公开范围按年限切分；
 * 归档稿件（public_scope=archive）不产静态页、不登记 301（发布器与 301 生成各自过滤）。
 * 全程幂等：按 article_id upsert，可反复执行；--dry-run 只校验与出报告，不写库。
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/backend/src/bootstrap.php';

use HechiZx\Content\HtmlSanitizer;
use HechiZx\Support\Db;
use HechiZx\Support\Json;

// 注意 out / side-tables 用「必带值」写法（`--out=<目录>` 与 `--out <目录>` 都支持）；
// 早期写成可选值时 `--out <目录>` 会被 PHP 解析成空值，报告就落到了空路径上。
$options = getopt('', ['in:', 'out:', 'side-tables:', 'dry-run', 'commit', 'help']);

if (isset($options['help']) || !isset($options['in'])) {
    fwrite(STDOUT, <<<TXT
用法：php tools/migrate/legacy_import.php --in=<articles.final.jsonl> [--dry-run|--commit]
  --dry-run          只校验、出报告，不写库（默认）
  --commit           写库：cms_article / cms_article_channel / cms_article_image / cms_attachment
  --out=<目录>       报告目录，默认与 --in 同目录（写 report.txt、imported_ids.txt）
  --side-tables=<json>  次要表产物（videos / links / hudong），默认读同目录 side_tables.json

TXT);
    exit(isset($options['help']) ? 0 : 1);
}

$inFile = (string) $options['in'];
if (!is_file($inFile)) {
    fwrite(STDERR, '找不到输入文件：' . $inFile . "\n");
    exit(1);
}

$outDir = rtrim((string) ($options['out'] ?? ''), '/');
if ($outDir === '') {
    $outDir = dirname($inFile);
}
// 次要表产物跟输入文件放一起（parse 就写在那个目录），与 --out 无关，避免换报告目录后漏迁
$sideFile = (string) ($options['side-tables'] ?? dirname($inFile) . '/side_tables.json');
$commit = isset($options['commit']);

$config = hechi_config();
$db = new Db((array) $config->get('db'));
$siteId = (int) $config->get('site.site_id', 1);
$now = $db->now();

/** 与 seed.php 同一套可移植 upsert（先查后写，SQLite / MySQL 都跑） */
function migrate_upsert(Db $db, string $table, array $where, array $values): void
{
    $whereSql = implode(' AND ', array_map(static fn (string $k): string => $k . ' = :w_' . $k, array_keys($where)));
    $exists = $db->selectOne('SELECT 1 AS ok FROM ' . $table . ' WHERE ' . $whereSql, migrate_prefix($where, 'w_'));
    if ($exists !== null) {
        $sets = implode(', ', array_map(static fn (string $k): string => $k . ' = :v_' . $k, array_keys($values)));
        $db->execute(
            'UPDATE ' . $table . ' SET ' . $sets . ' WHERE ' . $whereSql,
            migrate_prefix($values, 'v_') + migrate_prefix($where, 'w_')
        );
        return;
    }
    $columns = array_merge(array_keys($where), array_keys($values));
    $db->execute(
        'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')',
        $where + $values
    );
}

/** @return array<string, mixed> */
function migrate_prefix(array $values, string $prefix): array
{
    $out = [];
    foreach ($values as $key => $value) {
        $out[$prefix . $key] = $value;
    }
    return $out;
}

/** @return list<string> 校验失败的原因，空数组表示可以入库 */
function migrate_validate(array $record, array $channelTypes): array
{
    $problems = [];
    $id = (int) ($record['article_id'] ?? 0);
    if ($id <= 0) {
        $problems[] = 'article_id 非法';
    }
    $channel = (string) ($record['channel_type'] ?? '');
    if ($channel === '') {
        $problems[] = 'channel_type 为空';
    } elseif (!isset($channelTypes[$channel])) {
        $problems[] = '栏目不存在：' . $channel;
    }
    if (trim((string) ($record['title'] ?? '')) === '') {
        $problems[] = '标题为空';
    }
    $status = (string) ($record['status'] ?? '');
    if (!in_array($status, ['published', 'draft', 'pending', 'rejected', 'withdrawn', 'deleted'], true)) {
        $problems[] = '状态非法：' . $status;
    }
    $scope = (string) ($record['public_scope'] ?? '');
    if (!in_array($scope, ['public', 'archive'], true)) {
        $problems[] = '公开范围非法：' . $scope;
    }
    $published = (string) ($record['published_at'] ?? '');
    if ($published !== '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $published) !== 1) {
        $problems[] = '发布时间格式不对：' . $published;
    }
    return $problems;
}

/** @param array<string, mixed> $record */
function migrate_write_article(Db $db, int $siteId, array $record, string $now): void
{
    $id = (int) $record['article_id'];
    $content = HtmlSanitizer::clean((string) ($record['content_html'] ?? ''));
    migrate_upsert($db, 'cms_article', ['article_id' => $id], [
        'site_id'      => $siteId,
        'channel_type' => (string) $record['channel_type'],
        'title'        => (string) $record['title'],
        'subtitle'     => '',
        'summary'      => (string) ($record['summary'] ?? ''),
        'content_html' => $content,
        'source'       => (string) ($record['source'] ?? ''),
        'author'       => (string) ($record['author'] ?? ''),
        'editor'       => (string) ($record['editor'] ?? ''),
        'published_at' => (string) ($record['published_at'] ?? ''),
        'views'        => (string) ($record['views'] ?? '0'),
        'role'         => (string) ($record['role'] ?? ''),
        'thumb'        => (string) ($record['thumb'] ?? ''),
        'has_body'     => (int) ($record['has_body'] ?? 0),
        'url_alias'    => '',
        'is_top'       => 0,
        'is_hot'       => 0,
        'status'       => (string) $record['status'],
        'public_scope' => (string) $record['public_scope'],
        'created_by'   => 0,
        'updated_by'   => 0,
        'updated_at'   => $now,
    ]);

    migrate_upsert(
        $db,
        'cms_article_channel',
        ['article_id' => $id, 'site_id' => $siteId, 'channel_type' => (string) $record['channel_type']],
        [
            'sort_no'      => (int) ($record['sort_no'] ?? 0),
            'is_primary'   => 1,
            'is_top'       => 0,
            'is_highlight' => 0,
            'badge_text'   => '',
        ]
    );

    $db->execute('DELETE FROM cms_article_image WHERE article_id = :id', ['id' => $id]);
    $sort = 0;
    foreach ((array) ($record['images'] ?? []) as $path) {
        $path = trim((string) $path);
        if ($path === '') {
            continue;
        }
        $db->execute(
            'INSERT INTO cms_article_image (article_id, path, sort_no) VALUES (:id, :path, :sort)',
            ['id' => $id, 'path' => $path, 'sort' => ++$sort]
        );
    }

    $db->execute('DELETE FROM cms_attachment WHERE article_id = :id', ['id' => $id]);
    $sort = 0;
    foreach ((array) ($record['attachments'] ?? []) as $file) {
        if (!is_array($file) || trim((string) ($file['url'] ?? '')) === '') {
            continue;
        }
        $db->execute(
            'INSERT INTO cms_attachment (article_id, name, url, ext, size_bytes, sort_no, download_count, created_at)
             VALUES (:id, :name, :url, :ext, 0, :sort, 0, :t)',
            [
                'id'   => $id,
                'name' => basename((string) $file['url']),
                'url'  => (string) $file['url'],
                'ext'  => (string) ($file['ext'] ?? ''),
                'sort' => ++$sort,
                't'    => $now,
            ]
        );
    }
}

/** 次要表：视频与友情链接合并进首页整块配置，互动条目按稿件入库 */
function migrate_side_tables(Db $db, int $siteId, string $sideFile, string $now, array &$report): void
{
    if (!is_file($sideFile)) {
        $report[] = '次要表：未找到 ' . $sideFile . '，跳过';
        return;
    }
    $side = Json::decode((string) file_get_contents($sideFile), []);
    if (!is_array($side)) {
        $report[] = '次要表：文件内容不是合法 JSON，跳过';
        return;
    }

    foreach (['videos' => 'videos', 'links' => 'links'] as $key => $blockKey) {
        $entries = is_array($side[$key] ?? null) ? $side[$key] : [];
        if ($entries === []) {
            continue;
        }
        $row = $db->selectOne(
            'SELECT block_id, payload_json FROM cms_home_block WHERE site_id = :site AND block_key = :key',
            ['site' => $siteId, 'key' => $blockKey]
        );
        $payload = $row !== null ? Json::decode((string) $row['payload_json'], []) : [];
        if (!is_array($payload)) {
            $payload = [];
        }
        $payload = $key === 'videos'
            ? migrate_merge_videos($payload, $entries)
            : migrate_merge_links($payload, $entries);
        if ($row === null) {
            $db->execute(
                'INSERT INTO cms_home_block (site_id, block_key, sort_no, payload_json, updated_at)
                 VALUES (:site, :key, 0, :payload, :t)',
                ['site' => $siteId, 'key' => $blockKey, 'payload' => Json::encode($payload), 't' => $now]
            );
        } else {
            $db->execute(
                'UPDATE cms_home_block SET payload_json = :payload, updated_at = :t WHERE block_id = :id',
                ['payload' => Json::encode($payload), 't' => $now, 'id' => (int) $row['block_id']]
            );
        }
        $report[] = sprintf('次要表：%s 合并后共 %d 条', $blockKey, count($key === 'videos' ? $payload : ($payload['logos'] ?? [])));
    }

    $hudong = is_array($side['hudong'] ?? null) ? $side['hudong'] : [];
    $written = 0;
    foreach ($hudong as $entry) {
        if (!is_array($entry) || (int) ($entry['article_id'] ?? 0) <= 0) {
            continue;
        }
        $exists = $db->selectOne(
            'SELECT channel_type FROM cms_article WHERE article_id = :id',
            ['id' => (int) $entry['article_id']]
        );
        if ($exists !== null && (string) $exists['channel_type'] !== 'interactive') {
            $report[] = sprintf('次要表：互动条目 %d 与既有稿件号冲突，跳过', (int) $entry['article_id']);
            continue;
        }
        migrate_write_article($db, $siteId, [
            'article_id'   => (int) $entry['article_id'],
            'channel_type' => 'interactive',
            'title'        => (string) $entry['title'],
            'summary'      => '',
            'role'         => '',
            'content_html' => (string) ($entry['content_html'] ?? ''),
            'has_body'     => trim((string) ($entry['content_html'] ?? '')) === '' ? 0 : 1,
            'source'       => (string) ($entry['source'] ?? ''),
            'author'       => (string) ($entry['author'] ?? ''),
            'editor'       => '',
            'published_at' => (string) ($entry['published_at'] ?? ''),
            'views'        => '0',
            'thumb'        => '',
            'status'       => (string) ($entry['status'] ?? 'draft'),
            'public_scope' => (string) ($entry['public_scope'] ?? 'archive'),
            'sort_no'      => 0,
            'images'       => [],
            'attachments'  => [],
        ], $now);
        $written++;
    }
    if ($hudong !== []) {
        $report[] = sprintf('次要表：互动 %d 条入库（旧库为 2017 年条目，按年限进 archive，前台不展示）', $written);
    }
}

/** @return list<array<string, mixed>> */
function migrate_merge_videos(array $payload, array $entries): array
{
    $list = is_array($payload) && array_is_list($payload) ? $payload : [];
    $seen = [];
    foreach ($list as $item) {
        if (is_array($item) && (string) ($item['url'] ?? '') !== '') {
            $seen[(string) $item['url']] = true;
        }
    }
    foreach ($entries as $entry) {
        $url = (string) ($entry['url'] ?? '');
        if ($url === '' || isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $list[] = ['title' => (string) ($entry['title'] ?? ''), 'url' => $url, 'img' => (string) ($entry['img'] ?? '')];
    }
    return $list;
}

/** @return array<string, mixed> */
function migrate_merge_links(array $payload, array $entries): array
{
    if (!is_array($payload)) {
        $payload = [];
    }
    $logos = isset($payload['logos']) && is_array($payload['logos']) ? $payload['logos'] : [];
    $seen = [];
    foreach ($logos as $item) {
        if (is_array($item) && (string) ($item['url'] ?? '') !== '') {
            $seen[(string) $item['url']] = true;
        }
    }
    foreach ($entries as $entry) {
        $url = (string) ($entry['url'] ?? '');
        if ($url === '' || isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $logos[] = ['title' => (string) ($entry['title'] ?? ''), 'url' => $url, 'img' => (string) ($entry['img'] ?? '')];
    }
    $payload['logos'] = $logos;
    return $payload;
}

// ------------------------------------------------------------------ 主流程

$channelTypes = [];
foreach ($db->select('SELECT type_code FROM sys_channel WHERE site_id = :site', ['site' => $siteId]) as $row) {
    $channelTypes[(string) $row['type_code']] = true;
}

$records = [];
$invalid = [];
$seenIds = [];
$duplicates = [];
$handle = fopen($inFile, 'rb');
while (($line = fgets($handle)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $record = json_decode($line, true);
    if (!is_array($record)) {
        $invalid[] = ['article_id' => 0, 'problems' => ['JSON 解析失败']];
        continue;
    }
    $problems = migrate_validate($record, $channelTypes);
    $id = (int) ($record['article_id'] ?? 0);
    if (isset($seenIds[$id])) {
        $duplicates[] = $id;
        $problems[] = '稿件号重复';
    }
    $seenIds[$id] = true;
    if ($problems !== []) {
        $invalid[] = ['article_id' => $id, 'problems' => $problems];
        continue;
    }
    $records[] = $record;
}
fclose($handle);

$byStatus = [];
$byScope = [];
$byChannel = [];
$images = 0;
$attachments = 0;
foreach ($records as $record) {
    $byStatus[(string) $record['status']] = ($byStatus[(string) $record['status']] ?? 0) + 1;
    $byScope[(string) $record['public_scope']] = ($byScope[(string) $record['public_scope']] ?? 0) + 1;
    $byChannel[(string) $record['channel_type']] = ($byChannel[(string) $record['channel_type']] ?? 0) + 1;
    $images += count((array) ($record['images'] ?? []));
    $attachments += count((array) ($record['attachments'] ?? []));
}

$lines = [];
$lines[] = '旧库迁移入库报告';
$lines[] = '输入：' . $inFile;
$lines[] = '模式：' . ($commit ? '写入（--commit）' : '干跑（--dry-run）');
$lines[] = '';
$lines[] = sprintf('可入库 %d 篇；校验不通过 %d 条；重复稿件号 %d 条', count($records), count($invalid), count($duplicates));
$lines[] = '状态：' . ($byStatus === [] ? '—' : implode('、', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($byStatus), $byStatus)));
$lines[] = '公开范围：' . ($byScope === [] ? '—' : implode('、', array_map(static fn ($k, $v) => $k . ' ' . $v, array_keys($byScope), $byScope)));
$lines[] = sprintf('正文图片 %d 条、附件 %d 条将登记', $images, $attachments);
$lines[] = sprintf('涉及栏目 %d 个', count($byChannel));
foreach ($byChannel as $type => $count) {
    $lines[] = sprintf('  %-8s %d', $type, $count);
}
if ($invalid !== []) {
    $lines[] = '';
    $lines[] = '校验未通过与重复的条目（前 50 条）';
    foreach (array_slice($invalid, 0, 50) as $item) {
        $lines[] = sprintf('  #%d：%s', (int) $item['article_id'], implode('；', $item['problems']));
    }
}

// 媒体清单概况（parse 产出、fetch_media 回填，不在就说明还没抓图）
$manifest = dirname($inFile) . '/media_manifest.csv';
if (is_file($manifest)) {
    $mediaStat = ['ok' => 0, 'failed' => 0, 'pending' => 0];
    foreach (array_slice(file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), 1) as $row) {
        // PHP 8.4 起 str_getcsv 的 $escape 必须显式传，否则每条清单都会刷一条弃用告警
        $parts = str_getcsv($row, ',', '"', '\\');
        $status = strtolower(trim((string) ($parts[2] ?? '')));
        if ($status === 'ok') {
            $mediaStat['ok']++;
        } elseif ($status === 'failed') {
            $mediaStat['failed']++;
        } else {
            $mediaStat['pending']++;
        }
    }
    $lines[] = '';
    $lines[] = sprintf('媒体清单：已抓取 %d、失败 %d、待抓取 %d（%s）',
        $mediaStat['ok'], $mediaStat['failed'], $mediaStat['pending'], $manifest);
}

// 库内已有、但不在本次迁移范围的稿件：迁移前就存在的样例／一页式内容会落在这里，提醒复核
$importedLookup = [];
foreach ($records as $record) {
    $importedLookup[(int) $record['article_id']] = true;
}
$outsiders = [];
$stale = 0;
foreach ($db->select('SELECT article_id, channel_type, title, status FROM cms_article WHERE site_id = :site', ['site' => $siteId]) as $row) {
    $id = (int) $row['article_id'];
    if (!isset($importedLookup[$id])) {
        if ($commit) {
            $stale++;
        } else {
            $outsiders[] = sprintf('#%d %s（%s，%s）', $id, (string) $row['title'], (string) $row['channel_type'], (string) $row['status']);
        }
    }
}
$lines[] = '';
if ($commit) {
    $lines[] = sprintf('库内不在本次迁移范围的稿件：%d 篇（迁移前已有，未改动；如需清理请单独处理）', $stale);
} else {
    $lines[] = sprintf('库内不在本次迁移范围的稿件：%d 篇', count($outsiders));
    foreach (array_slice($outsiders, 0, 5) as $line) {
        $lines[] = '  ' . $line;
    }
}

if ($commit) {
    $db->pdo()->beginTransaction();
    try {
        $writtenIds = [];
        foreach ($records as $record) {
            migrate_write_article($db, $siteId, $record, $now);
            $writtenIds[] = (int) $record['article_id'];
        }
        $sideReport = [];
        migrate_side_tables($db, $siteId, $sideFile, $now, $sideReport);
        $db->execute(
            'INSERT INTO sys_operation_log (user_id, action, target_type, target_id, detail_json, ip, user_agent)
             VALUES (:uid, :action, :type, :tid, :detail, :ip, :ua)',
            [
                'uid'    => 0,
                'action' => 'migrate.legacy',
                'type'   => 'site',
                'tid'    => (string) $siteId,
                'detail' => Json::encode([
                    'in'       => $inFile,
                    'articles' => count($records),
                    'status'   => $byStatus,
                    'scope'    => $byScope,
                ]),
                'ip'     => '',
                'ua'     => 'cli:legacy_import.php',
            ]
        );
        $db->pdo()->commit();

        if (!is_dir($outDir)) {
            mkdir($outDir, 0775, true);
        }
        file_put_contents($outDir . '/imported_ids.txt', implode("\n", $writtenIds) . "\n");
        $lines[] = '';
        $lines[] = '次要表：';
        foreach ($sideReport as $line) {
            $lines[] = '  ' . $line;
        }
        $lines[] = '';
        $lines[] = '回滚清单：' . $outDir . '/imported_ids.txt（' . count($writtenIds) . ' 个稿件号）';
    } catch (Throwable $e) {
        $db->pdo()->rollBack();
        fwrite(STDERR, '写入失败已回滚：' . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    $lines[] = '';
    $lines[] = '干跑模式未写库；确认无误后加 --commit 执行。';
}

$reportText = implode("\n", $lines) . "\n";
fwrite(STDOUT, $reportText);
if (is_dir($outDir)) {
    file_put_contents($outDir . '/report.txt', $reportText);
}

exit($invalid === [] ? 0 : 1);
