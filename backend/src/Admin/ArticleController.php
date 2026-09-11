<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Repository\ArticleRepository;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Support\Db;

/**
 * 稿件管理：列表（筛选 + 分页）、编辑、保存、三态切换。
 */
final class ArticleController extends AdminController
{
    private const STATUSES = ['draft', 'published', 'offline'];
    private const PAGE_SIZE = 20;

    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private ArticleRepository $articles,
        private ChannelRepository $channels
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }

        $filters = [
            'channel' => (string) $request->query('channel', ''),
            'status'  => (string) $request->query('status', ''),
            'keyword' => (string) $request->query('keyword', ''),
        ];
        $page = $request->int('page', 1, 1);
        $result = $this->articles->adminPaginate($filters, $page, self::PAGE_SIZE);

        return $this->view->page('admin/articles', [
            'current'  => 'articles',
            'filters'  => $filters,
            'items'    => $result['items'],
            'total'    => $result['total'],
            'page'     => $page,
            'pages'    => max(1, (int) ceil($result['total'] / self::PAGE_SIZE)),
            'channels' => $this->channels->adminAll(),
        ], '稿件管理');
    }

    /**
     * @param array<string, string> $args
     */
    public function edit(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        $article = $this->articles->adminFind($args['id']);
        if ($article === null) {
            return $this->view->page('admin/message', [
                'current' => 'articles',
                'heading' => '未找到稿件',
                'message' => '稿件 ' . $args['id'] . ' 不存在，可能已被删除。',
                'backUrl' => '/admin/articles',
            ], '未找到稿件');
        }

        return $this->view->page('admin/article_edit', [
            'current' => 'articles',
            'article' => $article,
            'saved'   => $request->query('saved') === '1',
        ], '编辑稿件 · ' . $article['title']);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $id = $args['id'];
        $article = $this->articles->adminFind($id);
        if ($article === null) {
            return $this->view->page('admin/message', [
                'current' => 'articles',
                'heading' => '未找到稿件',
                'message' => '稿件 ' . $id . ' 不存在。',
                'backUrl' => '/admin/articles',
            ], '未找到稿件');
        }

        $title = $request->post('title');
        if ($title === '') {
            Flash::set('error', '标题不能为空。');
            return new RedirectResponse('/admin/article/' . $id);
        }

        $status = $request->post('status');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'draft';
        }

        $publishedAt = $this->composeDatetime($request->post('published_date'), $request->post('published_time'));

        $fields = [
            'title'        => $title,
            'subtitle'     => $request->post('subtitle'),
            'summary'      => $request->post('summary'),
            'content_html' => (string) ($_POST['content_html'] ?? ''),
            'source'       => $request->post('source'),
            'author'       => $request->post('author'),
            'editor'       => $request->post('editor'),
            'status'       => $status,
            'is_top'       => $request->post('is_top') === '1' ? 1 : 0,
        ];
        if ($publishedAt !== null) {
            $fields['published_at'] = $publishedAt;
        }

        $this->articles->adminUpdate($id, $fields);
        $this->log('article.update', 'article', $id, [
            'title'  => $title,
            'status' => $status,
        ]);

        Flash::set('ok', '已保存：' . $title . '（' . $this->statusLabel($status) . '）');
        return new RedirectResponse('/admin/article/' . $id . '?saved=1');
    }

    private function composeDatetime(string $date, string $time): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        $time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time) ? $time : '00:00';
        if (strlen($time) === 5) {
            $time .= ':00';
        }
        return $date . ' ' . $time;
    }

    private function statusLabel(string $status): string
    {
        return ['draft' => '草稿', 'published' => '已发布', 'offline' => '已下线'][$status] ?? $status;
    }
}
