<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Http\FileResponse;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Proposal\MemberImporter;
use HechiZx\Repository\MemberRepository;
use HechiZx\Support\Db;
use RuntimeException;

/**
 * 后台委员管理：名册列表、CSV 导入开通账号、停用／启用、重置密码。
 * 初始密码只当场下发（页面显示 + 一次性清单下载），库里只存 password_hash。
 */
final class MemberController extends AdminController
{
    private const PAGE_SIZE = 20;
    private const IMPORT_RESULT = 'member_import_result';
    private const IMPORT_CREDENTIALS = 'member_import_credentials';
    private const RESET_NOTICE = 'member_reset_notice';
    private const MANUAL_RESULT = 'member_manual_result';
    private const BATCH_CREDENTIALS = 'member_reset_batch';
    private const BATCH_LIMIT = 500;

    /** 手工建号一次最多提交多少条：每条都要算一次 bcrypt，防止一次请求把 PHP 进程占太久 */
    private const MANUAL_LIMIT = 50;

    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private MemberRepository $members,
        private MemberImporter $importer
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        $filters = [
            'keyword' => (string) ($request->query('keyword', '') ?? ''),
            'status'  => (string) ($request->query('status', '') ?? ''),
        ];
        $page = max(1, $request->int('page', 1, 1));
        $result = $this->members->paginate($filters, $page, self::PAGE_SIZE);

        $reset = $_SESSION[self::RESET_NOTICE] ?? null;
        unset($_SESSION[self::RESET_NOTICE]);
        $batch = $_SESSION[self::BATCH_CREDENTIALS] ?? [];
        // 手工建号的结果留到密码清单被下载为止，中途刷新页面也还能拿到密码
        $manual = $_SESSION[self::MANUAL_RESULT] ?? null;
        $credentials = $_SESSION[self::IMPORT_CREDENTIALS] ?? [];

        return $this->view->page('admin/members', [
            'current' => 'members',
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'pages'   => max(1, (int) ceil($result['total'] / self::PAGE_SIZE)),
            'filters' => $filters,
            // 批量重置的单次上限与篮子键：上限就是服务端常量（前端不再自己记一个数），
            // 篮子键只跟归一化后的筛选有关，换关键词或状态时不会串上一篮子的勾选
            'maxBulk' => self::BATCH_LIMIT,
            'bulkKey' => substr(sha1(http_build_query($filters)), 0, 12),
            'counts'  => $this->members->statusCounts(),
            'reset'   => is_array($reset) ? $reset : null,
            'batchPending' => is_array($batch) ? count($batch) : 0,
            'manual'       => is_array($manual) ? $manual : null,
            'credentials'  => is_array($credentials) ? $credentials : [],
        ], '委员管理');
    }

    /**
     * 手工新建账号：一次提交一行或多行（姓名／界别／职务／联系电话）。
     *
     * 逐行校验与建号都交给 MemberImporter，所以重名补序号、联系电话判重、姓名与职务必填、
     * 初始密码只显示一次这些规则跟名册导入完全一致，不会出现两套口径。
     */
    public function create(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        $rows = self::manualRows($_POST);
        if ($rows === []) {
            Flash::set('error', '请至少填一条账号：姓名与职务都要填。');
            return new RedirectResponse('/admin/members');
        }
        if (count($rows) > self::MANUAL_LIMIT) {
            Flash::set('error', '手工新建一次最多 ' . self::MANUAL_LIMIT . ' 条，请分批提交（更多人选名册导入更省事）。');
            return new RedirectResponse('/admin/members');
        }

        try {
            $result = $this->importer->importRows(array_merge([MemberImporter::HEADERS], $rows));
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            return new RedirectResponse('/admin/members');
        }

        // 密码清单复用导入那套一次性下载（/admin/members/credentials.csv）
        $_SESSION[self::IMPORT_CREDENTIALS] = $result['created'];
        $_SESSION[self::MANUAL_RESULT] = [
            'created_count' => count($result['created']),
            // 导入器按 CSV 行号报错，这里换算成「表单第几条」（表头占第 1 行）
            'failed'        => array_map(static fn (array $f): array => [
                'row'    => max(1, (int) $f['line'] - 1),
                'name'   => (string) $f['name'],
                'reason' => (string) $f['reason'],
            ], $result['failed']),
        ];
        $this->log('member.create', 'member', '', [
            'created' => count($result['created']),
            'failed'  => count($result['failed']),
        ]);

        $message = '新建完成：成功 ' . count($result['created']) . ' 条，失败 ' . count($result['failed']) . ' 条。';
        if ($result['created'] !== []) {
            $message .= '每条一个新密码，请立刻下载清单并线下发给委员，下载一次后本页不再显示。';
        }
        Flash::set($result['failed'] === [] ? 'ok' : 'error', $message);

        return new RedirectResponse('/admin/members');
    }

    /**
     * 手工建号表单的多行输入（name[]／sector[]／org[]／mobile[]）→ 数据行。
     * 整行空白的行丢掉，不占条数；各列的取值顺序与 MemberImporter::HEADERS 对齐。
     *
     * @param array<string, mixed> $post
     * @return list<list<string>>
     */
    private static function manualRows(array $post): array
    {
        $names = $post['name'] ?? null;
        if (!is_array($names)) {
            return [];
        }

        $cell = static function (array $post, string $key, int $index): string {
            $values = $post[$key] ?? null;
            if (!is_array($values)) {
                return '';
            }
            $value = $values[$index] ?? '';
            return is_string($value) ? trim($value) : '';
        };

        $rows = [];
        foreach (array_keys(array_values($names)) as $index) {
            $row = [
                $cell($post, 'name', $index),
                $cell($post, 'sector', $index),
                $cell($post, 'org', $index),
                $cell($post, 'mobile', $index),
            ];
            if (implode('', $row) === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function importForm(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        $result = $_SESSION[self::IMPORT_RESULT] ?? null;
        $credentials = $_SESSION[self::IMPORT_CREDENTIALS] ?? [];

        return $this->view->page('admin/member_import', [
            'current'        => 'members',
            'headers'        => MemberImporter::HEADERS,
            'result'         => is_array($result) ? $result : null,
            'credentials'    => is_array($credentials) ? $credentials : [],
            'hasCredentials' => is_array($credentials) && $credentials !== [],
        ], '委员名册导入');
    }

    public function importTemplate(Request $request): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        return new FileResponse(
            MemberImporter::template(),
            'text/csv; charset=utf-8',
            '委员名册导入模板.csv'
        );
    }

    public function importRun(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        $file = $_FILES['roster'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::set('error', '请选择要导入的 CSV 文件。');
            return new RedirectResponse('/admin/members/import');
        }
        $raw = (string) file_get_contents((string) $file['tmp_name']);
        if (trim($raw) === '') {
            Flash::set('error', '上传的文件是空的。');
            return new RedirectResponse('/admin/members/import');
        }

        // 每开一个账号要算一次 bcrypt（本机实测约 170 ms），291 人的名册就要跑 50 秒上下，
        // 超过 php.ini 里的 max_execution_time（默认 30 秒）会被掐断在半路，留下已开通、
        // 但初始密码没发出去的账号。这里按整份名册放宽这一次的执行时限。
        set_time_limit(300);

        try {
            $result = $this->importer->import($raw);
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            return new RedirectResponse('/admin/members/import');
        }

        // 密码只留在会话里：下载一次后立刻清掉，页面也不再显示（重置密码是唯一的重发路径）
        $_SESSION[self::IMPORT_CREDENTIALS] = $result['created'];
        $_SESSION[self::IMPORT_RESULT] = [
            'total'         => $result['total'],
            'created_count' => count($result['created']),
            'failed'        => $result['failed'],
        ];
        $this->log('member.import', 'member', '', [
            'created' => count($result['created']),
            'failed'  => count($result['failed']),
        ]);

        $message = '导入完成：成功 ' . count($result['created']) . ' 行，失败 ' . count($result['failed']) . ' 行。';
        if ($result['created'] !== []) {
            $message .= '请立刻下载初始密码清单并线下发给委员，下载一次后本页不再显示。';
        }
        Flash::set($result['failed'] === [] ? 'ok' : 'error', $message);

        return new RedirectResponse('/admin/members/import');
    }

    /** 一次性下载：导入产生的「姓名／登录名／初始密码」清单 */
    public function credentials(Request $request): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        $created = $_SESSION[self::IMPORT_CREDENTIALS] ?? null;
        if (!is_array($created) || $created === []) {
            Flash::set('error', '没有可下载的初始密码清单：它只在导入或手工新建后下载一次，需要重发请到委员管理里用「重置密码」。');
            return new RedirectResponse('/admin/members/import');
        }

        $csv = "\xEF\xBB\xBF" . "姓名,登录名,初始密码\n";
        foreach ($created as $row) {
            $csv .= implode(',', [
                self::csvCell((string) $row['name']),
                self::csvCell((string) $row['login_name']),
                self::csvCell((string) $row['password']),
            ]) . "\n";
        }
        unset($_SESSION[self::IMPORT_CREDENTIALS]);
        unset($_SESSION[self::MANUAL_RESULT]);
        $this->log('member.credentials', 'member', '', ['count' => count($created)]);

        return new FileResponse($csv, 'text/csv; charset=utf-8', '委员账号初始密码-' . date('Ymd-His') . '.csv');
    }

    /** @param array<string, string> $args */
    public function toggleStatus(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        $member = $this->members->find((int) $args['id']);
        if ($member === null) {
            Flash::set('error', '委员账号不存在。');
            return new RedirectResponse('/admin/members');
        }
        $target = (string) $member['status'] === 'enabled' ? 'disabled' : 'enabled';
        $this->members->updateStatus((int) $member['member_id'], $target);
        $this->log('member.status', 'member', (string) $member['member_id'], ['status' => $target]);

        Flash::set('ok', $target === 'enabled'
            ? '已启用账号：' . (string) $member['name']
            : '已停用账号：' . (string) $member['name'] . '（该委员随即无法登录）');
        return new RedirectResponse('/admin/members');
    }

    /** @param array<string, string> $args */
    public function resetPassword(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        $member = $this->members->find((int) $args['id']);
        if ($member === null) {
            Flash::set('error', '委员账号不存在。');
            return new RedirectResponse('/admin/members');
        }

        $password = MemberImporter::randomPassword();
        $this->members->setPassword((int) $member['member_id'], password_hash($password, PASSWORD_DEFAULT), true);
        $_SESSION[self::RESET_NOTICE] = [
            'name'       => (string) $member['name'],
            'login_name' => (string) $member['login_name'],
            'password'   => $password,
        ];
        $this->log('member.reset', 'member', (string) $member['member_id']);

        Flash::set('ok', '已重置密码，请把下面的新密码线下告知本人（只显示这一次）。');
        return new RedirectResponse('/admin/members');
    }

    /**
     * 批量重置密码：勾选若干委员，每人生成一个不同的随机密码，
     * 汇总成一份清单（下载即清），委员下次登录必须改密。
     */
    public function resetBatch(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        $ids = self::idList($_POST['member_ids'] ?? null);
        if ($ids === []) {
            Flash::set('error', '请先勾选要重置密码的委员。');
            return new RedirectResponse('/admin/members');
        }
        if (count($ids) > self::BATCH_LIMIT) {
            Flash::set('error', '一次最多重置 ' . self::BATCH_LIMIT . ' 个账号，请分批操作。');
            return new RedirectResponse('/admin/members');
        }

        // 每个账号都要算一次 bcrypt（与导入建号同一笔开销），整份名册重置会跑几十秒，
        // 不放开执行时限会被 max_execution_time（默认 30 秒）掐在循环中途：
        // 已改的密码落了库，而密码清单还没写进会话，谁也拿不到新密码。
        set_time_limit(300);

        $rows = $this->members->findMany($ids);
        if ($rows === []) {
            Flash::set('error', '勾选的账号都不存在，请刷新页面后重试。');
            return new RedirectResponse('/admin/members');
        }

        $credentials = [];
        foreach ($rows as $row) {
            $password = MemberImporter::randomPassword();
            $this->members->setPassword((int) $row['member_id'], password_hash($password, PASSWORD_DEFAULT), true);
            $credentials[] = [
                'name'       => (string) $row['name'],
                'login_name' => (string) $row['login_name'],
                'password'   => $password,
            ];
            // 逐行写回会话：万一请求还是被掐断，已经改过密码的账号也在清单里，不至于发不出去
            $_SESSION[self::BATCH_CREDENTIALS] = $credentials;
        }
        $this->log('member.reset_batch', 'member', '', ['count' => count($credentials)]);

        Flash::set('ok', '已重置 ' . count($credentials)
            . ' 个账号的密码。请立刻在页面顶部下载密码清单（下载一次后不再显示），线下发给委员。');
        return new RedirectResponse('/admin/members');
    }

    /** 批量重置的密码清单：下载一次即清空 */
    public function batchCredentials(Request $request): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::MEMBER_MANAGE)) {
            return $denied;
        }

        $credentials = $_SESSION[self::BATCH_CREDENTIALS] ?? null;
        if (!is_array($credentials) || $credentials === []) {
            Flash::set('error', '没有可下载的密码清单：它只在批量重置后下载一次，需要重发请重新重置。');
            return new RedirectResponse('/admin/members');
        }

        $csv = "\xEF\xBB\xBF" . "姓名,登录名,新密码\n";
        foreach ($credentials as $row) {
            $csv .= implode(',', [
                self::csvCell((string) $row['name']),
                self::csvCell((string) $row['login_name']),
                self::csvCell((string) $row['password']),
            ]) . "\n";
        }
        unset($_SESSION[self::BATCH_CREDENTIALS]);
        $this->log('member.reset_credentials', 'member', '', ['count' => count($credentials)]);

        return new FileResponse($csv, 'text/csv; charset=utf-8', '委员账号新密码-' . date('Ymd-His') . '.csv');
    }

    /**
     * 表单里的多选框（member_ids[]）：只留正整数，去重。
     *
     * @param mixed $raw
     * @return list<int>
     */
    private static function idList($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            $id = (int) (is_string($value) ? $value : 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private static function csvCell(string $value): string
    {
        // Excel 会把以 = + - @ 或制表符开头的单元格当公式执行，先加一个前导单引号挡掉
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            $value = "'" . $value;
        }
        if (preg_match('/[",\n\r]/', $value) === 1) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
