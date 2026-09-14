<?php

/**
 * 稿件列表：一个筛选面板（稿库 + 栏目 + 条件）+ 内容优先的表格 + 批量操作条。
 *
 * 三条设计原则（改动理由见 docs/后台稿件与栏目管理重构说明.md）：
 * 1. 稿库、栏目、关键词同处一块「筛选面板」，不再上下分三处各自为政；
 * 2. 表格以稿件内容为主：标题即入口，ID 退到次要行，状态与最近动作合并成一列；
 * 3. 批量操作、排序、每页条数与分页跳转都在列表页完成，不必来回点栏目导航。
 *
 * @var array{channel:string,group:string,status:string,keyword:string,sort:string,order:string} $filters
 * @var list<array<string, mixed>> $items
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var int $pageSize
 * @var list<int> $pageSizes
 * @var array<string, string> $sorts
 * @var array<string, array{label:string, danger:bool, needNote:bool, noteLabel:string}> $bulkActions
 * @var list<array<string, mixed>> $channels
 * @var list<array{key:string,title:string,channels:list<array<string,mixed>>}> $navGroups
 * @var array<string, int> $navCounts
 * @var array<string, int> $statusCounts
 * @var array<string, array{status:string,label:string,public:bool,sort:int}> $places
 * @var array<string, array<string, mixed>> $transitions
 */

declare(strict_types=1);

use HechiZx\Content\ArticleWorkflow;

$places = $places ?? [];
$transitions = $transitions ?? [];
$statusCounts = $statusCounts ?? [];
$adminIcons = require __DIR__ . '/_icons.php';
$pageSizes = $pageSizes ?? [20];
$sorts = $sorts ?? ['published_at' => '发布时间'];
$bulkActions = $bulkActions ?? [];
$pageSize = (int) ($pageSize ?? 20);

// 兼容旧值 offline：稿件列表里它和已撤回是同一库
$current = (string) $filters['status'];
if ($current === 'offline') {
    $current = 'withdrawn';
}
$allCount = 0;
foreach ($statusCounts as $count) {
    $allCount += (int) $count;
}

$sortValue = (string) $filters['sort'] . ':' . (string) $filters['order'];

/** 列表链接：保留当前筛选，覆盖指定参数；空的参数不进地址栏 */
$listUrl = static function (array $override = []) use ($filters, $pageSize): string {
    $params = [
        'channel' => (string) $filters['channel'],
        'group'   => (string) ($filters['group'] ?? ''),
        'status'  => (string) $filters['status'],
        'keyword' => (string) $filters['keyword'],
        'size'    => $pageSize === 20 ? '' : (string) $pageSize,
        'sort'    => ((string) $filters['sort'] . ':' . (string) $filters['order']) === 'published_at:desc'
            ? ''
            : (string) $filters['sort'] . ':' . (string) $filters['order'],
    ];
    foreach ($override as $key => $value) {
        $params[(string) $key] = (string) $value;
    }
    $params = array_filter($params, static fn (string $value): bool => $value !== '');
    return '/admin/articles' . ($params === [] ? '' : '?' . http_build_query($params));
};

$currentUrl = $listUrl(['page' => $page > 1 ? (string) $page : '']);
$newUrl = '/admin/article/new' . ($filters['channel'] !== '' ? '?channel=' . rawurlencode((string) $filters['channel']) : '');

// 当前筛选的自然语言摘要：让人一眼知道「我现在看的是哪一批稿」
$channelLabel = '';
foreach ($channels as $channel) {
    if ((string) $channel['type_code'] === (string) $filters['channel']) {
        $channelLabel = (string) $channel['inner_name'];
    }
}
// 按一级栏目整组筛选时，摘要里写清楚「整个栏目」，避免与同号的子栏目混淆
if ((string) ($filters['group'] ?? '') !== '') {
    foreach ($navGroups as $group) {
        if ((string) $group['key'] === (string) $filters['group']) {
            $channelLabel = (string) $group['title'] . '（整个栏目）';
        }
    }
}
$activeFilters = [];
// 按单个栏目筛选时，可以直接在列表里调整该栏目内的顺序（置顶稿始终在前）
$canOrder = in_array('article.edit', $userPerms ?? [], true);
$orderable = $canOrder && (string) $filters['channel'] !== '';
if ($channelLabel !== '') {
    $activeFilters[] = '栏目：' . $channelLabel;
}
if ($current !== '') {
    $activeFilters[] = '稿库：' . ArticleWorkflow::label($current);
}
if ((string) $filters['keyword'] !== '') {
    $activeFilters[] = '关键词：「' . (string) $filters['keyword'] . '」';
}
?>
<div class="page-head">
  <div class="page-title">
    <h1>稿件管理</h1>
    <p class="subtitle">
      共 <strong><?= (int) $total ?></strong> 篇稿件
      <?= $activeFilters === [] ? '（全部）' : '· 已筛选 ' . hechi_e(implode('　', $activeFilters)) ?>
      <?= $orderable ? '· 可用「↑ ↓」调整该栏目内的顺序' : '' ?>
      <?php if ($activeFilters !== []): ?>
        <a class="clear-filter" href="/admin/articles">清除筛选</a>
      <?php endif; ?>
    </p>
  </div>
  <div class="head-actions">
    <?php if (in_array('article.edit', $userPerms ?? [], true)): ?>
      <a class="btn-primary" href="<?= hechi_e($newUrl) ?>">新建稿件</a>
    <?php endif; ?>
  </div>
</div>

<section class="filter-panel" aria-label="稿件筛选">
  <div class="filter-row">
    <span class="filter-label" id="filter-vault-label">稿库</span>
    <nav class="vault-nav" aria-labelledby="filter-vault-label">
      <a class="vault-chip<?= $current === '' ? ' active' : '' ?>"
         href="<?= hechi_e($listUrl(['status' => '', 'page' => ''])) ?>">全部<span class="vault-num"><?= (int) $allCount ?></span></a>
      <?php foreach ($places as $place): ?>
        <?php $status = (string) $place['status']; ?>
        <a class="vault-chip vault-chip--<?= hechi_e($status) ?><?= $current === $status ? ' active' : '' ?>"
           href="<?= hechi_e($listUrl(['status' => $status, 'page' => ''])) ?>"
           <?= $current === $status ? 'aria-current="page"' : '' ?>><?= hechi_e((string) $place['label']) ?><span class="vault-num"><?= (int) ($statusCounts[$status] ?? 0) ?></span></a>
      <?php endforeach; ?>
    </nav>
  </div>

  <div class="filter-row">
    <span class="filter-label" id="filter-channel-label">栏目</span>
    <div class="filter-grow">
      <?php
      $navUrl = '/admin/articles';
      $navActive = (string) $filters['channel'];
      $navGroup = (string) ($filters['group'] ?? '');
      $navCounts = $navCounts ?? [];
      $navQuery = array_filter([
          'status'  => $current,
          'keyword' => (string) $filters['keyword'],
          'size'    => $pageSize === 20 ? '' : (string) $pageSize,
          'sort'    => $sortValue === 'published_at:desc' ? '' : $sortValue,
      ], static fn (string $value): bool => $value !== '');
      $navAllLabel = '全部栏目';
      include __DIR__ . '/_channel_nav.php';
      ?>
    </div>
  </div>

  <form class="filter-form" method="get" action="/admin/articles">
    <input type="hidden" name="channel" value="<?= hechi_e((string) $filters['channel']) ?>">
    <input type="hidden" name="group" value="<?= hechi_e((string) ($filters['group'] ?? '')) ?>">
    <input type="hidden" name="status" value="<?= hechi_e($current) ?>">
    <label class="filter-field">标题或摘要关键词
      <input type="search" name="keyword" value="<?= hechi_e((string) $filters['keyword']) ?>" placeholder="如：政协">
    </label>
    <label class="filter-field">排序
      <select name="sort">
        <?php foreach ($sorts as $field => $label): ?>
          <?php foreach (['desc' => '新→旧', 'asc' => '旧→新'] as $order => $arrow): ?>
            <?php $value = $field . ':' . $order; ?>
            <option value="<?= hechi_e($value) ?>"<?= $sortValue === $value ? ' selected' : '' ?>><?= hechi_e($label) ?>（<?= hechi_e($arrow) ?>）</option>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="filter-field">每页
      <select name="size">
        <?php foreach ($pageSizes as $size): ?>
          <option value="<?= (int) $size ?>"<?= $pageSize === (int) $size ? ' selected' : '' ?>><?= (int) $size ?> 条</option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn">筛选</button>
    <?php if ($activeFilters !== []): ?>
      <a class="btn btn-ghost" href="/admin/articles">重置</a>
    <?php endif; ?>
  </form>
</section>

<?php if ($bulkActions !== []): ?>
  <form method="post" action="/admin/articles/bulk" id="bulk-form" class="bulk-form">
    <?= $csrf ?>
    <input type="hidden" name="back" value="<?= hechi_e($currentUrl) ?>">
    <div class="bulk-bar" data-bulk-bar>
      <span class="bulk-count">已选 <strong data-bulk-count>0</strong> 篇</span>
      <label class="bulk-field">批量操作
        <select name="action" data-bulk-action>
          <option value="">请选择…</option>
          <?php foreach ($bulkActions as $action => $rule): ?>
            <option value="<?= hechi_e((string) $action) ?>" data-need-note="<?= $rule['needNote'] ? '1' : '0' ?>"><?= hechi_e($rule['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="bulk-field" data-bulk-note hidden>备注
        <input type="text" name="note" maxlength="200" placeholder="撤回原因或退回意见，必填">
      </label>
      <button type="submit" class="btn-primary">执行</button>
      <button type="button" class="btn btn-ghost" data-bulk-clear>取消选择</button>
      <span class="muted bulk-hint">每次最多 100 篇；「移入回收站」是单篇操作，要逐篇确认。</span>
    </div>
  </form>
<?php endif; ?>

<p class="muted list-meta">共 <?= (int) $total ?> 篇，第 <?= (int) $page ?>/<?= (int) $pages ?> 页</p>

<div class="table-scroll">
<table class="grid article-table">
  <caption class="visually-hidden">稿件列表</caption>
  <thead>
    <tr>
      <?php if ($bulkActions !== []): ?>
        <th class="bulk-col" scope="col"><input type="checkbox" data-select-all aria-label="全选本页稿件"></th>
      <?php endif; ?>
      <th scope="col">稿件</th>
      <th scope="col">栏目</th>
      <th scope="col" class="col-date">
        <?php $nextOrder = $filters['sort'] === 'published_at' && $filters['order'] === 'desc' ? 'asc' : 'desc'; ?>
        <a class="sort-link" href="<?= hechi_e($listUrl(['sort' => 'published_at:' . $nextOrder, 'page' => ''])) ?>">
          发布时间<span class="sort-mark"><?= $filters['sort'] === 'published_at' ? ($filters['order'] === 'desc' ? '↓' : '↑') : '↕' ?></span>
        </a>
      </th>
      <th scope="col">状态</th>
      <th scope="col" class="col-actions">操作</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $item): ?>
      <?php
      $id = (int) $item['article_id'];
      $statusKey = ArticleWorkflow::normalize((string) $item['status']);
      $recent = '';
      if ($statusKey === ArticleWorkflow::PUBLISHED && (string) ($item['published_at'] ?? '') !== '') {
          $recent = '发布 ' . substr((string) $item['published_at'], 0, 16);
      } elseif ($statusKey === ArticleWorkflow::PENDING && (string) ($item['submitted_at'] ?? '') !== '') {
          $recent = '提交 ' . substr((string) $item['submitted_at'], 0, 16);
      } elseif ($statusKey === ArticleWorkflow::WITHDRAWN && (string) ($item['withdrawn_at'] ?? '') !== '') {
          $recent = '撤回 ' . substr((string) $item['withdrawn_at'], 0, 16);
      } elseif ($statusKey === ArticleWorkflow::DELETED && (string) ($item['deleted_at'] ?? '') !== '') {
          $recent = '入回收站 ' . substr((string) $item['deleted_at'], 0, 16);
      } elseif ((string) ($item['updated_at'] ?? '') !== '') {
          $recent = '更新 ' . substr((string) $item['updated_at'], 0, 16);
      }
      $title = (string) $item['title'];
      ?>
      <tr>
        <?php if ($bulkActions !== []): ?>
          <td class="bulk-col">
            <input type="checkbox" form="bulk-form" name="ids[]" value="<?= $id ?>"
                   data-row-select aria-label="选择稿件：<?= hechi_e($title) ?>">
          </td>
        <?php endif; ?>
        <td class="col-title">
          <a class="title-link" href="/admin/article/<?= $id ?>"><?= hechi_e($title) ?></a>
          <?php if (trim((string) ($item['subtitle'] ?? '')) !== ''): ?>
            <span class="row-sub"><?= hechi_e((string) $item['subtitle']) ?></span>
          <?php endif; ?>
          <span class="row-meta">
            #<?= $id ?>
            <?php if ((int) ($item['is_top'] ?? 0) === 1): ?> <span class="tag tag-top">置顶</span><?php endif; ?>
            <?php if (trim((string) ($item['author'] ?? '')) !== ''): ?> · 作者 <?= hechi_e((string) $item['author']) ?><?php endif; ?>
          </span>
        </td>
        <td class="col-channel">
          <span class="channel-path"><?= hechi_e((string) ($item['channel_name'] ?? '')) ?></span>
          <span class="channel-sub"><?= hechi_e((string) ($item['channel_inner'] ?? '')) ?></span>
        </td>
        <td class="col-date nowrap"><?= hechi_e(substr((string) $item['published_at'], 0, 16)) ?></td>
        <td class="col-status nowrap">
          <span class="tag tag-<?= hechi_e($statusKey) ?>"><?= hechi_e((string) ($item['flow_state'] ?? $statusKey)) ?></span>
          <?php if ($recent !== ''): ?><span class="row-note"><?= hechi_e($recent) ?></span><?php endif; ?>
        </td>
        <td class="col-actions">
          <div class="row-actions">
            <?php if ($orderable): ?>
              <form method="post" action="/admin/article/<?= $id ?>/order" class="inline">
                <?= $csrf ?>
                <input type="hidden" name="dir" value="up">
                <button type="submit" class="btn btn-sm btn-icon" aria-label="把「<?= hechi_e($title) ?>」在该栏目内上移一位">↑</button>
              </form>
              <form method="post" action="/admin/article/<?= $id ?>/order" class="inline">
                <?= $csrf ?>
                <input type="hidden" name="dir" value="down">
                <button type="submit" class="btn btn-sm btn-icon" aria-label="把「<?= hechi_e($title) ?>」在该栏目内下移一位">↓</button>
              </form>
            <?php endif; ?>
            <a class="btn btn-sm" href="/admin/article/<?= $id ?>">编辑</a>
            <?php
              // 预览打开的是正式对外页面（前台详情页，与首页／栏目页点进去的是同一个）；
              // 复制链接给的仍是发布器产出的对外地址 /article/{id}.html。
              // 只有「已发布 + 公开发布」的稿件才有对外页面：归档稿（public_scope=archive，超出
              // 公开年限只留后台）既不产静态页、接口也取不到，两个按钮一律置灰。
              $official = '/article/' . $id . '.html';
              $previewUrl = '/detail.html?id=' . $id;
              $isPublished = (string) $item['status'] === 'published';
              $isPublic = (string) ($item['public_scope'] ?? 'public') === 'public';
            ?>
            <?php if ($isPublished && $isPublic): ?>
              <a class="btn btn-sm btn-ghost" href="<?= hechi_e($previewUrl) ?>" target="_blank" rel="noopener">预览</a>
              <button type="button" class="btn btn-sm btn-ghost" data-copy-link="<?= hechi_e($official) ?>">复制链接</button>
            <?php else: ?>
              <span class="btn btn-sm is-disabled" aria-disabled="true"
                    title="<?= $isPublished ? '归档稿不对外发布，没有对外页面' : '发布后可预览' ?>">预览</span>
              <span class="btn btn-sm is-disabled" aria-disabled="true"
                    title="<?= $isPublished ? '归档稿不对外发布，没有对外地址' : '发布后可复制链接' ?>">复制链接</span>
            <?php endif; ?>
            <?php foreach (($item['flow_rules'] ?? []) as $action => $rule): ?>
              <?php if ((string) $action === 'delete'): ?>
                <a class="btn btn-sm btn-danger-outline" href="/admin/article/<?= $id ?>/delete"><?= hechi_e((string) $rule['label']) ?></a>
              <?php elseif (!empty($rule['needNote'])): ?>
                <a class="btn btn-sm<?= !empty($rule['danger']) ? ' btn-danger-outline' : '' ?>"
                   href="/admin/article/<?= $id ?>#flow"><?= hechi_e((string) $rule['label']) ?>…</a>
              <?php else: ?>
                <form method="post" action="/admin/article/<?= $id ?>/flow" class="inline">
                  <?= $csrf ?>
                  <input type="hidden" name="action" value="<?= hechi_e((string) $action) ?>">
                  <button type="submit" class="btn btn-sm<?= !empty($rule['danger']) ? ' btn-danger-outline' : '' ?>"><?= hechi_e((string) $rule['label']) ?></button>
                </form>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($items === []): ?>
      <tr>
        <td colspan="<?= $bulkActions === [] ? 5 : 6 ?>" class="empty">
          <span class="empty-icon" aria-hidden="true">
            <?= $adminIcons['empty'] ?>
          </span>
          <span class="empty-title">没有符合条件的稿件。</span>
          <span class="empty-hint">
            <?php if ($activeFilters !== []): ?>
              换个稿库或栏目，或者 <a href="/admin/articles">清除全部筛选</a>。
            <?php else: ?>
              这个站还没有稿件，点右上角「新建稿件」开始。
            <?php endif; ?>
          </span>
          <?php if ($activeFilters === [] && in_array('article.edit', $userPerms ?? [], true)): ?>
            <a class="btn-primary empty-action" href="<?= hechi_e($newUrl) ?>">新建第一篇稿件</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endif; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
  <?php
  $window = 2;
  $start = max(1, $page - $window);
  $end = min($pages, $page + $window);
  ?>
  <nav class="pager" aria-label="分页">
    <?php if ($page > 1): ?>
      <a class="btn btn-sm" href="<?= hechi_e($listUrl(['page' => '1'])) ?>">首页</a>
      <a class="btn btn-sm" href="<?= hechi_e($listUrl(['page' => (string) ($page - 1)])) ?>">上一页</a>
    <?php else: ?>
      <span class="btn btn-sm is-disabled" aria-disabled="true">首页</span>
      <span class="btn btn-sm is-disabled" aria-disabled="true">上一页</span>
    <?php endif; ?>

    <?php if ($start > 1): ?>
      <a class="page-num" href="<?= hechi_e($listUrl(['page' => '1'])) ?>">1</a>
      <?php if ($start > 2): ?><span class="page-gap">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($p = $start; $p <= $end; $p++): ?>
      <a class="page-num<?= $p === $page ? ' active' : '' ?>"
         href="<?= hechi_e($listUrl(['page' => (string) $p])) ?>"
         <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
    <?php endfor; ?>

    <?php if ($end < $pages): ?>
      <?php if ($end < $pages - 1): ?><span class="page-gap">…</span><?php endif; ?>
      <a class="page-num" href="<?= hechi_e($listUrl(['page' => (string) $pages])) ?>"><?= (int) $pages ?></a>
    <?php endif; ?>

    <?php if ($page < $pages): ?>
      <a class="btn btn-sm" href="<?= hechi_e($listUrl(['page' => (string) ($page + 1)])) ?>">下一页</a>
      <a class="btn btn-sm" href="<?= hechi_e($listUrl(['page' => (string) $pages])) ?>">末页</a>
    <?php else: ?>
      <span class="btn btn-sm is-disabled" aria-disabled="true">下一页</span>
      <span class="btn btn-sm is-disabled" aria-disabled="true">末页</span>
    <?php endif; ?>

    <form class="pager-jump" method="get" action="/admin/articles">
      <input type="hidden" name="channel" value="<?= hechi_e((string) $filters['channel']) ?>">
      <input type="hidden" name="group" value="<?= hechi_e((string) ($filters['group'] ?? '')) ?>">
      <input type="hidden" name="status" value="<?= hechi_e($current) ?>">
      <input type="hidden" name="keyword" value="<?= hechi_e((string) $filters['keyword']) ?>">
      <input type="hidden" name="size" value="<?= $pageSize ?>">
      <input type="hidden" name="sort" value="<?= hechi_e($sortValue) ?>">
      <label class="filter-field">跳到
        <input type="number" name="page" min="1" max="<?= (int) $pages ?>" value="<?= (int) $page ?>" class="jump-input">
      </label>
      <button type="submit" class="btn btn-sm">跳转</button>
    </form>
  </nav>
<?php endif; ?>
