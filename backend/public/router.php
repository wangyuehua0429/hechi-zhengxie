<?php

/**
 * PHP 内置服务器路由脚本（仅本地开发用），一个进程同时提供三样东西：
 *
 *   /api/v1/*   内容接口（backend/public/index.php）
 *   /admin*     内容管理后台（同上）
 *   /assets/*   /uploads/*   后台样式与上传文件（backend/public 下真实文件）
 *   其余路径     站点静态页（frontend/home，含 js/css/data/images）
 *   /article/ /channel/ /sitemap.xml   静态化发布产物（与部署时的 Nginx location 一致）
 *   旧地址       静态页里没有的，查 sys_url_redirect 命中后 301（见 backend/bin/redirects.php）
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

// 引导放在上面那条之后：index.php 自己也 require 了 bootstrap.php，
// 若在它之前 require_once，随后那句 require 会重复定义 hechi_config()。
require_once dirname(__DIR__) . '/src/bootstrap.php';

// backend/public 下的真实文件（assets、uploads 等）交回内置服务器直出
$ownFile = realpath(__DIR__ . $path);
if ($ownFile !== false && is_file($ownFile)) {
    return false;
}

// 静态化产物：与 deploy/nginx/default.conf 的 location 对齐，本地也能验证 301 落点
$publishRoot = '';
if (str_starts_with($path, '/article/') || str_starts_with($path, '/channel/') || $path === '/sitemap.xml') {
    $publishRoot = realpath((string) hechi_config('publish.out', ''));
}

// 其余按站点静态文件处理：/ → index.html，找不到给 404
$frontendRoot = realpath(dirname(__DIR__, 2) . '/frontend/home');
$served = $frontendRoot === false ? false : realpath($frontendRoot . ($path === '/' ? '/index.html' : $path));
if (($served === false || !is_file($served)) && $publishRoot !== false && $publishRoot !== '') {
    // /channel/<目录名>/ 与 /channel/<目录名> 都对应 .../index.html
    foreach ([$path, rtrim($path, '/') . '/index.html'] as $relative) {
        $candidate = realpath($publishRoot . $relative);
        if ($candidate !== false && is_file($candidate) && str_starts_with($candidate, $publishRoot)) {
            $served = $candidate;
            break;
        }
    }
}

if ($frontendRoot === false || $served === false
    || (!str_starts_with($served, $frontendRoot)
        && ($publishRoot === false || $publishRoot === '' || !str_starts_with($served, $publishRoot)))
    || !is_file($served)) {
    // 旧地址 301：只在文件确实不存在时才连库，正常静态请求不受影响
    try {
        $db = new HechiZx\Support\Db((array) hechi_config('db'));
        $legacy = new HechiZx\Http\LegacyRedirect(
            new HechiZx\Publish\RedirectMap($db, (int) hechi_config('site.site_id', 1))
        );
        $redirect = $legacy->handle(HechiZx\Http\Request::fromGlobals());
        if ($redirect !== null) {
            $redirect->send();
            return;
        }
    } catch (Throwable $e) {
        // 映射表不可用不该把 404 变成 500：照旧给 404
    }

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
