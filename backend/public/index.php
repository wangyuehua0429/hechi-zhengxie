<?php

/**
 * 接口入口（唯一 Web 入口）：Nginx 或 PHP 内置服务器都把 /api/v1/* 指到这里。
 * 静态化页面由发布器产出到 publish 目录，Nginx 直出，不经过本入口。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Http\ApiException;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Http\Response;
use HechiZx\Http\Router;
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

try {
    $result = $router->dispatch($request);

    if ($result instanceof HtmlResponse || $result instanceof RedirectResponse) {
        $result->send();
    } else {
        $maxAge = $request->path() === '/api/v1/health' ? 0 : (int) $config->get('api.cache_max_age', 300);
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
