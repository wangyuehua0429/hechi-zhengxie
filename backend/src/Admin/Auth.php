<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Support\Db;

/**
 * 后台会话认证：账号来自 sys_user（密码用 password_hash 存），
 * 角色与权限来自 sys_role／sys_user_role／sys_role_permission，
 * 数据范围（能管哪些栏目）来自 sys_role_channel：空表示不限栏目。
 */
final class Auth
{
    private const SESSION_KEY = 'admin_uid';
    private const ADMIN_ROLE = 'admin';

    /** @var array<string, mixed>|null */
    private ?array $cachedUser = null;
    /** @var list<array<string, mixed>>|null */
    private ?array $cachedRoles = null;
    /** @var list<string>|null */
    private ?array $cachedPermissions = null;
    /** @var list<string>|null|false false 表示还没算过；null 表示不限栏目；数组为栏目白名单 */
    private array|false|null $cachedScope = false;

    public function __construct(private Db $db)
    {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'path'     => '/',
        ]);
        session_start();
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }
        $uid = $_SESSION[self::SESSION_KEY] ?? null;
        if ($uid === null) {
            return null;
        }
        $row = $this->db->selectOne(
            'SELECT user_id, username, real_name, status, last_login_at FROM sys_user WHERE user_id = :id',
            ['id' => (int) $uid]
        );
        if ($row === null || $row['status'] !== 'enabled') {
            $this->logout();
            return null;
        }
        return $this->cachedUser = $row;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * 当前账号的角色。
     *
     * @return list<array<string, mixed>>
     */
    public function roles(): array
    {
        if ($this->cachedRoles !== null) {
            return $this->cachedRoles;
        }
        $user = $this->user();
        if ($user === null) {
            return $this->cachedRoles = [];
        }
        return $this->cachedRoles = $this->db->select(
            'SELECT r.role_id, r.code, r.name FROM sys_role r
             JOIN sys_user_role ur ON ur.role_id = r.role_id
             WHERE ur.user_id = :id
             ORDER BY r.sort_no ASC, r.role_id ASC',
            ['id' => (int) $user['user_id']]
        );
    }

    /** @return list<string> */
    public function roleCodes(): array
    {
        return array_map(static fn (array $row): string => (string) $row['code'], $this->roles());
    }

    public function isAdmin(): bool
    {
        return in_array(self::ADMIN_ROLE, $this->roleCodes(), true);
    }

    /**
     * 账号拥有的权限码：管理员直接给全量，其余按角色并集。
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        if ($this->cachedPermissions !== null) {
            return $this->cachedPermissions;
        }
        $user = $this->user();
        if ($user === null) {
            return $this->cachedPermissions = [];
        }
        if ($this->isAdmin()) {
            return $this->cachedPermissions = Permissions::all();
        }
        $rows = $this->db->select(
            'SELECT DISTINCT rp.perm_code FROM sys_role_permission rp
             JOIN sys_user_role ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = :id',
            ['id' => (int) $user['user_id']]
        );
        return $this->cachedPermissions = array_map(
            static fn (array $row): string => (string) $row['perm_code'],
            $rows
        );
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * 数据范围：返回允许的栏目号；null 表示不限栏目。
     * 没有角色的账号返回空数组（什么都管不了），管理员返回 null。
     *
     * @return list<string>|null
     */
    public function channelScope(): ?array
    {
        if ($this->cachedScope !== false) {
            return $this->cachedScope;
        }

        $user = $this->user();
        if ($user === null) {
            return $this->cachedScope = [];
        }
        if ($this->isAdmin()) {
            return $this->cachedScope = null;
        }
        if ($this->roles() === []) {
            return $this->cachedScope = [];
        }

        $rows = $this->db->select(
            'SELECT DISTINCT rc.channel_type FROM sys_role_channel rc
             JOIN sys_user_role ur ON ur.role_id = rc.role_id
             WHERE ur.user_id = :id',
            ['id' => (int) $user['user_id']]
        );
        $types = array_map(static fn (array $row): string => (string) $row['channel_type'], $rows);

        // 角色没绑栏目表示不限栏目：小站常见「编辑管全站」，绑了才是限定
        return $this->cachedScope = ($types === [] ? null : $types);
    }

    public function canChannel(string $channelType): bool
    {
        $scope = $this->channelScope();
        return $scope === null || in_array($channelType, $scope, true);
    }

    /** 登录/退出后清空角色缓存，避免同一请求内串号 */
    private function forgetRoles(): void
    {
        $this->cachedRoles = null;
        $this->cachedPermissions = null;
        $this->cachedScope = false;
    }

    public function attempt(string $username, string $password): bool
    {
        $row = $this->db->selectOne(
            'SELECT user_id, password_hash, status FROM sys_user WHERE username = :u',
            ['u' => $username]
        );
        if ($row === null || $row['status'] !== 'enabled') {
            return false;
        }
        if (!password_verify($password, (string) $row['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $row['user_id'];
        $this->cachedUser = null;
        $this->forgetRoles();
        $this->db->execute(
            'UPDATE sys_user SET last_login_at = :t WHERE user_id = :id',
            ['t' => $this->db->now(), 'id' => (int) $row['user_id']]
        );
        $this->log((int) $row['user_id'], 'login');
        return true;
    }

    public function logout(): void
    {
        $uid = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        $this->cachedUser = null;
        $this->forgetRoles();
        if ($uid !== null) {
            $this->log((int) $uid, 'logout');
        }
    }

    /**
     * 操作日志（sys_operation_log，留存 180 天）。
     */
    public function log(int $userId, string $action, string $targetType = '', string $targetId = '', array $detail = []): void
    {
        $this->db->execute(
            'INSERT INTO sys_operation_log (user_id, action, target_type, target_id, detail_json, ip, user_agent)
             VALUES (:uid, :action, :type, :tid, :detail, :ip, :ua)',
            [
                'uid'    => $userId,
                'action' => $action,
                'type'   => $targetType,
                'tid'    => $targetId,
                'detail' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
                'ip'     => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'ua'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    }
}
