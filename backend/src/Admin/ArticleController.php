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
    private const MAX_UPLOAD_BYTES = 33554432;   // 32 MB，与 deploy/php/php.ini 的 upload_max_filesize 对齐
    private const FILE_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt'];
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private ArticleRepository $articles,
        private ChannelRepository $channels,
        private string $uploadsDir
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
            'navGroups' => $this->channels->navGroups(),
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
            'attachments' => $this->articles->attachments((int) $args['id']),
            'canDelete' => true,
            'saved'   => $request->query('saved') === '1',
        ], '编辑稿件 · ' . $article['title']);
    }

    public function createForm(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        return $this->view->page('admin/article_edit', [
            'current'     => 'articles',
            'article'     => null,
            'attachments' => [],
            'canDelete'   => false,
            'saved'       => false,
            'channels'    => $this->channels->adminAll(),
            'navGroups'   => $this->channels->navGroups(),
            'defaultChannel' => (string) $request->query('channel', '904'),
        ], '新建稿件');
    }

    public function store(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $title = $request->post('title');
        $channelType = $request->post('channel_type');
        if ($title === '' || $channelType === '') {
            Flash::set('error', '标题与所属栏目都不能为空。');
            return new RedirectResponse('/admin/article/new');
        }

        $status = $this->normalizeStatus($request->post('status'));
        $id = $this->articles->create([
            'channel_type' => $channelType,
            'title'        => $title,
            'subtitle'     => $request->post('subtitle'),
            'summary'      => $request->post('summary'),
            'content_html' => (string) ($_POST['content_html'] ?? ''),
            'source'       => $request->post('source'),
            'author'       => $request->post('author'),
            'editor'       => $request->post('editor'),
            'published_at' => $this->composeDatetime($request->post('published_date'), $request->post('published_time')),
            'status'       => $status,
            'is_top'       => $request->post('is_top') === '1' ? 1 : 0,
        ]);

        $this->log('article.create', 'article', (string) $id, ['title' => $title, 'status' => $status, 'channel' => $channelType]);
        Flash::set('ok', '已新建稿件 #' . $id . '（' . $this->statusLabel($status) . '），可继续编辑或上传附件。');
        return new RedirectResponse('/admin/article/' . $id);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteConfirm(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        $article = $this->articles->adminFind($args['id']);
        if ($article === null) {
            Flash::set('error', '稿件不存在。');
            return new RedirectResponse('/admin/articles');
        }
        return $this->view->page('admin/article_delete', [
            'current'     => 'articles',
            'article'     => $article,
            'attachments' => $this->articles->attachments((int) $args['id']),
        ], '删除稿件 · ' . $article['title']);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $id = (int) $args['id'];
        $article = $this->articles->adminFind((string) $id);
        if ($article === null) {
            Flash::set('error', '稿件不存在。');
            return new RedirectResponse('/admin/articles');
        }
        if ($request->post('confirm') !== 'delete') {
            Flash::set('error', '没有勾选确认，未执行删除。');
            return new RedirectResponse('/admin/article/' . $id . '/delete');
        }

        $this->articles->delete((string) $id);
        $this->removeUploadDir($id);
        $this->log('article.delete', 'article', (string) $id, ['title' => (string) $article['title']]);

        Flash::set('ok', '已删除稿件 #' . $id . '：' . $article['title']);
        return new RedirectResponse('/admin/articles');
    }

    /**
     * @param array<string, string> $args
     */
    public function uploadAttachment(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $id = (int) $args['id'];
        if ($this->articles->adminFind((string) $id) === null) {
            Flash::set('error', '稿件不存在。');
            return new RedirectResponse('/admin/articles');
        }

        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Flash::set('error', '没有选择文件。');
            return new RedirectResponse('/admin/article/' . $id);
        }

        try {
            $stored = $this->storeUpload($file, $id, self::FILE_EXTENSIONS);
        } catch (\RuntimeException $e) {
            Flash::set('error', '附件上传失败：' . $e->getMessage());
            return new RedirectResponse('/admin/article/' . $id);
        }

        $attachmentId = $this->articles->addAttachment($id, $stored);
        $this->log('attachment.create', 'article', (string) $id, ['name' => $stored['name'], 'ext' => $stored['ext']]);
        Flash::set('ok', '已上传附件：' . $stored['name']);
        return new RedirectResponse('/admin/article/' . $id);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteAttachment(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $id = (int) $args['id'];
        $attachmentId = (int) $args['aid'];
        $attachment = $this->articles->findAttachment($attachmentId, $id);
        if ($attachment === null) {
            Flash::set('error', '附件不存在。');
            return new RedirectResponse('/admin/article/' . $id);
        }

        $this->articles->deleteAttachment($attachmentId, $id);
        $this->removeUploadedFile((string) $attachment['url']);
        $this->log('attachment.delete', 'article', (string) $id, ['name' => (string) $attachment['name']]);
        Flash::set('ok', '已删除附件：' . $attachment['name']);
        return new RedirectResponse('/admin/article/' . $id);
    }

    /**
     * 正文插图：上传后追加到正文末尾，并登记到 cms_article_image（前端图集灯箱用）。
     *
     * @param array<string, string> $args
     */
    public function uploadImage(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $id = (int) $args['id'];
        $article = $this->articles->adminFind((string) $id);
        if ($article === null) {
            Flash::set('error', '稿件不存在。');
            return new RedirectResponse('/admin/articles');
        }

        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Flash::set('error', '没有选择图片。');
            return new RedirectResponse('/admin/article/' . $id);
        }

        try {
            $stored = $this->storeUpload($file, $id, self::IMAGE_EXTENSIONS);
        } catch (\RuntimeException $e) {
            Flash::set('error', '图片上传失败：' . $e->getMessage());
            return new RedirectResponse('/admin/article/' . $id);
        }

        $tag = '<p><img src="' . htmlspecialchars($stored['url'], ENT_QUOTES) . '" alt=""></p>';
        $this->articles->adminUpdate((string) $id, [
            'content_html' => (string) $article['content_html'] . "\n" . $tag,
        ]);
        $this->articles->addImage($id, $stored['url']);
        $this->articles->syncBodyAssets($id, (string) $article['content_html'] . $tag);
        $this->log('image.create', 'article', (string) $id, ['url' => $stored['url']]);

        Flash::set('ok', '图片已插入正文末尾：' . $stored['url']);
        return new RedirectResponse('/admin/article/' . $id);
    }

    /**
     * 落盘并返回附件信息；失败抛 RuntimeException（消息可直接给用户看）。
     *
     * @param array<string, mixed> $file
     * @param list<string> $allowedExtensions
     * @return array{name:string,url:string,ext:string,size:int,path:string}
     */
    private function storeUpload(array $file, int $articleId, array $allowedExtensions): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('上传中断（错误码 ' . $error . '）');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new \RuntimeException('文件为空');
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('文件超过 32 MB 上限');
        }

        $original = (string) ($file['name'] ?? 'file');
        $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            throw new \RuntimeException('不支持的文件类型：' . ($ext === '' ? '无扩展名' : $ext));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('临时文件不可用');
        }

        $dir = $this->articleUploadDir($articleId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            throw new \RuntimeException('无法创建上传目录');
        }

        $storedName = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $target = $dir . '/' . $storedName;
        if (!move_uploaded_file($tmp, $target)) {
            throw new \RuntimeException('保存文件失败');
        }
        @chmod($target, 0644);

        return [
            'name' => mb_substr(preg_replace('/[\x00-\x1F]/u', '', basename($original)) ?: $original, 0, 120),
            'url'  => '/uploads/' . $articleId . '/' . $storedName,
            'ext'  => $ext,
            'size' => $size,
            'path' => $target,
        ];
    }

    private function articleUploadDir(int $articleId): string
    {
        return rtrim($this->uploadsDir, '/') . '/' . $articleId;
    }

    /** 只允许删上传目录里的文件，避免越权删库外文件 */
    private function removeUploadedFile(string $url): void
    {
        $prefix = '/uploads/';
        if (!str_starts_with($url, $prefix)) {
            return;
        }
        $relative = substr($url, strlen($prefix));
        if (str_contains($relative, '..')) {
            return;
        }
        $path = rtrim($this->uploadsDir, '/') . '/' . $relative;
        $root = rtrim($this->uploadsDir, '/') . '/';
        if (str_starts_with($path, $root) && is_file($path)) {
            unlink($path);
        }
    }

    private function removeUploadDir(int $articleId): void
    {
        $dir = $this->articleUploadDir($articleId);
        $root = rtrim($this->uploadsDir, '/') . '/';
        if (!str_starts_with($dir, $root) || !is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($dir);
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
        $status = $this->normalizeStatus($status);

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
        $this->articles->syncBodyAssets((int) $id, $fields['content_html']);
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

    private function normalizeStatus(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'draft';
    }
}
