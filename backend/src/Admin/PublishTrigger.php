<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Publish\Publisher;
use HechiZx\Support\Db;

/**
 * 保存即自动发布（2026-09-17）：后台写操作成功后，把受影响的静态页在**后台进程**里重发，
 * 不拖慢保存请求。
 *
 * 范围与取舍：
 *   · 稿件改动（新建／编辑／流转／删除）→ 只重发这一篇详情页 + 重建 sitemap（增量）；
 *   · 栏目与首页配置的改动会波及所有页面的站头与列表，仍走「发布全站」（后台按钮或 CLI）。
 *
 * 执行方式：优先用 `exec` 起独立进程（fire-and-forget）；`exec` 被 disable_functions 禁掉时
 * 退回同步发布——保存会多花几十毫秒，但静态页一定跟上。设环境变量 `PUBLISH_AUTO=0` 可整体关掉。
 */
final class PublishTrigger
{
    /** @param int|string $articleId 稿件号 */
    public static function articleChanged(int|string $articleId): void
    {
        self::articlesChanged([$articleId]);
    }

    /**
     * 批量（流转／批量操作后调用）：一次进程带多个稿件号，避免为 100 篇起 100 个进程。
     *
     * @param list<int|string> $articleIds
     */
    public static function articlesChanged(array $articleIds): void
    {
        $ids = [];
        foreach ($articleIds as $raw) {
            $id = (string) $raw;
            // 只接受纯数字稿件号：这些值来自表单，拼进命令行与文件路径前必须卡死
            if ($id !== '' && ctype_digit($id) && $id !== '0') {
                $ids[$id] = true;
            }
        }
        if ($ids === [] || getenv('PUBLISH_AUTO') === '0') {
            return;
        }
        $cli = dirname(__DIR__, 2) . '/bin/publish.php';
        if (!is_file($cli)) {
            return;
        }
        $disabled = array_map('trim', (array) explode(',', (string) ini_get('disable_functions')));
        $canExec = function_exists('exec') && !in_array('exec', $disabled, true);
        // 一条命令最多带 50 个稿件号，避免命令行过长
        foreach (array_chunk(array_keys($ids), 50) as $chunk) {
            if ($canExec) {
                @exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cli)
                    . ' --article=' . escapeshellarg(implode(',', $chunk)) . ' > /dev/null 2>&1 &');
                continue;
            }
            foreach ($chunk as $id) {
                self::publishNow($id);
            }
        }
    }

    /** 同步兜底：与 php backend/bin/publish.php --article=<id> 同一条路径 */
    private static function publishNow(string $id): void
    {
        try {
            $config = hechi_config();
            $publisher = new Publisher(
                new Db((array) $config->get('db')),
                (int) $config->get('site.site_id', 1),
                (string) $config->get('publish.out'),
                rtrim((string) $config->get('paths.templates'), '/') . '/page.php',
                (string) $config->get('site.name'),
                (string) $config->get('site.domain')
            );
            $publisher->publishArticlePage($id);
        } catch (\Throwable $e) {
            // 自动发布失败不能影响保存结果：留痕即可，编辑仍可手动点「发布全站」
            error_log('自动发布失败（article ' . $id . '）：' . $e->getMessage());
        }
    }
}
