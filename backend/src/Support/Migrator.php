<?php

declare(strict_types=1);

namespace HechiZx\Support;

/**
 * 迁移执行器：按文件名顺序执行 database/migrations/<driver>/*.sql，
 * 已执行版本记在 schema_migrations，可重复运行。
 */
final class Migrator
{
    public function __construct(private Db $db, private string $driverDir)
    {
    }

    /** @return list<string> 本次新执行的版本 */
    public function run(): array
    {
        $this->ensureRegistry();
        $applied = $this->appliedVersions();
        $executed = [];

        foreach ($this->files() as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $applied, true)) {
                continue;
            }
            foreach (self::splitStatements((string) file_get_contents($file)) as $statement) {
                $this->db->pdo()->exec($statement);
            }
            $this->db->execute(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (:v, :t)',
                ['v' => $version, 't' => $this->db->now()]
            );
            $executed[] = $version;
        }

        return $executed;
    }

    /** @return list<string> */
    public function files(): array
    {
        $files = glob(rtrim($this->driverDir, '/') . '/*.sql') ?: [];
        sort($files);
        return $files;
    }

    /** @return list<string> */
    public function appliedVersions(): array
    {
        $rows = $this->db->select('SELECT version FROM schema_migrations');
        return array_map(static fn (array $row): string => (string) $row['version'], $rows);
    }

    private function ensureRegistry(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version VARCHAR(64) NOT NULL PRIMARY KEY, '
            . 'applied_at VARCHAR(32) NOT NULL)'
        );
    }

    /**
     * 按分号切分语句：本项目的迁移只含 CREATE TABLE / CREATE INDEX，
     * 不含存储过程与自定义分隔符，简单切分即可（遇到 DELIMITER 再另做处理）。
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        // 必须用显式换行匹配：PCRE 的 \R 在非 /u 模式下会匹配 0x85 等字节，
        // 而中文 UTF-8 的后续字节正好落在这个区间，会把注释切碎（2026-09-11 实测踩过）。
        $lines = preg_split('/\r\n|\n|\r/', $sql) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line)) {
                continue;
            }
            $clean[] = $line;
        }

        $statements = [];
        foreach (explode(';', implode("\n", $clean)) as $statement) {
            $trimmed = trim($statement);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
        }
        return $statements;
    }
}
