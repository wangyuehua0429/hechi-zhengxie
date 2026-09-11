<?php

declare(strict_types=1);

namespace HechiZx\Support;

use PDO;

/**
 * 数据库连接：驱动在配置里切换（sqlite 本地开发 / mysql 生产），
 * 业务代码只拿 PDO 与方言标识，连接串不散落各模块。
 */
final class Db
{
    private PDO $pdo;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
        $this->pdo = self::connect($config);
    }

    /** @param array<string, mixed> $config */
    public static function connect(array $config): PDO
    {
        $driver = (string) ($config['driver'] ?? 'sqlite');
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        if ($driver === 'sqlite') {
            $path = (string) ($config['database'] ?? '');
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            return $pdo;
        }

        if ($driver === 'mysql') {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                (string) ($config['host'] ?? '127.0.0.1'),
                (int) ($config['port'] ?? 3306),
                (string) ($config['database'] ?? ''),
                (string) ($config['charset'] ?? 'utf8mb4')
            );
            $pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $options);

            // 会话时区跟应用时区对齐，否则 CURRENT_TIMESTAMP 的默认值与 PHP 写入的时间会差几个小时。
            // 这条在 MySQL 上生效即可，失败也不阻断连接（例如账号无权设置会话变量）。
            try {
                $timezone = (string) ($config['timezone'] ?? 'Asia/Shanghai');
                $offset = (new \DateTimeImmutable('now', new \DateTimeZone($timezone)))->format('P');
                $pdo->exec("SET time_zone = '" . $offset . "'");
            } catch (\Throwable $e) {
                // 忽略：连接已经建立，时区由部署时的 MySQL 配置兜底
            }

            return $pdo;
        }

        throw new \RuntimeException('不支持的数据库驱动：' . $driver);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->config['driver'];
    }

    public function isSqlite(): bool
    {
        return $this->driver() === 'sqlite';
    }

    /** @param array<string, mixed> $params */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @param array<string, mixed> $params */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $rows = $this->select($sql, $params);
        return $rows[0] ?? null;
    }

    /** @param array<string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** @param array<string, mixed> $params */
    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    public function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
