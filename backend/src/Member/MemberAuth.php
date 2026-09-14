<?php

declare(strict_types=1);

namespace HechiZx\Member;

use HechiZx\Repository\MemberRepository;
use HechiZx\Support\Db;

/**
 * 委员门户会话：独立 session_name（zx_member），与后台 /admin 的会话互不可见。
 * 账号来自 sys_member，密码用 password_hash 存；连续输错 5 次锁定 15 分钟。
 */
final class MemberAuth
{
    private const SESSION_NAME = 'zx_member';
    private const SESSION_KEY = 'member_id';
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;
    public const MIN_PASSWORD = 8;

    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    public function __construct(private Db $db, private MemberRepository $members)
    {
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(self::SESSION_NAME);
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'path'     => '/',
        ]);
        session_start();
    }

    /** @return array<string, mixed>|null */
    public function member(): ?array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        if ($id === null) {
            return null;
        }
        $row = $this->members->find((int) $id);
        if ($row === null || (string) $row['status'] !== 'enabled') {
            $this->logout();
            return null;
        }

        return $this->cached = $row;
    }

    public function check(): bool
    {
        return $this->member() !== null;
    }

    public function id(): int
    {
        return (int) ($this->member()['member_id'] ?? 0);
    }

    public function mustChangePassword(): bool
    {
        return (int) ($this->member()['must_change_password'] ?? 0) === 1;
    }

    /**
     * 登录。返回 [成功?, 提示语]；提示语对「账号不存在」与「密码错误」保持一致，不泄露账号是否存在。
     *
     * @return array{ok:bool,message:string}
     */
    public function attempt(string $loginName, string $password): array
    {
        $row = $this->members->findByLogin($loginName);
        if ($row === null) {
            return ['ok' => false, 'message' => '登录名或密码不正确。'];
        }
        if ((string) $row['status'] !== 'enabled') {
            return ['ok' => false, 'message' => '该账号已停用，请联系提案委。'];
        }

        $lockedUntil = (string) ($row['locked_until'] ?? '');
        if ($lockedUntil !== '' && strtotime($lockedUntil) > time()) {
            $minutes = (int) ceil((strtotime($lockedUntil) - time()) / 60);
            return ['ok' => false, 'message' => '连续输错次数过多，请 ' . max(1, $minutes) . ' 分钟后再试。'];
        }

        if (!password_verify($password, (string) $row['password_hash'])) {
            $attempts = (int) $row['failed_attempts'] + 1;
            $until = null;
            $message = '登录名或密码不正确。';
            if ($attempts >= self::MAX_ATTEMPTS) {
                $until = date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60);
                $attempts = 0;
                $message = '连续输错 ' . self::MAX_ATTEMPTS . ' 次，账号已锁定 ' . self::LOCK_MINUTES . ' 分钟。';
            }
            $this->members->registerFailedAttempt((int) $row['member_id'], $attempts, $until);
            return ['ok' => false, 'message' => $message];
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = (int) $row['member_id'];
        $this->cached = null;
        $this->members->touchLogin((int) $row['member_id']);
        $this->log('member.login', (string) $row['member_id'], ['name' => (string) $row['name']]);

        return ['ok' => true, 'message' => ''];
    }

    public function logout(): void
    {
        $id = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);
        $this->cached = null;
        if ($id !== null) {
            $this->log('member.logout', (string) $id);
        }
    }

    /**
     * 改密：校验原密码与两次新密码。
     *
     * @return array{ok:bool,message:string}
     */
    public function changePassword(string $current, string $new, string $confirm): array
    {
        $row = $this->member();
        if ($row === null) {
            return ['ok' => false, 'message' => '登录已过期，请重新登录。'];
        }
        if (!password_verify($current, (string) $row['password_hash'])) {
            return ['ok' => false, 'message' => '原密码不正确。'];
        }
        if (mb_strlen($new) < self::MIN_PASSWORD) {
            return ['ok' => false, 'message' => '新密码至少 ' . self::MIN_PASSWORD . ' 位。'];
        }
        if ($new !== $confirm) {
            return ['ok' => false, 'message' => '两次输入的新密码不一致。'];
        }
        if ($new === $current) {
            return ['ok' => false, 'message' => '新密码不能与原密码相同。'];
        }

        $this->members->setPassword(
            (int) $row['member_id'],
            password_hash($new, PASSWORD_DEFAULT),
            false
        );
        $this->cached = null;
        $this->log('member.password', (string) $row['member_id']);

        return ['ok' => true, 'message' => '密码已修改。'];
    }

    /**
     * 委员侧操作日志：写 sys_operation_log（user_id 0 表示非后台账号，主体在 target_id 里）。
     *
     * @param array<string, mixed> $detail
     */
    public function log(string $action, string $targetId = '', array $detail = []): void
    {
        $this->db->execute(
            'INSERT INTO sys_operation_log (user_id, action, target_type, target_id, detail_json, ip, user_agent)
             VALUES (0, :action, :type, :tid, :detail, :ip, :ua)',
            [
                'action' => $action,
                'type'   => 'member',
                'tid'    => $targetId,
                'detail' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
                'ip'     => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'ua'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]
        );
    }
}
