<?php

/**
 * PHP 内置服务器路由脚本（仅本地开发用），一个进程同时提供三样东西：
 *
 *   /api/v1/*   内容接口（backend/public/index.php）
 *   /admin*     内容管理后台（同上）
 *   /assets/*   /uploads/*   后台样式与上传文件（backend/public 下真实文件）
 *   其余路径     站点静态页（frontend/home，含 js/css/data/images）
 *
 * 用法：php -S 127.0.0.1:8080 -t backend/public backend/public/router.php
 * 打开 http://127.0.0.1:8080/ 就是站点首页，接口同源，前端无需跨域配置。
 */

declare(strict_types=1);

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) && $path !== '' ? $path : '/';

// 接口与后台交给唯一入口
if (str_starts_with($path, '/api/') || str_starts_with($path, '/admin')) {
    require __DIR__ . '/index.php';
    return;
}

// backend/public 下的真实文件（assets、uploads 等）交回内置服务器直出
$ownFile = realpath(__DIR__ . $path);
if ($ownFile !== false && is_file($ownFile)) {
    return false;
}

// 其余按站点静态文件处理：/ → index.html，找不到给 404
$frontendRoot = realpath(dirname(__DIR__, 2) . '/frontend/home');
$served = realpath(($frontendRoot ?: '') . ($path === '/' ? '/index.html' : $path));

if ($frontendRoot === false || $served === false
    || !str_starts_with($served, $frontendRoot) || !is_file($served)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found: ' . $path . "\n";
    return;
}

$types = [
    'html' => 'text/html; charset=utf-8',
    'js'   => 'text/javascript; charset=utf-8',
    'css'  => 'text/css; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'svg'  => 'image/svg+xml',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'ico'  => 'image/x-icon',
    'woff2' => 'font/woff2',
    'txt'  => 'text/plain; charset=utf-8',
    'xml'  => 'application/xml; charset=utf-8',
];
$extension = strtolower(pathinfo($served, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
readfile($served);
