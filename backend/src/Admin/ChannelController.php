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

        return $this->view->page('admin/channels', [
            'current'  => 'channels',
            'channels' => $this->channels->adminAll(),
        ], '栏目管理');
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

        return $this->view->page('admin/channel_edit', [
            'current' => 'channels',
            'channel' => $channel,
            'layouts' => self::LAYOUTS,
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
