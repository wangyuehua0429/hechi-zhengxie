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
        // PHP 默认时区：不设会走 php.ini 的 UTC，与库里按本地时间写入的
        // 时间戳差 8 小时（后台「上次发布」显示 10:23 而实际是 18:23 就是这个原因）
        'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Shanghai',
    ],

    // 多站点预留：本期只启用主站，子站按 site_id 区分
    'site' => [
        'site_id' => (int) (getenv('SITE_ID') ?: 1),
        'code'    => getenv('SITE_CODE') ?: 'main',
        'name'    => getenv('SITE_NAME') ?: '广西河池政协网',
        'domain'  => getenv('SITE_DOMAIN') ?: 'www.gxhczx.gov.cn',
        // 委员门户登录页的对外联系电话：默认取官网页脚公开的「联系电话」
        // （frontend/home/data/home.json 的 meta.contactPhone），可用环境变量覆盖
        'contact_phone' => getenv('SITE_CONTACT_PHONE') ?: '0778-2320180',
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
        // 默认不缓存：内容随时可能被后台改动，缓存会让"刚发的稿件看不到"。
        // 上 Redis / CDN 并做了发布即失效之后再调大（见 docs/api-contract.md 第 2 节）。
        'cache_max_age'     => (int) (getenv('API_CACHE_MAX_AGE') ?: 0),
    ],
];
