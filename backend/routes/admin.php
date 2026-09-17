<?php

/**
 * 后台路由（会话登录 + CSRF）。
 * 由 backend/public/index.php 在路径以 /admin 开头时挂载。
 */

declare(strict_types=1);

use HechiZx\Admin\ArticleController;
use HechiZx\Admin\Auth;
use HechiZx\Admin\AuthController;
use HechiZx\Admin\BannerController;
use HechiZx\Admin\ChannelController;
use HechiZx\Admin\DashboardController;
use HechiZx\Admin\HealthController;
use HechiZx\Admin\LogController;
use HechiZx\Admin\MemberController;
use HechiZx\Admin\NavController;
use HechiZx\Admin\NoticeController;
use HechiZx\Admin\ProposalController;
use HechiZx\Admin\PublishController;
use HechiZx\Admin\RoleController;
use HechiZx\Admin\SectionController;
use HechiZx\Admin\SlideController;
use HechiZx\Admin\UserController;
use HechiZx\Admin\View;
use HechiZx\Http\Router;
use HechiZx\Repository\ArticleRepository;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Repository\HomeRepository;
use HechiZx\Repository\MemberRepository;
use HechiZx\Repository\ProposalRepository;
use HechiZx\Proposal\MemberImporter;
use HechiZx\Support\Config;
use HechiZx\Support\Db;

return static function (Router $router, Db $db, Config $config): void {
    $siteId = (int) $config->get('site.site_id', 1);

    $auth = new Auth($db);
    $auth->startSession();

    $view = new View((string) $config->get('paths.templates'), [
        'siteName'  => (string) $config->get('site.name'),
        'user'      => $auth->user(),
        'userRoleNames' => array_map(static fn (array $role): string => (string) $role['name'], $auth->roles()),
        'userPerms'     => $auth->permissions(),
    ]);

    $home = new HomeRepository($db, $siteId);
    $channels = new ChannelRepository($db, $siteId, $home);
    $articles = new ArticleRepository($db, $siteId);
    $uploadsDir = (string) $config->get('paths.uploads');

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
    $articleController = new ArticleController(
        $auth,
        $view,
        $db,
        $siteId,
        $articles,
        $channels,
        (string) $config->get('paths.uploads')
    );
    $channelController = new ChannelController($auth, $view, $db, $siteId, $channels);
    $navController = new NavController($auth, $view, $db, $siteId, $home, $channels);
    $noticeController = new NoticeController($auth, $view, $db, $siteId, $home);
    $slideController = new SlideController($auth, $view, $db, $siteId, $home, $articles, $uploadsDir);
    $sectionController = new SectionController($auth, $view, $db, $siteId, $home, $channels);
    $bannerController = new BannerController($auth, $view, $db, $siteId, $home, $uploadsDir);
    $userController = new UserController($auth, $view, $db, $siteId);
    $roleController = new RoleController($auth, $view, $db, $siteId, $channels);
    $logController = new LogController($auth, $view, $db, $siteId);
    $healthController = new HealthController($auth, $view, $db, $siteId, $config);
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
    $proposals = new ProposalRepository($db, $siteId);
    $members = new MemberRepository($db);
    $proposalController = new ProposalController(
        $auth,
        $view,
        $db,
        $siteId,
        $proposals,
        (string) $config->get('paths.storage')
    );
    $memberController = new MemberController(
        $auth,
        $view,
        $db,
        $siteId,
        $members,
        new MemberImporter($members)
    );

    $router->get('/admin', [$dashboard, 'index']);
    $router->get('/admin/health', [$healthController, 'index']);
    $router->get('/admin/login', [$authController, 'showLogin']);
    $router->post('/admin/login', [$authController, 'login']);
    $router->post('/admin/logout', [$authController, 'logout']);

    $router->get('/admin/articles', [$articleController, 'index']);
    $router->post('/admin/articles/bulk', [$articleController, 'bulk']);
    $router->post('/admin/article/{id}/order', [$articleController, 'order']);
    $router->post('/admin/article/{id}/top', [$articleController, 'top']);
    $router->post('/admin/article/{id}/flags', [$articleController, 'flags']);
    $router->get('/admin/article/new', [$articleController, 'createForm']);
    $router->post('/admin/article/create', [$articleController, 'store']);
    $router->get('/admin/article/{id}', [$articleController, 'edit']);
    $router->post('/admin/article/{id}', [$articleController, 'update']);
    $router->get('/admin/article/{id}/delete', [$articleController, 'deleteConfirm']);
    $router->post('/admin/article/{id}/delete', [$articleController, 'delete']);
    $router->post('/admin/article/{id}/flow', [$articleController, 'flow']);
    $router->post('/admin/article/{id}/attachment', [$articleController, 'uploadAttachment']);
    $router->post('/admin/article/{id}/attachment/{aid}/delete', [$articleController, 'deleteAttachment']);
    $router->post('/admin/article/{id}/image', [$articleController, 'uploadImage']);
    $router->post('/admin/media/image', [$articleController, 'uploadImageMedia']);
    $router->post('/admin/media/video', [$articleController, 'uploadVideoMedia']);

    $router->get('/admin/channels', [$channelController, 'index']);
    // 必须排在 /admin/channel/{type} 前面：路由首条匹配生效，否则 new/create 会被当成栏目号
    $router->get('/admin/channel/new', [$channelController, 'createForm']);
    $router->post('/admin/channel/create', [$channelController, 'store']);
    $router->post('/admin/channel/{type}/move', [$channelController, 'move']);
    $router->get('/admin/channel/{type}/delete', [$channelController, 'deleteConfirm']);
    $router->post('/admin/channel/{type}/delete', [$channelController, 'delete']);
    $router->get('/admin/channel/{type}', [$channelController, 'edit']);
    $router->post('/admin/channel/{type}', [$channelController, 'update']);

    $router->get('/admin/nav', [$navController, 'index']);
    $router->post('/admin/nav/{index}/move', [$navController, 'move']);
    $router->post('/admin/nav/{index}', [$navController, 'update']);

    $router->get('/admin/notice', [$noticeController, 'edit']);
    $router->post('/admin/notice', [$noticeController, 'update']);

    $router->get('/admin/slides', [$slideController, 'index']);
    $router->post('/admin/slides/create', [$slideController, 'create']);
    // 必须排在 /admin/slides/{id} 前面：路由是首条匹配生效，否则 order 会被当成 id
    $router->post('/admin/slides/order', [$slideController, 'reorder']);
    $router->post('/admin/slides/{id}', [$slideController, 'update']);
    $router->post('/admin/slides/{id}/move', [$slideController, 'move']);
    $router->post('/admin/slides/{id}/status', [$slideController, 'toggle']);
    $router->post('/admin/slides/{id}/delete', [$slideController, 'delete']);

    $router->get('/admin/sections', [$sectionController, 'index']);
    $router->post('/admin/section/{key}', [$sectionController, 'update']);

    $router->get('/admin/banners', [$bannerController, 'index']);
    $router->post('/admin/banner/{slot}', [$bannerController, 'update']);

    $router->get('/admin/users', [$userController, 'index']);
    $router->get('/admin/user/new', [$userController, 'createForm']);
    $router->post('/admin/user/create', [$userController, 'store']);
    $router->get('/admin/user/{user_id}', [$userController, 'edit']);
    $router->post('/admin/user/{user_id}', [$userController, 'update']);

    $router->get('/admin/roles', [$roleController, 'index']);
    $router->get('/admin/role/{role_id}', [$roleController, 'edit']);
    $router->post('/admin/role/{role_id}', [$roleController, 'update']);

    $router->get('/admin/logs', [$logController, 'index']);

    $router->get('/admin/proposals', [$proposalController, 'index']);
    // 固定路径排在带参数的路由前面：路由表首条匹配生效
    $router->get('/admin/proposals/export.xlsx', [$proposalController, 'exportXlsx']);
    $router->get('/admin/proposal/{id}/word', [$proposalController, 'word']);
    $router->get('/admin/proposal/{id}/attachment/{aid}', [$proposalController, 'attachment']);
    $router->post('/admin/proposal/{id}/accept', [$proposalController, 'accept']);
    $router->post('/admin/proposal/{id}/return', [$proposalController, 'returnBack']);
    $router->get('/admin/proposal/{id}', [$proposalController, 'show']);

    $router->get('/admin/members', [$memberController, 'index']);
    $router->get('/admin/members/import', [$memberController, 'importForm']);
    $router->post('/admin/members/import', [$memberController, 'importRun']);
    $router->post('/admin/members/create', [$memberController, 'create']);
    $router->get('/admin/members/import/template.csv', [$memberController, 'importTemplate']);
    $router->get('/admin/members/credentials.csv', [$memberController, 'credentials']);
    $router->post('/admin/member/{id}/status', [$memberController, 'toggleStatus']);
    $router->post('/admin/member/{id}/password', [$memberController, 'resetPassword']);

    $router->post('/admin/publish', [$publishController, 'run']);
};
