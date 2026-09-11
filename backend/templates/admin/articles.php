<?php

/**
 * 稿件列表：筛选 + 分页。
 *
 * @var array{channel:string,status:string,keyword:string} $filters
 * @var list<array<string, mixed>> $items
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var list<array<string, mixed>> $channels
 */

declare(strict_types=1);

$statusLabels = ['published' => '已发布', 'draft' => '草稿', 'offline' => '已下线'];
$query = static function (array $extra) use ($filters): string {
    return '/admin/articles?' . http_build_query(array_merge($filters, $extra));
};
?>
<div class="page-head">
  <h1>稿件管理</h1>
  <a class="btn-primary" href="/admin/article/new<?= $filters['channel'] !== '' ? '?channel=' . hechi_e($filters['channel']) : '' ?>">新建稿件</a>
</div>

<form class="filters" method="get" action="/admin/articles">
  <label>栏目
    <select name="channel">
      <option value="">全部栏目</option>
      <?php foreach ($channels as $ch): ?>
        <option value="<?= hechi_e($ch['type_code']) ?>"<?= $filters['channel'] === (string) $ch['type_code'] ? ' selected' : '' ?>>
          <?= hechi_e($ch['inner_name'] . '（' . $ch['type_code'] . '）') ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>状态
    <select name="status">
      <option value="">全部状态</option>
      <?php foreach ($statusLabels as $key => $label): ?>
        <option value="<?= hechi_e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= hechi_e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>标题关键词
    <input type="text" name="keyword" value="<?= hechi_e($filters['keyword']) ?>" placeholder="如：政协">
  </label>
  <button type="submit" class="btn">筛选</button>
</form>

<p class="muted">共 <?= (int) $total ?> 篇，第 <?= (int) $page ?>/<?= (int) $pages ?> 页</p>

<table class="grid">
  <thead><tr><th>ID</th><th>标题</th><th>栏目</th><th>发布时间</th><th>状态</th><th>正文</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td class="nowrap"><?= (int) $item['article_id'] ?></td>
        <td><?= hechi_e($item['title']) ?></td>
        <td class="nowrap"><?= hechi_e($item['channel_inner'] ?? $item['channel_name'] ?? '') ?></td>
        <td class="nowrap"><?= hechi_e(substr((string) $item['published_at'], 0, 16)) ?></td>
        <td class="nowrap"><span class="tag tag-<?= hechi_e((string) $item['status']) ?>"><?= hechi_e($statusLabels[(string) $item['status']] ?? $item['status']) ?></span></td>
        <td class="nowrap"><?= (int) $item['has_body'] === 1 ? '有' : '—' ?></td>
        <td class="nowrap"><a href="/admin/article/<?= (int) $item['article_id'] ?>">编辑</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?>
      <tr><td colspan="7" class="empty">没有符合条件的稿件。</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="<?= hechi_e($query(['page' => $page - 1])) ?>">上一页</a><?php endif; ?>
    <span>第 <?= (int) $page ?> 页 / 共 <?= (int) $pages ?> 页</span>
    <?php if ($page < $pages): ?><a href="<?= hechi_e($query(['page' => $page + 1])) ?>">下一页</a><?php endif; ?>
  </nav>
<?php endif; ?>
