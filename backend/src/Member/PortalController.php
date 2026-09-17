<?php

declare(strict_types=1);

namespace HechiZx\Member;

use HechiZx\Admin\Flash;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;

/**
 * 门户页与登录：/member 显示红头图 + 系统说明 + 登录表单；
 * 委员登录后进工作台（我的提案）。首登强制改密在 /member/password。
 */
final class PortalController extends MemberController
{
    public function home(Request $request): HtmlResponse|RedirectResponse
    {
        if ($this->auth->check()) {
            return new RedirectResponse($this->auth->mustChangePassword() ? '/member/password' : '/member/proposals');
        }

        // 门户首页自带完整文档（整幅底图 + 登录框 + 登录指南），不套后台式外壳
        return $this->view->bare('member/home', [
            'member' => null,
            'login'  => $request->query('login', '') ?? '',
        ], '首页');
    }

    public function login(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $result = $this->auth->attempt($request->post('login_name'), (string) ($_POST['password'] ?? ''));
        if (!$result['ok']) {
            Flash::set('error', $result['message']);
            // 回登录页时把登录名带回去，委员不用重打一遍
            return new RedirectResponse('/member/login?login=' . urlencode($request->post('login_name')));
        }

        Flash::set('ok', '登录成功，欢迎回来。');
        return new RedirectResponse($this->auth->mustChangePassword() ? '/member/password' : '/member/proposals');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->auth->logout();
        Flash::set('ok', '已退出登录。');

        return new RedirectResponse('/member');
    }

    public function passwordForm(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        // 平时不提供自助改密（提案委口径：改密只在首登做一次，忘记密码一律线下重置）
        if (!$this->auth->mustChangePassword()) {
            Flash::set('error', '系统不提供自助修改密码。忘记密码请联系提案委线下重置。');
            return new RedirectResponse('/member/proposals');
        }

        return $this->view->page('member/password', [
            'member'   => $this->member(),
            'required' => $this->auth->mustChangePassword(),
        ], '修改密码');
    }

    public function passwordSave(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        if (!$this->auth->mustChangePassword()) {
            Flash::set('error', '系统不提供自助修改密码。忘记密码请联系提案委线下重置。');
            return new RedirectResponse('/member/proposals');
        }

        $result = $this->auth->changePassword(
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        Flash::set($result['ok'] ? 'ok' : 'error', $result['message']);
        if (!$result['ok']) {
            return new RedirectResponse('/member/password');
        }

        return new RedirectResponse('/member/proposals');
    }
}
