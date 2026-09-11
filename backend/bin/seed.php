#!/usr/bin/env php
<?php

/**
 * 把阶段 A 的静态快照灌进数据库（栏目、稿件、正文图片、附件、首页模块）。
 * 幂等：可反复执行，按主键覆盖。
 *
 *   php backend/bin/seed.php
 *   php backend/bin/seed.php --snapshots=/path/to/frontend/home/data
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Support\Db;
use HechiZx\Support\Json;

$options = getopt('', ['snapshots::', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "用法：php backend/bin/seed.php [--snapshots=<目录>]\n");
    exit(0);
}

$config = hechi_config();
$snapshotDir = rtrim((string) ($options['snapshots'] ?? $config->get('paths.snapshots')), '/');
$siteId = (int) $config->get('site.site_id', 1);
$now = date('Y-m-d H:i:s');

$db = new Db((array) $config->get('db'));

/**
 * 按主键判断插入或更新：SQLite 与 MySQL 的 upsert 语法不同，这里走可移植写法。
 *
 * @param array<string, mixed> $where
 * @param array<string, mixed> $values
 */
function upsert(Db $db, string $table, array $where, array $values): void
{
    $whereSql = implode(' AND ', array_map(static fn (string $k): string => $k . ' = :w_' . $k, array_keys($where)));
    $exists = $db->selectOne('SELECT 1 AS ok FROM ' . $table . ' WHERE ' . $whereSql, prefixKeys($where, 'w_'));

    if ($exists !== null) {
        $sets = implode(', ', array_map(static fn (string $k): string => $k . ' = :v_' . $k, array_keys($values)));
        $db->execute(
            'UPDATE ' . $table . ' SET ' . $sets . ' WHERE ' . $whereSql,
            prefixKeys($values, 'v_') + prefixKeys($where, 'w_')
        );
        return;
    }

    $columns = array_merge(array_keys($where), array_keys($values));
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')';
    $db->execute($sql, $where + $values);
}

/** @param array<string, mixed> $params @return array<string, mixed> */
function prefixKeys(array $params, string $prefix): array
{
    $out = [];
    foreach ($params as $key => $value) {
        $out[$prefix . $key] = $value;
    }
    return $out;
}

/** @return array<string, mixed> */
function readSnapshot(string $dir, string $file): array
{
    $data = Json::readFile($dir . '/' . $file);
    return is_array($data) ? $data : [];
}

$home = readSnapshot($snapshotDir, 'home.json');
$channelData = readSnapshot($snapshotDir, 'channel.json');
$articleData = readSnapshot($snapshotDir, 'article.json');
/** @var list<array<string, mixed>> $channels */
$channels = $channelData['channels'] ?? [];
/** @var list<array<string, mixed>> $articles */
$articles = $articleData['articles'] ?? [];

// 1) 站点
$meta = is_array($home['meta'] ?? null) ? $home['meta'] : [];
upsert($db, 'sys_site', ['code' => (string) $config->get('site.code', 'main')], [
    'name'       => (string) ($meta['title'] ?? $config->get('site.name')),
    'domain'     => (string) ($meta['domain'] ?? $config->get('site.domain')),
    'owner'      => (string) ($meta['owner'] ?? ''),
    'icp'        => (string) ($meta['icp'] ?? ''),
    'theme'      => 'default',
    'status'     => 'enabled',
    'updated_at' => $now,
]);

// 2) 栏目
foreach ($channels as $channel) {
    upsert($db, 'sys_channel', ['site_id' => $siteId, 'type_code' => (string) $channel['type']], [
        'parent_type'   => (string) ($channel['columnId'] ?? ''),
        'slug'          => (string) ($channel['slug'] ?? ''),
        'name'          => (string) ($channel['name'] ?? ''),
        'inner_name'    => (string) ($channel['inner'] ?? ''),
        'intro'         => (string) ($channel['intro'] ?? ''),
        'layout'        => (string) ($channel['layout'] ?? 'list'),
        'total_count'   => (int) ($channel['total'] ?? 0),
        'home_sourced'  => !empty($channel['homeSourced']) ? 1 : 0,
        'status'        => 'published',
        'siblings_json' => Json::encode($channel['siblings'] ?? []),
        'counties_json' => isset($channel['counties']) ? Json::encode($channel['counties']) : null,
        'note'          => (string) ($channel['note'] ?? ''),
        'feature_json'  => isset($channel['feature']) ? Json::encode($channel['feature']) : null,
        'updated_at'    => $now,
    ]);
}

// 3) 稿件：先收列表项（含无正文的），再用正文覆盖
//    说明：视频、专题等栏目取自首页模块，列表项的 id 是 <栏目号>-<序号> 这类原型合成值，
//    不是旧库稿件号，因此不进 cms_article；这些栏目的列表由接口从 cms_home_block 现算。
$rows = [];
$links = [];
$primary = [];
foreach ($channels as $channel) {
    $position = 0;
    foreach (($channel['list'] ?? []) as $item) {
        $id = (string) $item['id'];
        if (!ctype_digit($id)) {
            continue;
        }
        $position++;
        $channelType = (string) $channel['type'];

        if (!isset($rows[$id])) {
            $rows[$id] = [
                'channel_type' => $channelType,
                'title'        => (string) $item['title'],
                'published_at' => (string) ($item['datetime'] ?? $item['date'] ?? ''),
                'source'       => (string) ($item['source'] ?? ''),
                'views'        => (string) ($item['views'] ?? '0'),
                'thumb'        => (string) ($item['img'] ?? ''),
                'role'         => (string) ($item['role'] ?? ''),
                'has_body'     => 0,
            ];
        }

        $links[] = ['id' => (int) $id, 'channel' => $channelType, 'sort' => $position];
        if (!isset($primary[$id])) {
            $primary[$id] = $channelType;
        }
    }
}

$imageCount = 0;
$attachmentCount = 0;
foreach ($articles as $article) {
    $id = (string) $article['id'];
    $base = $rows[$id] ?? [
        'channel_type' => (string) $article['channelType'],
        'title'        => (string) $article['title'],
        'published_at' => (string) ($article['date'] ?? ''),
        'source'       => (string) ($article['source'] ?? ''),
        'views'        => (string) ($article['views'] ?? '0'),
        'thumb'        => '',
        'role'         => '',
    ];

    // 正文时间以列表项为准（列表带秒，正文只到分），避免列表时间被截断
    $rows[$id] = $base;
    $rows[$id]['has_body'] = 1;
    $rows[$id]['channel_type'] = (string) $article['channelType'];
    $primary[$id] = (string) $article['channelType'];
    $rows[$id]['subtitle'] = (string) ($article['subtitle'] ?? '');
    $rows[$id]['summary'] = (string) ($article['summary'] ?? '');
    $rows[$id]['content_html'] = (string) ($article['content'] ?? '');
    $rows[$id]['author'] = (string) ($article['author'] ?? '');
    $rows[$id]['editor'] = (string) ($article['editor'] ?? '');
}

foreach ($rows as $id => $row) {
    upsert($db, 'cms_article', ['article_id' => (int) $id], [
        'site_id'      => $siteId,
        'channel_type' => $row['channel_type'],
        'title'        => $row['title'],
        'subtitle'     => (string) ($row['subtitle'] ?? ''),
        'summary'      => (string) ($row['summary'] ?? ''),
        'content_html' => (string) ($row['content_html'] ?? ''),
        'source'       => $row['source'],
        'author'       => (string) ($row['author'] ?? ''),
        'editor'       => (string) ($row['editor'] ?? ''),
        'published_at' => $row['published_at'],
        'views'        => $row['views'],
        'role'         => (string) ($row['role'] ?? ''),
        'thumb'        => (string) ($row['thumb'] ?? ''),
        'has_body'     => (int) $row['has_body'],
        'status'       => 'published',
        'public_scope' => 'public',
        'updated_at'   => $now,
    ]);
}

// 4) 栏目归属：清掉重灌，保证重跑结果一致
$db->execute('DELETE FROM cms_article_channel WHERE site_id = :site', ['site' => $siteId]);
$linked = [];
foreach ($links as $link) {
    $key = $link['id'] . '|' . $link['channel'];
    if (isset($linked[$key])) {
        continue;
    }
    $linked[$key] = true;
    upsert(
        $db,
        'cms_article_channel',
        ['article_id' => $link['id'], 'site_id' => $siteId, 'channel_type' => $link['channel']],
        [
            'sort_no'    => $link['sort'],
            'is_primary' => ($primary[(string) $link['id']] ?? '') === $link['channel'] ? 1 : 0,
        ]
    );
}
// 注：只出现在详情、没进任何栏目列表的稿件（如「机构设置」「政协章程」这类单页）
// 不补栏目归属——它们在旧站本来就不出现在栏目列表里，详情页靠 cms_article.channel_type 取面包屑。

// 5) 正文图片与附件（整篇覆盖，保证重跑结果一致）
foreach ($articles as $article) {
    $id = (int) $article['id'];
    $db->execute('DELETE FROM cms_article_image WHERE article_id = :id', ['id' => $id]);
    $db->execute('DELETE FROM cms_attachment WHERE article_id = :id', ['id' => $id]);

    foreach (array_values($article['images'] ?? []) as $index => $path) {
        $db->execute(
            'INSERT INTO cms_article_image (article_id, path, sort_no) VALUES (:id, :path, :sort)',
            ['id' => $id, 'path' => (string) $path, 'sort' => $index]
        );
        $imageCount++;
    }

    foreach (array_values($article['attachments'] ?? []) as $index => $file) {
        $db->execute(
            'INSERT INTO cms_attachment (article_id, name, url, ext, sort_no) VALUES (:id, :name, :url, :ext, :sort)',
            [
                'id'   => $id,
                'name' => (string) ($file['name'] ?? ''),
                'url'  => (string) ($file['url'] ?? ''),
                'ext'  => (string) ($file['ext'] ?? ''),
                'sort' => $index,
            ]
        );
        $attachmentCount++;
    }
}

// 6) 首页模块：按 home.json 顶层键整块存
$blockIndex = 0;
foreach ($home as $key => $payload) {
    upsert($db, 'cms_home_block', ['site_id' => $siteId, 'block_key' => (string) $key], [
        'sort_no'      => $blockIndex++,
        'payload_json' => Json::encode($payload),
        'updated_at'   => $now,
    ]);
}

fwrite(STDOUT, sprintf(
    "已灌入：栏目 %d 个、稿件 %d 篇（含正文 %d 篇）、栏目归属 %d 条、正文图片 %d 张、附件 %d 条、首页模块 %d 个\n",
    count($channels),
    count($rows),
    count($articles),
    count($linked),
    $imageCount,
    $attachmentCount,
    $blockIndex
));
fwrite(STDOUT, '数据源：' . $snapshotDir . "\n");
