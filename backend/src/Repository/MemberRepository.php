<?php

declare(strict_types=1);

namespace HechiZx\Repository;

use HechiZx\Support\Db;

/**
 * 委员账号仓储。与后台账号（sys_user）完全分开：委员只从 /member 门户登录，
 * 不挂任何后台权限码，因此进不了 /admin。
 */
final class MemberRepository
{
    public function __construct(private Db $db)
    {
    }

    /**
     * @param array<string, string> $filters keyword/status
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function paginate(array $filters, int $page, int $size): array
    {
        [$whereSql, $params] = $this->where($filters);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM sys_member m WHERE ' . $whereSql, $params);
        $offset = max(0, ($page - 1) * $size);
        $rows = $this->db->select(
            'SELECT m.*,
                    (SELECT COUNT(*) FROM cms_proposal p WHERE p.member_id = m.member_id) AS proposal_count
             FROM sys_member m WHERE ' . $whereSql . '
             ORDER BY m.member_id LIMIT ' . max(1, $size) . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public function find(int $memberId): ?array
    {
        return $this->db->selectOne('SELECT * FROM sys_member WHERE member_id = :id', ['id' => $memberId]);
    }

    public function findByLogin(string $loginName): ?array
    {
        return $this->db->selectOne('SELECT * FROM sys_member WHERE login_name = :l', ['l' => $loginName]);
    }

    /**
     * 按登录标识取候选账号，按「登录名（本人姓名）→ 手机号 → 姓名」定序。
     *
     * 登录名与手机号在导入时判重、姓名不判重，所以重名或手机号撞车时这里会返回多行；
     * 调用方用密码决定登哪个账号（谁的密码对就登谁），不在这里擅自挑一个。
     *
     * @return list<array<string,mixed>> 命中登录名的排最前，同层按 member_id
     */
    public function findByIdentifier(string $identifier): array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return [];
        }

        // PDO 关闭了模拟预处理，同名占位符不能复用，所以每个位置各用一个名字（i1…i5）
        return $this->db->select(
            'SELECT * FROM sys_member
             WHERE login_name = :i1 OR mobile = :i2 OR name = :i3
             ORDER BY CASE WHEN login_name = :i4 THEN 0 WHEN mobile = :i5 THEN 1 ELSE 2 END, member_id',
            [
                'i1' => $identifier, 'i2' => $identifier, 'i3' => $identifier,
                'i4' => $identifier, 'i5' => $identifier,
            ]
        );
    }

    public function loginExists(string $loginName): bool
    {
        return $this->db->scalar('SELECT member_id FROM sys_member WHERE login_name = :l', ['l' => $loginName]) !== null;
    }

    /** @return list<array<string,mixed>> 导入时判重用 */
    public function all(): array
    {
        return $this->db->select('SELECT member_id, name, login_name, mobile FROM sys_member');
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): int
    {
        $now = $this->db->now();
        $this->db->execute(
            'INSERT INTO sys_member
               (name, login_name, password_hash, must_change_password, mobile, sector, committee,
                org_title, term, status, remark, created_at, updated_at)
             VALUES
               (:name, :login, :hash, 1, :mobile, :sector, :committee, :org, :term, :status, :remark, :t, :t)',
            [
                'name'      => (string) ($data['name'] ?? ''),
                'login'     => (string) ($data['login_name'] ?? ''),
                'hash'      => (string) ($data['password_hash'] ?? ''),
                'mobile'    => (string) ($data['mobile'] ?? ''),
                'sector'    => (string) ($data['sector'] ?? ''),
                'committee' => (string) ($data['committee'] ?? ''),
                'org'       => (string) ($data['org_title'] ?? ''),
                'term'      => (string) ($data['term'] ?? ''),
                'status'    => (string) ($data['status'] ?? 'enabled'),
                'remark'    => (string) ($data['remark'] ?? ''),
                't'         => $now,
            ]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateStatus(int $memberId, string $status): void
    {
        $this->db->execute(
            'UPDATE sys_member SET status = :s, updated_at = :t WHERE member_id = :id',
            ['s' => $status, 't' => $this->db->now(), 'id' => $memberId]
        );
    }

    public function setPassword(int $memberId, string $hash, bool $mustChange): void
    {
        $this->db->execute(
            'UPDATE sys_member SET password_hash = :h, must_change_password = :m, failed_attempts = 0,
               locked_until = NULL, updated_at = :t WHERE member_id = :id',
            ['h' => $hash, 'm' => $mustChange ? 1 : 0, 't' => $this->db->now(), 'id' => $memberId]
        );
    }

    public function touchLogin(int $memberId): void
    {
        $this->db->execute(
            'UPDATE sys_member SET last_login_at = :t, failed_attempts = 0, locked_until = NULL WHERE member_id = :id',
            ['t' => $this->db->now(), 'id' => $memberId]
        );
    }

    public function registerFailedAttempt(int $memberId, int $attempts, ?string $lockedUntil): void
    {
        $this->db->execute(
            'UPDATE sys_member SET failed_attempts = :a, locked_until = :l, updated_at = :t WHERE member_id = :id',
            ['a' => $attempts, 'l' => $lockedUntil, 't' => $this->db->now(), 'id' => $memberId]
        );
    }

    /** @return array<string, int> 状态 => 人数 */
    public function statusCounts(): array
    {
        $rows = $this->db->select('SELECT status, COUNT(*) AS c FROM sys_member GROUP BY status');
        $counts = ['enabled' => 0, 'disabled' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * @param array<string, string> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function where(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'enabled' || $status === 'disabled') {
            $where[] = 'm.status = :status';
            $params['status'] = $status;
        }
        $keyword = (string) ($filters['keyword'] ?? '');
        if ($keyword !== '') {
            // 占位符不复用：Db 关掉了预处理模拟，同一个名字出现两次在 MySQL 上会报 HY093
            $params['kwName']   = self::likePattern($keyword);
            $params['kwLogin']  = $params['kwName'];
            $params['kwMobile'] = $params['kwName'];
            $where[] = "(m.name LIKE :kwName ESCAPE '!'
                OR m.login_name LIKE :kwLogin ESCAPE '!'
                OR m.mobile LIKE :kwMobile ESCAPE '!')";
        }

        return [implode(' AND ', $where), $params];
    }

    private static function likePattern(string $keyword): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword) . '%';
    }
}
