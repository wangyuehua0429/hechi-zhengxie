<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Support\Db;

/**
 * 后台控制器基类：登录校验 + CSRF 校验 + 当前用户，子类只写业务。
 */
abstract class AdminController
{
    public function __construct(
        protected Auth $auth,
        protected View $view,
        protected Db $db,
        protected int $siteId
    ) {
    }

    /**
     * 未登录返回跳转响应，已登录返回 null。
     */
    protected function requireLogin(): ?RedirectResponse
    {
        return $this->auth->check() ? null : new RedirectResponse('/admin/login');
    }

    /**
     * 校验表单令牌与登录态；不通过时返回统一的提示页。
     */
    protected function guard(Request $request, string $message = '页面已过期，请返回重新提交。'): ?HtmlResponse
    {
        if (!Csrf::check($request->post('_token'))) {
            return $this->view->page('admin/message', [
                'current' => '',
                'heading' => '提交被拒绝',
                'message' => $message,
                'backUrl' => '/admin',
            ], '提交被拒绝', 400);
        }
        return null;
    }

    /** @return array<string, mixed> */
    protected function user(): array
    {
        return $this->auth->user() ?? ['user_id' => 0, 'username' => '', 'real_name' => ''];
    }

    protected function log(string $action, string $targetType = '', string $targetId = '', array $detail = []): void
    {
        $this->auth->log((int) ($this->user()['user_id'] ?? 0), $action, $targetType, $targetId, $detail);
    }
}
