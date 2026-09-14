<?php

declare(strict_types=1);

namespace HechiZx\Member;

use HechiZx\Admin\Flash;
use HechiZx\Content\ProposalWorkflow;
use HechiZx\Http\FileResponse;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Proposal\WordExporter;

/**
 * 委员端提案：我的提案、填写提交、查看进度、退回后改稿重交、下载提案表与附件。
 * 委员只能看到自己的提案，取不到一律按「不存在」处理（不泄露他人提案是否存在）。
 */
final class ProposalController extends MemberController
{
    private const TITLE_MAX = 50;
    private const TEXT_MAX = 3000;
    private const PAGE_SIZE = 10;

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        if ($denied = $this->requirePasswordChanged()) {
            return $denied;
        }

        $status = (string) ($request->query('status', '') ?? '');
        $page = max(1, $request->int('page', 1, 1));
        $result = $this->proposals->memberList($this->auth->id(), $status, $page, self::PAGE_SIZE);

        return $this->view->page('member/proposals', [
            'member'  => $this->member(),
            'current' => 'list',
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'pages'   => max(1, (int) ceil($result['total'] / self::PAGE_SIZE)),
            'status'  => $status,
            'counts'  => $this->proposals->memberList($this->auth->id(), '', 1, self::PAGE_SIZE)['total'],
        ], '我的提案');
    }

    public function createForm(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        if ($denied = $this->requirePasswordChanged()) {
            return $denied;
        }

        return $this->renderForm(null, $this->defaultValues(), []);
    }

    public function create(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        if ($denied = $this->requirePasswordChanged()) {
            return $denied;
        }

        [$data, $errors] = $this->validated($request);
        [$files, $fileError] = $this->collectFiles();
        if ($fileError !== '') {
            $errors[] = $fileError;
        }
        if ($errors !== []) {
            return $this->renderForm(null, $data, $errors, 400);
        }

        $proposalId = $this->proposals->create($this->auth->id(), $data);
        $this->saveFiles($proposalId, $files, 0);
        $this->proposals->writeLog($proposalId, 'member', $this->auth->id(), 'submit');
        $this->auth->log('proposal.submit', (string) $proposalId, ['title' => $data['title']]);

        Flash::set('ok', '提案已提交，提案委会在系统内收件。');
        return new RedirectResponse('/member/proposal/' . $proposalId);
    }

    /** @param array<string, string> $args */
    public function show(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        if ($denied = $this->requirePasswordChanged()) {
            return $denied;
        }

        $proposal = $this->proposals->memberFind((int) $args['id'], $this->auth->id());
        if ($proposal === null) {
            return $this->notFound();
        }

        return $this->view->page('member/proposal_show', [
            'member'      => $this->member(),
            'current'     => 'list',
            'proposal'    => $proposal,
            'attachments' => $this->proposals->attachments((int) $proposal['proposal_id']),
            'logs'        => $this->proposals->logs((int) $proposal['proposal_id']),
            'canEdit'     => ProposalWorkflow::canMemberEdit((string) $proposal['status']),
        ], '提案详情');
    }

    /** @param array<string, string> $args */
    public function editForm(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        if ($denied = $this->requirePasswordChanged()) {
            return $denied;
        }

        $proposal = $this->proposals->memberFind((int) $args['id'], $this->auth->id());
        if ($proposal === null) {
            return $this->notFound();
        }
        if (!ProposalWorkflow::canMemberEdit((string) $proposal['status'])) {
            Flash::set('error', '只有被退回的提案可以修改。');
            return new RedirectResponse('/member/proposal/' . (int) $proposal['proposal_id']);
        }

        return $this->renderForm($proposal, $this->valuesFrom($proposal), []);
    }

    /** @param array<string, string> $args */
    public function submit(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        if ($denied = $this->requirePasswordChanged()) {
            return $denied;
        }

        $proposal = $this->proposals->memberFind((int) $args['id'], $this->auth->id());
        if ($proposal === null) {
            return $this->notFound();
        }
        if (!ProposalWorkflow::canMemberEdit((string) $proposal['status'])) {
            Flash::set('error', '只有被退回的提案可以修改后重新提交。');
            return new RedirectResponse('/member/proposal/' . (int) $proposal['proposal_id']);
        }

        [$data, $errors] = $this->validated($request);
        [$files, $fileError] = $this->collectFiles();
        if ($fileError !== '') {
            $errors[] = $fileError;
        }
        if ($errors !== []) {
            return $this->renderForm($proposal, $data, $errors, 400);
        }

        $proposalId = (int) $proposal['proposal_id'];
        $existing = count($this->proposals->attachments($proposalId));
        $this->proposals->resubmit($proposalId, $data);
        $this->saveFiles($proposalId, $files, $existing);
        $this->proposals->writeLog($proposalId, 'member', $this->auth->id(), 'resubmit');
        $this->auth->log('proposal.resubmit', (string) $proposalId, ['title' => $data['title']]);

        Flash::set('ok', '已重新提交，提案委会再次收件。');
        return new RedirectResponse('/member/proposal/' . $proposalId);
    }

    /** @param array<string, string> $args */
    public function word(Request $request, array $args): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        if ($denied = $this->requirePasswordChanged()) {
            return $denied;
        }

        $proposal = $this->proposals->memberFind((int) $args['id'], $this->auth->id());
        if ($proposal === null) {
            return $this->notFound();
        }

        $binary = WordExporter::proposal($proposal, $this->proposals->attachments((int) $proposal['proposal_id']));
        $name = '提案-' . (int) $proposal['proposal_id'] . '-' . mb_substr((string) $proposal['title'], 0, 30) . '.docx';
        $this->auth->log('proposal.word', (string) $proposal['proposal_id']);

        return new FileResponse(
            $binary,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $name
        );
    }

    /** @param array<string, string> $args */
    public function attachment(Request $request, array $args): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($denied = $this->requireLogin()) {
            return $denied;
        }
        if ($denied = $this->requirePasswordChanged()) {
            return $denied;
        }

        $proposal = $this->proposals->memberFind((int) $args['id'], $this->auth->id());
        if ($proposal === null) {
            return $this->notFound();
        }
        $attachment = $this->proposals->findAttachment((int) $args['aid'], (int) $proposal['proposal_id']);
        if ($attachment === null) {
            return $this->notFound();
        }

        $path = $this->resolveStoredPath((string) $attachment['stored_path']);
        if ($path === null) {
            return $this->notFound('附件文件已不存在，请联系提案委。');
        }

        return FileResponse::fromPath($path, 'application/octet-stream', (string) $attachment['name']);
    }

    /**
     * 表单校验：案由、情况与问题、建议必填，其余按类别校验；超出长度一律挡下并给出提示。
     *
     * @return array{0:array<string,string>,1:list<string>}
     */
    private function validated(Request $request): array
    {
        $data = $this->defaultValues();
        foreach (array_keys($data) as $field) {
            if ($request->hasPost($field)) {
                $data[$field] = $request->post($field);
            }
        }

        $errors = [];
        if ($data['title'] === '') {
            $errors[] = '请填写案由。';
        } elseif (mb_strlen($data['title']) > self::TITLE_MAX) {
            $errors[] = '案由不能超过 ' . self::TITLE_MAX . ' 个字。';
        }
        if ($data['problem_text'] === '') {
            $errors[] = '请填写「情况与问题」。';
        }
        if ($data['suggestion_text'] === '') {
            $errors[] = '请填写「建议」。';
        }
        foreach (['problem_text', 'analysis_text', 'suggestion_text'] as $field) {
            if (mb_strlen($data[$field]) > self::TEXT_MAX) {
                $errors[] = '正文每段不能超过 ' . self::TEXT_MAX . ' 个字。';
                break;
            }
        }
        if (!array_key_exists($data['proposer_type'], ProposalWorkflow::proposerTypes())) {
            $errors[] = '请选择提案人类别。';
        }
        if ($data['proposer_type'] === 'joint' && $data['co_members'] === '') {
            $errors[] = '联名提案请填写联名委员名单。';
        }
        if ($data['proposer_type'] === 'collective' && $data['collective_name'] === '') {
            $errors[] = '集体提案请填写提出单位或界别名称。';
        }
        if ($data['proposer_name'] === '') {
            $errors[] = '请填写提案人。';
        }
        if ($data['contact_mobile'] === '') {
            $errors[] = '请填写联系电话。';
        }
        if (!in_array($data['category'], ProposalWorkflow::categories(), true)) {
            $errors[] = '请选择提案类别。';
        }

        return [$data, $errors];
    }

    /** @return array<string, string> */
    private function defaultValues(): array
    {
        $member = $this->member();

        return [
            'proposer_type'   => 'personal',
            'proposer_name'   => (string) ($member['name'] ?? ''),
            'sector'          => (string) ($member['sector'] ?? ''),
            'committee'       => (string) ($member['committee'] ?? ''),
            'contact_mobile'  => (string) ($member['mobile'] ?? ''),
            'co_members'      => '',
            'collective_name' => '',
            'category'        => '',
            'title'           => '',
            'problem_text'    => '',
            'analysis_text'   => '',
            'suggestion_text' => '',
        ];
    }

    /** @param array<string, mixed> $proposal @return array<string, string> */
    private function valuesFrom(array $proposal): array
    {
        $values = $this->defaultValues();
        foreach (array_keys($values) as $field) {
            $values[$field] = (string) ($proposal[$field] ?? $values[$field]);
        }

        return $values;
    }

    /**
     * @param array<string, mixed>|null $proposal
     * @param array<string, string> $values
     * @param list<string> $errors
     */
    private function renderForm(?array $proposal, array $values, array $errors, int $status = 200): HtmlResponse
    {
        $proposalId = $proposal === null ? 0 : (int) $proposal['proposal_id'];

        return $this->view->page('member/proposal_form', [
            'member'      => $this->member(),
            'current'     => 'new',
            'proposal'    => $proposal,
            'proposalId'  => $proposalId,
            'values'      => $values,
            'errors'      => $errors,
            'categories'  => ProposalWorkflow::categories(),
            'proposerTypes' => ProposalWorkflow::proposerTypes(),
            'attachments' => $proposalId === 0 ? [] : $this->proposals->attachments($proposalId),
            'maxCount'    => self::ATTACHMENT_MAX_COUNT,
            'maxMb'       => (int) (self::ATTACHMENT_MAX_BYTES / 1048576),
        ], $proposalId === 0 ? '填写提案' : '修改提案', $status);
    }

    /**
     * 收集上传的附件（字段名 attachments[]），校验扩展名与大小。
     *
     * @return array{0:list<array{name:string,tmp:string,size:int}>,1:string}
     */
    private function collectFiles(): array
    {
        $files = [];
        $raw = $_FILES['attachments'] ?? null;
        if (!is_array($raw) || !isset($raw['name'])) {
            return [$files, ''];
        }
        $names = is_array($raw['name']) ? $raw['name'] : [$raw['name']];
        $tmps = is_array($raw['tmp_name'] ?? '') ? $raw['tmp_name'] : [$raw['tmp_name'] ?? ''];
        $sizes = is_array($raw['size'] ?? 0) ? $raw['size'] : [$raw['size'] ?? 0];
        $errors = is_array($raw['error'] ?? 0) ? $raw['error'] : [$raw['error'] ?? 0];

        foreach ($names as $index => $name) {
            $name = (string) $name;
            if ($name === '' || (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if (count($files) >= self::ATTACHMENT_MAX_COUNT) {
                return [$files, '一次最多上传 ' . self::ATTACHMENT_MAX_COUNT . ' 个附件。'];
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ATTACHMENT_EXTENSIONS, true)) {
                return [$files, '附件格式不支持：' . $name];
            }
            $size = (int) ($sizes[$index] ?? 0);
            if ($size > self::ATTACHMENT_MAX_BYTES) {
                return [$files, '附件超过服务器允许的上传大小（' . (int) (self::ATTACHMENT_MAX_BYTES / 1048576) . ' MB）：' . $name];
            }
            $files[] = ['name' => $name, 'tmp' => (string) ($tmps[$index] ?? ''), 'size' => $size];
        }

        return [$files, ''];
    }

    /**
     * 落盘：storage/proposals/{提案号}/，库内只记相对路径。
     *
     * @param list<array{name:string,tmp:string,size:int}> $files
     * @return list<string> 失败原因
     */
    private function saveFiles(int $proposalId, array $files, int $existingCount): array
    {
        if ($files === []) {
            return [];
        }
        $directory = $this->proposalStorage() . '/' . $proposalId;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return ['附件目录创建失败，请联系管理员。'];
        }

        $errors = [];
        $index = $existingCount;
        foreach ($files as $file) {
            if ($index >= self::ATTACHMENT_MAX_COUNT) {
                $errors[] = '附件数量超过上限（' . self::ATTACHMENT_MAX_COUNT . ' 个）。';
                break;
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $stored = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . ($ext === '' ? '' : '.' . $ext);
            $target = $directory . '/' . $stored;
            if (!move_uploaded_file($file['tmp'], $target)) {
                $errors[] = '附件保存失败：' . $file['name'];
                continue;
            }
            $this->proposals->addAttachment(
                $proposalId,
                $file['name'],
                'proposals/' . $proposalId . '/' . $stored,
                $ext,
                $file['size']
            );
            $index++;
        }

        return $errors;
    }

    /** 解析附件落盘路径，越出 storage 目录一律拒绝 */
    private function resolveStoredPath(string $storedPath): ?string
    {
        $root = realpath($this->storageRoot);
        if ($root === false) {
            return null;
        }
        $path = realpath($root . '/' . ltrim($storedPath, '/'));
        // 前缀要比到分隔符，避免 storage-evil 这类同级目录被判成命中
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        return $path;
    }

    private function notFound(string $message = '提案不存在，或不属于当前账号。'): HtmlResponse
    {
        return $this->view->page('member/message', [
            'member'  => $this->auth->member(),
            'heading' => '没有找到这份提案',
            'message' => $message,
            'backUrl' => '/member/proposals',
        ], '没有找到这份提案', 404);
    }
}
