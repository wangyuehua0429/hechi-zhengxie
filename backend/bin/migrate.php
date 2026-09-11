#!/usr/bin/env php
<?php

/**
 * 建表 / 升级表结构。
 *
 *   php backend/bin/migrate.php                 # 按配置里的驱动执行未跑过的迁移
 *   php backend/bin/migrate.php --fresh         # 仅 SQLite：删库重建（本地开发用）
 *   php backend/bin/migrate.php --driver=mysql  # 指定驱动
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Support\Db;
use HechiZx\Support\Migrator;

$options = getopt('', ['driver::', 'database::', 'fresh', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "用法：php backend/bin/migrate.php [--driver=sqlite|mysql] [--database=<路径或库名>] [--fresh]\n");
    exit(0);
}

$config = hechi_config();
/** @var array<string, mixed> $dbConfig */
$dbConfig = (array) $config->get('db');
if (!empty($options['driver'])) {
    $dbConfig['driver'] = (string) $options['driver'];
}
if (!empty($options['database'])) {
    $dbConfig['database'] = (string) $options['database'];
}

if (isset($options['fresh']) && $dbConfig['driver'] === 'sqlite') {
    $path = (string) $dbConfig['database'];
    $storage = rtrim((string) $config->get('paths.storage'), '/') . '/';
    if (is_file($path) && str_starts_with($path, $storage)) {
        unlink($path);
        fwrite(STDOUT, "已删除本地 SQLite 文件：" . $path . "\n");
    }
}

$db = new Db($dbConfig);
$driverDir = rtrim((string) $config->get('paths.migrations'), '/') . '/' . $db->driver();
if (!is_dir($driverDir)) {
    fwrite(STDERR, "找不到迁移目录：" . $driverDir . "\n");
    exit(1);
}

$migrator = new Migrator($db, $driverDir);
$executed = $migrator->run();

fwrite(STDOUT, '驱动：' . $db->driver() . '　库：' . (string) $dbConfig['database'] . "\n");
fwrite(STDOUT, $executed === []
    ? "没有待执行的迁移（已是最新）\n"
    : '本次执行：' . implode('、', $executed) . "\n");

$tables = $db->isSqlite()
    ? $db->select("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")
    : $db->select('SHOW TABLES');
$names = array_map(static fn (array $row): string => (string) array_values($row)[0], $tables);
fwrite(STDOUT, '表数量：' . count($names) . '（' . implode('、', $names) . '）' . "\n");
