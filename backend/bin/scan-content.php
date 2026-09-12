#!/usr/bin/env php
<?php

/**
 * 正文存量体检（只读）：扫 cms_article.content_html，报出含可疑标记的稿件，
 * 并给出「清洗前后」预览，用于决定是否需要回填。
 *
 *   php backend/bin/scan-content.php                # 默认预览 3 篇
 *   php backend/bin/scan-content.php --limit=10     # 预览 10 篇
 *   php backend/bin/scan-content.php --id=62180     # 只看某篇
 *
 * 本命令只发 SELECT，不改库、不写文件。回填是另一件事：先备份库文件再单独执行。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Content\HtmlSanitizer;
use HechiZx\Support\Db;

$options = getopt('', ['limit::', 'id::', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "用法：php backend/bin/scan-content.php [--limit=3] [--id=<稿件号>]\n");
    exit(0);
}

$limit = max(1, (int) ($options['limit'] ?? 3));
$onlyId = isset($options['id']) ? (int) $options['id'] : 0;

/**
 * 可疑标记的分族判断。SQL 先做粗筛（能走索引、避免全表拉正文），这里再精确确认。
 *
 * @return list<string> 命中项
 */
function suspiciousHits(string $html): array
{
    $families = [
        '<script'      => '/<script[\s>]/i',
        '<style'       => '/<style[\s>]/i',
        '<iframe'      => '/<iframe[\s>]/i',
        '<object／embed' => '/<(object|embed)[\s>]/i',
        'on 事件'      => '/\son[a-z]+\s*=/i',
        'javascript:'  => '/javascript\s*:/i',
        'data:text'    => '/data\s*:\s*text\/html/i',
    ];

    $hits = [];
    foreach ($families as $label => $pattern) {
        if (preg_match($pattern, $html) === 1) {
            $hits[] = $label;
        }
    }

    return $hits;
}

function preview(string $html, int $width = 120): string
{
    $flat = trim((string) preg_replace('/\s+/u', ' ', $html));
    return mb_strlen($flat, 'UTF-8') > $width ? mb_substr($flat, 0, $width, 'UTF-8') . '……' : $flat;
}

$config = hechi_config();
/** @var array<string, mixed> $dbConfig */
$dbConfig = (array) $config->get('db');
$db = new Db($dbConfig);

fwrite(STDOUT, '驱动：' . $db->driver() . '　库：' . (string) $dbConfig['database'] . "\n");

$where = "content_html IS NOT NULL AND content_html <> ''";
$params = [];

$total = (int) $db->scalar('SELECT COUNT(*) FROM cms_article WHERE ' . $where, $params);

// 粗筛：只在库里含可疑片段的行上拉正文
$coarse = " AND (content_html LIKE '%<script%' OR content_html LIKE '%<style%'"
    . " OR content_html LIKE '%<iframe%' OR content_html LIKE '%<object%'"
    . " OR content_html LIKE '%<embed%' OR content_html LIKE '%javascript:%'"
    . " OR content_html LIKE '%data:text/html%' OR content_html LIKE '%on%=')";

if ($onlyId > 0) {
    $where .= ' AND article_id = :id';
    $params['id'] = $onlyId;
    $total = (int) $db->scalar('SELECT COUNT(*) FROM cms_article WHERE ' . $where, $params);
}

$rows = $db->select(
    'SELECT article_id, title, content_html FROM cms_article WHERE ' . $where . $coarse . ' ORDER BY article_id ASC',
    $params
);

$flagged = [];
foreach ($rows as $row) {
    $hits = suspiciousHits((string) $row['content_html']);
    if ($hits !== []) {
        $flagged[] = ['id' => (int) $row['article_id'], 'title' => (string) $row['title'], 'html' => (string) $row['content_html'], 'hits' => $hits];
    }
}

fwrite(STDOUT, '有正文稿件：' . $total . " 篇\n");
fwrite(STDOUT, '含可疑标记：' . count($flagged) . " 篇\n");

if ($flagged === []) {
    fwrite(STDOUT, "没有发现含可疑标记的正文，无需回填。\n");
    exit(0);
}

$familyTally = [];
foreach ($flagged as $item) {
    foreach ($item['hits'] as $hit) {
        $familyTally[$hit] = ($familyTally[$hit] ?? 0) + 1;
    }
}
ksort($familyTally);
$parts = [];
foreach ($familyTally as $family => $count) {
    $parts[] = $family . ' ' . $count . ' 篇';
}
fwrite(STDOUT, '命中分布：' . implode('、', $parts) . "\n\n");

fwrite(STDOUT, "可疑稿件（按稿件号）：\n");
foreach ($flagged as $item) {
    fwrite(STDOUT, '  ' . $item['id'] . '　' . $item['title'] . '　命中：' . implode('、', $item['hits']) . "\n");
}

fwrite(STDOUT, "\n清洗前后预览（最多 " . $limit . " 篇）：\n");
$shown = 0;
foreach ($flagged as $item) {
    if ($shown >= $limit) {
        break;
    }
    $shown += 1;
    fwrite(STDOUT, '[' . $item['id'] . '] ' . $item['title'] . "\n");
    fwrite(STDOUT, '  清洗前：' . preview($item['html']) . "\n");
    fwrite(STDOUT, '  清洗后：' . preview(HtmlSanitizer::clean($item['html'])) . "\n");
}
if (count($flagged) > $shown) {
    fwrite(STDOUT, '（另有 ' . (count($flagged) - $shown) . " 篇未预览，可用 --limit 调整）\n");
}

fwrite(STDOUT, "\n提示：本命令只读。确需回填时先备份库文件，再单独执行回填。\n");
exit(0);
