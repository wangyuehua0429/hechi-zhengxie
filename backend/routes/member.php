<?php

/**
 * 委员门户路由（/member/*）：会话独立（session_name = zx_member），
 * 页面全部服务端渲染，不对外开放任何接口。设计与字段说明见 docs/提案系统设计说明.md。
 */

declare(strict_types=1);

use HechiZx\Http\Router;
use HechiZx\Member\MemberAuth;
use HechiZx\Member\MemberView;
use HechiZx\Member\PortalController;
use HechiZx\Member\ProposalController;
use HechiZx\Repository\MemberRepository;
use HechiZx\Repository\ProposalRepository;
use HechiZx\Support\Config;
use HechiZx\Support\Db;

return static function (Router $router, Db $db, Config $config): void {
    $siteId = (int) $config->get('site.site_id', 1);
    $storageRoot = (string) $config->get('paths.storage', '');

    $members = new MemberRepository($db);
    $proposals = new ProposalRepository($db, $siteId);

    $auth = new MemberAuth($db, $members);
    $auth->startSession();

    $view = new MemberView((string) $config->get('paths.templates'), [
        'siteName'     => (string) $config->get('site.name'),
        'contactPhone' => (string) $config->get('site.contact_phone', ''),
        'member'       => $auth->member(),
    ]);

    $portal = new PortalController($auth, $view, $db, $siteId, $members, $proposals, $storageRoot);
    $items = new ProposalController($auth, $view, $db, $siteId, $members, $proposals, $storageRoot);

    $router->get('/member', [$portal, 'home']);
    $router->get('/member/login', [$portal, 'home']);
    $router->post('/member/login', [$portal, 'login']);
    $router->post('/member/logout', [$portal, 'logout']);
    $router->get('/member/password', [$portal, 'passwordForm']);
    $router->post('/member/password', [$portal, 'passwordSave']);

    $router->get('/member/proposals', [$items, 'index']);
    // 固定路径要排在带参数的路由前面：路由表首条匹配生效
    $router->get('/member/proposal/new', [$items, 'createForm']);
    $router->post('/member/proposal/create', [$items, 'create']);
    $router->get('/member/proposal/{id}/edit', [$items, 'editForm']);
    $router->post('/member/proposal/{id}/submit', [$items, 'submit']);
    $router->get('/member/proposal/{id}/word', [$items, 'word']);
    $router->get('/member/proposal/{id}/attachment/{aid}', [$items, 'attachment']);
    $router->get('/member/proposal/{id}', [$items, 'show']);
};
