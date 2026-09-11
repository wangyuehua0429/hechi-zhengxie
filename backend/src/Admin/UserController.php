<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Support\Db;

/**
 * 用户管理：账号列表、新建、改资料、改角色、重置密码、停用/启用。
 * 只有 user.manage 权限（默认只有管理员角色）能进。
 */
final class UserController extends AdminController
{
    private const MIN_PASSWORD = 8;

    public function __construct(Auth $auth, View $view, Db $db, int $siteId)
    {
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

        return $this->view->page('admin/users', [
            'current' => 'users',
            'users'   => $this->db->select(
                "SELECT u.user_id, u.username, u.real_name, u.dept, u.mobile, u.email, u.status, u.last_login_at,
                        COALESCE(GROUP_CONCAT(r.name), '') AS role_names,
                        COUNT(r.role_id) AS role_count
                 FROM sys_user u
                 LEFT JOIN sys_user_role ur ON ur.user_id = u.user_id
                 LEFT JOIN sys_role r ON r.role_id = ur.role_id
                 GROUP BY u.user_id, u.username, u.real_name, u.dept, u.mobile, u.email, u.status, u.last_login_at
                 ORDER BY u.user_id"
            ),
            'roles' => $this->roles(),
        ], '用户管理');
    }

    public function createForm(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::USER_MANAGE)) {
            return $denied;
        }

        return $this->view->page('admin/user_edit', [
            'current'   => 'users',
            'user'      => null,
            'userRoles' => [],
            'roles'     => $this->roles(),
        ], '新建账号');
    }

    public function store(Request $request): HtmlResponse|RedirectResponse
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

        $username = $request->post('username');
        $password = (string) ($_POST['password'] ?? '');
        if ($username === '' || $password === '') {
            Flash::set('error', '账号与初始密码都不能为空。');
            return new RedirectResponse('/admin/user/new');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            Flash::set('error', '初始密码至少 ' . self::MIN_PASSWORD . ' 位。');
            return new RedirectResponse('/admin/user/new');
        }
        if ($this->db->scalar('SELECT user_id FROM sys_user WHERE username = :u', ['u' => $username]) !== null) {
            Flash::set('error', '账号已存在：' . $username);
            return new RedirectResponse('/admin/user/new');
        }

        $now = $this->db->now();
        $this->db->execute(
            'INSERT INTO sys_user (username, password_hash, real_name, dept, mobile, email, remark, status, created_at, updated_at)
             VALUES (:u, :p, :name, :dept, :mobile, :email, :remark, :status, :t, :t)',
            [
                'u'      => $username,
                'p'      => password_hash($password, PASSWORD_DEFAULT),
                'name'   => $request->post('real_name'),
                'dept'   => $request->post('dept'),
                'mobile' => $request->post('mobile'),
                'email'  => $request->post('email'),
                'remark' => $request->post('remark'),
                'status' => $request->post('status') === 'disabled' ? 'disabled' : 'enabled',
                't'      => $now,
            ]
        );
        $userId = (int) $this->db->pdo()->lastInsertId();
        $this->saveRoles($userId, $this->postedIds('roles'));
        $this->log('user.create', 'user', (string) $userId, ['username' => $username]);

        Flash::set('ok', '已新建账号：' . $username . '。请把初始密码当面告知本人，并提醒首次登录后修改。');
        return new RedirectResponse('/admin/user/' . $userId);
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

        $user = $this->find($args['user_id']);
        if ($user === null) {
            Flash::set('error', '账号不存在。');
            return new RedirectResponse('/admin/users');
        }
        $rows = $this->db->select('SELECT role_id FROM sys_user_role WHERE user_id = :id', ['id' => (int) $user['user_id']]);

        return $this->view->page('admin/user_edit', [
            'current'   => 'users',
            'user'      => $user,
            'userRoles' => array_map(static fn (array $row): int => (int) $row['role_id'], $rows),
            'roles'     => $this->roles(),
        ], '编辑账号 · ' . $user['username']);
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

        $userId = (int) ($args['user_id'] ?? 0);
        $user = $this->find((string) $userId);
        if ($user === null) {
            Flash::set('error', '账号不存在。');
            return new RedirectResponse('/admin/users');
        }

        $roleIds = $this->postedIds('roles');
        $status = $request->post('status') === 'disabled' ? 'disabled' : 'enabled';
        $adminRoleId = (int) $this->db->scalar("SELECT role_id FROM sys_role WHERE code = 'admin'");
        $isSelf = $userId === (int) ($this->user()['user_id'] ?? 0);

        // 防呆：不能把自己停用或摘掉管理员，否则会把自己锁在门外
        if ($isSelf && $status === 'disabled') {
            Flash::set('error', '不能停用当前登录的账号。');
            return new RedirectResponse('/admin/user/' . $userId);
        }
        if ($isSelf && !in_array($adminRoleId, $roleIds, true)) {
            Flash::set('error', '不能把当前登录账号的管理员角色取消。');
            return new RedirectResponse('/admin/user/' . $userId);
        }

        $fields = [
            'real_name'  => $request->post('real_name'),
            'dept'       => $request->post('dept'),
            'mobile'     => $request->post('mobile'),
            'email'      => $request->post('email'),
            'remark'     => $request->post('remark'),
            'status'     => $status,
            'updated_at' => $this->db->now(),
        ];
        $sets = [];
        $params = ['id' => $userId];
        foreach ($fields as $column => $value) {
            $sets[] = $column . ' = :f_' . $column;
            $params['f_' . $column] = $value;
        }
        $this->db->execute('UPDATE sys_user SET ' . implode(', ', $sets) . ' WHERE user_id = :id', $params);
        $this->saveRoles($userId, $roleIds);

        $password = (string) ($_POST['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < self::MIN_PASSWORD) {
                Flash::set('error', '新密码至少 ' . self::MIN_PASSWORD . ' 位，其它修改已保存。');
                return new RedirectResponse('/admin/user/' . $userId);
            }
            $this->db->execute(
                'UPDATE sys_user SET password_hash = :p, pwd_changed_at = :t WHERE user_id = :id',
                ['p' => password_hash($password, PASSWORD_DEFAULT), 't' => $this->db->now(), 'id' => $userId]
            );
            $this->log('user.reset_password', 'user', (string) $userId, ['username' => (string) $user['username']]);
        }

        $this->log('user.update', 'user', (string) $userId, ['username' => (string) $user['username'], 'roles' => $roleIds, 'status' => $status]);
        Flash::set('ok', '已保存账号：' . $user['username']);
        return new RedirectResponse('/admin/user/' . $userId);
    }

    /** @return array<string, mixed>|null */
    private function find(string $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM sys_user WHERE user_id = :id', ['id' => (int) $id]);
    }

    /** @return list<array<string, mixed>> */
    private function roles(): array
    {
        return $this->db->select('SELECT role_id, code, name, description FROM sys_role ORDER BY sort_no ASC, role_id ASC');
    }

    /**
     * 读取勾选框数组（roles[]），只保留数字。
     *
     * @return list<int>
     */
    private function postedIds(string $field): array
    {
        $raw = $_POST[$field] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_scalar($value) && preg_match('/^\d+$/', (string) $value) === 1) {
                $ids[] = (int) $value;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @param list<int> $roleIds */
    private function saveRoles(int $userId, array $roleIds): void
    {
        $this->db->execute('DELETE FROM sys_user_role WHERE user_id = :id', ['id' => $userId]);
        foreach ($roleIds as $roleId) {
            $exists = $this->db->scalar('SELECT role_id FROM sys_role WHERE role_id = :r', ['r' => $roleId]);
            if ($exists === null) {
                continue;
            }
            $this->db->execute(
                'INSERT INTO sys_user_role (user_id, role_id) VALUES (:id, :r)',
                ['id' => $userId, 'r' => $roleId]
            );
        }
    }
}
