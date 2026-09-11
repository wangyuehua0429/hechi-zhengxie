<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Support\Db;

/**
 * 操作日志：谁在什么时候做了什么。数据来自 sys_operation_log，
 * 稿件流转、账号与角色改动都会写进来，用于事后追溯。
 */
final class LogController extends AdminController
{
    private const PAGE_SIZE = 100;

    public function __construct(Auth $auth, View $view, Db $db, int $siteId)
    {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::LOG_VIEW)) {
            return $denied;
        }

        $page = $request->int('page', 1, 1);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM sys_operation_log');
        $offset = max(0, ($page - 1) * self::PAGE_SIZE);

        return $this->view->page('admin/logs', [
            'current' => 'logs',
            'logs'    => $this->db->select(
                'SELECT l.log_id, l.action, l.target_type, l.target_id, l.detail_json, l.ip, l.created_at,
                        u.username, u.real_name
                 FROM sys_operation_log l
                 LEFT JOIN sys_user u ON u.user_id = l.user_id
                 ORDER BY l.log_id DESC
                 LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset
            ),
            'total' => $total,
            'page'  => $page,
            'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
        ], '操作日志');
    }
}
