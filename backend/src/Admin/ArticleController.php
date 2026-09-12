<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Http\Response;
use HechiZx\Content\ArticleWorkflow;
use HechiZx\Content\HtmlSanitizer;
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
    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];

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

        $navGroups = $this->channels->navGroups();

        $filters = [
            'channel' => (string) $request->query('channel', ''),
            'group'   => (string) $request->query('group', ''),
            'status'  => (string) $request->query('status', ''),
            'keyword' => (string) $request->query('keyword', ''),
            'sort'    => isset(self::SORTS[$sortField]) ? $sortField : 'published_at',
            'order'   => in_array($sortOrder, ['asc', 'desc'], true) ? $sortOrder : 'desc',
        ];
        // 栏目组只认导航里真实存在的一级栏目号；组与单栏目互斥，避免两个条件同时生效
        $groupKeys = array_map(static fn (array $group): string => (string) $group['key'], $navGroups);
        if ($filters['group'] === '' || !in_array($filters['group'], $groupKeys, true)) {
            $filters['group'] = '';
        } else {
            $filters['channel'] = '';
        }
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
            'navGroups' => $navGroups,
            'navCounts' => $this->articles->channelCounts($filters, $scope),
            'statusCounts' => $this->articles->statusCounts($filters, $scope),
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
            'pageHead' => $this->editorHead(),
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

        // 新建页的栏目条与稿件列表同一套：一级项点开先按整组展开。
        // 这里把「只选了组、没选具体子栏目」落到该组第一个子栏目，
        // 页面显示的当前栏目与提交值就不会打架（导航也会自动展开该组）。
        $defaultChannel = (string) $request->query('channel', '');
        $groupParam = (string) $request->query('group', '');
        if ($defaultChannel === '' && $groupParam !== '') {
            foreach ($navGroups as $group) {
                if ((string) $group['key'] === $groupParam) {
                    $first = $group['channels'][0]['type_code'] ?? '';
                    $defaultChannel = (string) $first;
                }
            }
        }
        if ($defaultChannel === '') {
            $defaultChannel = '904';
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
            'defaultChannel' => $defaultChannel,
            // 新建默认「已发布」：编辑写完点保存就是要发出去，草稿/下线仍可手选
            'defaultStatus'  => 'published',
            'pageHead'       => $this->editorHead(),
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
        $content = $this->normalizeContent((string) ($_POST['content_html'] ?? ''));
        $id = $this->articles->create([
            'channel_type' => $channelType,
            'title'        => $title,
            'subtitle'     => $request->post('subtitle'),
            // 摘要不在稿件里维护：只有推荐到首页轮换头条时，在「首页管理」里写（存在轮播表上）
            'summary'      => '',
            'content_html' => $content,
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

        // 新建页上传的图片／视频先落在 pending，这里迁到稿件目录并登记图集
        $adopted = $this->adoptPendingMedia($id, $content);
        if ($adopted !== $content) {
            $this->articles->adminUpdate((string) $id, ['content_html' => $adopted]);
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
        if ($denied = $this->requirePermission(Permissions::ARTICLE_EDIT)) {
            return $denied;
        }

        $id = (int) $args['id'];
        $article = $this->articles->adminFind((string) $id);
        if ($article === null) {
            Flash::set('error', '稿件不存在。');
            return new RedirectResponse('/admin/articles');
        }

        $channel = $this->actionChannel($request, $id, (string) $article['channel_type']);
        $back = $this->backUrl($request, '/admin/articles?channel=' . rawurlencode($channel));
        if (!$this->auth->canChannel($channel)) {
            Flash::set('error', '当前账号不在该稿件所属栏目的数据范围内。');
            return new RedirectResponse($back);
        }

        $direction = $request->post('dir') === 'up' ? 'up' : 'down';
        $result = $this->articles->moveInChannel($id, $channel, $direction);
        if (!$result['ok']) {
            Flash::set('error', $result['message']);
            return new RedirectResponse($back);
        }

        $this->log('article.order', 'article', (string) $id, ['channel' => $channel, 'direction' => $direction]);
        Flash::set('ok', $result['message'] . '稿件：' . (string) $article['title']);
        return new RedirectResponse($back);
    }

    /**
     * 按栏目置顶／取消置顶：首页模块页与稿件管理页都能直接点。
     *
     * @param array<string, string> $args
     */
    public function top(Request $request, array $args): HtmlResponse|RedirectResponse
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

        $id = (int) $args['id'];
        $article = $this->articles->adminFind((string) $id);
        if ($article === null) {
            Flash::set('error', '稿件不存在。');
            return new RedirectResponse('/admin/articles');
        }

        $channel = $this->actionChannel($request, $id, (string) $article['channel_type']);
        $back = $this->backUrl($request, '/admin/article/' . $id);
        if (!$this->auth->canChannel($channel)) {
            Flash::set('error', '当前账号不在该稿件所属栏目的数据范围内。');
            return new RedirectResponse($back);
        }
        if ((string) $article['status'] !== ArticleWorkflow::PUBLISHED) {
            Flash::set('error', '只有「已发布」的稿件能置顶，当前是：'
                . ArticleWorkflow::label((string) $article['status']) . '。');
            return new RedirectResponse($back);
        }

        $value = $request->post('value') === '1' ? 1 : 0;
        $this->articles->setChannelTop($id, $channel, $value);
        $this->log('article.top', 'article', (string) $id, ['channel' => $channel, 'top' => $value]);
        Flash::set('ok', ($value === 1 ? '已在栏目「' . $this->channelLabel($channel) . '」置顶：' : '已取消置顶：')
            . (string) $article['title']);
        return new RedirectResponse($back);
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
     * 编辑器插图（图片）。表单里带 article 时直接落到该稿件目录，否则落 pending（新建页）。
     *
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    public function uploadImageMedia(Request $request, array $args): array
    {
        return $this->storeMedia($request, 'image');
    }

    /**
     * 编辑器插入视频。返回与图片相同的契约形状。
     *
     * @param array<string, string> $args
     * @return array<string, mixed>
     */
    public function uploadVideoMedia(Request $request, array $args): array
    {
        return $this->storeMedia($request, 'video');
    }

    /**
     * 编辑器用的素材上传（JSON）。
     *
     * 与 302 表单接口 /admin/article/{id}/image 并存：那个把图追加到正文末尾，
     * 这里供编辑器在光标处插图，返回 SunEditor 约定的形状：
     * {"result":[{"url":"…","name":"…","size":123}]}
     *
     * @return array<string, mixed>
     */
    private function storeMedia(Request $request, string $kind): array
    {
        if ($this->requireLogin() !== null) {
            Response::error('unauthorized', '请先登录。', 401);
            exit;
        }
        $token = $request->post('_token');
        if (($token === null || $token === '') && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (!Csrf::check($token)) {
            Response::error('csrf_failed', '页面已过期，请刷新后重试。', 400);
            exit;
        }

        // 新建页还没有稿件号：article 传空即落 pending，保存时认领
        $articleId = (int) ($request->post('article') ?? 0);
        $article = $articleId > 0 ? $this->articles->adminFind((string) $articleId) : null;
        if ($articleId > 0 && $article === null) {
            Response::error('not_found', '稿件不存在。', 404);
            exit;
        }

        // SunEditor 的 FileManager 用 file-0、file-1… 作为字段名，这里也接受 file
        $file = $_FILES['file-0'] ?? $_FILES['file'] ?? null;
        if ($file === null) {
            foreach ($_FILES as $candidate) {
                $file = $candidate;
                break;
            }
        }
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Response::error('no_file', '没有选择文件。', 400);
            exit;
        }

        $label = $kind === 'video' ? '视频' : '图片';
        try {
            $stored = $this->storeUpload($file, $articleId, $kind === 'video' ? self::VIDEO_EXTENSIONS : self::IMAGE_EXTENSIONS);
        } catch (\RuntimeException $e) {
            Response::error('upload_failed', $label . '上传失败：' . $e->getMessage(), 400);
            exit;
        }

        if ($article !== null) {
            if ($kind === 'image') {
                $this->articles->addImage($articleId, $stored['url']);
            }
            $tag = $kind === 'video'
                ? '<video src="' . $stored['url'] . '" controls></video>'
                : '<img src="' . $stored['url'] . '" alt="">';
            $this->articles->syncBodyAssets($articleId, (string) $article['content_html'] . $tag);
            $this->log('media.upload', 'article', (string) $articleId, ['kind' => $kind, 'url' => $stored['url']]);
        }

        return [
            'result' => [[
                'url'  => $stored['url'],
                'name' => $stored['name'],
                'size' => (int) $stored['size'],
            ]],
        ];
    }

    /**
     * 认领新建页上传的素材：把 /uploads/pending/ 下的文件迁到稿件目录，
     * 并把正文里的图片登记进图集（视频只迁文件，不进图集）。
     *
     * 迁移失败（文件已不在、重命名失败）时保留原地址，不让保存失败。
     */
    private function adoptPendingMedia(int $articleId, string $contentHtml): string
    {
        if (!str_contains($contentHtml, '/uploads/pending/')) {
            return $contentHtml;
        }
        $dir = $this->articleUploadDir($articleId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
            return $contentHtml;
        }
        $uploads = rtrim($this->uploadsDir, '/');

        $contentHtml = (string) preg_replace_callback(
            '~/uploads/pending/([A-Za-z0-9._-]+)~',
            static function (array $m) use ($uploads, $dir, $articleId): string {
                $source = $uploads . '/pending/' . $m[1];
                if (!is_file($source)) {
                    return $m[0];
                }
                $target = $dir . '/' . $m[1];
                if (!is_file($target) && !@rename($source, $target)) {
                    return $m[0];
                }
                return '/uploads/' . $articleId . '/' . $m[1];
            },
            $contentHtml
        );

        preg_match_all('~/uploads/' . $articleId . '/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|gif|webp)~i', $contentHtml, $matches);
        $existing = $this->articles->images($articleId);
        foreach (array_unique($matches[0]) as $url) {
            if (!in_array($url, $existing, true)) {
                $this->articles->addImage($articleId, $url);
            }
        }

        return $contentHtml;
    }

    /**
     * 编辑页专用资源：编辑器只在编辑／新建页加载，列表页与其它页零影响。
     */
    private function editorHead(): string
    {
        return '<link rel="stylesheet" href="/assets/editor/suneditor.min.css">'
            . '<link rel="stylesheet" href="/assets/editor/suneditor-contents.min.css">'
            . '<link rel="stylesheet" href="/assets/editor/admin-editor.css">'
            . '<script src="/assets/editor/suneditor.min.js" defer></script>'
            . '<script src="/assets/editor/lang/zh_cn.js" defer></script>'
            . '<script src="/assets/editor/admin-editor.js" defer></script>';
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

        // 新建页还没有稿件号，素材先落在 pending 桶，保存时再由 adoptPendingMedia() 认领
        $bucket = $articleId > 0 ? (string) $articleId : 'pending';
        $dir = rtrim($this->uploadsDir, '/') . '/' . $bucket;
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
            'url'  => '/uploads/' . $bucket . '/' . $storedName,
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
            // 新建页上传过的素材如果还挂在 pending 桶，保存时一并认领
            'content_html' => $this->adoptPendingMedia(
                (int) $id,
                $this->normalizeContent((string) ($_POST['content_html'] ?? ''))
            ),
            'source'       => $request->post('source'),
            'author'       => $request->post('author'),
            'editor'       => $request->post('editor'),
            'updated_by'   => $userId,
        ];
        if ($publishedAt !== null) {
            $fields['published_at'] = $publishedAt;
        }
        // 编辑页已无置顶复选框
        // 表单没提交就不动它，置顶改在首页管理维护
        if ($request->post('is_top') !== null) {
            $fields['is_top'] = $request->post('is_top') === '1' ? 1 : 0;
        }

        $this->articles->adminUpdate($id, $fields);
        // 「置顶」按栏目生效：首页对应模块与该栏目列表共用这一套顺序
        // 只在勾选状态真的变了时才写，免得「编辑一条旧稿」顺手把它的栏目内顺序重置掉
        $channelType = (string) $article['channel_type'];
        $channelTopNow = $this->articles->channelTop((int) $id, $channelType);
        if (array_key_exists('is_top', $fields) && (int) $fields['is_top'] !== $channelTopNow) {
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
     *
     * 不判状态——同一批稿件状态可能不同，交给 applyFlow 逐篇判断并跳过。
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

    /**
     * 排序／置顶作用在哪个栏目：优先用表单传来的栏目号（首页模块页会带），
     * 但必须确实是这篇稿件挂着的栏目，否则回落到稿件的主栏目。
     */
    private function actionChannel(Request $request, int $articleId, string $primaryChannel): string
    {
        $requested = trim($request->post('channel'));
        if ($requested !== '' && $this->articles->isLinkedTo($articleId, $requested)) {
            return $requested;
        }
        return $primaryChannel;
    }

    /** 操作完成后回到哪：只接受站内后台地址，避免开放跳转 */
    private function backUrl(Request $request, string $default): string
    {
        $back = (string) $request->post('back');
        return str_starts_with($back, '/admin/') ? $back : $default;
    }

    private function channelLabel(string $type): string
    {
        $channel = $this->channels->adminFind($type);
        return $channel === null ? $type : (string) $channel['inner_name'];
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
     * 已经带 HTML 标签的正文过一遍白名单清洗（旧库正文是 <div>/<p> 结构，允许 div）；
     * 这是正文进入数据库的唯一入口，store／update／插图追加都走这里。
     */
    private function normalizeContent(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return $trimmed;
        }
        if (preg_match('/<[a-z][^>]*>/i', $trimmed) === 1) {
            return $this->restoreSiteUrls(HtmlSanitizer::clean($trimmed));
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

    /**
     * 把"本站绝对地址"还原成根相对路径。
     *
     * 富文本编辑器从 DOM 取值时，浏览器会把相对地址解析成绝对地址：正文里原本的
     * `images/channel/x.jpg` 会被写成 `http://<后台域名>/admin/images/channel/x.jpg`
     * （相对路径按后台编辑页的地址解析，所以还多出一段 `/admin`）。不还原的话，
     * 旧稿重存一次就把后台域名写进正文，前台静态页会破图。
     *
     * 只处理属于本站的地址（配置里的站点域名与当前请求域名），外站链接原样保留。
     */
    private function restoreSiteUrls(string $html): string
    {
        $hosts = [trim((string) hechi_config('site.domain', '')), trim((string) ($_SERVER['HTTP_HOST'] ?? ''))];
        $hosts = array_values(array_unique(array_filter($hosts, static fn(string $host): bool => $host !== '')));
        if ($hosts === []) {
            return $html;
        }

        $escaped = implode('|', array_map(static fn(string $host): string => preg_quote($host, '~'), $hosts));
        $pattern = '~\b(src|href)="https?://(?:' . $escaped . ')(/[^"]*)"~i';

        return (string) preg_replace_callback($pattern, static function (array $m): string {
            $path = (string) $m[2];
            // 相对地址是按后台编辑页解析的，会多出 /admin 这一段
            if (str_starts_with($path, '/admin/')) {
                $path = substr($path, strlen('/admin'));
            }
            return $m[1] . '="' . $path . '"';
        }, $html);
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
