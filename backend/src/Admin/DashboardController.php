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

        return $this->view->page('admin/dashboard', [
            'current'     => 'dashboard',
            'statusCount' => $this->articles->statusCounts(),
            'channelCount' => count($this->channels->adminAll()),
            'recent'      => $recent['items'],
            'total'       => $recent['total'],
            'publishDir'  => $this->publishDir,
            'lastPublish' => $this->lastPublish(),
            'logCount'    => (int) $this->db->scalar('SELECT COUNT(*) FROM sys_operation_log'),
        ], '后台首页');
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
