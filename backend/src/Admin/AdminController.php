<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Content\ArticleWorkflow;
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
     * 权限校验：没有该权限码时给一个说清楚的 403 页面。
     */
    protected function requirePermission(string $permission): ?HtmlResponse
    {
        if ($this->auth->can($permission)) {
            return null;
        }
        return $this->view->page('admin/message', [
            'current' => '',
            'heading' => '没有这项权限',
            'message' => '当前账号不能执行这个操作（需要权限：' . $permission . '）。'
                . '请联系管理员在「系统管理 · 用户管理」里调整角色。',
            'backUrl' => '/admin',
        ], '没有这项权限', 403);
    }

    protected function can(string $permission): bool
    {
        return $this->auth->can($permission);
    }

    /** @return list<string> */
    protected function roleCodes(): array
    {
        return $this->auth->roleCodes();
    }

    /**
     * 当前账号在该稿件状态下能执行的动作：先过状态机，再过权限位。
     *
     * @return list<string>
     */
    protected function allowedActions(string $status): array
    {
        $current = ArticleWorkflow::normalize($status);
        $allowed = [];
        foreach (ArticleWorkflow::transitions() as $action => $rule) {
            if (!in_array($current, $rule['from'], true)) {
                continue;
            }
            $ok = $this->auth->can((string) $rule['perm']);
            if (!$ok && $rule['altPerm'] !== '') {
                $ok = $this->auth->can((string) $rule['altPerm']);
            }
            if ($ok) {
                $allowed[] = (string) $action;
            }
        }
        return $allowed;
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
