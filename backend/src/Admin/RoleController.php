<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Support\Db;

/**
 * 角色与权限：角色列表、权限码勾选、数据范围（能管哪些栏目）。
 * 权限码清单固定在代码里（HechiZx\Content\Permissions），页面只做勾选。
 */
final class RoleController extends AdminController
{
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
        if ($denied = $this->requirePermission(Permissions::USER_MANAGE)) {
            return $denied;
        }

        $roles = $this->db->select(
            'SELECT r.role_id, r.code, r.name, r.description, r.is_system, r.sort_no,
                    COUNT(DISTINCT rp.perm_code) AS perm_count,
                    COUNT(DISTINCT rc.channel_type) AS channel_count,
                    COUNT(DISTINCT ur.user_id) AS user_count
             FROM sys_role r
             LEFT JOIN sys_role_permission rp ON rp.role_id = r.role_id
             LEFT JOIN sys_role_channel rc ON rc.role_id = r.role_id
             LEFT JOIN sys_user_role ur ON ur.role_id = r.role_id
             GROUP BY r.role_id, r.code, r.name, r.description, r.is_system, r.sort_no
             ORDER BY r.sort_no ASC, r.role_id ASC'
        );

        return $this->view->page('admin/roles', [
            'current' => 'users',
            'roles'   => $roles,
        ], '角色与权限');
    }

    /**
     * @param array<string, string> $args
     */
    public function edit(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::USER_MANAGE)) {
            return $denied;
        }

        $role = $this->find($args['role_id']);
        if ($role === null) {
            Flash::set('error', '角色不存在。');
            return new RedirectResponse('/admin/roles');
        }

        $permRows = $this->db->select('SELECT perm_code FROM sys_role_permission WHERE role_id = :id', ['id' => (int) $role['role_id']]);
        $channelRows = $this->db->select('SELECT channel_type FROM sys_role_channel WHERE role_id = :id', ['id' => (int) $role['role_id']]);

        return $this->view->page('admin/role_edit', [
            'current'    => 'users',
            'role'       => $role,
            'perms'      => array_map(static fn (array $row): string => (string) $row['perm_code'], $permRows),
            'channels'   => array_map(static fn (array $row): string => (string) $row['channel_type'], $channelRows),
            'permGroups' => Permissions::groups(),
            'allChannels' => $this->channels->adminAll(),
        ], '编辑角色 · ' . $role['name']);
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
        if ($denied = $this->requirePermission(Permissions::USER_MANAGE)) {
            return $denied;
        }

        $roleId = (int) ($args['role_id'] ?? 0);
        $role = $this->find((string) $roleId);
        if ($role === null) {
            Flash::set('error', '角色不存在。');
            return new RedirectResponse('/admin/roles');
        }

        $name = $request->post('name');
        if ($name === '') {
            Flash::set('error', '角色名称不能为空。');
            return new RedirectResponse('/admin/role/' . $roleId);
        }

        $isAdminRole = (string) $role['code'] === 'admin';
        $perms = $isAdminRole ? Permissions::all() : $this->postedStrings('perms', Permissions::all());
        $channels = $this->postedStrings('channels', null);

        $this->db->execute(
            'UPDATE sys_role SET name = :name, description = :descr, remark = :remark WHERE role_id = :id',
            [
                'name'   => $name,
                'descr'  => $request->post('description'),
                'remark' => $request->post('remark'),
                'id'     => $roleId,
            ]
        );

        $this->db->execute('DELETE FROM sys_role_permission WHERE role_id = :id', ['id' => $roleId]);
        foreach ($perms as $code) {
            $this->db->execute(
                'INSERT INTO sys_role_permission (role_id, perm_code) VALUES (:id, :code)',
                ['id' => $roleId, 'code' => $code]
            );
        }

        $this->db->execute('DELETE FROM sys_role_channel WHERE role_id = :id', ['id' => $roleId]);
        foreach ($channels as $type) {
            $this->db->execute(
                'INSERT INTO sys_role_channel (role_id, site_id, channel_type) VALUES (:id, :site, :type)',
                ['id' => $roleId, 'site' => $this->siteId, 'type' => $type]
            );
        }

        $this->log('role.update', 'role', (string) $roleId, [
            'code' => (string) $role['code'],
            'perms' => count($perms),
            'channels' => $channels === [] ? '不限' : count($channels),
        ]);

        Flash::set('ok', '已保存角色：' . $name
            . ($isAdminRole ? '（管理员角色固定拥有全部权限）' : '')
            . '；数据范围：' . ($channels === [] ? '不限栏目' : count($channels) . ' 个栏目'));
        return new RedirectResponse('/admin/role/' . $roleId);
    }

    /** @return array<string, mixed>|null */
    private function find(string $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM sys_role WHERE role_id = :id', ['id' => (int) $id]);
    }

    /**
     * 读取勾选框数组并限制在白名单内；白名单为 null 表示不设限（栏目号来自数据库）。
     *
     * @param list<string>|null $allowed
     * @return list<string>
     */
    private function postedStrings(string $field, ?array $allowed): array
    {
        $raw = $_POST[$field] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $values = [];
        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $string = trim((string) $value);
            if ($string === '') {
                continue;
            }
            if ($allowed !== null && !in_array($string, $allowed, true)) {
                continue;
            }
            $values[] = $string;
        }
        return array_values(array_unique($values));
    }
}
