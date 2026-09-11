<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Content\ArticleWorkflow;
use HechiZx\Content\Permissions;
use HechiZx\Repository\ArticleRepository;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Support\Db;

/**
 * 稿件管理：列表（稿库 + 栏目 + 关键词）、编辑、保存、稿库流转。
 */
final class ArticleController extends AdminController
{
    private const PAGE_SIZE = 20;
    private const PAGE_SIZES = [20, 50, 100];
    /** 列表可排序的字段：值会进 SQL 的 ORDER BY，白名单之外一律回落到发布时间 */
    private const SORTS = [
        'published_at' => '发布时间',
        'updated_at'   => '最近更新',
        'article_id'   => '稿件号',
    ];
    /** 允许批量执行的状态流转：移入回收站保留单篇确认页，不进批量 */
    private const BULK_ACTIONS = ['submit', 'approve', 'reject', 'withdraw', 'republish', 'restore'];
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

        // 排序在界面上是一个下拉，取值形如 article_id:asc；也兼容 sort/order 分开传
        $sortParam = (string) $request->query('sort', 'published_at:desc');
        $orderParam = (string) $request->query('order', '');
        if (str_contains($sortParam, ':')) {
            [$sortField, $sortOrder] = explode(':', $sortParam, 2);
        } else {
            $sortField = $sortParam;
            $sortOrder = $orderParam;
        }

        $filters = [
            'channel' => (string) $request->query('channel', ''),
            'status'  => (string) $request->query('status', ''),
            'keyword' => (string) $request->query('keyword', ''),
            'sort'    => isset(self::SORTS[$sortField]) ? $sortField : 'published_at',
            'order'   => in_array($sortOrder, ['asc', 'desc'], true) ? $sortOrder : 'desc',
        ];
        $pageSize = $request->int('size', self::PAGE_SIZE, 1);
        if (!in_array($pageSize, self::PAGE_SIZES, true)) {
            $pageSize = self::PAGE_SIZE;
        }

        $scope = $this->auth->channelScope();
        $page = $request->int('page', 1, 1);
        $result = $this->articles->adminPaginate($filters, $page, $pageSize, $scope);
        $items = $result['items'];
        foreach ($items as $index => $item) {
            $items[$index]['flow_actions'] = $this->allowedActions((string) $item['status']);
            $items[$index]['flow_state'] = ArticleWorkflow::label((string) $item['status']);
            // 行内动作一律走已授权的流转表，模板不自己判断权限
            $items[$index]['flow_rules'] = array_filter(
                ArticleWorkflow::transitions(),
                static fn (string $key): bool => in_array($key, $items[$index]['flow_actions'], true),
                ARRAY_FILTER_USE_KEY
            );
        }

        return $this->view->page('admin/articles', [
            'current'  => 'articles',
            'filters'  => $filters,
            'items'    => $items,
            'total'    => $result['total'],
            'page'     => $page,
            'pages'    => max(1, (int) ceil($result['total'] / $pageSize)),
            'pageSize' => $pageSize,
            'pageSizes' => self::PAGE_SIZES,
            'sorts'    => self::SORTS,
            'bulkActions' => $this->bulkActions(),
            'channels' => $this->channels->adminAll(),
            'navGroups' => $this->channels->navGroups(),
            'statusCounts' => $this->articles->statusCounts($filters['channel'] !== '' ? $filters['channel'] : null, $scope),
            'places'   => ArticleWorkflow::places(),
            'transitions' => ArticleWorkflow::transitions(),
        ], '稿件管理');
    }

    /**
     * 批量流转：只做「一批稿件同一个动作」，逐篇复用 applyFlow，
     * 因此状态机判断、数据范围与权限位和单篇完全一致，逐篇写操作日志。
     */
    public function bulk(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $back = (string) $request->post('back');
        if (!str_starts_with($back, '/admin/articles')) {
            $back = '/admin/articles';
        }

        $action = $request->post('action');
        $rule = in_array($action, self::BULK_ACTIONS, true) ? ArticleWorkflow::transition($action) : null;
        if ($rule === null) {
            Flash::set('error', '请先选择要执行的批量操作。');
            return new RedirectResponse($back);
        }

        $allowed = $this->can((string) $rule['perm']);
        if (!$allowed && (string) $rule['altPerm'] !== '') {
            $allowed = $this->can((string) $rule['altPerm']);
        }
        if (!$allowed) {
            Flash::set('error', '当前账号没有「' . $rule['label'] . '」权限（需要 ' . $rule['perm'] . '）。');
            return new RedirectResponse($back);
        }

        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            Flash::set('error', '没有选中任何稿件。');
            return new RedirectResponse($back);
        }
        if (count($ids) > 100) {
            Flash::set('error', '一次最多处理 100 篇，请缩小选择范围。');
            return new RedirectResponse($back);
        }

        $note = trim($request->post('note'));
        $userId = (int) ($this->user()['user_id'] ?? 0);
        $done = 0;
        $skipped = [];
        foreach (array_values($ids) as $rawId) {
            $article = $this->articles->adminFind((string) $rawId);
            if ($article === null) {
                $skipped[] = '#' . (int) $rawId . '（不存在）';
                continue;
            }
            $result = $this->applyFlow($article, $action, $note, $userId);
            if ($result['ok']) {
                $done++;
                continue;
            }
            $skipped[] = '#' . (int) $rawId . '（' . $result['message'] . '）';
        }

        if ($done === 0) {
            Flash::set('error', '批量' . $rule['label'] . '没有执行：' . implode('；', array_slice($skipped, 0, 3)));
            return new RedirectResponse($back);
        }

        $message = '批量' . $rule['label'] . '：成功 ' . $done . ' 篇';
        if ($skipped !== []) {
            $message .= '；跳过 ' . count($skipped) . ' 篇——' . implode('；', array_slice($skipped, 0, 3))
                . (count($skipped) > 3 ? ' 等' : '');
        }
        Flash::set('ok', $message . '。');
        return new RedirectResponse($back);
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
            'canEdit' => $this->can(Permissions::ARTICLE_EDIT) && $this->auth->canChannel((string) $article['channel_type']),
            'channelTop' => $this->articles->channelTop((int) $args['id'], (string) $article['channel_type']),
            'actions' => $this->allowedActions((string) $article['status']),
            'transitions' => ArticleWorkflow::transitions(),
            'saved'   => $request->query('saved') === '1',
        ], '编辑稿件 · ' . $article['title']);
    }

    public function createForm(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::ARTICLE_EDIT)) {
            return $denied;
        }
        $scope = $this->auth->channelScope();
        $navGroups = $this->channels->navGroups();
        if ($scope !== null) {
            $navGroups = $this->filterNavGroups($navGroups, $scope);
        }
        return $this->view->page('admin/article_edit', [
            'current'     => 'articles',
            'article'     => null,
            'attachments' => [],
            'canDelete'   => false,
            'canEdit'     => true,
            'actions'     => [],
            'transitions' => ArticleWorkflow::transitions(),
            'saved'       => false,
            'channels'    => $this->channels->adminAll(),
            'navGroups'   => $navGroups,
            'defaultChannel' => (string) $request->query('channel', '904'),
            // 新建默认「已发布」：编辑写完点保存就是要发出去，草稿/下线仍可手选
            'defaultStatus'  => 'published',
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
        if ($denied = $this->requirePermission(Permissions::ARTICLE_EDIT)) {
            return $denied;
        }

        $title = $request->post('title');
        $channelType = $request->post('channel_type');
        if ($title === '' || $channelType === '') {
            Flash::set('error', '标题与所属栏目都不能为空。');
            return new RedirectResponse('/admin/article/new');
        }
        if (!$this->auth->canChannel($channelType)) {
            Flash::set('error', '当前账号没有这个栏目的操作权限（栏目号 ' . $channelType . '）。');
            return new RedirectResponse('/admin/article/new');
        }

        $status = $request->post('status') === ArticleWorkflow::DRAFT ? ArticleWorkflow::DRAFT : ArticleWorkflow::PUBLISHED;
        $downgraded = false;
        if ($status === ArticleWorkflow::PUBLISHED && !$this->can(Permissions::ARTICLE_PUBLISH)) {
            $status = ArticleWorkflow::DRAFT;
            $downgraded = true;
        }
        $userId = (int) ($this->user()['user_id'] ?? 0);
        $isTop = $request->post('is_top') === '1' ? 1 : 0;
        $id = $this->articles->create([
            'channel_type' => $channelType,
            'title'        => $title,
            'subtitle'     => $request->post('subtitle'),
            'summary'      => $request->post('summary'),
            'content_html' => $this->normalizeContent((string) ($_POST['content_html'] ?? '')),
            'source'       => $request->post('source'),
            'author'       => $request->post('author'),
            'editor'       => $request->post('editor'),
            'published_at' => $this->composeDatetime($request->post('published_date'), $request->post('published_time')),
            'status'       => $status,
            'is_top'       => $isTop,
            'created_by'   => $userId,
        ]);
        if ($isTop === 1) {
            $this->articles->setChannelTop($id, $channelType, 1);
        }

        $this->log('article.create', 'article', (string) $id, ['title' => $title, 'status' => $status, 'channel' => $channelType]);
        Flash::set('ok', '已新建稿件 #' . $id . '（' . $this->statusLabel($status) . '）'
            . ($downgraded ? '；当前账号没有发布权限，已存为草稿。' : '，可继续编辑或上传附件。'));
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
        if ($denied = $this->requirePermission(Permissions::ARTICLE_DELETE)) {
            return $denied;
        }
        $article = $this->articles->adminFind($args['id']);
        if ($article === null) {
            Flash::set('error', '稿件不存在。');
            return new RedirectResponse('/admin/articles');
        }
        $current = ArticleWorkflow::normalize((string) $article['status']);
        return $this->view->page('admin/article_delete', [
            'current'     => 'articles',
            'article'     => $article,
            'attachments' => $this->articles->attachments((int) $args['id']),
            'blockedReason' => $current === ArticleWorkflow::PUBLISHED
                ? '这篇稿件还在「已发布」，请先撤回再移入回收站。'
                : '',
        ], '移入回收站 · ' . $article['title']);
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
        if ($denied = $this->requirePermission(Permissions::ARTICLE_DELETE)) {
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

        $userId = (int) ($this->user()['user_id'] ?? 0);
        $result = $this->applyFlow($article, 'delete', '', $userId);
        if (!$result['ok']) {
            Flash::set('error', $result['message']);
            return new RedirectResponse('/admin/article/' . $id . '/delete');
        }

        Flash::set('ok', $result['message'] . '，可在回收站恢复。');
        return new RedirectResponse('/admin/articles?status=deleted');
    }

    /**
     * 稿库流转唯一入口：提交／通过／退回／撤回／重新发布／移入回收站／恢复。
     *
     * @param array<string, string> $args
     */
    public function flow(Request $request, array $args): HtmlResponse|RedirectResponse
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

        $action = $request->post('action');
        $userId = (int) ($this->user()['user_id'] ?? 0);
        $result = $this->applyFlow($article, $action, $request->post('note'), $userId);
        if (!$result['ok']) {
            Flash::set('error', $result['message']);
            return new RedirectResponse('/admin/article/' . $id);
        }

        Flash::set('ok', $result['message']);
        if ($action === 'delete') {
            return new RedirectResponse('/admin/articles?status=deleted');
        }
        return new RedirectResponse('/admin/article/' . $id . '?saved=1');
    }

    /**
     * 状态流转的统一实现：状态机判可达 → 数据范围判栏目 → 权限位判动作 → 写字段与日志。
     *
     * @param array<string, mixed> $article
     * @return array{ok: bool, message: string}
     */
    private function applyFlow(array $article, string $action, string $note, int $userId): array
    {
        $rule = ArticleWorkflow::transition($action);
        if ($rule === null) {
            return ['ok' => false, 'message' => '未知的操作：' . $action];
        }

        $current = ArticleWorkflow::normalize((string) $article['status']);
        if (!in_array($current, $rule['from'], true)) {
            if ($action === 'delete' && $current === ArticleWorkflow::PUBLISHED) {
                return ['ok' => false, 'message' => '已发布的稿件要先撤回，再移入回收站。'];
            }
            return ['ok' => false, 'message' => '当前状态（' . ArticleWorkflow::label($current) . '）不能执行「' . $rule['label'] . '」。'];
        }
        if (!$this->auth->canChannel((string) $article['channel_type'])) {
            return ['ok' => false, 'message' => '当前账号不在该稿件所属栏目的数据范围内。'];
        }

        $allowed = $this->can((string) $rule['perm']);
        if (!$allowed && $rule['altPerm'] !== '') {
            $allowed = $this->can((string) $rule['altPerm']);
        }
        if (!$allowed) {
            return ['ok' => false, 'message' => '当前账号没有「' . $rule['label'] . '」权限（需要 ' . $rule['perm'] . '）。'];
        }

        $note = trim($note);
        if ($rule['needNote'] && $note === '') {
            return ['ok' => false, 'message' => '请填写' . $rule['noteLabel'] . '。'];
        }
        if (in_array($action, ['submit', 'approve'], true)) {
            if (trim((string) $article['title']) === '') {
                return ['ok' => false, 'message' => '标题为空，不能提交或发布。'];
            }
            if (trim((string) ($article['content_html'] ?? '')) === '') {
                return ['ok' => false, 'message' => '正文为空，请先补正文再提交或发布。'];
            }
        }

        $target = ArticleWorkflow::target($action, (string) $article['status'], (string) ($article['status_before_delete'] ?? ''));
        if ($target === null) {
            return ['ok' => false, 'message' => '状态流转失败，请刷新页面后重试。'];
        }

        $now = $this->db->now();
        $fields = ['status' => $target, 'updated_by' => $userId];
        switch ($action) {
            case 'submit':
                $fields['submitted_at'] = $now;
                break;
            case 'approve':
                $fields['reviewer_id'] = $userId;
                $fields['reviewed_at'] = $now;
                $fields['review_note'] = '';
                if (trim((string) ($article['published_at'] ?? '')) === '') {
                    $fields['published_at'] = $now;
                }
                break;
            case 'reject':
                $fields['reviewer_id'] = $userId;
                $fields['reviewed_at'] = $now;
                $fields['review_note'] = $note;
                break;
            case 'withdraw':
                $fields['withdrawn_at'] = $now;
                $fields['withdraw_reason'] = $note;
                break;
            case 'republish':
                $fields['withdrawn_at'] = null;
                $fields['withdraw_reason'] = '';
                if (trim((string) ($article['published_at'] ?? '')) === '') {
                    $fields['published_at'] = $now;
                }
                break;
            case 'delete':
                $fields['deleted_at'] = $now;
                $fields['deleted_by'] = $userId;
                $fields['status_before_delete'] = $current;
                break;
            case 'restore':
                $fields['deleted_at'] = null;
                $fields['deleted_by'] = 0;
                $fields['status_before_delete'] = '';
                break;
        }

        $this->articles->adminUpdate((string) (int) $article['article_id'], $fields);
        $this->log('article.' . $action, 'article', (string) (int) $article['article_id'], [
            'title' => (string) $article['title'],
            'from'  => $current,
            'to'    => $target,
            'note'  => $note,
        ]);

        return [
            'ok' => true,
            'message' => '已' . $rule['label'] . '：' . (string) $article['title']
                . '（' . ArticleWorkflow::label($target) . '）',
        ];
    }

    /**
     * 栏目内上移／下移：列表页在按单个栏目筛选时按行提供。
     *
     * @param array<string, string> $args
     */
    public function order(Request $request, array $args): HtmlResponse|RedirectResponse
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

        $channel = (string) $article['channel_type'];
        if (!$this->auth->canChannel($channel)) {
            Flash::set('error', '当前账号不在该稿件所属栏目的数据范围内。');
            return new RedirectResponse('/admin/articles');
        }

        $direction = $request->post('dir') === 'up' ? 'up' : 'down';
        $result = $this->articles->moveInChannel($id, $channel, $direction);
        if (!$result['ok']) {
            Flash::set('error', $result['message']);
            return new RedirectResponse('/admin/articles?channel=' . rawurlencode($channel));
        }

        $this->log('article.order', 'article', (string) $id, ['channel' => $channel, 'direction' => $direction]);
        Flash::set('ok', $result['message'] . '稿件：' . (string) $article['title']);
        return new RedirectResponse('/admin/articles?channel=' . rawurlencode($channel));
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
        if ($denied = $this->requirePermission(Permissions::ARTICLE_EDIT)) {
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
        if (!$this->auth->canChannel((string) $article['channel_type'])) {
            return $this->view->page('admin/message', [
                'current' => 'articles',
                'heading' => '没有这个栏目的权限',
                'message' => '稿件 #' . $id . ' 属于栏目 ' . $article['channel_type'] . '，当前账号不在该栏目的数据范围内。',
                'backUrl' => '/admin/articles',
            ], '没有这个栏目的权限', 403);
        }
        if (in_array(ArticleWorkflow::normalize((string) $article['status']), [ArticleWorkflow::DELETED], true)) {
            Flash::set('error', '回收站里的稿件要先恢复才能编辑。');
            return new RedirectResponse('/admin/article/' . $id);
        }

        $title = $request->post('title');
        if ($title === '') {
            Flash::set('error', '标题不能为空。');
            return new RedirectResponse('/admin/article/' . $id);
        }

        $publishedAt = $this->composeDatetime($request->post('published_date'), $request->post('published_time'));
        $userId = (int) ($this->user()['user_id'] ?? 0);

        $fields = [
            'title'        => $title,
            'subtitle'     => $request->post('subtitle'),
            'summary'      => $request->post('summary'),
            'content_html' => $this->normalizeContent((string) ($_POST['content_html'] ?? '')),
            'source'       => $request->post('source'),
            'author'       => $request->post('author'),
            'editor'       => $request->post('editor'),
            'is_top'       => $request->post('is_top') === '1' ? 1 : 0,
            'updated_by'   => $userId,
        ];
        if ($publishedAt !== null) {
            $fields['published_at'] = $publishedAt;
        }

        $this->articles->adminUpdate($id, $fields);
        // 「置顶」按栏目生效：首页对应模块与该栏目列表共用这一套顺序
        // 只在勾选状态真的变了时才写，免得「编辑一条旧稿」顺手把它的栏目内顺序重置掉
        $channelType = (string) $article['channel_type'];
        if ((int) $fields['is_top'] !== $this->articles->channelTop((int) $id, $channelType)) {
            $this->articles->setChannelTop((int) $id, $channelType, (int) $fields['is_top']);
        }
        $this->articles->syncBodyAssets((int) $id, $fields['content_html']);
        $this->log('article.update', 'article', $id, [
            'title'  => $title,
            'status' => ArticleWorkflow::normalize((string) $article['status']),
        ]);

        Flash::set('ok', '已保存：' . $title . '（' . ArticleWorkflow::label((string) $article['status']) . '）');
        return new RedirectResponse('/admin/article/' . $id . '?saved=1');
    }

    /**
     * 列表页批量操作条里能出现的动作：白名单 ∩ 权限位。
     * 不判状态——同一批稿件状态可能不同，交给 applyFlow 逐篇判断并跳过。
     *
     * @return array<string, array{label:string, danger:bool, needNote:bool, noteLabel:string}>
     */
    private function bulkActions(): array
    {
        $out = [];
        foreach (self::BULK_ACTIONS as $action) {
            $rule = ArticleWorkflow::transition($action);
            if ($rule === null) {
                continue;
            }
            $allowed = $this->can((string) $rule['perm']);
            if (!$allowed && (string) $rule['altPerm'] !== '') {
                $allowed = $this->can((string) $rule['altPerm']);
            }
            if (!$allowed) {
                continue;
            }
            $out[$action] = [
                'label'     => (string) $rule['label'],
                'danger'    => (bool) $rule['danger'],
                'needNote'  => (bool) $rule['needNote'],
                'noteLabel' => (string) $rule['noteLabel'],
            ];
        }
        return $out;
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

    /**
     * 正文规范化：编辑直接敲纯文本时自动分段，避免详情页出来一整坨没有段落间距的文字。
     * 已经带 HTML 标签的正文原样保留（旧库正文是 <div>/<p> 结构）。
     */
    private function normalizeContent(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || preg_match('/<[a-z][^>]*>/i', $trimmed) === 1) {
            return $trimmed;
        }

        $paragraphs = preg_split('/\n\s*\n/', $trimmed) ?: [];
        $html = [];
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }
            $html[] = '<p>' . str_replace("\n", '<br>', htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8')) . '</p>';
        }
        return implode("\n", $html);
    }

    private function statusLabel(string $status): string
    {
        return ArticleWorkflow::label($status);
    }

    /**
     * 按数据范围裁剪栏目导航条，避免编辑看到自己管不了的栏目。
     *
     * @param list<array{key:string,title:string,channels:list<array<string,mixed>>}> $groups
     * @param list<string> $scope
     * @return list<array{key:string,title:string,channels:list<array<string,mixed>>}>
     */
    private function filterNavGroups(array $groups, array $scope): array
    {
        $out = [];
        foreach ($groups as $group) {
            $channels = array_values(array_filter(
                $group['channels'],
                static fn (array $channel): bool => in_array((string) $channel['type_code'], $scope, true)
            ));
            if ($channels !== []) {
                $group['channels'] = $channels;
                $out[] = $group;
            }
        }
        return $out;
    }
}
