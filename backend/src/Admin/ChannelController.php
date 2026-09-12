<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Content\Permissions;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Support\Db;

/**
 * 栏目管理：列表 + 基本信息编辑（名称、版式、排序、上下线）。
 */
final class ChannelController extends AdminController
{
    private const LAYOUTS = ['list', 'leaders', 'about', 'county', 'gallery', 'video', 'topic', 'interactive'];
    private const STATUSES = ['published', 'offline'];

    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private ChannelRepository $channels
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::CHANNEL_MANAGE)) {
            return $denied;
        }

        $keyword = trim((string) $request->query('q', ''));
        $all = $this->channels->adminAll();
        $groups = $this->groupChannels($all, $keyword);

        // 顶部统计按全量算：搜索只影响下面列出的分组，不改总数
        $published = 0;
        $articleCount = 0;
        foreach ($all as $channel) {
            if ((string) $channel['status'] === 'published') {
                $published++;
            }
            $articleCount += (int) $channel['article_count'];
        }

        return $this->view->page('admin/channels', [
            'current'      => 'channels',
            'channels'     => $all,
            'groups'       => $groups,
            'keyword'      => $keyword,
            'total'        => count($all),
            'published'    => $published,
            'offline'      => count($all) - $published,
            'articleCount' => $articleCount,
        ], '栏目管理');
    }

    /**
     * 组内上移／下移：只交换相邻两个栏目的排序值，顺序变动可预期，
     * 比让编辑去改「越小越靠前」的数字更直接。
     */
    public function move(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requirePermission(Permissions::CHANNEL_MANAGE)) {
            return $denied;
        }

        $type = (string) $args['type'];
        $channel = $this->channels->adminFind($type);
        if ($channel === null) {
            Flash::set('error', '栏目不存在。');
            return new RedirectResponse('/admin/channels');
        }

        $direction = $request->post('dir') === 'up' ? 'up' : 'down';
        $result = $this->channels->moveWithinGroup($channel, $direction);
        if (!$result['ok']) {
            Flash::set('error', $result['message']);
        } else {
            $this->log('channel.move', 'channel', $type, [
                'name'      => (string) $channel['inner_name'],
                'direction' => $direction,
                'neighbor'  => $result['neighbor'],
            ]);
            Flash::set('ok', $result['message']);
        }

        $query = trim((string) $request->post('q'));
        return new RedirectResponse('/admin/channels' . ($query === '' ? '' : '?q=' . rawurlencode($query)));
    }

    /**
     * 按一级栏目分组，并给每个栏目标出「组内第几个／能不能再上下移」。
     *
     * @param list<array<string, mixed>> $channels
     * @return list<array{key:string, title:string, channels:list<array<string,mixed>>, article_count:int, published:int, offline:int}>
     */
    private function groupChannels(array $channels, string $keyword = ''): array
    {
        $groups = [];
        $index = [];
        foreach ($channels as $channel) {
            $key = (string) ($channel['parent_type'] ?? '') !== ''
                ? (string) $channel['parent_type']
                : (string) $channel['type_code'];
            if (!isset($index[$key])) {
                $index[$key] = count($groups);
                $groups[] = [
                    'key' => $key, 'title' => '', 'channels' => [],
                    'article_count' => 0, 'published' => 0, 'offline' => 0,
                ];
            }
            $at = $index[$key];
            $groups[$at]['channels'][] = $channel;
            $groups[$at]['article_count'] += (int) $channel['article_count'];
            $groups[$at]['published'] += (string) $channel['status'] === 'published' ? 1 : 0;
            $groups[$at]['offline'] += (string) $channel['status'] === 'published' ? 0 : 1;
            if ((string) $channel['type_code'] === $key) {
                $groups[$at]['title'] = (string) $channel['name'];
            }
        }

        $out = [];
        foreach ($groups as $group) {
            if ($group['title'] === '') {
                $group['title'] = (string) $group['channels'][0]['name'];
            }
            $children = [];
            $count = count($group['channels']);
            foreach ($group['channels'] as $i => $channel) {
                if ($keyword !== '' && !$this->matchKeyword($channel, $keyword)) {
                    continue;
                }
                $channel['can_move_up'] = $i > 0;
                $channel['can_move_down'] = $i < $count - 1;
                $children[] = $channel;
            }
            if ($children === []) {
                continue;
            }
            $group['channels'] = $children;
            $out[] = $group;
        }
        return $out;
    }

    /**
     * 栏目检索：栏目号、一级名、子栏目名任一命中即可（43 个栏目的规模，内存过滤够用）。
     *
     * @param array<string, mixed> $channel
     */
    private function matchKeyword(array $channel, string $keyword): bool
    {
        $haystack = (string) $channel['type_code'] . ' '
            . (string) $channel['name'] . ' '
            . (string) $channel['inner_name'] . ' '
            . (string) $channel['slug'];
        return mb_stripos($haystack, $keyword) !== false;
    }

    /**
     * @param array<string, string> $args
     */
    public function edit(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::CHANNEL_MANAGE)) {
            return $denied;
        }
        $channel = $this->channels->adminFind($args['type']);
        if ($channel === null) {
            return $this->view->page('admin/message', [
                'current' => 'channels',
                'heading' => '未找到栏目',
                'message' => '栏目 ' . $args['type'] . ' 不存在。',
                'backUrl' => '/admin/channels',
            ], '未找到栏目');
        }

        // 排序位置与左右邻居：编辑改完顺序后能立刻知道自己在整组里的位置
        $siblings = $this->channels->adminSiblings($channel);
        $position = 1;
        $prev = null;
        $next = null;
        foreach ($siblings as $i => $row) {
            if ((string) $row['type_code'] !== (string) $channel['type_code']) {
                continue;
            }
            $position = $i + 1;
            $prev = $siblings[$i - 1] ?? null;
            $next = $siblings[$i + 1] ?? null;
            break;
        }

        return $this->view->page('admin/channel_edit', [
            'current'      => 'channels',
            'channel'      => $channel,
            'layouts'      => self::LAYOUTS,
            'siblingCount' => count($siblings),
            'position'     => $position,
            'prevChannel'  => $prev,
            'nextChannel'  => $next,
            // 一级栏目的 parent_type 是自身栏目号，不该把它自己当成“上级栏目”
            'parent'       => ChannelRepository::isTopLevel((string) $channel['type_code'], (string) $channel['parent_type'])
                ? null
                : $this->channels->adminFind((string) $channel['parent_type']),
        ], '编辑栏目 · ' . $channel['inner_name']);
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
        if ($denied = $this->requirePermission(Permissions::CHANNEL_MANAGE)) {
            return $denied;
        }

        $type = $args['type'];
        if ($this->channels->adminFind($type) === null) {
            return $this->view->page('admin/message', [
                'current' => 'channels',
                'heading' => '未找到栏目',
                'message' => '栏目 ' . $type . ' 不存在。',
                'backUrl' => '/admin/channels',
            ], '未找到栏目');
        }

        $name = $request->post('name');
        $inner = $request->post('inner_name');
        if ($name === '' || $inner === '') {
            Flash::set('error', '一级栏目名与子栏目名都不能为空。');
            return new RedirectResponse('/admin/channel/' . $type);
        }

        $layout = $request->post('layout');
        if (!in_array($layout, self::LAYOUTS, true)) {
            $layout = 'list';
        }
        $status = $request->post('status');
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'published';
        }

        $this->channels->adminUpdate($type, [
            'name'       => $name,
            'inner_name' => $inner,
            'intro'      => $request->post('intro'),
            'layout'     => $layout,
            'sort_no'    => (int) $request->post('sort_no', '0'),
            'status'     => $status,
        ]);
        $this->log('channel.update', 'channel', $type, ['name' => $name, 'layout' => $layout, 'status' => $status]);

        Flash::set('ok', '已保存栏目：' . $inner);
        return new RedirectResponse('/admin/channel/' . $type);
    }

    // ---- 新建与删除（2026-09-12）------------------------------------------------

    public function createForm(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::CHANNEL_MANAGE)) {
            return $denied;
        }

        return $this->view->page('admin/channel_new', [
            'current'  => 'channels',
            'layouts'  => self::LAYOUTS,
            'parents'  => $this->channels->adminTopChannels(),
            'defaults' => [
                'parent_type' => '',
                'type_code'   => '',
                'name'        => '',
                'inner_name'  => '',
                'slug'        => '',
                'layout'      => 'list',
                'status'      => 'published',
                'intro'       => '',
            ],
            'error' => '',
        ], '新建栏目');
    }

    /**
     * 新建栏目：栏目号唯一、子栏目名必填；挂在一级栏目下时，一级栏目名跟随父栏目，
     * 避免同一组里出现两个不同的一级名（前台导航条按一级名分组）。
     */
    public function store(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requirePermission(Permissions::CHANNEL_MANAGE)) {
            return $denied;
        }

        $type = trim($request->post('type_code'));
        $parent = trim($request->post('parent_type'));
        $inner = trim($request->post('inner_name'));
        $name = trim($request->post('name'));
        $slug = trim($request->post('slug'));
        $intro = $request->post('intro');
        $layout = $request->post('layout');
        $status = $request->post('status');

        if (!in_array($layout, self::LAYOUTS, true)) {
            $layout = 'list';
        }
        if (!in_array($status, self::STATUSES, true)) {
            $status = 'published';
        }

        $error = '';
        $parentRow = null;
        if ($parent !== '') {
            $parentRow = $this->channels->adminFind($parent);
            if ($parentRow === null) {
                $error = '选的一级栏目不存在，请重新选择。';
            } elseif (!ChannelRepository::isTopLevel((string) $parentRow['type_code'], (string) $parentRow['parent_type'])) {
                $error = '只能挂在一级栏目下，本系统只做两级栏目。';
            }
        }
        if ($error === '' && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $type) !== 1) {
            $error = '栏目号只能用 1—32 位字母、数字、下划线或连字符（例如 320 或 xianqu-news）。';
        }
        if ($error === '' && in_array(strtolower($type), ['new', 'create', 'index', 'delete'], true)) {
            $error = '栏目号「' . $type . '」是后台的保留字，换一个。';
        }
        if ($error === '' && $this->channels->adminTypeExists($type)) {
            $error = '栏目号「' . $type . '」已经被占用，换一个。';
        }
        if ($error === '' && $inner === '') {
            $error = '子栏目名不能为空（前台导航与栏目页标题用它）。';
        }
        if ($error === '' && $slug !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug) !== 1) {
            $error = 'URL 标识只能用字母、数字、点、下划线或连字符，且以字母或数字开头；留空则用栏目号。';
        }
        if ($error === '' && $parentRow === null && $name === '') {
            $error = '新建一级栏目时，一级栏目名不能为空。';
        }

        if ($error !== '') {
            // 直接回渲染，保留编辑已经填好的内容，不让他重填一遍
            return $this->view->page('admin/channel_new', [
                'current'  => 'channels',
                'layouts'  => self::LAYOUTS,
                'parents'  => $this->channels->adminTopChannels(),
                'defaults' => [
                    'parent_type' => $parent,
                    'type_code'   => $type,
                    'name'        => $name,
                    'inner_name'  => $inner,
                    'slug'        => $slug,
                    'layout'      => $layout,
                    'status'      => $status,
                    'intro'       => $intro,
                ],
                'error' => $error,
            ], '新建栏目');
        }

        $finalName = $parentRow !== null ? (string) $parentRow['name'] : $name;
        $parentType = $parentRow !== null ? (string) $parentRow['type_code'] : $type;
        $this->channels->adminCreate([
            'type_code'   => $type,
            // 一级栏目按 seed 的约定写自身栏目号（见 ChannelRepository::isTopLevel）
            'parent_type' => $parentType,
            'slug'        => $slug !== '' ? $slug : $type,
            'name'        => $finalName,
            'inner_name'  => $inner,
            'intro'       => $intro,
            'layout'      => $layout,
            'sort_no'     => $this->channels->adminMaxSortNo($parentRow !== null ? $parentType : '') + 1,
            'status'      => $status,
        ]);
        $this->log('channel.create', 'channel', $type, [
            'name'        => $finalName,
            'inner_name'  => $inner,
            'parent_type' => $parentRow !== null ? (string) $parentRow['type_code'] : '',
            'layout'      => $layout,
            'status'      => $status,
        ]);

        Flash::set('ok', '已新建栏目：' . $inner . '。它排在所在分组的最后，可在列表里上移／下移。');
        return new RedirectResponse('/admin/channel/' . $type);
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteConfirm(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::CHANNEL_MANAGE)) {
            return $denied;
        }

        $type = (string) $args['type'];
        $channel = $this->channels->adminFind($type);
        if ($channel === null) {
            return $this->view->page('admin/message', [
                'current' => 'channels',
                'heading' => '未找到栏目',
                'message' => '栏目 ' . $type . ' 不存在。',
                'backUrl' => '/admin/channels',
            ], '未找到栏目');
        }

        return $this->view->page('admin/channel_delete', [
            'current'     => 'channels',
            'channel'     => $channel,
            'blockers'    => $this->channels->adminBlockers($type),
            'parent'      => ChannelRepository::isTopLevel($type, (string) $channel['parent_type'])
                ? null
                : $this->channels->adminFind((string) $channel['parent_type']),
        ], '删除栏目 · ' . $channel['inner_name']);
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
        if ($denied = $this->requirePermission(Permissions::CHANNEL_MANAGE)) {
            return $denied;
        }

        $type = (string) $args['type'];
        $channel = $this->channels->adminFind($type);
        if ($channel === null) {
            Flash::set('error', '栏目不存在。');
            return new RedirectResponse('/admin/channels');
        }

        if ($request->post('confirm') !== 'delete') {
            Flash::set('error', '请先勾选确认再删除。');
            return new RedirectResponse('/admin/channel/' . $type . '/delete');
        }

        $blockers = $this->channels->adminBlockers($type);
        if ($blockers !== []) {
            Flash::set('error', '这个栏目还有关联内容，暂不能删除：' . implode(' ', $blockers));
            return new RedirectResponse('/admin/channel/' . $type . '/delete');
        }

        $this->channels->adminDelete($type);
        $this->log('channel.delete', 'channel', $type, [
            'name'       => (string) $channel['name'],
            'inner_name' => (string) $channel['inner_name'],
        ]);

        Flash::set('ok', '已删除栏目：' . $channel['inner_name'] . '。它名下的旧地址 301 映射已一并清掉，重新发布后前台不再有这一页。');
        return new RedirectResponse('/admin/channels');
    }
}
