<?php

declare(strict_types=1);

namespace HechiZx\Repository;

use HechiZx\Support\Db;
use RuntimeException;

/**
 * 建议承办单位：市直单位清单（sys_proposal_unit）。
 * 清单由提案委在后台维护（手工增删或 CSV 导入），委员端只读启用中的单位。
 */
final class UnitRepository
{
    /** 一份提案最多可选几个承办单位（提案委 2026-09-16 确认） */
    public const MAX_PER_PROPOSAL = 5;

    public function __construct(private Db $db)
    {
    }

    /**
     * 委员端下拉用：只给启用中的单位。
     *
     * @return list<array<string,mixed>>
     */
    public function enabled(): array
    {
        return $this->db->select(
            "SELECT unit_id, name FROM sys_proposal_unit WHERE status = 'enabled' ORDER BY sort_no, unit_id"
        );
    }

    /**
     * 后台列表：关键词 + 状态筛选，附引用计数（有几份提案选了它）。
     *
     * @param array<string, string> $filters keyword/status
     * @return list<array<string,mixed>>
     */
    public function all(array $filters = []): array
    {
        $where = ['1 = 1'];
        $params = [];
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'enabled' || $status === 'disabled') {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $params['kwName'] = self::likePattern($keyword);
            $where[] = "u.name LIKE :kwName ESCAPE '!'";
        }

        return $this->db->select(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM cms_proposal_unit l WHERE l.unit_id = u.unit_id) AS used_count
             FROM sys_proposal_unit u
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY u.sort_no, u.unit_id',
            $params
        );
    }

    public function find(int $unitId): ?array
    {
        return $this->db->selectOne('SELECT * FROM sys_proposal_unit WHERE unit_id = :id', ['id' => $unitId]);
    }

    /** @return array<string, int> 状态 => 条数 */
    public function statusCounts(): array
    {
        $rows = $this->db->select('SELECT status, COUNT(*) AS c FROM sys_proposal_unit GROUP BY status');
        $counts = ['enabled' => 0, 'disabled' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }

        return $counts;
    }

    /** 按名称取（委员端提交时把 unit_id 还原成名称用） */
    public function findByName(string $name): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM sys_proposal_unit WHERE name = :name',
            ['name' => trim($name)]
        );
    }

    /** @return list<array<string,mixed>> 按 id 批量取启用中的单位 */
    public function enabledByIds(array $unitIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $unitIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $placeholders[] = ':u' . $index;
            $params['u' . $index] = $id;
        }

        return $this->db->select(
            "SELECT unit_id, name FROM sys_proposal_unit
             WHERE status = 'enabled' AND unit_id IN (" . implode(', ', $placeholders) . ')
             ORDER BY sort_no, unit_id',
            $params
        );
    }

    /** 新增：重名直接拒绝，避免同一单位出现两条 */
    public function create(string $name, int $sortNo = 0, string $remark = ''): int
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            throw new RuntimeException('单位名称不能为空。');
        }
        if ($this->findByName($name) !== null) {
            throw new RuntimeException('清单里已经有「' . $name . '」了。');
        }

        $now = $this->db->now();
        $this->db->execute(
            'INSERT INTO sys_proposal_unit (name, sort_no, status, remark, created_at, updated_at)
             VALUES (:name, :sort, :status, :remark, :t, :t)',
            ['name' => $name, 'sort' => $sortNo, 'status' => 'enabled', 'remark' => $remark, 't' => $now]
        );

        return (int) $this->db->pdo()->lastInsertId();
    }

    public function updateStatus(int $unitId, string $status): void
    {
        $this->db->execute(
            'UPDATE sys_proposal_unit SET status = :s, updated_at = :t WHERE unit_id = :id',
            ['s' => $status === 'disabled' ? 'disabled' : 'enabled', 't' => $this->db->now(), 'id' => $unitId]
        );
    }

    /** 改名要连提案上的名称摘要一起改，否则列表筛选与导出口径会对不上 */
    public function rename(int $unitId, string $name): void
    {
        $name = self::normalizeName($name);
        if ($name === '') {
            throw new RuntimeException('单位名称不能为空。');
        }
        $existing = $this->findByName($name);
        if ($existing !== null && (int) $existing['unit_id'] !== $unitId) {
            throw new RuntimeException('清单里已经有「' . $name . '」了。');
        }

        $this->db->execute(
            'UPDATE sys_proposal_unit SET name = :name, updated_at = :t WHERE unit_id = :id',
            ['name' => $name, 't' => $this->db->now(), 'id' => $unitId]
        );
        $this->db->execute(
            'UPDATE cms_proposal_unit SET unit_name = :name WHERE unit_id = :id',
            ['name' => $name, 'id' => $unitId]
        );
        $this->refreshSummariesFor($unitId);
    }

    /** 被提案引用的单位只能停用，不能删（删了历史提案的承办单位就丢了） */
    public function delete(int $unitId): void
    {
        if ($this->usedCount($unitId) > 0) {
            throw new RuntimeException('该单位已经被提案引用，只能停用，不能删除。');
        }
        $this->db->execute('DELETE FROM sys_proposal_unit WHERE unit_id = :id', ['id' => $unitId]);
    }

    public function usedCount(int $unitId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM cms_proposal_unit WHERE unit_id = :id',
            ['id' => $unitId]
        );
    }

    /**
     * CSV 导入：每行一个单位名（可带排序号），首行是表头时自动跳过。
     *
     * @return array{created:int,skipped:list<string>,failed:list<string>}
     */
    public function importCsv(string $raw): array
    {
        $raw = self::toUtf8($raw);
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        $created = 0;
        $skipped = [];
        $failed = [];
        $rowNumber = 0;
        $nextSort = (int) $this->db->scalar('SELECT COALESCE(MAX(sort_no), 0) FROM sys_proposal_unit');

        foreach ($lines as $line) {
            $rowNumber++;
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            // 第 4 个参数 $escape 必须显式传：PHP 8.5 起省略会每行报一条 Deprecated，
            // PHP 9 起该参数的默认值还要改（不传就是按废弃默认解析）
            $cells = str_getcsv($line, ',', '"', '\\');
            $name = self::normalizeName((string) ($cells[0] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($rowNumber === 1 && in_array($name, ['单位名称', '单位', '市直单位', '建议承办单位'], true)) {
                continue;
            }
            // 模板里那行「示例：…」是给人看格式的，直接当数据导进来会变成一条垃圾单位
            if (preg_match('/^(示例|例如)/u', $name) === 1) {
                continue;
            }
            $sort = isset($cells[1]) && is_numeric(trim((string) $cells[1])) ? (int) trim((string) $cells[1]) : 0;
            if ($sort <= 0) {
                $nextSort += 10;
                $sort = $nextSort;
            }
            if ($this->findByName($name) !== null) {
                $skipped[] = '第 ' . $rowNumber . ' 行：' . $name . '（已在清单里）';
                continue;
            }
            try {
                $this->create($name, $sort);
                $created++;
            } catch (RuntimeException $e) {
                $failed[] = '第 ' . $rowNumber . ' 行：' . $e->getMessage();
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed];
    }

    /** @return string 导入模板（表头 + 一行示例格式说明） */
    public static function template(): string
    {
        return "\xEF\xBB\xBF" . "单位名称,排序号\n"
            . "示例：河池市住房和城乡建设局,10\n";
    }

    public const HEADERS = ['单位名称', '排序号'];

    /** 单位改名后，把引用它的提案上的名称摘要重算一遍 */
    private function refreshSummariesFor(int $unitId): void
    {
        $rows = $this->db->select(
            'SELECT DISTINCT proposal_id FROM cms_proposal_unit WHERE unit_id = :id',
            ['id' => $unitId]
        );
        foreach ($rows as $row) {
            $proposalId = (int) $row['proposal_id'];
            $names = array_map(
                static fn (array $item): string => (string) $item['unit_name'],
                $this->db->select(
                    'SELECT unit_name FROM cms_proposal_unit WHERE proposal_id = :id ORDER BY sort_no, id',
                    ['id' => $proposalId]
                )
            );
            $this->db->execute(
                'UPDATE cms_proposal SET host_units = :names, updated_at = :t WHERE proposal_id = :id',
                ['names' => implode('、', $names), 't' => $this->db->now(), 'id' => $proposalId]
            );
        }
    }

    private static function normalizeName(string $name): string
    {
        // 全角空格与制表符在复制粘贴的名单里很常见，先清掉再比对重名
        return trim(str_replace(["\xE3\x80\x80", "\t"], ' ', $name));
    }

    /**
     * 名单常从 Excel 导出成 GBK，与委员名册导入同一口径；BOM 必须剥掉，
     * 否则模板第一行的表头会变成「\uFEFF单位名称」，识别不成表头就当成一条单位导进来
     * （2026-09-17 review-team 实测：拿系统自带的模板导入会凭空多出两条垃圾单位）。
     */
    public static function toUtf8(string $raw): string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        return mb_convert_encoding($text, 'UTF-8', 'GB18030');
    }

    private static function likePattern(string $keyword): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $keyword) . '%';
    }
}
