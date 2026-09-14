#!/usr/bin/env php
<?php

/**
 * 原标题（引题／主标题／副题）存量修复：把正文顶部的题区收进「原标题」三列，并把题区行的
 * 首行缩进统一成「strong 内两个全角空格」。
 *
 * 背景（2026-09-14 排查，开发库实测）：
 * - 2129 篇正文开头带题区，其中 2128 篇的 orig_kicker／orig_title／orig_subtitle 是空的
 *   （旧库迁移只把题区留在正文里，编辑页的「原标题」输入框一直是空的）；
 * - 题区共 4306 行，4092 行完全没有首行缩进、128 行缩进写在 `<strong>` 外面——
 *   编辑器（SunEditor）同步内容时会把 strong 外侧的行首空白丢掉，表现为「首行没空两格」。
 *
 * 用法：
 *   php backend/bin/fix-orig-title.php                 # 只统计与抽样，不写库
 *   php backend/bin/fix-orig-title.php --apply         # 写库（同时留回滚清单）
 *   php backend/bin/fix-orig-title.php --id=64049 --apply
 *   php backend/bin/fix-orig-title.php --limit=20      # 只处理前 20 篇（配合 --apply 可试跑）
 *
 * 口径：
 * - 只动「正文开头连续整块加粗」的那几行（最多 3 行），其余正文一个字节都不改；
 * - 三个原标题列都有值时不覆盖（只整理缩进），并单独报出来供人工核对；
 * - 正文里仍保留题区（前台与静态页从正文读题区），保存时由编辑页按三列重新拼回，幂等。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Content\BodyNormalizer;
use HechiZx\Support\Db;

$options = getopt('', ['apply', 'id::', 'limit::', 'show::', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "用法：php backend/bin/fix-orig-title.php [--apply] [--id=<稿件号>] [--limit=N] [--show=<稿件号>]\n");
    exit(0);
}

$apply = isset($options['apply']);
if (isset($options['show'])) {
    $id = (int) $options['show'];
    $row = (new Db((array) hechi_config('db')))->selectOne(
        'SELECT article_id, content_html FROM cms_article WHERE article_id = :id',
        ['id' => $id]
    );
    if ($row === null) {
        fwrite(STDERR, "找不到稿件 {$id}\n");
        exit(1);
    }
    $before = (string) $row['content_html'];
    $fixed = fixTitleZoneIndent($before);
    fwrite(STDOUT, "#{$id} 题区：" . json_encode($fixed['lines'], JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDOUT, "整理前：\n" . mb_substr($before, 0, 260) . "\n");
    fwrite(STDOUT, "整理后：\n" . mb_substr($fixed['html'], 0, 260) . "\n");
    fwrite(STDOUT, '正文其余部分是否变化：' . ($fixed['html'] === $before ? '否' : '有变化（仅题区行缩进）') . "\n");
    exit(0);
}

$onlyId = isset($options['id']) ? (int) $options['id'] : 0;
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 0;

$config = hechi_config();
$db = new Db((array) $config->get('db'));
$siteId = (int) $config->get('site.site_id', 1);

/* ---------------------------------------------------------------- 题区识别与整理 */

/** 把题区行的缩进统一成 strong 内的两个全角空格；返回新正文与处理行数（无变化时 html 不变） */
function fixTitleZoneIndent(string $html, int $limit = 3): array
{
    // 行首空白：既可能是真字符（全角空格、&nbsp; 解出的 U+00A0），也可能是没解码的实体写法
    $lead = '(?:\s|\x{00a0}|\x{2002}|\x{2003}|\x{2009}|\x{3000}'
        . '|&nbsp;|&emsp;|&ensp;|&thinsp;|&#160;|&#8195;|&#8194;|&#8201;|&#x2003;|&#x2002;|&#x2009;|&#xa0;)';
    $offset = 0;
    $out = '';
    $lines = [];
    $moved = 0;
    $changed = false;
    while (count($lines) < $limit) {
        if (!preg_match(
            '/\G(\s*)<(div|p)(\s[^>]*)?>(.*?)<\/\2>/is',
            $html,
            $m,
            0,
            $offset
        )) {
            break;
        }
        if (!preg_match('/^' . $lead . '*<(strong|b)(\s[^>]*)?>(.*)<\/\1>' . $lead . '*$/isu', $m[4], $inner)) {
            break;   // 不是「整块加粗」，题区到此为止
        }
        $tag = strtolower($inner[1]);
        $text = html_entity_decode(strip_tags($inner[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[\x{00a0}\x{2002}\x{2003}\x{2009}\x{3000}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            break;
        }

        $body = (string) preg_replace('/^' . $lead . '+/iu', '', $inner[3]);
        $block = $m[1] . '<' . $m[2] . ($m[3] ?? '') . '><' . $inner[1] . ($inner[2] ?? '') . '>'
            . BodyNormalizer::TITLE_INDENT . $body . '</' . $tag . '></' . $m[2] . '>';
        if (trim($m[0]) !== trim($block)) {
            $moved++;
            $changed = true;
        }

        $out .= $block;
        $lines[] = $text;
        $offset += strlen($m[0]);
    }

    $rest = $offset > 0 ? substr($html, $offset) : $html;
    $result = $out . $rest;

    return [
        'lines' => $lines,
        'html'  => $result,
        'moved' => $moved,
        'changed' => $changed,
    ];
}

/* ---------------------------------------------------------------- 取稿件 */

$where = ['site_id = :site', 'deleted_at IS NULL'];
$params = ['site' => $siteId];
if ($onlyId > 0) {
    $where[] = 'article_id = :id';
    $params['id'] = $onlyId;
}
$sql = 'SELECT article_id, title, content_html, orig_kicker, orig_title, orig_subtitle
        FROM cms_article WHERE ' . implode(' AND ', $where) . ' ORDER BY article_id';
if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}
$rows = $db->select($sql, $params);

$stat = [
    '扫描' => 0, '无题区' => 0, '有题区' => 0, '回填原标题' => 0,
    '缩进整理' => 0, '已有原标题跳过回填' => 0, '写库' => 0,
];
$samples = [];

// 回滚清单：写库前先按行追加，脚本中途被打断也留得住；整批更新包在事务里，失败即回滚
$rollbackFile = '';
if ($apply) {
    $dir = rtrim((string) $config->get('paths.storage'), '/') . '/backup';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $rollbackFile = $dir . '/fix-orig-title-' . date('Ymd-His') . '.jsonl';
}

$pdo = $apply ? $db->pdo() : null;
try {
    if ($pdo !== null) {
        $pdo->beginTransaction();
    }

    foreach ($rows as $row) {
        $stat['扫描']++;
        $fixed = fixTitleZoneIndent((string) $row['content_html']);
        if ($fixed['lines'] === []) {
            $stat['无题区']++;
            continue;
        }
        $stat['有题区']++;
        if ($fixed['moved'] > 0) {
            $stat['缩进整理'] += $fixed['moved'];
        }

        $hasOrig = trim((string) $row['orig_kicker'] . (string) $row['orig_title'] . (string) $row['orig_subtitle']) !== '';
        $kicker = $hasOrig ? (string) $row['orig_kicker'] : ($fixed['lines'][0] ?? '');
        $main = $hasOrig ? (string) $row['orig_title'] : ($fixed['lines'][1] ?? '');
        $sub = $hasOrig ? (string) $row['orig_subtitle'] : ($fixed['lines'][2] ?? '');
        if ($hasOrig) {
            $stat['已有原标题跳过回填']++;
        } else {
            $stat['回填原标题']++;
        }

        $fieldsChanged = $kicker !== (string) $row['orig_kicker']
            || $main !== (string) $row['orig_title']
            || $sub !== (string) $row['orig_subtitle'];
        $contentChanged = $fixed['changed'];
        if (!$fieldsChanged && !$contentChanged) {
            continue;
        }

        if (count($samples) < 8) {
            $samples[] = [
                'id' => (int) $row['article_id'],
                'title' => (string) $row['title'],
                'lines' => $fixed['lines'],
                'indent' => $fixed['moved'],
                'orig' => [$kicker, $main, $sub],
            ];
        }

        if ($apply) {
            // 先落回滚记录再改库：中断时清单与库的进度一致（清单多出的那行按「未生效」忽略即可）
            file_put_contents($rollbackFile, json_encode([
                'article_id' => (int) $row['article_id'],
                'content_html' => (string) $row['content_html'],
                'orig_kicker' => (string) $row['orig_kicker'],
                'orig_title' => (string) $row['orig_title'],
                'orig_subtitle' => (string) $row['orig_subtitle'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

            $db->execute(
                'UPDATE cms_article
                 SET content_html = :content, orig_kicker = :kicker, orig_title = :main, orig_subtitle = :sub
                 WHERE site_id = :site AND article_id = :id',
                [
                    'content' => $fixed['html'],
                    'kicker' => $kicker,
                    'main' => $main,
                    'sub' => $sub,
                    'site' => $siteId,
                    'id' => (int) $row['article_id'],
                ]
            );
            $stat['写库']++;
        }
    }

    if ($pdo !== null) {
        $pdo->commit();
    }
} catch (\Throwable $e) {
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "写库失败，已回滚：" . $e->getMessage() . "\n");
    if ($rollbackFile !== '' && is_file($rollbackFile)) {
        fwrite(STDERR, "本次中断前的回滚清单（最后一行可能未生效）：" . $rollbackFile . "\n");
    }
    exit(1);
}

/* ---------------------------------------------------------------- 输出 */

foreach ($stat as $key => $value) {
    fwrite(STDOUT, sprintf("%-14s %d\n", $key . '：', $value));
}
fwrite(STDOUT, "\n样例（最多 8 条）：\n");
foreach ($samples as $sample) {
    fwrite(STDOUT, sprintf(
        "  #%d %s\n    题区 %s\n    缩进整理 %d 行 → 原标题：%s\n",
        $sample['id'],
        mb_substr($sample['title'], 0, 30),
        json_encode($sample['lines'], JSON_UNESCAPED_UNICODE),
        $sample['indent'],
        json_encode($sample['orig'], JSON_UNESCAPED_UNICODE)
    ));
}

if (!$apply) {
    fwrite(STDOUT, "\n（未写库。确认无误后加 --apply；写库前会逐条留回滚清单）\n");
    exit(0);
}

$db->execute(
    'INSERT INTO sys_operation_log (user_id, action, target_type, target_id, detail_json, ip, user_agent, created_at)
     VALUES (0, :action, :type, :target, :detail, :ip, :ua, :at)',
    [
        'action' => 'article.fix_orig_title',
        'type' => 'site',
        'target' => (string) $siteId,
        'detail' => json_encode($stat, JSON_UNESCAPED_UNICODE),
        'ip' => '127.0.0.1',
        'ua' => 'fix-orig-title.php',
        'at' => date('Y-m-d H:i:s'),
    ]
);

fwrite(STDOUT, "\n回滚清单：" . ($rollbackFile !== '' && is_file($rollbackFile) ? $rollbackFile : '（本次没有需要写库的稿件）') . "\n");
fwrite(STDOUT, "回滚方法：按 jsonl 里的 article_id 把 content_html 与三列原标题写回。\n");
