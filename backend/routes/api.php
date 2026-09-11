<?php

/**
 * 对外只读接口路由表（v1）。写接口（后台）另开 routes/admin.php，鉴权后并入。
 * 契约见 docs/api-contract.md 第 4 节。
 */

declare(strict_types=1);

use HechiZx\Api\ArticleController;
use HechiZx\Api\ChannelController;
use HechiZx\Api\HealthController;
use HechiZx\Api\HomeController;
use HechiZx\Api\SearchController;
use HechiZx\Http\Router;
use HechiZx\Repository\ArticleRepository;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Repository\HomeRepository;
use HechiZx\Support\Config;
use HechiZx\Support\Db;

return static function (Router $router, Db $db, Config $config): void {
    $siteId = (int) $config->get('site.site_id', 1);
    $defaultSize = (int) $config->get('api.default_page_size', 20);
    $maxSize = (int) $config->get('api.max_page_size', 100);

    $health = new HealthController($db);
    $home = new HomeController(new HomeRepository($db, $siteId));
    $channels = new ChannelController(new ChannelRepository($db, $siteId, new HomeRepository($db, $siteId)));
    $articles = new ArticleController(new ArticleRepository($db, $siteId), $defaultSize, $maxSize);
    $search = new SearchController(new ArticleRepository($db, $siteId), $defaultSize, $maxSize);

    $router->get('/api/v1/health', [$health, 'show']);
    $router->get('/api/v1/home', [$home, 'show']);
    $router->get('/api/v1/channels', [$channels, 'index']);
    $router->get('/api/v1/channels/{type}', [$channels, 'show']);
    $router->get('/api/v1/articles', [$articles, 'index']);
    $router->get('/api/v1/article/{id}', [$articles, 'show']);
    $router->get('/api/v1/article/{id}/attachments', [$articles, 'attachments']);
    $router->get('/api/v1/attachments', [$articles, 'attachmentsByQuery']);
    $router->get('/api/v1/search', [$search, 'index']);
};
