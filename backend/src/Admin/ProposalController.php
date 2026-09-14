<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Content\ProposalWorkflow;
use HechiZx\Http\FileResponse;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Proposal\WordExporter;
use HechiZx\Proposal\XlsxExporter;
use HechiZx\Repository\ProposalRepository;
use HechiZx\Support\Db;

/**
 * 后台提案收件：列表与筛选、详情、受理、退回补充、导出 Excel／Word、附件下载。
 * 权限：proposal.view 看，proposal.review 办，proposal.export 导出。
 */
final class ProposalController extends AdminController
{
    private const PAGE_SIZE = 20;
    private const EXPORT_LIMIT = 3000;

    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private ProposalRepository $proposals,
        private string $storageRoot = ''
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::PROPOSAL_VIEW)) {
            return $denied;
        }

        $filters = $this->filters($request);
        $page = max(1, $request->int('page', 1, 1));
        $result = $this->proposals->adminList($filters, $page, self::PAGE_SIZE);

        return $this->view->page('admin/proposals', [
            'current'  => 'proposals',
            'rows'     => $result['rows'],
            'total'    => $result['total'],
            'page'     => $page,
            'pages'    => max(1, (int) ceil($result['total'] / self::PAGE_SIZE)),
            'filters'  => $filters,
            'counts'   => $this->proposals->statusCounts(),
            'categories' => ProposalWorkflow::categories(),
            'statuses' => ProposalWorkflow::places(),
            'canExport' => $this->can(Permissions::PROPOSAL_EXPORT),
        ], '提案收件');
    }

    /** @param array<string, string> $args */
    public function show(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::PROPOSAL_VIEW)) {
            return $denied;
        }

        $proposal = $this->proposals->find((int) $args['id']);
        if ($proposal === null) {
            return $this->notFound();
        }
        $proposalId = (int) $proposal['proposal_id'];

        return $this->view->page('admin/proposal_show', [
            'current'     => 'proposals',
            'proposal'    => $proposal,
            'attachments' => $this->proposals->attachments($proposalId),
            'logs'        => $this->proposals->logs($proposalId),
            'member'      => $this->db->selectOne(
                'SELECT * FROM sys_member WHERE member_id = :id',
                ['id' => (int) $proposal['member_id']]
            ),
            'actions'  => ProposalWorkflow::allowedActions(
                (string) $proposal['status'],
                fn (string $perm): bool => $this->can($perm)
            ),
            'canExport' => $this->can(Permissions::PROPOSAL_EXPORT),
        ], '提案详情');
    }

    /** @param array<string, string> $args */
    public function accept(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::PROPOSAL_REVIEW)) {
            return $denied;
        }

        $proposal = $this->proposals->find((int) $args['id']);
        if ($proposal === null) {
            return $this->notFound();
        }
        $status = ProposalWorkflow::normalize((string) $proposal['status']);
        if ($status === ProposalWorkflow::RETURNED) {
            Flash::set('error', '提案已被退回，请等待委员修改后重新提交。');
            return new RedirectResponse('/admin/proposal/' . (int) $proposal['proposal_id']);
        }
        if ($status === ProposalWorkflow::ACCEPTED) {
            Flash::set('error', '该提案已经受理过。');
            return new RedirectResponse('/admin/proposal/' . (int) $proposal['proposal_id']);
        }

        $note = $request->post('review_note');
        $this->proposals->accept((int) $proposal['proposal_id'], (int) $this->user()['user_id'], $note);
        $this->proposals->writeLog(
            (int) $proposal['proposal_id'],
            'staff',
            (int) $this->user()['user_id'],
            'accept',
            $note
        );
        $this->log('proposal.accept', 'proposal', (string) $proposal['proposal_id'], ['title' => (string) $proposal['title']]);

        Flash::set('ok', '已受理该提案。');
        return new RedirectResponse('/admin/proposal/' . (int) $proposal['proposal_id']);
    }

    /** @param array<string, string> $args */
    public function returnBack(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::PROPOSAL_REVIEW)) {
            return $denied;
        }

        $proposal = $this->proposals->find((int) $args['id']);
        if ($proposal === null) {
            return $this->notFound();
        }
        $reason = $request->post('returned_reason');
        if ($reason === '') {
            Flash::set('error', '退回应写明意见，委员需要据此修改。');
            return new RedirectResponse('/admin/proposal/' . (int) $proposal['proposal_id']);
        }

        $this->proposals->returnBack((int) $proposal['proposal_id'], (int) $this->user()['user_id'], $reason);
        $this->proposals->writeLog(
            (int) $proposal['proposal_id'],
            'staff',
            (int) $this->user()['user_id'],
            'return',
            $reason
        );
        $this->log('proposal.return', 'proposal', (string) $proposal['proposal_id'], ['reason' => $reason]);

        Flash::set('ok', '已退回，委员可在门户里看到意见并修改后重新提交。');
        return new RedirectResponse('/admin/proposal/' . (int) $proposal['proposal_id']);
    }

    /** 收件清单导出（.xlsx），按当前筛选条件 */
    public function exportXlsx(Request $request): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::PROPOSAL_EXPORT)) {
            return $denied;
        }

        $filters = $this->filters($request);
        $rows = $this->proposals->exportRows($filters, self::EXPORT_LIMIT);
        $binary = XlsxExporter::proposalList($rows);
        $this->log('proposal.export', 'proposal', '', ['count' => count($rows), 'filters' => $filters]);

        return new FileResponse(
            $binary,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            '提案收件清单-' . date('Ymd-His') . '.xlsx'
        );
    }

    /** @param array<string, string> $args */
    public function word(Request $request, array $args): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::PROPOSAL_EXPORT)) {
            return $denied;
        }

        $proposal = $this->proposals->find((int) $args['id']);
        if ($proposal === null) {
            return $this->notFound();
        }

        $binary = WordExporter::proposal($proposal, $this->proposals->attachments((int) $proposal['proposal_id']));
        $name = '提案-' . (int) $proposal['proposal_id'] . '-' . mb_substr((string) $proposal['title'], 0, 30) . '.docx';
        $this->log('proposal.word', 'proposal', (string) $proposal['proposal_id']);

        return new FileResponse(
            $binary,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $name
        );
    }

    /** @param array<string, string> $args */
    public function attachment(Request $request, array $args): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::PROPOSAL_VIEW)) {
            return $denied;
        }

        $attachment = $this->proposals->findAttachment((int) $args['aid'], (int) $args['id']);
        if ($attachment === null) {
            return $this->notFound();
        }
        $root = realpath($this->storageRoot);
        $path = $root === false ? false : realpath($root . '/' . ltrim((string) $attachment['stored_path'], '/'));
        // 前缀比到分隔符：否则 storage-evil 这类同级目录会被误判为命中
        if ($path === false || !str_starts_with($path, (string) $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return $this->notFound('附件文件已不存在。');
        }
        $this->log('proposal.attachment', 'proposal', (string) $args['id'], ['name' => (string) $attachment['name']]);

        return FileResponse::fromPath($path, 'application/octet-stream', (string) $attachment['name']);
    }

    /** @return array<string, string> */
    private function filters(Request $request): array
    {
        return [
            'status'   => (string) ($request->query('status', '') ?? ''),
            'category' => (string) ($request->query('category', '') ?? ''),
            'sector'   => (string) ($request->query('sector', '') ?? ''),
            'keyword'  => (string) ($request->query('keyword', '') ?? ''),
            'from'     => (string) ($request->query('from', '') ?? ''),
            'to'       => (string) ($request->query('to', '') ?? ''),
        ];
    }

    private function notFound(string $message = '提案不存在。'): HtmlResponse
    {
        return $this->view->page('admin/message', [
            'current' => 'proposals',
            'heading' => '没有找到这份提案',
            'message' => $message,
            'backUrl' => '/admin/proposals',
        ], '没有找到这份提案', 404);
    }
}
