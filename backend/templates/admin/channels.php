<?php

/**
 * 栏目管理：按一级栏目分组展示，组内可上移／下移，组头给出该组概况。
 *
 * 43 个栏目平铺成一张表时，「一级栏目」这一列每行都在重复，看不出层级，
 * 也找不到某一个栏目在整站导航里的位置；改成「一组一个表体」后，
 * 组头的名字只出现一次，组内顺序就是前台导航顺序。
 *
 * @var list<array<string, mixed>> $channels
 * @var list<array{key:string, title:string, channels:list<array<string,mixed>>, article_count:int, published:int, offline:int}> $groups
 * @var string $keyword
 * @var int $total
 * @var int $published
 * @var int $offline
 * @var int $articleCount
 */

declare(strict_types=1);

$layoutLabels = [
    'list' => '列表', 'leaders' => '领导', 'about' => '一页式', 'county' => '县区',
    'gallery' => '图集', 'video' => '视频', 'topic' => '专题', 'interactive' => '互动',
];
$groups = $groups ?? [];
$keyword = $keyword ?? '';
$shown = 0;
foreach ($groups as $group) {
    $shown += count($group['channels']);
}
?>
<div class="page-head">
  <div class="page-title">
    <h1>栏目管理</h1>
    <p class="subtitle">
      共 <?= (int) $total ?> 个栏目，<strong>顺序与前台导航一致</strong>（同一级栏目下的子栏目按前台顺序排列）。
      版式决定内页形态，改版式不会新建页面，只换渲染方式。
    </p>
  </div>
  <div class="head-actions">
    <a class="btn" href="/" target="_blank" rel="noopener">打开前台首页</a>
  </div>
</div>

<div class="stats stats-compact">
  <div class="stat"><span class="stat-num"><?= (int) $total ?></span><span class="stat-label">栏目总数</span></div>
  <div class="stat"><span class="stat-num"><?= (int) $published ?></span><span class="stat-label">已上线</span></div>
  <div class="stat"><span class="stat-num"><?= (int) $offline ?></span><span class="stat-label">已下线</span></div>
  <a class="stat" href="/admin/articles"><span class="stat-num"><?= (int) $articleCount ?></span><span class="stat-label">栏目稿件数合计</span></a>
</div>

<form class="filter-panel filter-panel--slim" method="get" action="/admin/channels">
  <div class="filter-row">
    <label class="filter-field">找栏目
      <input type="search" name="q" value="<?= hechi_e($keyword) ?>" placeholder="栏目名、栏目号或 URL 标识">
    </label>
    <button type="submit" class="btn">筛选</button>
    <?php if ($keyword !== ''): ?>
      <a class="btn btn-ghost" href="/admin/channels">显示全部</a>
      <span class="muted">匹配到 <?= (int) $shown ?> 个栏目。</span>
    <?php endif; ?>
  </div>
</form>

<?php if ($groups === []): ?>
  <p class="empty-card">没有匹配「<?= hechi_e($keyword) ?>」的栏目，<a href="/admin/channels">显示全部栏目</a>。</p>
<?php endif; ?>

<?php if ($groups !== []): ?>
  <div class="table-scroll">
  <table class="grid channel-table">
    <caption class="visually-hidden">栏目列表，按一级栏目分组</caption>
    <thead>
      <tr>
        <th scope="col">子栏目</th>
        <th scope="col" class="nowrap">栏目号</th>
        <th scope="col" class="nowrap">版式</th>
        <th scope="col" class="nowrap">稿件数</th>
        <th scope="col" class="nowrap">状态</th>
        <th scope="col" class="col-actions">排序与操作</th>
      </tr>
    </thead>
    <?php foreach ($groups as $group): ?>
      <tbody class="channel-block">
        <tr class="group-head">
          <th colspan="6" scope="colgroup">
            <span class="group-name"><?= hechi_e((string) $group['title']) ?></span>
            <span class="group-meta">
              <?= count($group['channels']) ?> 个子栏目 ·
              稿件 <?= (int) $group['article_count'] ?> 条 ·
              上线 <?= (int) $group['published'] ?> / 下线 <?= (int) $group['offline'] ?>
            </span>
          </th>
        </tr>
        <?php foreach ($group['channels'] as $index => $ch): ?>
          <?php $type = (string) $ch['type_code']; ?>
          <tr>
            <td class="col-title">
              <a class="title-link" href="/admin/channel/<?= hechi_e($type) ?>"><?= hechi_e((string) $ch['inner_name']) ?></a>
              <span class="row-meta">组内第 <?= (int) ($index + 1) ?> 个 · 排序值 <?= (int) $ch['sort_no'] ?></span>
            </td>
            <td class="nowrap"><code><?= hechi_e($type) ?></code></td>
            <td class="nowrap"><?= hechi_e($layoutLabels[(string) $ch['layout']] ?? (string) $ch['layout']) ?></td>
            <td class="nowrap">
              <a href="/admin/articles?channel=<?= hechi_e($type) ?>"><?= (int) $ch['article_count'] ?></a>
            </td>
            <td class="nowrap">
              <span class="tag tag-<?= $ch['status'] === 'published' ? 'published' : 'offline' ?>"><?= $ch['status'] === 'published' ? '已上线' : '已下线' ?></span>
            </td>
            <td class="col-actions">
              <div class="row-actions">
                <form method="post" action="/admin/channel/<?= hechi_e($type) ?>/move" class="inline">
                  <?= $csrf ?>
                  <input type="hidden" name="q" value="<?= hechi_e($keyword) ?>">
                  <input type="hidden" name="dir" value="up">
                  <button type="submit" class="btn btn-sm btn-icon"<?= empty($ch['can_move_up']) ? ' disabled' : '' ?>
                          aria-label="把「<?= hechi_e((string) $ch['inner_name']) ?>」上移一位">↑</button>
                </form>
                <form method="post" action="/admin/channel/<?= hechi_e($type) ?>/move" class="inline">
                  <?= $csrf ?>
                  <input type="hidden" name="q" value="<?= hechi_e($keyword) ?>">
                  <input type="hidden" name="dir" value="down">
                  <button type="submit" class="btn btn-sm btn-icon"<?= empty($ch['can_move_down']) ? ' disabled' : '' ?>
                          aria-label="把「<?= hechi_e((string) $ch['inner_name']) ?>」下移一位">↓</button>
                </form>
                <a class="btn btn-sm" href="/admin/channel/<?= hechi_e($type) ?>">编辑</a>
                <a class="btn btn-sm btn-ghost" href="/channel.html?id=<?= hechi_e($type) ?>" target="_blank" rel="noopener">前台</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    <?php endforeach; ?>
  </table>
  </div>
  <p class="muted">
    上移／下移只在同一级栏目内部交换位置，改完立即生效于后台列表与前台导航；
    需要同时改名称、版式或下线，进「编辑」页。
  </p>
<?php endif; ?>
