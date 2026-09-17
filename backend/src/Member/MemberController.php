<?php

declare(strict_types=1);

namespace HechiZx\Member;

use HechiZx\Admin\Csrf;
use HechiZx\Admin\Flash;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Repository\MemberRepository;
use HechiZx\Repository\ProposalRepository;
use HechiZx\Repository\UnitRepository;
use HechiZx\Support\Db;

/**
 * 委员门户控制器基类：登录校验 + 首登强制改密 + CSRF，子类只写业务。
 */
abstract class MemberController
{
    /** 附件白名单与上限（与后台附件同一口径） */
    protected const ATTACHMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', 'txt'];
    protected const ATTACHMENT_MAX_BYTES = 33554432;
    protected const ATTACHMENT_MAX_COUNT = 5;

    public function __construct(
        protected MemberAuth $auth,
        protected MemberView $view,
        protected Db $db,
        protected int $siteId,
        protected MemberRepository $members,
        protected ProposalRepository $proposals,
        protected UnitRepository $units,
        protected string $storageRoot = ''
    ) {
    }

    protected function requireLogin(): ?RedirectResponse
    {
        if (!$this->auth->check()) {
            Flash::set('error', '请先登录。');
            return new RedirectResponse('/member/login');
        }

        return null;
    }

    /** 首次登录（或管理员重置密码后）必须改密，否则不能进业务页 */
    protected function requirePasswordChanged(): ?RedirectResponse
    {
        if ($this->auth->mustChangePassword()) {
            Flash::set('error', '首次登录请先修改密码。');
            return new RedirectResponse('/member/password');
        }

        return null;
    }

    /** 表单令牌校验 */
    protected function guard(Request $request, string $message = '页面已过期，请返回重新提交。'): ?HtmlResponse
    {
        if (Csrf::check($request->post('_token'))) {
            return null;
        }

        return $this->view->page('member/message', [
            'member'  => $this->auth->member(),
            'heading' => '提交被拒绝',
            'message' => $message,
            'backUrl' => '/member/proposals',
        ], '提交被拒绝', 400);
    }

    /** @return array<string, mixed> */
    protected function member(): array
    {
        return $this->auth->member() ?? ['member_id' => 0, 'name' => '', 'sector' => '', 'committee' => '', 'mobile' => ''];
    }

    protected function proposalStorage(): string
    {
        return rtrim($this->storageRoot, '/') . '/proposals';
    }
}
