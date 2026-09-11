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
        $publishedAt = null;
        $indexFile = rtrim($this->publishDir, '/') . '/index.html';
        if (is_file($indexFile)) {
            $publishedAt = date('Y-m-d H:i:s', (int) filemtime($indexFile));
        }

        return $this->view->page('admin/dashboard', [
            'current'     => 'dashboard',
            'statusCount' => $this->articles->statusCounts(),
            'channelCount' => count($this->channels->adminAll()),
            'recent'      => $recent['items'],
            'total'       => $recent['total'],
            'publishDir'  => $this->publishDir,
            'publishedAt' => $publishedAt,
            'logCount'    => (int) $this->db->scalar('SELECT COUNT(*) FROM sys_operation_log'),
        ], '后台首页');
    }
}
