<?php

declare(strict_types=1);

namespace HechiZx\Member;

use HechiZx\Admin\Flash;
use HechiZx\Content\ProposalBody;
use HechiZx\Content\ProposalWorkflow;
use HechiZx\Http\FileResponse;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Proposal\WordExporter;
use HechiZx\Repository\UnitRepository;

/**
 * 委员端提案：我的提案、填写提交、查看进度、退回后改稿重交、下载提案表与附件。
 * 委员只能看到自己的提案，取不到一律按「不存在」处理（不泄露他人提案是否存在）。
 *
 * 表单口径（提案委 2026-09-16 需求）：正文合并为一段轻量富文本、整体不超过 2000 字；
 * 联名委员与建议承办单位是多行／多选；办理联系人六项必填，填过一次就记在账号上。
 */
final class ProposalController extends MemberController
{
    private const TITLE_MAX = 50;
    private const PAGE_SIZE = 10;
    private const COL_MEMBERS = 10;

    /** 办理联系人六项：字段名 => 中文名，错误提示按这个顺序拼 */
    private const CONTACT_FIELDS = [
        'contact_name'     => '姓名',
        'contact_org'      => '单位',
        'contact_title'    => '职务',
        'contact_address'  => '联系地址',
        'contact_postcode' => '邮政编码',
        'contact_mobile'   => '联系电话',
    ];

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
        $this->rememberContact($data);
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
        $proposalId = (int) $proposal['proposal_id'];

        return $this->view->page('member/proposal_show', [
            'member'      => $this->member(),
            'current'     => 'list',
            'proposal'    => $proposal,
            'coMembers'   => $this->proposals->coMembers($proposalId),
            'units'       => $this->proposals->units($proposalId),
            'edges'       => $this->edges($proposalId),
            'attachments' => $this->proposals->attachments($proposalId),
            'logs'        => $this->proposals->logs($proposalId),
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
        $this->rememberContact($data);
        $this->proposals->writeLog($proposalId, 'member', $this->auth->id(), 'resubmit');
        $this->auth->log('proposal.resubmit', (string) $proposalId, ['title' => $data['title']]);

        Flash::set('ok', '已重新提交，提案委会再次收件。');
        return new RedirectResponse('/member/proposal/' . $proposalId);
    }

    /**
     * 错别字勘误：本期只预留入口与接口，接上编校服务后在这一处换实现。
     * 委员点了按钮先回到原表单，已填内容照原样带回去，不让填了一半的正文丢掉。
     */
    public function checkText(Request $request): HtmlResponse|RedirectResponse
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

        $returnTo = $this->safeReturnTo($request->post('return_to'));
        $count = ProposalBody::charCount($request->post('body_html'));
        Flash::set(
            'error',
            '错别字勘误功能待接入（接口已预留）。当前正文 ' . $count . ' 字，上限 '
                . ProposalBody::MAX_CHARS . ' 字：请先自行校读，或把正文复制到 Word 里校对后再提交。'
        );

        return new RedirectResponse($returnTo);
    }

    /**
     * 名册检索：联名委员那一栏输入姓名／单位，带出在册委员资料。
     * 路由返回数组即 JSON，只回姓名、单位及职务、联系电话。
     */
    public function roster(Request $request): array
    {
        if (!$this->auth->check() || $this->auth->mustChangePassword()) {
            return ['error' => ['code' => 'forbidden', 'message' => '请先登录。']];
        }

        $items = [];
        foreach ($this->members->search((string) ($request->query('keyword', '') ?? ''), self::COL_MEMBERS) as $row) {
            $items[] = [
                'name'      => (string) $row['name'],
                'org_title' => (string) $row['org_title'],
                'mobile'    => (string) $row['mobile'],
            ];
        }

        return ['items' => $items];
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

        $proposalId = (int) $proposal['proposal_id'];
        $binary = WordExporter::proposal(
            $proposal,
            $this->proposals->attachments($proposalId),
            $this->proposals->coMembers($proposalId),
            $this->proposals->units($proposalId)
        );
        $name = '提案-' . $proposalId . '-' . mb_substr((string) $proposal['title'], 0, 30) . '.docx';
        $this->auth->log('proposal.word', (string) $proposalId);

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
     * 表单校验：案由、正文、办理联系人六项必填；正文 ≤2000 字；联名提案至少一位联名委员；
     * 承办单位最多 5 个且必须来自启用中的清单。
     *
     * @return array{0:array<string,mixed>,1:list<string>}
     */
    private function validated(Request $request): array
    {
        $data = $this->defaultValues();
        $fields = ['proposer_type', 'proposer_name', 'sector', 'committee', 'collective_name', 'category', 'title'];
        foreach (array_merge($fields, array_keys(self::CONTACT_FIELDS)) as $field) {
            if ($request->hasPost($field)) {
                $data[$field] = $request->post($field);
            }
        }
        if ($request->hasPost('body_html')) {
            $data['body_html'] = ProposalBody::clean($request->post('body_html'));
        }

        $errors = [];
        if ($data['title'] === '') {
            $errors[] = '请填写案由。';
        } elseif (mb_strlen((string) $data['title'], 'UTF-8') > self::TITLE_MAX) {
            $errors[] = '案由不能超过 ' . self::TITLE_MAX . ' 个字。';
        }

        $bodyCount = ProposalBody::charCount((string) $data['body_html']);
        if ($bodyCount === 0) {
            $errors[] = '请填写提案内容。';
        } elseif ($bodyCount > ProposalBody::MAX_CHARS) {
            $errors[] = '提案内容 ' . $bodyCount . ' 字，超出 ' . ProposalBody::MAX_CHARS . ' 字上限，请精简后再提交。';
        }

        if (!array_key_exists((string) $data['proposer_type'], ProposalWorkflow::proposerTypes())) {
            $errors[] = '请选择提案人类别。';
        }
        if ($data['proposer_name'] === '') {
            $errors[] = '请填写提案人。';
        }
        if (!in_array($data['category'], ProposalWorkflow::categories(), true)) {
            $errors[] = '请选择提案类别。';
        }

        // 办理联系人：缺哪几项一次报清，避免委员来回提交
        $missing = [];
        foreach (self::CONTACT_FIELDS as $field => $label) {
            if ((string) $data[$field] === '') {
                $missing[] = $label;
            }
        }
        if ($missing !== []) {
            $errors[] = '请填写提案办理联系人：' . implode('、', $missing) . '。';
        } elseif (preg_match('/^\d{6}$/', (string) $data['contact_postcode']) !== 1) {
            $errors[] = '邮政编码请填 6 位数字。';
        }

        // 联名委员：整行留空视为没填，填了姓名才收
        $coRows = [];
        foreach ($this->postArray('co_name') as $index => $name) {
            $name = trim($name);
            $org = trim($this->postArrayValue('co_org', $index));
            $mobile = trim($this->postArrayValue('co_mobile', $index));
            if ($name === '' && $org === '' && $mobile === '') {
                continue;
            }
            if ($name === '') {
                $errors[] = '联名委员请填写姓名（第 ' . ($index + 1) . ' 位）。';
                continue;
            }
            $coRows[] = ['name' => $name, 'org_title' => $org, 'mobile' => $mobile];
        }
        if ((string) $data['proposer_type'] === 'joint' && $coRows === []) {
            $errors[] = '联名提案请至少填写一位联名委员的资料。';
        }
        $data['co_member_rows'] = $coRows;
        $data['co_members'] = implode('、', array_map(static fn (array $row): string => $row['name'], $coRows));

        if ((string) $data['proposer_type'] === 'collective' && (string) $data['collective_name'] === '') {
            $errors[] = '集体提案请填写提出单位或界别名称。';
        }

        // 建议承办单位：选填，最多 5 个，只认启用中的
        $posted = array_values(array_filter($this->postArray('units'), static fn (string $id): bool => $id !== ''));
        $unitRows = [];
        if ($posted !== []) {
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
        }
        $data['unit_rows'] = $unitRows;
        $data['host_units'] = implode('、', array_map(static fn (array $row): string => $row['unit_name'], $unitRows));

        return [$data, $errors];
    }

    /** @return array<string, mixed> */
    private function defaultValues(): array
    {
        $member = $this->member();
        // 联系人默认带委员本人：能确定的三项先填好，其余空着等首次填写
        $contact = [
            'contact_name'     => (string) ($member['contact_name'] ?? ''),
            'contact_org'      => (string) ($member['contact_org'] ?? ''),
            'contact_title'    => (string) ($member['contact_title'] ?? ''),
            'contact_address'  => (string) ($member['contact_address'] ?? ''),
            'contact_postcode' => (string) ($member['contact_postcode'] ?? ''),
            'contact_mobile'   => (string) ($member['contact_mobile'] ?? ''),
        ];
        if ($contact['contact_name'] === '') {
            $contact['contact_name'] = (string) ($member['name'] ?? '');
        }
        if ($contact['contact_org'] === '') {
            $contact['contact_org'] = (string) ($member['org_title'] ?? '');
        }
        if ($contact['contact_mobile'] === '') {
            $contact['contact_mobile'] = (string) ($member['mobile'] ?? '');
        }

        return array_merge($contact, [
            'proposer_type'   => 'personal',
            'proposer_name'   => (string) ($member['name'] ?? ''),
            'sector'          => (string) ($member['sector'] ?? ''),
            'committee'       => (string) ($member['committee'] ?? ''),
            'co_members'      => '',
            'co_member_rows'  => [],
            'collective_name' => '',
            'category'        => '',
            'title'           => '',
            'body_html'       => '',
            'unit_rows'       => [],
            'host_units'      => '',
        ]);
    }

    /** @param array<string, mixed> $proposal @return array<string, mixed> */
    private function valuesFrom(array $proposal): array
    {
        $values = $this->defaultValues();
        foreach (array_keys($values) as $field) {
            if (array_key_exists($field, $proposal)) {
                $values[$field] = $proposal[$field];
            }
        }
        $proposalId = (int) ($proposal['proposal_id'] ?? 0);
        $values['co_member_rows'] = array_map(
            static fn (array $row): array => [
                'name'      => (string) $row['name'],
                'org_title' => (string) $row['org_title'],
                'mobile'    => (string) $row['mobile'],
            ],
            $this->proposals->coMembers($proposalId)
        );
        $values['unit_rows'] = array_map(
            static fn (array $row): array => [
                'unit_id'   => (int) $row['unit_id'],
                'unit_name' => (string) $row['unit_name'],
            ],
            $this->proposals->units($proposalId)
        );

        return $values;
    }

    /**
     * @param array<string, mixed>|null $proposal
     * @param array<string, mixed> $values
     * @param list<string> $errors
     */
    private function renderForm(?array $proposal, array $values, array $errors, int $status = 200): HtmlResponse
    {
        $proposalId = $proposal === null ? 0 : (int) $proposal['proposal_id'];
        $coRows = (array) $values['co_member_rows'];
        if ($coRows === []) {
            $coRows = [['name' => '', 'org_title' => '', 'mobile' => '']];
        }

        return $this->view->page('member/proposal_form', [
            'member'        => $this->member(),
            'current'       => 'new',
            'proposal'      => $proposal,
            'proposalId'    => $proposalId,
            'values'        => $values,
            'errors'        => $errors,
            'categories'    => ProposalWorkflow::categories(),
            'proposerTypes' => ProposalWorkflow::proposerTypes(),
            'unitOptions'   => $this->units->enabled(),
            'selectedUnits' => array_map(static fn (array $row): int => (int) $row['unit_id'], (array) $values['unit_rows']),
            'coRows'        => $coRows,
            'maxUnits'      => UnitRepository::MAX_PER_PROPOSAL,
            'bodyLimit'     => ProposalBody::MAX_CHARS,
            'bodyCount'     => ProposalBody::charCount((string) $values['body_html']),
            'attachments'   => $proposalId === 0 ? [] : $this->proposals->attachments($proposalId),
            'maxCount'      => self::ATTACHMENT_MAX_COUNT,
            'maxMb'         => (int) (self::ATTACHMENT_MAX_BYTES / 1048576),
            'head'          => '<link rel="stylesheet" href="/assets/editor/suneditor.min.css">'
                . '<link rel="stylesheet" href="/assets/editor/admin-editor.css">'
                . '<link rel="stylesheet" href="/assets/unit-picker.css">',
            'scripts'       => '<script src="/assets/editor/suneditor.min.js"></script>'
                . '<script src="/assets/editor/lang/zh_cn.js"></script>'
                . '<script src="/assets/member-editor.js"></script>'
                . '<script src="/assets/unit-picker.js"></script>',
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

    /**
     * 办理联系人记在委员账号上：下次登录自动带出，省得每份提案重填。
     *
     * @param array<string, mixed> $data
     */
    private function rememberContact(array $data): void
    {
        $profile = [];
        foreach (array_keys(self::CONTACT_FIELDS) as $field) {
            $profile[$field] = (string) ($data[$field] ?? '');
        }
        $this->members->updateContactProfile($this->auth->id(), $profile);
    }

    /**
     * 提案委改过稿的提案：委员端要能看出来「内容被调整过」。
     * 只给条数与改动摘要，不把改前快照摊给委员，避免两份正文对不上。
     *
     * @return list<array<string,string>>
     */
    private function edges(int $proposalId): array
    {
        return array_map(
            static fn (array $row): array => [
                'summary'    => (string) $row['summary'],
                'created_at' => (string) $row['created_at'],
            ],
            $this->proposals->revisions($proposalId)
        );
    }

    /** 表单里的数组字段（co_name[]、units[] 这类），逐个取成字符串 */
    /** @return list<string> */
    private function postArray(string $key): array
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

    private function postArrayValue(string $key, int $index): string
    {
        return $this->postArray($key)[$index] ?? '';
    }

    /** 只接受站内 /member/ 开头的返回地址，防止被拿来当跳板 */
    private function safeReturnTo(string $returnTo): string
    {
        $path = parse_url($returnTo, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/member/')) {
            return '/member/proposals';
        }

        return $path;
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
