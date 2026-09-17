<?php

/**
 * 提案收件：筛选、状态统计、列表与导出。
 *
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array<string, string> $filters
 * @var array<string, int> $counts
 * @var list<string> $categories
 * @var array<string, string> $statuses
 * @var bool $canExport
 */

declare(strict_types=1);

$query = static function (array $override) use ($filters): string {
    $merged = array_filter(array_merge($filters, $override), static fn ($value): bool => (string) $value !== '');
    return $merged === [] ? '' : '?' . http_build_query($merged);
};
?>
<div class="page-head">
  <h1>提案收件</h1>
  <?php if ($canExport): ?>
    <div class="row-actions">
      <a class="btn" href="/admin/proposals/export.xlsx<?= hechi_e($query([])) ?>">导出收件清单（Excel）</a>
      <a class="btn" href="/admin/proposals/export.docx<?= hechi_e($query([])) ?>">批量导出提案表（Word，单次最多 <?= (int) ($wordLimit ?? 200) ?> 件）</a>
      <?php if ((int) $total > (int) ($wordLimit ?? 200)): ?>
        <?php /* 超限时先说清楚，不让人点一次、被挡回来才知道 */ ?>
        <span class="muted">当前筛选 <?= (int) $total ?> 件，超过 Word 批量导出的单次上限，直接点会被挡回并提示分批；请先按状态或提交日期缩小范围。</span>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<p class="muted">
  委员在门户提交后在这里收件；受理前可调整案由、正文、承办单位等内容，受理即确认收下，
  退回须写明意见，委员可在门户里看到意见并修改后重新提交。本系统不含交办与答复环节，交办后按线下流程办理。
</p>

<p class="muted">
  共 <?= (int) $total ?> 件：
  <?php foreach ($statuses as $key => $label): ?>
    <a href="/admin/proposals<?= hechi_e($query(['status' => (string) $key, 'page' => ''])) ?>"><?= hechi_e($label) ?> <?= (int) ($counts[$key] ?? 0) ?></a><?= $key === array_key_last($statuses) ? '' : ' · ' ?>
  <?php endforeach; ?>
  <?php if ($filters['status'] !== '' || $filters['category'] !== '' || $filters['keyword'] !== '' || $filters['sector'] !== '' || $filters['unit'] !== '' || $filters['from'] !== '' || $filters['to'] !== ''): ?>
    ·<a href="/admin/proposals">清除筛选</a>
  <?php endif; ?>
</p>

<form class="filters" method="get" action="/admin/proposals">
  <label>状态
    <select name="status">
      <option value="">全部</option>
      <?php foreach ($statuses as $key => $label): ?>
        <option value="<?= hechi_e($key) ?>"<?= $filters['status'] === (string) $key ? ' selected' : '' ?>><?= hechi_e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>提案类别
    <select name="category">
      <option value="">全部</option>
      <?php foreach ($categories as $category): ?>
        <option value="<?= hechi_e($category) ?>"<?= $filters['category'] === $category ? ' selected' : '' ?>><?= hechi_e($category) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>界别
    <input type="text" name="sector" value="<?= hechi_e($filters['sector']) ?>" placeholder="如：经济界">
  </label>
  <label>承办单位
    <input type="text" name="unit" value="<?= hechi_e($filters['unit']) ?>" placeholder="如：住房和城乡建设局">
  </label>
  <label>关键词
    <input type="text" name="keyword" value="<?= hechi_e($filters['keyword']) ?>" placeholder="案由或提案人姓名">
  </label>
  <label>提交日期（起）
    <input type="date" name="from" value="<?= hechi_e($filters['from']) ?>">
  </label>
  <label>提交日期（止）
    <input type="date" name="to" value="<?= hechi_e($filters['to']) ?>">
  </label>
  <button type="submit" class="btn-primary">筛选</button>
</form>

<div class="table-scroll">
  <table class="grid">
    <caption class="visually-hidden">提案收件列表</caption>
    <thead>
      <tr>
        <th>提案号</th>
        <th>案由</th>
        <th>提案人</th>
        <th>界别</th>
        <th>类别</th>
        <th>建议承办单位</th>
        <th>提交时间</th>
        <th>状态</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <?php $status = \HechiZx\Content\ProposalWorkflow::normalize((string) $row['status']); ?>
        <tr>
          <td class="nowrap"><?= (int) $row['proposal_id'] ?></td>
          <td><a href="/admin/proposal/<?= (int) $row['proposal_id'] ?>"><?= hechi_e((string) $row['title']) ?></a></td>
          <td class="nowrap"><?= hechi_e((string) $row['proposer_name']) ?></td>
          <td class="nowrap"><?= hechi_e((string) $row['sector']) ?></td>
          <td class="nowrap"><?= hechi_e((string) $row['category']) ?></td>
          <td><?= hechi_e((string) $row['host_units']) ?></td>
          <td class="nowrap"><?= hechi_e((string) ($row['submitted_at'] ?? '')) ?></td>
          <td class="nowrap">
            <span class="tag tag-<?= hechi_e($status) ?>"><?= hechi_e(\HechiZx\Content\ProposalWorkflow::label($status)) ?></span>
          </td>
          <td class="nowrap"><a href="/admin/proposal/<?= (int) $row['proposal_id'] ?>">查看</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?>
        <tr><td colspan="9" class="empty">没有符合条件的提案。</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
  <nav class="pager" aria-label="分页">
    <?php if ($page > 1): ?>
      <a class="btn btn-sm" href="/admin/proposals<?= hechi_e($query(['page' => (string) ($page - 1)])) ?>">上一页</a>
    <?php endif; ?>
    <span>第 <?= (int) $page ?> / <?= (int) $pages ?> 页</span>
    <?php if ($page < $pages): ?>
      <a class="btn btn-sm" href="/admin/proposals<?= hechi_e($query(['page' => (string) ($page + 1)])) ?>">下一页</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
