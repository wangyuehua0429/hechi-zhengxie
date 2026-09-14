<?php

declare(strict_types=1);

namespace HechiZx\Repository;

use HechiZx\Content\ProposalWorkflow;
use HechiZx\Support\Db;

/**
 * 提案仓储：SQL 只写在这里（与 ArticleRepository 同一约定）。
 * 委员端只读自己的提案；后台按筛选条件读全量，两者共用同一套表。
 */
final class ProposalRepository
{
    public function __construct(private Db $db, private int $siteId)
    {
    }

    /**
     * 委员端「我的提案」列表。
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function memberList(int $memberId, string $status, int $page, int $size): array
    {
        $where = ['site_id = :site', 'member_id = :member'];
        $params = ['site' => $this->siteId, 'member' => $memberId];
        if ($status !== '' && in_array($status, ProposalWorkflow::statuses(), true)) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM cms_proposal WHERE ' . $whereSql, $params);
        $offset = max(0, ($page - 1) * $size);
        $rows = $this->db->select(
            'SELECT proposal_id, title, category, status, submitted_at, reviewed_at, returned_reason, updated_at
             FROM cms_proposal WHERE ' . $whereSql . '
             ORDER BY proposal_id DESC LIMIT ' . max(1, $size) . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /** 委员按提案号取自己的提案，取不到（不是本人的或不存在）返回 null */
    public function memberFind(int $proposalId, int $memberId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_proposal WHERE proposal_id = :id AND member_id = :member AND site_id = :site',
            ['id' => $proposalId, 'member' => $memberId, 'site' => $this->siteId]
        );
    }

    /** @param array<string, mixed> $data */
    public function create(int $memberId, array $data): int
    {
        $now = $this->db->now();
        $this->db->execute(
            'INSERT INTO cms_proposal
               (site_id, member_id, proposer_type, proposer_name, sector, committee, contact_mobile,
                co_members, collective_name, category, title, problem_text, analysis_text, suggestion_text,
                status, submitted_at, created_at, updated_at)
             VALUES
               (:site, :member, :ptype, :pname, :sector, :committee, :mobile,
                :comembers, :collective, :category, :title, :problem, :analysis, :suggestion,
                :status, :submitted, :t, :t)',
            [
                'site'       => $this->siteId,
                'member'     => $memberId,
                'ptype'      => (string) ($data['proposer_type'] ?? 'personal'),
                'pname'      => (string) ($data['proposer_name'] ?? ''),
                'sector'     => (string) ($data['sector'] ?? ''),
                'committee'  => (string) ($data['committee'] ?? ''),
                'mobile'     => (string) ($data['contact_mobile'] ?? ''),
                'comembers'  => (string) ($data['co_members'] ?? ''),
                'collective' => (string) ($data['collective_name'] ?? ''),
                'category'   => (string) ($data['category'] ?? ''),
                'title'      => (string) ($data['title'] ?? ''),
                'problem'    => (string) ($data['problem_text'] ?? ''),
                'analysis'   => (string) ($data['analysis_text'] ?? ''),
                'suggestion' => (string) ($data['suggestion_text'] ?? ''),
                'status'     => ProposalWorkflow::SUBMITTED,
                'submitted'  => $now,
                't'          => $now,
            ]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * 退回后改稿重交：更新字段、状态回到已提交、清掉上一次的退回意见。
     *
     * @param array<string, mixed> $data
     */
    public function resubmit(int $proposalId, array $data): void
    {
        $now = $this->db->now();
        $this->db->execute(
            'UPDATE cms_proposal SET
               proposer_type = :ptype, proposer_name = :pname, sector = :sector, committee = :committee,
               contact_mobile = :mobile, co_members = :comembers, collective_name = :collective,
               category = :category, title = :title, problem_text = :problem, analysis_text = :analysis,
               suggestion_text = :suggestion, status = :status, returned_reason = :empty,
               submitted_at = :submitted, updated_at = :t
             WHERE proposal_id = :id AND site_id = :site',
            [
                'ptype'      => (string) ($data['proposer_type'] ?? 'personal'),
                'pname'      => (string) ($data['proposer_name'] ?? ''),
                'sector'     => (string) ($data['sector'] ?? ''),
                'committee'  => (string) ($data['committee'] ?? ''),
                'mobile'     => (string) ($data['contact_mobile'] ?? ''),
                'comembers'  => (string) ($data['co_members'] ?? ''),
                'collective' => (string) ($data['collective_name'] ?? ''),
                'category'   => (string) ($data['category'] ?? ''),
                'title'      => (string) ($data['title'] ?? ''),
                'problem'    => (string) ($data['problem_text'] ?? ''),
                'analysis'   => (string) ($data['analysis_text'] ?? ''),
                'suggestion' => (string) ($data['suggestion_text'] ?? ''),
                'status'     => ProposalWorkflow::SUBMITTED,
                'empty'      => '',
                'submitted'  => $now,
                't'          => $now,
                'id'         => $proposalId,
                'site'       => $this->siteId,
            ]
        );
    }

    public function accept(int $proposalId, int $reviewerId, string $note): void
    {
        $now = $this->db->now();
        $this->db->execute(
            'UPDATE cms_proposal SET status = :status, reviewer_id = :reviewer, reviewed_at = :t,
               review_note = :note, returned_reason = :empty, updated_at = :t
             WHERE proposal_id = :id AND site_id = :site',
            [
                'status'   => ProposalWorkflow::ACCEPTED,
                'reviewer' => $reviewerId,
                't'        => $now,
                'note'     => $note,
                'empty'    => '',
                'id'       => $proposalId,
                'site'     => $this->siteId,
            ]
        );
    }

    public function returnBack(int $proposalId, int $reviewerId, string $reason): void
    {
        $now = $this->db->now();
        $this->db->execute(
            'UPDATE cms_proposal SET status = :status, reviewer_id = :reviewer, reviewed_at = :t,
               returned_reason = :reason, updated_at = :t
             WHERE proposal_id = :id AND site_id = :site',
            [
                'status'   => ProposalWorkflow::RETURNED,
                'reviewer' => $reviewerId,
                't'        => $now,
                'reason'   => $reason,
                'id'       => $proposalId,
                'site'     => $this->siteId,
            ]
        );
    }

    public function find(int $proposalId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_proposal WHERE proposal_id = :id AND site_id = :site',
            ['id' => $proposalId, 'site' => $this->siteId]
        );
    }

    /**
     * 后台收件列表（带筛选与分页）。
     *
     * @param array<string, string> $filters status/category/sector/keyword/from/to
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function adminList(array $filters, int $page, int $size): array
    {
        [$whereSql, $params] = $this->adminWhere($filters);

        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM cms_proposal p LEFT JOIN sys_member m ON m.member_id = p.member_id WHERE ' . $whereSql,
            $params
        );
        $offset = max(0, ($page - 1) * $size);
        $rows = $this->db->select(
            "SELECT p.*, m.name AS member_name, m.login_name
             FROM cms_proposal p
             LEFT JOIN sys_member m ON m.member_id = p.member_id
             WHERE " . $whereSql . "
             ORDER BY (CASE p.status WHEN 'submitted' THEN 0 WHEN 'returned' THEN 1 ELSE 2 END), p.proposal_id DESC
             LIMIT " . max(1, $size) . ' OFFSET ' . $offset,
            $params
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * 导出用：同一套筛选、不分页（上限保护见调用方）。
     *
     * @param array<string, string> $filters
     * @return list<array<string,mixed>>
     */
    public function exportRows(array $filters, int $limit = 3000): array
    {
        [$whereSql, $params] = $this->adminWhere($filters);

        return $this->db->select(
            'SELECT p.*, m.name AS member_name
             FROM cms_proposal p
             LEFT JOIN sys_member m ON m.member_id = p.member_id
             WHERE ' . $whereSql . '
             ORDER BY p.proposal_id DESC LIMIT ' . max(1, $limit),
            $params
        );
    }

    /** @return array<string, int> 状态 => 条数 */
    public function statusCounts(): array
    {
        $rows = $this->db->select(
            'SELECT status, COUNT(*) AS c FROM cms_proposal WHERE site_id = :site GROUP BY status',
            ['site' => $this->siteId]
        );
        $counts = array_fill_keys(ProposalWorkflow::statuses(), 0);
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /** @return list<array<string,mixed>> */
    public function attachments(int $proposalId): array
    {
        return $this->db->select(
            'SELECT * FROM cms_proposal_attachment WHERE proposal_id = :id ORDER BY attachment_id',
            ['id' => $proposalId]
        );
    }

    public function findAttachment(int $attachmentId, int $proposalId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_proposal_attachment WHERE attachment_id = :aid AND proposal_id = :pid',
            ['aid' => $attachmentId, 'pid' => $proposalId]
        );
    }

    public function addAttachment(int $proposalId, string $name, string $storedPath, string $ext, int $size): void
    {
        $this->db->execute(
            'INSERT INTO cms_proposal_attachment (proposal_id, name, stored_path, ext, size_bytes, created_at)
             VALUES (:pid, :name, :path, :ext, :size, :t)',
            [
                'pid'  => $proposalId,
                'name' => $name,
                'path' => $storedPath,
                'ext'  => $ext,
                'size' => $size,
                't'    => $this->db->now(),
            ]
        );
    }

    /** @return list<array<string,mixed>> */
    public function logs(int $proposalId): array
    {
        return $this->db->select(
            'SELECT * FROM cms_proposal_log WHERE proposal_id = :id ORDER BY log_id',
            ['id' => $proposalId]
        );
    }

    public function writeLog(int $proposalId, string $actorType, int $actorId, string $action, string $note = ''): void
    {
        $this->db->execute(
            'INSERT INTO cms_proposal_log (proposal_id, actor_type, actor_id, action, note, ip, created_at)
             VALUES (:pid, :atype, :aid, :action, :note, :ip, :t)',
            [
                'pid'    => $proposalId,
                'atype'  => $actorType,
                'aid'    => $actorId,
                'action' => $action,
                'note'   => $note,
                'ip'     => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                't'      => $this->db->now(),
            ]
        );
    }

    /**
     * @param array<string, string> $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function adminWhere(array $filters): array
    {
        $where = ['p.site_id = :site'];
        $params = ['site' => $this->siteId];

        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && in_array($status, ProposalWorkflow::statuses(), true)) {
            $where[] = 'p.status = :status';
            $params['status'] = $status;
        }
        $category = (string) ($filters['category'] ?? '');
        if ($category !== '' && in_array($category, ProposalWorkflow::categories(), true)) {
            $where[] = 'p.category = :category';
            $params['category'] = $category;
        }
        $sector = (string) ($filters['sector'] ?? '');
        if ($sector !== '') {
            $where[] = 'p.sector = :sector';
            $params['sector'] = $sector;
        }
        $keyword = (string) ($filters['keyword'] ?? '');
        if ($keyword !== '') {
            // 三个占位符各绑一次：Support/Db 关掉了预处理模拟，同名占位符复用会报 HY093
            $params['kwTitle'] = self::likePattern($keyword);
            $params['kwName']  = $params['kwTitle'];
            $params['kwMember'] = $params['kwTitle'];
            $where[] = "(p.title LIKE :kwTitle ESCAPE '!'
                OR p.proposer_name LIKE :kwName ESCAPE '!'
                OR m.name LIKE :kwMember ESCAPE '!')";
        }
        $from = (string) ($filters['from'] ?? '');
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1) {
            $where[] = 'p.submitted_at >= :from';
            $params['from'] = $from . ' 00:00:00';
        }
        $to = (string) ($filters['to'] ?? '');
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1) {
            $where[] = 'p.submitted_at <= :to';
            $params['to'] = $to . ' 23:59:59';
        }

        return [implode(' AND ', $where), $params];
    }

    private static function likePattern(string $keyword): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword) . '%';
    }
}
