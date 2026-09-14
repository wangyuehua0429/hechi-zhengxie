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

        return $this->view->page('admin/members', [
            'current' => 'members',
            'rows'    => $result['rows'],
            'total'   => $result['total'],
            'page'    => $page,
            'pages'   => max(1, (int) ceil($result['total'] / self::PAGE_SIZE)),
            'filters' => $filters,
            'counts'  => $this->members->statusCounts(),
            'reset'   => is_array($reset) ? $reset : null,
        ], '委员管理');
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
            Flash::set('error', '没有可下载的初始密码清单：它只在导入后下载一次，需要重发请到委员管理里用「重置密码」。');
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
