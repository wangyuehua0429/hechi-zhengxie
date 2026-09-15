#!/usr/bin/env php
<?php

/**
 * 正文末尾署名清理：删掉与作者栏重复的末尾署名，作者归口到标题下的作者栏。
 *
 * 背景（2026-09-15 排查，开发库 4969 篇实读）：
 * - 旧站正文结尾普遍带一行署名，形态三种：`（黄荞丹 覃可论）`、`口黄正华`（旧站的方框署名标记
 *   导出后成了「口」）、`（作者：本报首席记者 罗昌亮）`；
 * - 署名与 `cms_article.author` 基本重复，而标题下的元信息行已经显示「作者：…」，
 *   正文里再来一遍就是重复；口径与判据见 {@see \HechiZx\Content\AuthorSignature}；
 * - 尾随的「（作者系…职务）」「（文章刊登于…）」「（本版图片均由…/摄）」「（发言者为…）」
 *   是职务说明、出处与图片署名，不在删除范围内，脚本会把它们数出来供复核。
 *
 * 用法：
 *   php backend/bin/fix-author-signature.php                 # 只统计与抽样，不写库
 *   php backend/bin/fix-author-signature.php --apply         # 写库（同时留回滚清单）
 *   php backend/bin/fix-author-signature.php --id=64088      # 只看某篇
 *   php backend/bin/fix-author-signature.php --limit=50      # 只处理前 50 篇（配合 --apply 可试跑）
 *   php backend/bin/fix-author-signature.php --dump=/tmp/a.tsv   # 把每条改动写成清单（tab 分隔）
 *
 * 口径：
 * - 只动正文末尾（最后 240 字节窗口内）的署名，正文中间出现的括号不动；
 * - 只删「与作者栏对得上」的署名；作者栏为空的稿件不猜名字，只有末尾写明「口姓名」或
 *   「（作者：X）」这种能明确取名的，才先把名字填进作者栏再删；
 * - 库里只改 content_html（必要时加 author），status／public_scope／发布时间等一律不动。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Content\AuthorSignature;
use HechiZx\Support\Db;

$options = getopt('', ['apply', 'id::', 'limit::', 'dump::', 'sample::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "用法：php backend/bin/fix-author-signature.php [--apply] [--id=<稿件号>] [--limit=N] [--dump=<文件>] [--sample=N]\n");
    exit(0);
}

$apply = isset($options['apply']);
$onlyId = isset($options['id']) ? (int) $options['id'] : 0;
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 0;
$sampleSize = isset($options['sample']) ? max(1, (int) $options['sample']) : 8;
$dumpFile = isset($options['dump']) ? (string) $options['dump'] : '';

$config = hechi_config();
$db = new Db((array) $config->get('db'));
$siteId = (int) $config->get('site.site_id', 1);

/* ---------------------------------------------------------------- 取稿件 */

$where = ['site_id = :site', 'deleted_at IS NULL'];
$params = ['site' => $siteId];
if ($onlyId > 0) {
    $where[] = 'article_id = :id';
    $params['id'] = $onlyId;
}
$sql = 'SELECT article_id, title, author, content_html FROM cms_article
        WHERE ' . implode(' AND ', $where) . ' ORDER BY article_id';
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}
$rows = $db->select($sql, $params);

$stat = [
    '扫描' => 0,
    '删署名' => 0,
    '回填作者栏' => 0,
    '保留说明行' => 0,
    '写库' => 0,
];
$samples = [];
$kept = [];
$dump = $dumpFile !== '' ? fopen($dumpFile, 'wb') : null;
if ($dump !== false && $dump !== null) {
    fwrite($dump, "article_id\tauthor\tafter_author\tremoved\tbody_tail_before\tbody_tail_after\n");
}

// 回滚清单：写库前先按行追加，脚本中途被打断也留得住；整批更新包在事务里，失败即回滚
$rollbackFile = '';
if ($apply) {
    $dir = rtrim((string) $config->get('paths.storage'), '/') . '/backup';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $rollbackFile = $dir . '/fix-author-signature-' . date('Ymd-His') . '.jsonl';
}

/** 正文纯文本（去标签、压缩空白），用于抽样展示与人工核对 */
function plainText(string $html): string
{
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace('/[\x{00a0}\x{2002}\x{2003}\x{2009}\x{3000}]+/u', ' ', $text);

    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/** 末尾一带的纯文本，用于抽样展示 */
function tailText(string $html, int $width = 60): string
{
    return mb_substr(plainText($html), -$width);
}

/** 末尾是否还留着括号说明（说明行／出处／图片署名），只统计不改 */
function hasTailNote(string $html): bool
{
    return preg_match('~[（(][^（()）]{1,60}[)）]\s*(?:</(?:div|p)>|\s)*$~u', rtrim($html)) === 1;
}

$pdo = $apply ? $db->pdo() : null;
try {
    if ($pdo !== null) {
        $pdo->beginTransaction();
    }

    foreach ($rows as $row) {
        $stat['扫描']++;
        $id = (int) $row['article_id'];
        $author = trim((string) $row['author']);
        $before = (string) $row['content_html'];

        // 作者栏为空的稿件：末尾明确写了名字才取（「口潘剑」「（作者：X）」），取不到就不动
        $filled = '';
        if ($author === '') {
            $filled = AuthorSignature::extractName($before);
            if ($filled === '') {
                continue;
            }
        }
        $target = $filled !== '' ? $filled : $author;
        $after = AuthorSignature::strip($before, $target);
        $bodyChanged = $after !== $before;
        $authorChanged = $filled !== '' && $filled !== $author;
        if (!$bodyChanged && !$authorChanged) {
            if (hasTailNote($before) && count($kept) < 12) {
                $kept[] = ['id' => $id, 'author' => $author, 'tail' => tailText($before, 46)];
            }
            if (hasTailNote($before)) {
                $stat['保留说明行']++;
            }
            continue;
        }

        if ($bodyChanged) {
            $stat['删署名']++;
        }
        if ($authorChanged) {
            $stat['回填作者栏']++;
        }
        $beforePlain = plainText($before);
        $afterPlain = plainText($after);
        $beforeTail = mb_substr($beforePlain, -60);
        $afterTail = mb_substr($afterPlain, -60);
        if (count($samples) < $sampleSize) {
            $samples[] = [
                'id' => $id,
                'author' => $author !== '' ? $author : '（空 → ' . $filled . '）',
                'before' => $beforeTail,
                'after' => $afterTail,
            ];
        }
        if ($dump !== null) {
            $removed = [];
            foreach (AuthorSignature::spans($before, $target) as [$from, $to]) {
                $removed[] = plainText(substr($before, $from, $to - $from));
            }
            fwrite($dump, implode("\t", [
                (string) $id,
                $author,
                $target,
                implode(' | ', array_reverse($removed)),
                $beforeTail,
                $afterTail,
            ]) . "\n");
        }

        if (!$apply) {
            continue;
        }
        // 先落回滚记录再改库：中断时清单与库的进度一致（清单多出的那行按「未生效」忽略即可）
        file_put_contents($rollbackFile, json_encode([
            'article_id' => $id,
            'content_html' => $before,
            'author' => (string) $row['author'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        $db->execute(
            'UPDATE cms_article SET content_html = :content, author = :author
             WHERE site_id = :site AND article_id = :id',
            [
                'content' => $after,
                'author' => $target,
                'site' => $siteId,
                'id' => $id,
            ]
        );
        $stat['写库']++;
    }

    if ($pdo !== null) {
        $pdo->commit();
    }
} catch (\Throwable $e) {
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, '写库失败，已回滚：' . $e->getMessage() . "\n");
    if ($rollbackFile !== '' && is_file($rollbackFile)) {
        fwrite(STDERR, '本次中断前的回滚清单（最后一行可能未生效）：' . $rollbackFile . "\n");
    }
    exit(1);
} finally {
    if ($dump !== null) {
        fclose($dump);
    }
}

/* ---------------------------------------------------------------- 输出 */

foreach ($stat as $key => $value) {
    fwrite(STDOUT, sprintf("%-14s %d\n", $key . '：', $value));
}
fwrite(STDOUT, "\n样例（最多 " . $sampleSize . " 条）：\n");
foreach ($samples as $sample) {
    fwrite(STDOUT, sprintf(
        "  #%d 作者：%s\n    删前：…%s\n    删后：…%s\n",
        $sample['id'],
        $sample['author'],
        $sample['before'],
        $sample['after']
    ));
}
if ($kept !== []) {
    fwrite(STDOUT, "\n保留未删（末尾是职务／出处／图片署名）：\n");
    foreach ($kept as $item) {
        fwrite(STDOUT, sprintf("  #%d 作者：%s …%s\n", $item['id'], $item['author'], $item['tail']));
    }
}

if (!$apply) {
    fwrite(STDOUT, "\n（未写库。确认无误后加 --apply；写库前会逐条留回滚清单）\n");
    exit(0);
}

$db->execute(
    'INSERT INTO sys_operation_log (user_id, action, target_type, target_id, detail_json, ip, user_agent, created_at)
     VALUES (0, :action, :type, :target, :detail, :ip, :ua, :at)',
    [
        'action' => 'article.fix_author_signature',
        'type' => 'site',
        'target' => (string) $siteId,
        'detail' => json_encode($stat, JSON_UNESCAPED_UNICODE),
        'ip' => '127.0.0.1',
        'ua' => 'fix-author-signature.php',
        'at' => date('Y-m-d H:i:s'),
    ]
);

fwrite(STDOUT, "\n回滚清单：" . ($rollbackFile !== '' && is_file($rollbackFile) ? $rollbackFile : '（本次没有需要写库的稿件）') . "\n");
fwrite(STDOUT, "回滚方法：按 jsonl 里的 article_id 把 content_html 与 author 写回。\n");
