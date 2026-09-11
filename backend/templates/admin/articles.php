<?php

/**
 * 稿件列表：稿库导航条（替代状态下拉）+ 栏目导航条 + 关键词，行内动作随状态变化。
 *
 * @var array{channel:string,status:string,keyword:string} $filters
 * @var list<array<string, mixed>> $items
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var list<array<string, mixed>> $channels
 * @var list<array{key:string,title:string,channels:list<array<string,mixed>>}> $navGroups
 * @var array<string, int> $statusCounts
 * @var array<string, array{status:string,label:string,public:bool,sort:int}> $places
 * @var array<string, array<string, mixed>> $transitions
 */

declare(strict_types=1);

$places = $places ?? [];
$transitions = $transitions ?? [];
$statusCounts = $statusCounts ?? [];

$current = (string) $filters['status'];
if ($current === 'offline') {
    $current = 'withdrawn';
}
$allCount = 0;
foreach ($statusCounts as $n) {
    $allCount += (int) $n;
}

// 稿库 / 分页链接都要保留栏目与关键词。注意别叫 $link：
// 下面 include 的 _channel_nav.php 自己定义了 $link，重名会被覆盖。
$vaultLink = static function (array $extra) use ($filters, $current): string {
    $params = array_filter([
        'channel' => $filters['channel'],
        'keyword' => $filters['keyword'],
    ], static fn (string $value): bool => $value !== '');
    foreach ($extra as $key => $value) {
        if ($key === 'status' && (string) $value === $current) {
            continue;
        }
        if ((string) $value === '') {
            unset($params[$key]);
            continue;
        }
        $params[$key] = (string) $value;
    }
    return '/admin/articles' . ($params === [] ? '' : '?' . http_build_query($params));
};

$newUrl = '/admin/article/new' . ($filters['channel'] !== '' ? '?channel=' . hechi_e($filters['channel']) : '');
?>
<div class="page-head">
  <h1>稿件管理</h1>
  <a class="btn-primary" href="<?= $newUrl ?>">新建稿件</a>
</div>

<nav class="vault-nav" aria-label="稿库">
  <a class="vault-chip<?= $current === '' ? ' active' : '' ?>"
     href="<?= hechi_e($vaultLink(['status' => ''])) ?>">全部<span class="vault-num"><?= (int) $allCount ?></span></a>
  <?php foreach ($places as $place): ?>
    <?php $status = (string) $place['status']; ?>
    <a class="vault-chip vault-chip--<?= hechi_e($status) ?><?= $current === $status ? ' active' : '' ?>"
       href="<?= hechi_e($vaultLink(['status' => $status])) ?>"
       <?= $current === $status ? 'aria-current="page"' : '' ?>><?= hechi_e((string) $place['label']) ?><span class="vault-num"><?= (int) ($statusCounts[$status] ?? 0) ?></span></a>
  <?php endforeach; ?>
</nav>

<form class="filters" method="get" action="/admin/articles">
  <input type="hidden" name="channel" value="<?= hechi_e($filters['channel']) ?>">
  <input type="hidden" name="status" value="<?= hechi_e($current) ?>">
  <label>标题关键词
    <input type="text" name="keyword" value="<?= hechi_e($filters['keyword']) ?>" placeholder="如：政协">
  </label>
  <button type="submit" class="btn">筛选</button>
</form>

<?php
$navUrl = '/admin/articles';
$navActive = $filters['channel'];
$navQuery = array_filter([
    'status'  => $current,
    'keyword' => $filters['keyword'],
], static fn (string $value): bool => $value !== '');
$navAllLabel = '全部栏目';
include __DIR__ . '/_channel_nav.php';
?>

<p class="muted">共 <?= (int) $total ?> 篇，第 <?= (int) $page ?>/<?= (int) $pages ?> 页</p>

<table class="grid">
  <thead><tr><th>ID</th><th>标题</th><th>栏目</th><th>发布时间</th><th>状态</th><th>最近动作</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($items as $item): ?>
      <?php
      $id = (int) $item['article_id'];
      $statusKey = (string) $item['status'];
      $actions = $item['flow_actions'] ?? [];
      $recent = '';
      if ($statusKey === 'published' && (string) ($item['published_at'] ?? '') !== '') {
          $recent = '发布 ' . substr((string) $item['published_at'], 0, 16);
      } elseif ($statusKey === 'pending' && (string) ($item['submitted_at'] ?? '') !== '') {
          $recent = '提交 ' . substr((string) $item['submitted_at'], 0, 16);
      } elseif ($statusKey === 'withdrawn' && (string) ($item['withdrawn_at'] ?? '') !== '') {
          $recent = '撤回 ' . substr((string) $item['withdrawn_at'], 0, 16);
      } elseif ($statusKey === 'deleted' && (string) ($item['deleted_at'] ?? '') !== '') {
          $recent = '入回收站 ' . substr((string) $item['deleted_at'], 0, 16);
      }
      ?>
      <tr>
        <td class="nowrap"><?= $id ?></td>
        <td><?= hechi_e($item['title']) ?></td>
        <td class="nowrap"><?= hechi_e($item['channel_inner'] ?? $item['channel_name'] ?? '') ?></td>
        <td class="nowrap"><?= hechi_e(substr((string) $item['published_at'], 0, 16)) ?></td>
        <td class="nowrap"><span class="tag tag-<?= hechi_e($statusKey) ?>"><?= hechi_e((string) ($item['flow_state'] ?? $statusKey)) ?></span></td>
        <td class="nowrap muted"><?= hechi_e($recent !== '' ? $recent : '—') ?></td>
        <td class="nowrap row-actions">
          <?php foreach ($actions as $action): ?>
            <?php $rule = $transitions[$action] ?? null; ?>
            <?php if ($rule === null): ?><?php continue; ?><?php endif; ?>
            <?php if (!empty($rule['needNote'])): ?>
              <a href="/admin/article/<?= $id ?>#flow"><?= hechi_e((string) $rule['label']) ?>…</a>
            <?php else: ?>
              <form method="post" action="/admin/article/<?= $id ?>/flow" class="inline">
                <?= $csrf ?>
                <input type="hidden" name="action" value="<?= hechi_e((string) $action) ?>">
                <button type="submit" class="link-btn<?= !empty($rule['danger']) ? ' link-danger' : '' ?>"><?= hechi_e((string) $rule['label']) ?></button>
              </form>
            <?php endif; ?>
          <?php endforeach; ?>
          <a href="/admin/article/<?= $id ?>">编辑</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?>
      <tr><td colspan="7" class="empty">没有符合条件的稿件。</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="<?= hechi_e($vaultLink(['status' => $current, 'page' => $page - 1])) ?>">上一页</a><?php endif; ?>
    <span>第 <?= (int) $page ?> 页 / 共 <?= (int) $pages ?> 页</span>
    <?php if ($page < $pages): ?><a href="<?= hechi_e($vaultLink(['status' => $current, 'page' => $page + 1])) ?>">下一页</a><?php endif; ?>
  </nav>
<?php endif; ?>
