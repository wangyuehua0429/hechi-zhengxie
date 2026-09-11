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
    /** 首页横幅与头条上传的大图：与稿件图共用同一套限制 */
    protected const HOME_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    protected const HOME_UPLOAD_MAX_BYTES = 33554432;

    public function __construct(
        protected Auth $auth,
        protected View $view,
        protected Db $db,
        protected int $siteId,
        protected string $uploadsRoot = ''
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

    /**
     * 首页素材上传（头条大图、横幅）：统一落到 uploads/home/，返回对外地址。
     * 失败抛 RuntimeException，消息可以直接给编辑看。
     *
     * @param array<string, mixed> $file
     */
    protected function storeHomeImage(array $file): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('上传中断（错误码 ' . $error . '）');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new \RuntimeException('文件为空');
        }
        if ($size > self::HOME_UPLOAD_MAX_BYTES) {
            throw new \RuntimeException('文件超过 32 MB 上限');
        }

        $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, self::HOME_IMAGE_EXTENSIONS, true)) {
            throw new \RuntimeException('图片只支持 jpg／jpeg／png／gif／webp');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('临时文件不可用');
        }

        $dir = rtrim($this->uploadsRoot, '/') . '/home';
        if ($this->uploadsRoot === '' || (!is_dir($dir) && !mkdir($dir, 0775, true))) {
            throw new \RuntimeException('无法创建上传目录');
        }

        $name = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('保存图片失败');
        }
        @chmod($dir . '/' . $name, 0644);

        return '/uploads/home/' . $name;
    }
}
