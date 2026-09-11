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
            'parent'       => (string) $channel['parent_type'] !== ''
                ? $this->channels->adminFind((string) $channel['parent_type'])
                : null,
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
}
