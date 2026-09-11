<?php

/**
 * 运行配置：本地默认走 SQLite（不装 Docker 也能跑通全链路），生产按环境变量切 MySQL 8。
 * 变量清单见 .env.example；本文件不写死任何口令。
 */

declare(strict_types=1);

$backendRoot = dirname(__DIR__);
$repoRoot = dirname($backendRoot);

return [
    'app' => [
        'name'  => '河池政协网 CMS',
        'env'   => getenv('APP_ENV') ?: 'local',
        'debug' => filter_var(getenv('APP_DEBUG') ?: '1', FILTER_VALIDATE_BOOL),
    ],

    // 多站点预留：本期只启用主站，子站按 site_id 区分
    'site' => [
        'site_id' => (int) (getenv('SITE_ID') ?: 1),
        'code'    => getenv('SITE_CODE') ?: 'main',
        'name'    => getenv('SITE_NAME') ?: '广西河池政协网',
        'domain'  => getenv('SITE_DOMAIN') ?: 'www.gxhczx.gov.cn',
    ],

    'db' => [
        'driver'   => getenv('DB_DRIVER') ?: 'sqlite',          // sqlite | mysql
        'database' => getenv('DB_DATABASE') ?: $backendRoot . '/storage/hechi_zx.sqlite',
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => (int) (getenv('DB_PORT') ?: 3306),
        'username' => getenv('DB_USERNAME') ?: 'hechi_zx',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset'  => 'utf8mb4',
    ],

    'paths' => [
        'backend'    => $backendRoot,
        'repo'       => $repoRoot,
        'storage'    => $backendRoot . '/storage',
        'templates'  => $backendRoot . '/templates',
        'public'     => $backendRoot . '/public',
        'uploads'    => $backendRoot . '/public/uploads',
        'migrations' => $repoRoot . '/database/migrations',
        // 阶段 A 的静态快照就是数据契约原型：灌库、对拍、发布取同一份
        'snapshots'  => $repoRoot . '/frontend/home/data',
    ],

    'publish' => [
        'out' => getenv('PUBLISH_OUT') ?: $backendRoot . '/storage/publish',
    ],

    'api' => [
        'default_page_size' => 20,
        'max_page_size'     => 100,
        'cache_max_age'     => 300,
    ],
];
