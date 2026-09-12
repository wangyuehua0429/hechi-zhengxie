#!/usr/bin/env php
<?php

/**
 * 旧地址 301 映射：把旧站地址整理进 sys_url_redirect，并产出上线要用的产物。
 *
 *   php backend/bin/redirects.php                          # 按库内内容生成/更新映射表
 *   php backend/bin/redirects.php --dry-run                # 只看统计，不写库
 *   php backend/bin/redirects.php --out=backend/storage/publish
 *                                                          # 另外写出 Nginx 片段与核对用 CSV
 *   php backend/bin/redirects.php --legacy-site=/path/to/gxhczx.gov.cn
 *                                                          # 拿旧站目录对照，列出没登记的旧地址
 *   php backend/bin/redirects.php --check=backend/storage/publish
 *                                                          # 校验每条映射的目标产物是否真的存在
 *
 * 旧地址形态与口径见 backend/src/Publish/RedirectMap.php 与
 * docs/旧地址301映射说明.md；改口径要同时改这两处。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Publish\RedirectMap;
use HechiZx\Support\Db;

$options = getopt('', [
    'out::', 'legacy-site::', 'check::', 'dry-run', 'fastcgi::', 'script::', 'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TXT
用法：php backend/bin/redirects.php [选项]
  --dry-run                 只统计将新增/更新多少条，不写库
  --out=<目录>              同时写出 redirects/nginx-301.conf、url-map.csv、report.txt
  --legacy-site=<目录>      旧站站点目录（内含 html/ 与 zl* 专题目录），用于列出未登记的旧地址
  --check=<发布目录>        校验每条映射的目标文件是否存在，缺失时退出码为 1
  --fastcgi=<地址>          Nginx 片段里的 fastcgi_pass，默认 php:9000
  --script=<路径>           Nginx 片段里的 SCRIPT_FILENAME，默认 /var/www/backend/public/index.php

TXT);
    exit(0);
}

$config = hechi_config();
$siteId = (int) $config->get('site.site_id', 1);
$db = new Db((array) $config->get('db'));
$map = new RedirectMap($db, $siteId);
$dryRun = isset($options['dry-run']);

$legacySite = isset($options['legacy-site']) ? rtrim((string) $options['legacy-site'], '/') : '';
$outDir = isset($options['out']) ? rtrim((string) $options['out'], '/') : '';
$checkDir = isset($options['check']) ? rtrim((string) $options['check'], '/') : '';

$audit = $legacySite !== ''
    ? $map->auditLegacySite($legacySite)
    : ['scanned' => false, 'html_pages' => 0, 'mapped' => 0, 'unmapped' => []];

$stat = $map->sync($dryRun);
$exact = $map->exact();

$lines = [];
$lines[] = '旧地址 301 映射';
$lines[] = $dryRun ? '（试运行：未写库）' : '（已写库）';
$lines[] = '';
$lines[] = sprintf('映射条数        %d', $stat['total']);
$lines[] = sprintf('新增 / 更新 / 不变   %d / %d / %d', $stat['inserted'], $stat['updated'], $stat['unchanged']);

$byNote = [];
foreach ($exact as $row) {
    $byNote[$row['note']] = ($byNote[$row['note']] ?? 0) + 1;
}
$lines[] = '';
$lines[] = '分类小计';
foreach ($byNote as $note => $count) {
    $lines[] = sprintf('  %-46s %d', $note, $count);
}

$lines[] = '';
$lines[] = '旧站目录对照';
if ($audit['scanned']) {
    $lines[] = sprintf('  旧站静态文章页 %d 个，其中已登记 %d 个', $audit['html_pages'], $audit['mapped']);
    $lines[] = sprintf('  未登记 %d 条', count($audit['unmapped']));
    foreach (array_slice($audit['unmapped'], 0, 10) as $item) {
        $lines[] = '    · ' . $item['old_path'] . '——' . $item['reason'];
    }
    if (count($audit['unmapped']) > 10) {
        $lines[] = sprintf('    …另有 %d 条见 report.txt', count($audit['unmapped']) - 10);
    }
} else {
    $lines[] = '  未提供 --legacy-site，未与旧站目录对照（上线前应带旧站目录跑一次）';
}

$missing = [];
if ($checkDir !== '') {
    $missing = $map->missingTargets($checkDir);
    $lines[] = '';
    $lines[] = sprintf('目标产物校验（%s）', $checkDir);
    $lines[] = $missing === []
        ? '  全部命中，没有指向空地址的 301'
        : sprintf('  %d 条映射的目标文件不存在，示例：%s', count($missing), $missing[0]['target']);
}

if ($outDir !== '') {
    $dir = $outDir . '/redirects';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $fastcgi = (string) ($options['fastcgi'] ?? 'php:9000');
    $script = (string) ($options['script'] ?? '/var/www/backend/public/index.php');

    file_put_contents($dir . '/nginx-301.conf', $map->nginx($fastcgi, $script));
    file_put_contents($dir . '/url-map.csv', $map->csv());

    $report = $lines;
    if ($missing !== []) {
        $report[] = '';
        $report[] = '目标缺失明细（前 200 条）';
        foreach (array_slice($missing, 0, 200) as $item) {
            $report[] = '  ' . $item['old_path'] . ' → ' . $item['target'] . '（' . $item['reason'] . '）';
        }
    }
    if ($audit['scanned'] && $audit['unmapped'] !== []) {
        $report[] = '';
        $report[] = '未登记的旧地址（前 200 条）';
        foreach (array_slice($audit['unmapped'], 0, 200) as $item) {
            $report[] = '  ' . $item['old_path'] . '——' . $item['reason'];
        }
    }
    file_put_contents($dir . '/report.txt', implode("\n", $report) . "\n");

    $lines[] = '';
    $lines[] = '产物已写入 ' . $dir . '：nginx-301.conf、url-map.csv、report.txt';
}

fwrite(STDOUT, implode("\n", $lines) . "\n");

if ($missing !== []) {
    exit(1);
}
exit(0);
