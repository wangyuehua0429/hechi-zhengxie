<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Repository\ArticleRepository;
use HechiZx\Repository\HomeRepository;
use HechiZx\Support\Db;

/**
 * 头条轮换：首屏 6 张大图的条目维护。
 *
 * 两种来源都支持：
 *   * 引用已发布稿件（自动带标题、摘要、链接，图片缺省取稿件缩略图或正文首图）；
 *   * 手工外链条目（专题页、推广页这类不在稿件库里的）。
 * 只做上下线，不做定时生效。
 */
final class SlideController extends AdminController
{
    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private HomeRepository $home,
        private ArticleRepository $articles,
        string $uploadsDir
    ) {
        parent::__construct($auth, $view, $db, $siteId, $uploadsDir);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($notReady = $this->requireHomeTables($this->home)) {
            return $notReady;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        $keyword = trim((string) $request->query('q', ''));
        $found = [];
        if ($keyword !== '') {
            $found = $this->articles->adminPaginate(
                ['status' => 'published', 'keyword' => $keyword],
                1,
                10,
                null
            )['items'];
        }

        $slides = [];
        foreach ($this->home->slideRows() as $row) {
            $slides[] = $this->presentSlide($row);
        }

        return $this->view->page('admin/slides', [
            'current' => 'slides',
            'slides'  => $slides,
            'keyword' => $keyword,
            'found'   => $found,
        ], '头条轮换');
    }

    public function create(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($notReady = $this->requireHomeTables($this->home)) {
            return $notReady;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        $articleId = (int) $request->post('article_id');
        $title = trim($request->post('title'));
        $link = trim($request->post('link_url'));

        if ($articleId > 0) {
            $article = $this->articles->adminFind((string) $articleId);
            if ($article === null) {
                Flash::set('error', '稿件 #' . $articleId . ' 不存在。');
                return new RedirectResponse('/admin/slides');
            }
            if ((string) $article['status'] !== 'published') {
                Flash::set('error', '只有「已发布」的稿件能进头条轮换，稿件 #' . $articleId . ' 当前是'
                    . (string) $article['status'] . '。');
                return new RedirectResponse('/admin/slides');
            }
            $title = $title !== '' ? $title : (string) $article['title'];
        } elseif ($title === '' || $link === '') {
            Flash::set('error', '手工新增的条目要填标题和链接。');
            return new RedirectResponse('/admin/slides');
        }

        $image = trim($request->post('image_url'));
        $file = $_FILES['image'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $image = $this->storeHomeImage($file);
            } catch (\RuntimeException $e) {
                Flash::set('error', '图片上传失败：' . $e->getMessage());
                return new RedirectResponse('/admin/slides');
            }
        }

        $userId = (int) ($this->user()['user_id'] ?? 0);
        $now = $this->db->now();
        $this->db->execute(
            'INSERT INTO cms_home_slide
               (site_id, article_id, title, summary, image_url, link_url, sort_no, status, created_by, updated_by, created_at, updated_at)
             VALUES (:site, :aid, :title, :summary, :img, :link, :sort, :status, :uid, :uid, :t, :t)',
            [
                'site'    => $this->siteId,
                'aid'     => $articleId,
                'title'   => $title,
                'summary' => trim($request->post('summary')),
                'img'     => $image,
                'link'    => $link,
                'sort'    => $this->nextSortNo(),
                'status'  => 'published',
                'uid'     => $userId,
                't'       => $now,
            ]
        );
        $this->log('slide.create', 'home', '', ['title' => $title, 'article' => $articleId]);
        Flash::set('ok', '已加入头条轮换：' . $title);
        return new RedirectResponse('/admin/slides');
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($notReady = $this->requireHomeTables($this->home)) {
            return $notReady;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        $id = (int) $args['id'];
        $slide = $this->home->slideFind($id);
        if ($slide === null) {
            Flash::set('error', '轮播条目不存在。');
            return new RedirectResponse('/admin/slides');
        }

        $title = trim($request->post('title'));
        if ($title === '') {
            Flash::set('error', '标题不能为空。');
            return new RedirectResponse('/admin/slides');
        }

        $image = trim($request->post('image_url'));
        $file = $_FILES['image'] ?? null;
        if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $image = $this->storeHomeImage($file);
            } catch (\RuntimeException $e) {
                Flash::set('error', '图片上传失败：' . $e->getMessage());
                return new RedirectResponse('/admin/slides');
            }
        }

        $this->db->execute(
            'UPDATE cms_home_slide
             SET title = :title, summary = :summary, image_url = :img, link_url = :link, updated_by = :uid, updated_at = :t
             WHERE site_id = :site AND slide_id = :id',
            [
                'title'   => $title,
                'summary' => trim($request->post('summary')),
                'img'     => $image,
                'link'    => trim($request->post('link_url')),
                'uid'     => (int) ($this->user()['user_id'] ?? 0),
                't'       => $this->db->now(),
                'site'    => $this->siteId,
                'id'      => $id,
            ]
        );
        $this->log('slide.update', 'home', (string) $id, ['title' => $title]);
        Flash::set('ok', '已保存轮播条目：' . $title);
        return new RedirectResponse('/admin/slides');
    }

    /**
     * @param array<string, string> $args
     */
    public function move(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($notReady = $this->requireHomeTables($this->home)) {
            return $notReady;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        $id = (int) $args['id'];
        $rows = $this->home->slideRows();
        $index = null;
        foreach ($rows as $i => $row) {
            if ((int) $row['slide_id'] === $id) {
                $index = $i;
                break;
            }
        }
        $target = $index === null ? null : ($request->post('dir') === 'up' ? $index - 1 : $index + 1);
        if ($index === null || $target === null || !isset($rows[$target])) {
            Flash::set('error', $request->post('dir') === 'up' ? '已经在最前面了。' : '已经在最后面了。');
            return new RedirectResponse('/admin/slides');
        }

        $selfSort = (int) $rows[$index]['sort_no'];
        $neighborSort = (int) $rows[$target]['sort_no'];
        if ($selfSort === $neighborSort) {
            // 两条排序值相同，交换没有效果，把当前这条往前／后挪一格
            $this->setSort($id, $neighborSort + ($request->post('dir') === 'up' ? -1 : 1));
        } else {
            $this->setSort($id, $neighborSort);
            $this->setSort((int) $rows[$target]['slide_id'], $selfSort);
        }

        $this->log('slide.move', 'home', (string) $id, ['direction' => $request->post('dir')]);
        Flash::set('ok', '已调整轮播顺序。');
        return new RedirectResponse('/admin/slides');
    }

    /**
     * @param array<string, string> $args
     */
    public function toggle(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($notReady = $this->requireHomeTables($this->home)) {
            return $notReady;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        $id = (int) $args['id'];
        $slide = $this->home->slideFind($id);
        if ($slide === null) {
            Flash::set('error', '轮播条目不存在。');
            return new RedirectResponse('/admin/slides');
        }

        $status = (string) $slide['status'] === 'published' ? 'offline' : 'published';
        $this->db->execute(
            'UPDATE cms_home_slide SET status = :status, updated_at = :t WHERE site_id = :site AND slide_id = :id',
            ['status' => $status, 't' => $this->db->now(), 'site' => $this->siteId, 'id' => $id]
        );
        $this->log('slide.status', 'home', (string) $id, ['status' => $status]);
        Flash::set('ok', ($status === 'published' ? '已上线：' : '已下线：') . (string) $slide['title']);
        return new RedirectResponse('/admin/slides');
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($notReady = $this->requireHomeTables($this->home)) {
            return $notReady;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requirePermission(Permissions::HOME_MANAGE)) {
            return $denied;
        }

        $id = (int) $args['id'];
        $slide = $this->home->slideFind($id);
        if ($slide === null) {
            Flash::set('error', '轮播条目不存在。');
            return new RedirectResponse('/admin/slides');
        }

        $this->db->execute(
            'DELETE FROM cms_home_slide WHERE site_id = :site AND slide_id = :id',
            ['site' => $this->siteId, 'id' => $id]
        );
        $this->log('slide.delete', 'home', (string) $id, ['title' => (string) $slide['title']]);
        Flash::set('ok', '已删除轮播条目：' . (string) $slide['title']);
        return new RedirectResponse('/admin/slides');
    }

    /**
     * 列表展示用：把引用稿件的条目补上稿件标题/缩略图，方便编辑核对。
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentSlide(array $row): array
    {
        $articleId = (int) $row['article_id'];
        $article = $articleId > 0 ? $this->articles->adminFind((string) $articleId) : null;
        $row['article'] = $article;
        $row['article_state'] = $article === null
            ? ($articleId > 0 ? '稿件不存在' : '')
            : (string) $article['status'];
        return $row;
    }

    private function nextSortNo(): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_no), 0) + 1 FROM cms_home_slide WHERE site_id = :site',
            ['site' => $this->siteId]
        );
    }

    private function setSort(int $id, int $sortNo): void
    {
        $this->db->execute(
            'UPDATE cms_home_slide SET sort_no = :sort, updated_at = :t WHERE site_id = :site AND slide_id = :id',
            ['sort' => $sortNo, 't' => $this->db->now(), 'site' => $this->siteId, 'id' => $id]
        );
    }
}
