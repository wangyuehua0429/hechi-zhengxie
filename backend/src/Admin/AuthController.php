<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Support\Db;

/**
 * 登录 / 退出。
 */
final class AuthController extends AdminController
{
    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function showLogin(Request $request): HtmlResponse|RedirectResponse
    {
        if ($this->auth->check()) {
            return new RedirectResponse('/admin');
        }
        return $this->view->page('admin/login', [
            'current'    => '',
            'error'      => '',
            'hasAccount' => $this->hasAccount(),
        ], '登录后台');
    }

    public function login(Request $request): HtmlResponse|RedirectResponse
    {
        if ($this->auth->check()) {
            return new RedirectResponse('/admin');
        }
        if (!Csrf::check($request->post('_token'))) {
            return $this->loginError('页面已过期，请重新登录。');
        }

        $username = $request->post('username');
        $password = $request->post('password');
        if ($username === '' || $password === '') {
            return $this->loginError('请填写账号与密码。');
        }
        if (!$this->auth->attempt($username, $password)) {
            usleep(300000);   // 失败也走一次固定的轻微延时，削弱撞库
            return $this->loginError('账号或密码不正确。');
        }

        Flash::set('ok', '已登录，欢迎回来。');
        return new RedirectResponse('/admin');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->auth->logout();
        return new RedirectResponse('/admin/login');
    }

    private function loginError(string $message): HtmlResponse
    {
        return $this->view->page('admin/login', [
            'current'    => '',
            'error'      => $message,
            'hasAccount' => $this->hasAccount(),
        ], '登录后台');
    }

    private function hasAccount(): bool
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM sys_user') > 0;
    }
}
