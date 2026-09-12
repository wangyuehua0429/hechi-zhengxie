<?php

/**
 * 接口入口（唯一 Web 入口）：Nginx 或 PHP 内置服务器都把 /api/v1/* 指到这里。
 * 静态化页面由发布器产出到 publish 目录，Nginx 直出，不经过本入口。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Http\ApiException;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\LegacyRedirect;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Http\Response;
use HechiZx\Http\Router;
use HechiZx\Publish\RedirectMap;
use HechiZx\Support\Db;

$config = hechi_config();
$request = Request::fromGlobals();

try {
    $db = new Db($config->get('db'));
} catch (Throwable $e) {
    Response::error('internal_error', '数据库连接失败，请检查 .env 与数据库实例', 500);
    exit;
}

$router = new Router();
(require dirname(__DIR__) . '/routes/api.php')($router, $db, $config);

$isAdmin = str_starts_with($request->path(), '/admin');
if ($isAdmin) {
    (require dirname(__DIR__) . '/routes/admin.php')($router, $db, $config);
}

// 旧地址 301：Nginx 把旧路径形态转到这里（规则见发布目录 redirects/nginx-301.conf），
// 命中 sys_url_redirect 的精确映射就跳转并累计命中数，未命中继续往下走 404。
if (!$isAdmin && !str_starts_with($request->path(), '/api/')) {
    $legacy = new LegacyRedirect(new RedirectMap($db, (int) $config->get('site.site_id', 1)));
    $redirect = $legacy->handle($request);
    if ($redirect !== null) {
        $redirect->send();
        exit;
    }
}

try {
    $result = $router->dispatch($request);

    if ($result instanceof HtmlResponse || $result instanceof RedirectResponse) {
        $result->send();
    } else {
        $maxAge = $request->path() === '/api/v1/health' ? 0 : (int) $config->get('api.cache_max_age', 0);
        Response::json(is_array($result) ? $result : [], 200, $maxAge);
    }
} catch (ApiException $e) {
    if ($isAdmin) {
        $view = new HechiZx\Admin\View((string) $config->get('paths.templates'), [
            'siteName' => (string) $config->get('site.name'),
            'user'     => null,
        ]);
        $view->page('admin/message', [
            'current' => '',
            'heading' => $e->status() === 404 ? '页面不存在' : '操作未完成',
            'message' => $e->getMessage(),
            'backUrl' => '/admin',
        ], '提示')->send();
    } else {
        Response::error($e->errorCode(), $e->getMessage(), $e->status());
    }
} catch (Throwable $e) {
    $debug = (bool) $config->get('app.debug', false);
    Response::error('internal_error', $debug ? $e->getMessage() : '服务内部错误', 500);
}
