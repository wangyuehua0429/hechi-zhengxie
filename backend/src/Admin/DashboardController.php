<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Repository\ArticleRepository;
use HechiZx\Repository\ChannelRepository;

/**
 * 后台首页：数据概览 + 最近稿件 + 发布状态。
 */
final class DashboardController extends AdminController
{
    public function __construct(
        Auth $auth,
        View $view,
        \HechiZx\Support\Db $db,
        int $siteId,
        private ArticleRepository $articles,
        private ChannelRepository $channels,
        private string $publishDir
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }

        $recent = $this->articles->adminPaginate([], 1, 10);
        $lastPublish = $this->lastPublish();

        return $this->view->page('admin/dashboard', [
            'current'     => 'dashboard',
            'statusCount' => $this->articles->statusCounts(),
            'channelCount' => count($this->channels->adminAll()),
            'recent'      => $recent['items'],
            'total'       => $recent['total'],
            'publishDir'  => $this->publishDir,
            'lastPublish' => $lastPublish,
            'pending'     => $this->pendingPublish($lastPublish),
            'logCount'    => (int) $this->db->scalar('SELECT COUNT(*) FROM sys_operation_log'),
        ], '后台首页');
    }

    /**
     * 待发布条数：上次发布之后动过的稿件／栏目／首页配置。
     *
     * 口径与边界（2026-09-17）：基准取最后一条 publish.all 日志的时间（后台按钮与命令行
     * 发布都会写），比各表的 updated_at。它回答的是"上次发布之后动过几处"，不是"发布后
     * 会多出几页"——稿件转归档/删除导致静态页被清掉（pruned）的那部分未必刷新 updated_at，
     * 会漏报；绕过应用直接改库且不写 updated_at 的操作同样漏报；改了又改回来会轻度高估。
     * 要做精确的"新增/修改/删除"三类，需要一张发布快照表（记录每次产出的稿件号与内容哈希），
     * 那是增量发布阶段的活儿。
     *
     * @param array{at:string,by:string,pages:int,channels:int,articles:int}|null $lastPublish
     * @return array{articles:int,channels:int,home:int,total:int}|null 从未发布过时返回 null
     */
    private function pendingPublish(?array $lastPublish): ?array
    {
        if ($lastPublish === null) {
            return null;
        }
        $since = (string) $lastPublish['at'];
        $count = function (string $sql) use ($since): int {
            return (int) $this->db->scalar($sql, ['site' => $this->siteId, 'since' => $since]);
        };

        $articles = $count(
            "SELECT COUNT(*) FROM cms_article
             WHERE site_id = :site AND status = 'published' AND public_scope = 'public' AND has_body = 1
               AND updated_at > :since"
        );
        $channels = $count(
            "SELECT COUNT(*) FROM sys_channel
             WHERE site_id = :site AND status = 'published' AND updated_at > :since"
        );
        // 没跑 003 迁移的老库没有这三张表：不能因为概览页挂在缺表上，缺表就当 0
        try {
            $home = $count("SELECT COUNT(*) FROM cms_home_section WHERE site_id = :site AND updated_at > :since")
                + $count("SELECT COUNT(*) FROM cms_home_slide WHERE site_id = :site AND updated_at > :since")
                + $count("SELECT COUNT(*) FROM cms_home_banner WHERE site_id = :site AND updated_at > :since");
        } catch (\PDOException $e) {
            $home = 0;
        }

        return [
            'articles' => $articles,
            'channels' => $channels,
            'home'     => $home,
            'total'    => $articles + $channels + $home,
        ];
    }

    /**
     * 上次发布：以操作日志为准（后台按钮与命令行发布都会写），
     * 早期没有日志时才退回看静态产物的修改时间。
     *
     * @return array{at:string,by:string,pages:int,channels:int,articles:int}|null
     */
    private function lastPublish(): ?array
    {
        $row = $this->db->selectOne(
            'SELECT l.created_at, l.detail_json, l.user_id, u.real_name, u.username
             FROM sys_operation_log l
             LEFT JOIN sys_user u ON u.user_id = l.user_id
             WHERE l.action = :action
             ORDER BY l.log_id DESC
             LIMIT 1',
            ['action' => 'publish.all']
        );

        if ($row !== null) {
            $detail = json_decode((string) ($row['detail_json'] ?? ''), true);
            $detail = is_array($detail) ? $detail : [];
            $name = trim((string) ($row['real_name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['username'] ?? ''));
            }
            if ($name === '') {
                $name = (int) ($row['user_id'] ?? 0) === 0 ? '命令行' : '未知账号';
            }
            return [
                'at'       => (string) $row['created_at'],
                'by'       => $name,
                'pages'    => (int) ($detail['html_pages'] ?? 0),
                'channels' => (int) ($detail['channels'] ?? 0),
                'articles' => (int) ($detail['articles'] ?? 0),
            ];
        }

        $indexFile = rtrim($this->publishDir, '/') . '/index.html';
        if (is_file($indexFile)) {
            return [
                'at'       => date('Y-m-d H:i:s', (int) filemtime($indexFile)),
                'by'       => '（无发布日志，按文件时间估算）',
                'pages'    => 0,
                'channels' => 0,
                'articles' => 0,
            ];
        }

        return null;
    }
}
