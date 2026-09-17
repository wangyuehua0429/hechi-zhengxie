<?php

/**
 * 市直单位清单维护：委员端「建议承办单位」下拉的可选项。
 *
 * @var list<array<string, mixed>> $rows
 * @var array<string, string> $filters
 * @var array<string, int> $counts
 * @var int $maxUnits
 * @var array{created:int,skipped:list<string>,failed:list<string>}|null $import
 * @var string $csrf
 */

declare(strict_types=1);
?>
<div class="page-head">
  <h1>市直单位</h1>
  <a class="btn" href="/admin/units/import/template.csv">下载导入模板</a>
</div>

<p class="muted">
  委员填写提案时，「建议承办单位」从这里启用中的单位里选，每份最多选 <?= (int) $maxUnits ?> 个。
  共 <?= count($rows) ?> 条：启用 <?= (int) ($counts['enabled'] ?? 0) ?>、停用 <?= (int) ($counts['disabled'] ?? 0) ?>。
  停用的单位不再出现在下拉里，已经选了它的提案不受影响。
</p>

<?php if ($import !== null): ?>
  <div class="notice">
    <strong>导入结果：</strong>新增 <?= (int) $import['created'] ?> 个，跳过 <?= count($import['skipped']) ?> 个，失败 <?= count($import['failed']) ?> 个。
    <?php if ($import['skipped'] !== [] || $import['failed'] !== []): ?>
      <ul>
        <?php foreach (array_merge($import['skipped'], $import['failed']) as $item): ?>
          <li><?= hechi_e($item) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <h2>添加单位</h2>
    <span class="muted">单个添加；名单调整由提案委自己维护，不用找开发</span>
  </div>
  <form method="post" action="/admin/units" class="edit-form">
    <?= $csrf ?>
    <div class="row">
      <label class="grow">单位名称
        <input type="text" name="name" maxlength="128" required placeholder="如：河池市住房和城乡建设局">
      </label>
      <label>排序号
        <input type="number" name="sort_no" value="0" min="0" max="9999">
      </label>
      <label class="grow">备注
        <input type="text" name="remark" maxlength="200" placeholder="选填，如：2026 年新增">
      </label>
      <label class="check">&nbsp;
        <button type="submit" class="btn-primary">添加</button>
      </label>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-head">
    <h2>批量导入</h2>
    <span class="muted">两列：单位名称、排序号（排序号可留空）；UTF-8 / GBK 都能识别</span>
  </div>
  <form method="post" action="/admin/units/import" enctype="multipart/form-data" class="edit-form">
    <?= $csrf ?>
    <div class="row">
      <label class="grow">CSV 文件
        <input type="file" name="units" accept=".csv,text/csv" required>
      </label>
      <label class="check">&nbsp;
        <button type="submit" class="btn-primary">导入</button>
      </label>
    </div>
  </form>
</div>

<form class="filters" method="get" action="/admin/units">
  <label>关键词
    <input type="text" name="keyword" value="<?= hechi_e($filters['keyword']) ?>" placeholder="单位名称">
  </label>
  <label>状态
    <select name="status">
      <option value="">全部</option>
      <option value="enabled"<?= $filters['status'] === 'enabled' ? ' selected' : '' ?>>启用</option>
      <option value="disabled"<?= $filters['status'] === 'disabled' ? ' selected' : '' ?>>停用</option>
    </select>
  </label>
  <button type="submit" class="btn-primary">筛选</button>
</form>

<div class="table-scroll">
  <table class="grid">
    <caption class="visually-hidden">市直单位清单</caption>
    <thead>
      <tr>
        <th>排序</th>
        <th>单位名称</th>
        <th>备注</th>
        <th>被提案选用</th>
        <th>状态</th>
        <th>操作</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="nowrap"><?= (int) $row['sort_no'] ?></td>
          <td>
            <form method="post" action="/admin/unit/<?= (int) $row['unit_id'] ?>/rename" class="inline">
              <?= $csrf ?>
              <input type="text" name="name" value="<?= hechi_e((string) $row['name']) ?>" maxlength="128" required>
              <button type="submit" class="btn btn-sm">改名</button>
            </form>
          </td>
          <td><?= hechi_e((string) $row['remark']) ?></td>
          <td class="nowrap"><?= (int) $row['used_count'] ?> 件</td>
          <td class="nowrap">
            <span class="tag tag-<?= (string) $row['status'] === 'enabled' ? 'published' : 'offline' ?>">
              <?= (string) $row['status'] === 'enabled' ? '启用' : '停用' ?>
            </span>
          </td>
          <td class="col-actions">
            <div class="row-actions">
              <form method="post" action="/admin/unit/<?= (int) $row['unit_id'] ?>/status">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm"><?= (string) $row['status'] === 'enabled' ? '停用' : '启用' ?></button>
              </form>
              <?php if ((int) $row['used_count'] === 0): ?>
                <form method="post" action="/admin/unit/<?= (int) $row['unit_id'] ?>/delete">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger-outline">删除</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?>
        <tr><td colspan="6" class="empty">清单还是空的。请提案委提供市直单位名单后，用上面的「批量导入」一次导入。</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
