<?php

/**
 * 后台路由（会话登录 + CSRF）。
 * 由 backend/public/index.php 在路径以 /admin 开头时挂载。
 */

declare(strict_types=1);

use HechiZx\Admin\ArticleController;
use HechiZx\Admin\Auth;
use HechiZx\Admin\AuthController;
use HechiZx\Admin\ChannelController;
use HechiZx\Admin\DashboardController;
use HechiZx\Admin\PublishController;
use HechiZx\Admin\View;
use HechiZx\Http\Router;
use HechiZx\Repository\ArticleRepository;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Repository\HomeRepository;
use HechiZx\Support\Config;
use HechiZx\Support\Db;

return static function (Router $router, Db $db, Config $config): void {
    $siteId = (int) $config->get('site.site_id', 1);

    $auth = new Auth($db);
    $auth->startSession();

    $view = new View((string) $config->get('paths.templates'), [
        'siteName' => (string) $config->get('site.name'),
        'user'     => $auth->user(),
    ]);

    $channels = new ChannelRepository($db, $siteId, new HomeRepository($db, $siteId));
    $articles = new ArticleRepository($db, $siteId);

    $authController = new AuthController($auth, $view, $db, $siteId);
    $dashboard = new DashboardController(
        $auth,
        $view,
        $db,
        $siteId,
        $articles,
        $channels,
        (string) $config->get('publish.out')
    );
    $articleController = new ArticleController($auth, $view, $db, $siteId, $articles, $channels);
    $channelController = new ChannelController($auth, $view, $db, $siteId, $channels);
    $publishController = new PublishController(
        $auth,
        $view,
        $db,
        $siteId,
        (string) $config->get('publish.out'),
        (string) $config->get('paths.templates') . '/page.php',
        (string) $config->get('site.name'),
        (string) $config->get('site.domain')
    );

    $router->get('/admin', [$dashboard, 'index']);
    $router->get('/admin/login', [$authController, 'showLogin']);
    $router->post('/admin/login', [$authController, 'login']);
    $router->post('/admin/logout', [$authController, 'logout']);

    $router->get('/admin/articles', [$articleController, 'index']);
    $router->get('/admin/article/{id}', [$articleController, 'edit']);
    $router->post('/admin/article/{id}', [$articleController, 'update']);

    $router->get('/admin/channels', [$channelController, 'index']);
    $router->get('/admin/channel/{type}', [$channelController, 'edit']);
    $router->post('/admin/channel/{type}', [$channelController, 'update']);

    $router->post('/admin/publish', [$publishController, 'run']);
};
