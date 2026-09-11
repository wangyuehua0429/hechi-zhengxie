#!/usr/bin/env php
<?php

/**
 * 静态化发布：产出数据快照（data/*.json）与全文静态页（*.html、sitemap.xml）。
 *
 *   php backend/bin/publish.php                     # 全量发布到 backend/storage/publish
 *   php backend/bin/publish.php --out=/tmp/site     # 指定输出目录
 *   php backend/bin/publish.php --data-only         # 只出数据快照
 *   php backend/bin/publish.php --html-only         # 只出静态页
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Publish\Publisher;
use HechiZx\Support\Db;

$options = getopt('', ['out::', 'data-only', 'html-only', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "用法：php backend/bin/publish.php [--out=<目录>] [--data-only] [--html-only]\n");
    exit(0);
}

$config = hechi_config();
$out = rtrim((string) ($options['out'] ?? $config->get('publish.out')), '/');
$template = (string) $config->get('paths.templates') . '/page.php';

if (!is_file($template)) {
    fwrite(STDERR, '找不到模板：' . $template . "\n");
    exit(1);
}

$db = new Db((array) $config->get('db'));
$siteId = (int) $config->get('site.site_id', 1);

$publisher = new Publisher(
    $db,
    $siteId,
    $out,
    $template,
    (string) $config->get('site.name'),
    (string) $config->get('site.domain')
);

$started = microtime(true);
$result = [];

if (!isset($options['html-only'])) {
    $result += $publisher->publishDataSnapshots();
}
if (!isset($options['data-only'])) {
    $result += $publisher->publishHtml();
}

// 记一条发布日志：后台「上次发布」以它为准（命令行没有登录用户，user_id 记 0）
$db->execute(
    'INSERT INTO sys_operation_log (user_id, action, target_type, target_id, detail_json, ip, user_agent)
     VALUES (:uid, :action, :type, :tid, :detail, :ip, :ua)',
    [
        'uid'    => 0,
        'action' => 'publish.all',
        'type'   => 'site',
        'tid'    => (string) $siteId,
        'detail' => json_encode($result, JSON_UNESCAPED_UNICODE),
        'ip'     => '',
        'ua'     => 'cli',
    ]
);

fwrite(STDOUT, '输出目录：' . $publisher->outDir() . "\n");
foreach ($result as $name => $count) {
    fwrite(STDOUT, sprintf("  %-12s %d\n", $name, $count));
}
fwrite(STDOUT, sprintf("用时 %.2fs\n", microtime(true) - $started));
