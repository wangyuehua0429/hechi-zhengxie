<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Support\Db;

/**
 * 后台会话认证：账号来自 sys_user（密码用 password_hash 存），
 * 权限到「站点 × 栏目」的细分留给阶段 C 后续（sys_role／sys_role_channel 已建表）。
 */
final class Auth
{
    private const SESSION_KEY = 'admin_uid';

    /** @var array<string, mixed>|null */
    private ?array $cachedUser = null;

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
