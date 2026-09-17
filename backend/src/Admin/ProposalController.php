<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Content\ProposalBody;
use HechiZx\Content\ProposalWorkflow;
use HechiZx\Http\FileResponse;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Proposal\WordExporter;
use HechiZx\Proposal\XlsxExporter;
use HechiZx\Repository\ProposalRepository;
use HechiZx\Repository\UnitRepository;
use HechiZx\Support\Db;

/**
 * 后台提案收件：列表与筛选、详情、调整提案、受理、退回补充、导出 Excel／Word、附件下载。
 * 权限：proposal.view 看，proposal.review 办（含调整提案），proposal.export 导出。
 */
final class ProposalController extends AdminController
{
    private const PAGE_SIZE = 20;
    private const EXPORT_LIMIT = 3000;
    private const BATCH_LIMIT = 200;

    /** 与委员端同一套办理联系人字段，后台调整提案时按钮位一致 */
    private const CONTACT_FIELDS = [
        'contact_name', 'contact_org', 'contact_title', 'contact_address', 'contact_postcode', 'contact_mobile',
    ];

    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private ProposalRepository $proposals,
        private UnitRepository $units,
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
            'coMembers'   => $this->proposals->coMembers($proposalId),
            'units'       => $this->proposals->units($proposalId),
            'revisions'   => $this->proposals->revisions($proposalId),
            'unitOptions' => $this->units->enabled(),
            'editable'    => $this->can(Permissions::PROPOSAL_REVIEW)
                && ProposalWorkflow::normalize((string) $proposal['status']) === ProposalWorkflow::SUBMITTED,
            'attachments' => $this->proposals->attachments($proposalId),
            'logs'        => $this->proposals->logs($proposalId),
            'pageHead'    => $this->editorHead(),
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

    /**
     * 详情页专用资源：调整提案要用的富文本编辑器，只在详情页加载。
     * 与稿件编辑器分开一份脚本，提案正文的按钮面更窄。
     */
    private function editorHead(): string
    {
        return '<link rel="stylesheet" href="/assets/editor/suneditor.min.css">'
            . '<link rel="stylesheet" href="/assets/editor/suneditor-contents.min.css">'
            . '<link rel="stylesheet" href="/assets/editor/admin-editor.css">'
            . '<link rel="stylesheet" href="/assets/unit-picker.css">'
            . '<script src="/assets/editor/suneditor.min.js" defer></script>'
            . '<script src="/assets/editor/lang/zh_cn.js" defer></script>'
            . '<script src="/assets/admin-proposal-editor.js" defer></script>'
            . '<script src="/assets/unit-picker.js" defer></script>';
    }

    /**
     * 调整提案：受理前可改案由、正文、建议承办单位、联名委员与办理联系人。$args 里是提案号。
     * 保存前先把改前内容存成一份快照，流转记录写明改动了哪些字段。
     *
     * @param array<string, string> $args
     */
    public function edit(Request $request, array $args): HtmlResponse|RedirectResponse
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
        $proposalId = (int) $proposal['proposal_id'];
        if (ProposalWorkflow::normalize((string) $proposal['status']) !== ProposalWorkflow::SUBMITTED) {
            Flash::set('error', '只有未受理的提案可以调整；已被退回的提案请等委员修改后重新提交。');
            return new RedirectResponse('/admin/proposal/' . $proposalId);
        }

        [$data, $errors] = $this->validatedEdit($request);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            return new RedirectResponse('/admin/proposal/' . $proposalId);
        }

        $before = $this->proposals->snapshot($proposalId);
        $summary = $this->changedFields($before, $data);
        if ($summary === '') {
            Flash::set('ok', '内容和原来一样，没有改动。');
            return new RedirectResponse('/admin/proposal/' . $proposalId);
        }

        // 先存快照再改：留痕记录的是改前那一版，出问题照着还原
        $this->proposals->addRevision($proposalId, (int) $this->user()['user_id'], $summary, $before);
        $this->proposals->adminUpdate($proposalId, $data, (int) $this->user()['user_id']);
        $this->proposals->writeLog(
            $proposalId,
            'staff',
            (int) $this->user()['user_id'],
            'edit',
            $summary
        );
        $this->log('proposal.edit', 'proposal', (string) $proposalId, ['fields' => $summary]);

        Flash::set('ok', '已保存调整（' . $summary . '）。委员在门户里会看到「提案委已调整内容」的提示。');
        return new RedirectResponse('/admin/proposal/' . $proposalId);
    }

    /**
     * 批量导出：按当前筛选范围把全部提案合并成一个 Word，每份独立起页。
     * 走系统内置的标准提案表格式；提案委的办理文件模板到位后再做套版。
     */
    public function exportWord(Request $request): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::PROPOSAL_EXPORT)) {
            return $denied;
        }

        $filters = $this->filters($request);
        $rows = $this->proposals->exportRows($filters, self::BATCH_LIMIT);
        if ($rows === []) {
            Flash::set('error', '当前筛选条件下没有提案可导出。');
            return new RedirectResponse('/admin/proposals');
        }

        $items = [];
        foreach ($rows as $row) {
            $proposalId = (int) $row['proposal_id'];
            $items[] = [
                'proposal'    => $row,
                'attachments' => $this->proposals->attachments($proposalId),
                'coMembers'   => $this->proposals->coMembers($proposalId),
                'units'       => $this->proposals->units($proposalId),
            ];
        }
        $binary = WordExporter::batch($items);
        $this->log('proposal.export_word', 'proposal', '', ['count' => count($items), 'filters' => $filters]);

        return new FileResponse(
            $binary,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            '提案汇总-' . date('Ymd-His') . '.docx'
        );
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

        $proposalId = (int) $proposal['proposal_id'];
        $binary = WordExporter::proposal(
            $proposal,
            $this->proposals->attachments($proposalId),
            $this->proposals->coMembers($proposalId),
            $this->proposals->units($proposalId)
        );
        $name = '提案-' . $proposalId . '-' . mb_substr((string) $proposal['title'], 0, 30) . '.docx';
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
            'unit'     => (string) ($request->query('unit', '') ?? ''),
            'keyword'  => (string) ($request->query('keyword', '') ?? ''),
            'from'     => (string) ($request->query('from', '') ?? ''),
            'to'       => (string) ($request->query('to', '') ?? ''),
        ];
    }

    /**
     * 后台调整提案的校验：案由与正文必填且不超限，承办单位 ≤5 个且必须在启用清单里，
     * 邮政编码填了就要 6 位数字。联系人六项不强制（历史提案可能不全）。
     *
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private function validatedEdit(Request $request): array
    {
        $data = [
            'title'       => $request->post('title'),
            'category'    => $request->post('category'),
            'body_html'   => ProposalBody::clean($request->post('body_html')),
            'collective_name' => $request->post('collective_name'),
        ];
        foreach (self::CONTACT_FIELDS as $field) {
            $data[$field] = $request->post($field);
        }

        $errors = [];
        if ($data['title'] === '') {
            $errors[] = '案由不能为空。';
        } elseif (mb_strlen($data['title'], 'UTF-8') > 50) {
            $errors[] = '案由不能超过 50 个字。';
        }
        $count = ProposalBody::charCount($data['body_html']);
        if ($count === 0) {
            $errors[] = '提案内容不能为空。';
        } elseif ($count > ProposalBody::MAX_CHARS) {
            $errors[] = '提案内容 ' . $count . ' 字，超出 ' . ProposalBody::MAX_CHARS . ' 字上限。';
        }
        if (!in_array($data['category'], ProposalWorkflow::categories(), true)) {
            $errors[] = '请选择提案类别。';
        }
        if ($data['contact_postcode'] !== '' && preg_match('/^\d{6}$/', $data['contact_postcode']) !== 1) {
            $errors[] = '邮政编码请填 6 位数字。';
        }

        $coRows = [];
        foreach (self::postArray('co_name') as $index => $name) {
            $name = trim($name);
            $org = self::postArrayValue('co_org', $index);
            $mobile = self::postArrayValue('co_mobile', $index);
            if ($name === '' && $org === '' && $mobile === '') {
                continue;
            }
            if ($name === '') {
                $errors[] = '联名委员请填写姓名（第 ' . ($index + 1) . ' 位）。';
                continue;
            }
            $coRows[] = ['name' => $name, 'org_title' => $org, 'mobile' => $mobile];
        }
        $data['co_member_rows'] = $coRows;
        $data['co_members'] = implode('、', array_map(static fn (array $row): string => $row['name'], $coRows));

        $posted = array_values(array_filter(self::postArray('units'), static fn (string $id): bool => $id !== ''));
        $unitRows = [];
        if (count($posted) > UnitRepository::MAX_PER_PROPOSAL) {
            $errors[] = '建议承办单位最多选 ' . UnitRepository::MAX_PER_PROPOSAL . ' 个。';
        } else {
            $found = [];
            foreach ($this->units->enabledByIds($posted) as $row) {
                $found[(int) $row['unit_id']] = (string) $row['name'];
            }
            foreach ($posted as $id) {
                $unitId = (int) $id;
                if (!isset($found[$unitId])) {
                    $errors[] = '建议承办单位里有已停用或不存在的一项，请重新选择。';
                    break;
                }
                $unitRows[] = ['unit_id' => $unitId, 'unit_name' => $found[$unitId]];
            }
        }
        $data['unit_rows'] = $unitRows;
        $data['host_units'] = implode('、', array_map(static fn (array $row): string => $row['unit_name'], $unitRows));

        return [$data, $errors];
    }

    /**
     * 与改前快照逐项比对，给出「改了哪些」的中文清单（留痕与提示文案共用）。
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    private function changedFields(array $before, array $after): string
    {
        $labels = [
            'title'       => '案由',
            'category'    => '提案类别',
            'body_html'   => '正文',
            'host_units'  => '建议承办单位',
            'co_members'  => '联名委员',
            'contact_name' => '联系人姓名',
            'contact_org' => '联系人单位',
            'contact_title' => '联系人职务',
            'contact_address' => '联系地址',
            'contact_postcode' => '邮政编码',
            'contact_mobile' => '联系电话',
        ];
        $changed = [];
        foreach ($labels as $field => $label) {
            if ((string) ($before[$field] ?? '') !== (string) ($after[$field] ?? '')) {
                $changed[] = $label;
            }
        }

        return implode('、', $changed);
    }

    /** @return list<string> */
    private static function postArray(string $key): array
    {
        $raw = $_POST[$key] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $values = [];
        foreach ($raw as $value) {
            $values[] = is_string($value) ? trim($value) : '';
        }

        return $values;
    }

    private static function postArrayValue(string $key, int $index): string
    {
        return self::postArray($key)[$index] ?? '';
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
