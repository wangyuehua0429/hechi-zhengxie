<?php

declare(strict_types=1);

namespace HechiZx\Admin;

use HechiZx\Content\Permissions;
use HechiZx\Http\FileResponse;
use HechiZx\Http\HtmlResponse;
use HechiZx\Http\RedirectResponse;
use HechiZx\Http\Request;
use HechiZx\Repository\UnitRepository;
use HechiZx\Support\Db;
use RuntimeException;

/**
 * 建议承办单位的市直单位清单维护：增删、停用／启用、改名、CSV 批量导入。
 * 清单由提案委自行维护，改名单不需要改代码；委员端只看到启用中的单位。
 */
final class UnitController extends AdminController
{
    private const IMPORT_RESULT = 'unit_import_result';

    public function __construct(
        Auth $auth,
        View $view,
        Db $db,
        int $siteId,
        private UnitRepository $units
    ) {
        parent::__construct($auth, $view, $db, $siteId);
    }

    public function index(Request $request): HtmlResponse|RedirectResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::UNIT_MANAGE)) {
            return $denied;
        }

        $filters = [
            'keyword' => (string) ($request->query('keyword', '') ?? ''),
            'status'  => (string) ($request->query('status', '') ?? ''),
        ];
        $import = $_SESSION[self::IMPORT_RESULT] ?? null;
        unset($_SESSION[self::IMPORT_RESULT]);

        return $this->view->page('admin/units', [
            'current' => 'units',
            'rows'    => $this->units->all($filters),
            'filters' => $filters,
            'counts'  => $this->units->statusCounts(),
            'maxUnits' => UnitRepository::MAX_PER_PROPOSAL,
            'import'  => is_array($import) ? $import : null,
        ], '市直单位');
    }

    public function create(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::UNIT_MANAGE)) {
            return $denied;
        }

        $name = $request->post('name');
        $sortNo = (int) $request->post('sort_no');
        try {
            $this->units->create($name, $sortNo, $request->post('remark'));
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            return new RedirectResponse('/admin/units');
        }
        $this->log('unit.create', 'unit', '', ['name' => $name]);

        Flash::set('ok', '已添加：' . $name);
        return new RedirectResponse('/admin/units');
    }

    /** @param array<string, string> $args */
    public function rename(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::UNIT_MANAGE)) {
            return $denied;
        }

        $unit = $this->units->find((int) $args['id']);
        if ($unit === null) {
            Flash::set('error', '清单里没有这个单位。');
            return new RedirectResponse('/admin/units');
        }

        try {
            $this->units->rename((int) $unit['unit_id'], $request->post('name'));
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            return new RedirectResponse('/admin/units');
        }
        $this->log('unit.rename', 'unit', (string) $unit['unit_id'], [
            'from' => (string) $unit['name'],
            'to'   => $request->post('name'),
        ]);

        Flash::set('ok', '已改名：' . (string) $unit['name'] . ' → ' . $request->post('name')
            . '（已提交提案里引用的名称同步更新）');
        return new RedirectResponse('/admin/units');
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
        if ($denied = $this->requirePermission(Permissions::UNIT_MANAGE)) {
            return $denied;
        }

        $unit = $this->units->find((int) $args['id']);
        if ($unit === null) {
            Flash::set('error', '清单里没有这个单位。');
            return new RedirectResponse('/admin/units');
        }
        $target = (string) $unit['status'] === 'enabled' ? 'disabled' : 'enabled';
        $this->units->updateStatus((int) $unit['unit_id'], $target);
        $this->log('unit.status', 'unit', (string) $unit['unit_id'], ['status' => $target]);

        Flash::set('ok', $target === 'enabled'
            ? '已启用：' . (string) $unit['name'] . '（委员端下拉里重新出现）'
            : '已停用：' . (string) $unit['name'] . '（已提交的提案不受影响）');
        return new RedirectResponse('/admin/units');
    }

    /** @param array<string, string> $args */
    public function remove(Request $request, array $args): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::UNIT_MANAGE)) {
            return $denied;
        }

        $unit = $this->units->find((int) $args['id']);
        if ($unit === null) {
            Flash::set('error', '清单里没有这个单位。');
            return new RedirectResponse('/admin/units');
        }

        try {
            $this->units->delete((int) $unit['unit_id']);
        } catch (RuntimeException $e) {
            Flash::set('error', $e->getMessage());
            return new RedirectResponse('/admin/units');
        }
        $this->log('unit.delete', 'unit', (string) $unit['unit_id'], ['name' => (string) $unit['name']]);

        Flash::set('ok', '已从清单里删除：' . (string) $unit['name']);
        return new RedirectResponse('/admin/units');
    }

    public function importTemplate(Request $request): HtmlResponse|RedirectResponse|FileResponse
    {
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::UNIT_MANAGE)) {
            return $denied;
        }

        return new FileResponse(
            UnitRepository::template(),
            'text/csv; charset=utf-8',
            '市直单位清单导入模板.csv'
        );
    }

    public function import(Request $request): HtmlResponse|RedirectResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        if ($redirect = $this->requireLogin()) {
            return $redirect;
        }
        if ($denied = $this->requirePermission(Permissions::UNIT_MANAGE)) {
            return $denied;
        }

        $file = $_FILES['units'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::set('error', '请选择要导入的 CSV 文件。');
            return new RedirectResponse('/admin/units');
        }
        $raw = (string) file_get_contents((string) $file['tmp_name']);
        if (trim($raw) === '') {
            Flash::set('error', '上传的文件是空的。');
            return new RedirectResponse('/admin/units');
        }

        $result = $this->units->importCsv($raw);
        $_SESSION[self::IMPORT_RESULT] = $result;
        $this->log('unit.import', 'unit', '', [
            'created' => $result['created'],
            'skipped' => count($result['skipped']),
            'failed'  => count($result['failed']),
        ]);

        Flash::set(
            $result['failed'] === [] ? 'ok' : 'error',
            '导入完成：新增 ' . $result['created'] . ' 个，跳过 ' . count($result['skipped'])
                . ' 个（已在清单里），失败 ' . count($result['failed']) . ' 个。'
        );
        return new RedirectResponse('/admin/units');
    }
}
