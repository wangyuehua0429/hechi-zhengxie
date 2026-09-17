#!/usr/bin/env php
<?php

/**
 * 静态化发布：产出全文静态页（*.html、sitemap.xml）——对外唯一的页面产物。
 *
 *   php backend/bin/publish.php                     # 全量发布到 backend/storage/publish
 *   php backend/bin/publish.php --out=/tmp/site     # 指定输出目录
 *   php backend/bin/publish.php --article=<稿件号>  # 增量：只重发这一篇详情页（后台保存稿件时自动调用）
 *   php backend/bin/publish.php --html-only         # 与默认相同：只出静态页
 *   php backend/bin/publish.php --data-only         # 只出数据快照（离线预览/契约对拍用）
 *
 * 数据快照（data/*.json）自 2026-09-17 起不再随默认发布产出：线上没有任何消费者，
 * 内容以库为唯一来源，Nginx 只直出 /article/、/channel/ 与 /sitemap.xml。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Publish\Publisher;
use HechiZx\Support\Db;

$options = getopt('', ['out::', 'data-only', 'html-only', 'article:', 'help']);

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

// 增量：只重发一篇详情页（后台保存稿件时自动调用），不写 publish.all 日志——
// 它不算“发布全站”，后台「待发布条数」按 publish.article 单独排除这一篇。
if (isset($options['article'])) {
    // 支持逗号分隔的多个稿件号（批量流转后一次进程处理）
    // 只认纯数字稿件号（命令行参数同样不能带出目录边界）
    $ids = array_values(array_filter(array_map('trim', explode(',', (string) $options['article'])),
        static fn (string $v): bool => $v !== '' && ctype_digit($v)));
    $republished = 0;
    $removed = 0;
    $failed = [];
    foreach ($ids as $articleId) {
        $action = 'publish.article';
        $detail = [];
        try {
            // 一批只重建一次 sitemap（循环内不重复写整份文件）
            $published = $publisher->publishArticlePage($articleId, false);
            $published ? $republished++ : $removed++;
            $detail = ['published' => $published];
        } catch (\Throwable $e) {
            // 自动发布失败要留痕（后台操作日志可查），并且不能用成功退出码糊过去
            $failed[] = $articleId;
            $action = 'publish.article.failed';
            $detail = ['error' => $e->getMessage()];
            fwrite(STDERR, '增量发布失败（' . $articleId . '）：' . $e->getMessage() . "\n");
        }
        $db->execute(
            'INSERT INTO sys_operation_log (user_id, action, target_type, target_id, detail_json, ip, user_agent)
             VALUES (:uid, :action, :type, :tid, :detail, :ip, :ua)',
            ['uid' => 0, 'action' => $action, 'type' => 'article', 'tid' => $articleId,
             'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE), 'ip' => '', 'ua' => 'cli']
        );
    }
    if ($ids !== []) {
        $publisher->rebuildSitemap();
    }
    fwrite(STDOUT, sprintf("增量发布：重发 %d 篇、移除 %d 篇、失败 %d 篇（用时 %.2fs）\n",
        $republished, $removed, count($failed), microtime(true) - $started));
    exit($failed === [] ? 0 : 1);
}

if (isset($options['data-only'])) {
    $result += $publisher->publishDataSnapshots();
} else {
    $result += $publisher->publishHtml();
}

// 记一条发布日志：后台「上次发布」与「待发布条数」以它为准（命令行没有登录用户，user_id 记 0）。
// --data-only 只出数据快照、不动静态页，不能冒充“发布全站”——记 publish.data，否则后台会显示
// “已是最新”但静态页其实没重发（2026-09-17 独立评审 P3）。
$action = isset($options['data-only']) ? 'publish.data' : 'publish.all';
$db->execute(
    'INSERT INTO sys_operation_log (user_id, action, target_type, target_id, detail_json, ip, user_agent)
     VALUES (:uid, :action, :type, :tid, :detail, :ip, :ua)',
    [
        'uid'    => 0,
        'action' => $action,
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
