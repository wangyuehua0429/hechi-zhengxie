<?php

/**
 * PHP 内置服务器路由脚本（仅本地开发用）：
 *   php -S 127.0.0.1:8080 -t backend/public backend/public/router.php
 * 真实存在的静态文件直出，其余请求全部交给 public/index.php。
 */

declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . (is_string($path) ? $path : '/');

if (is_string($path) && $path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
