<?php

/**
 * 导航栏目：首页与内页共用的顶部导航条维护。
 *
 * 导航条是「首页 / 政协概况 / 政协动态 / …」这 18 个入口，里面有栏目、
 * 聚合页和外部链接，所以不按栏目自动生成，而是让编辑自己排。
 *
 * @var list<array<string, mixed>> $items
 * @var array<string, int> $channelCounts
 */

declare(strict_types=1);

$lastIndex = count($items) - 1;
?>
<div class="page-head">
  <div class="page-title">
    <h1>导航栏目</h1>
    <p class="subtitle">
      首页与内页顶栏是同一份数据，共 <?= count($items) ?> 个入口。
      顺序即前台从左到右的排列；隐藏后前台不显示，但条目仍留在这里。
    </p>
  </div>
  <div class="head-actions">
    <a class="btn" href="/" target="_blank" rel="noopener">打开前台首页</a>
    <a class="btn btn-ghost" href="/admin/channels">全部栏目</a>
  </div>
</div>

<?php foreach ($items as $index => $item): ?>
  <form id="nav-<?= (int) $index ?>" method="post" action="/admin/nav/<?= (int) $index ?>"
        data-confirm="保存导航「<?= hechi_e((string) $item['title']) ?>」的改动？<?= !empty($item['hidden'])
          ? '这一项勾了「隐藏」，保存后前台顶部导航里就不显示它了。'
          : '前台顶部导航会立刻按新名称与链接显示。' ?>"><?= $csrf ?></form>
<?php endforeach; ?>

<div class="table-scroll">
<table class="grid article-table">
  <caption class="visually-hidden">首页导航条</caption>
  <thead>
    <tr>
      <th scope="col" class="nowrap">序</th>
      <th scope="col">导航名称</th>
      <th scope="col">链接</th>
      <th scope="col" class="nowrap">指向栏目</th>
      <th scope="col" class="nowrap">隐藏</th>
      <th scope="col" class="col-actions">操作</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $index => $item): ?>
      <?php
      $formId = 'nav-' . (int) $index;
      $channel = (string) ($item['channel'] ?? '');
      $count = $channel !== '' ? (int) ($channelCounts[$channel] ?? 0) : null;
      ?>
      <tr<?= !empty($item['hidden']) ? ' class="row-hidden"' : '' ?>>
        <td class="nowrap muted"><?= (int) $index + 1 ?></td>
        <td>
          <input form="<?= $formId ?>" type="text" name="title" value="<?= hechi_e((string) $item['title']) ?>" required>
        </td>
        <td>
          <input form="<?= $formId ?>" type="text" name="url" value="<?= hechi_e((string) $item['url']) ?>" required>
          <span class="row-meta">可以是栏目内页（channel.html?id=904）、站内路径或外部链接。</span>
        </td>
        <td class="nowrap">
          <?php if ($channel !== ''): ?>
            <a href="/admin/articles?channel=<?= hechi_e($channel) ?>"><?= hechi_e($channel) ?></a>
            <span class="muted">（<?= $count ?> 篇）</span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td class="nowrap">
          <label class="check-line">
            <input form="<?= $formId ?>" type="checkbox" name="hidden" value="1"<?= !empty($item['hidden']) ? ' checked' : '' ?>>
            隐藏
          </label>
        </td>
        <td class="col-actions">
          <div class="row-actions">
            <button form="<?= $formId ?>" type="submit" class="btn btn-sm">保存</button>
            <form method="post" action="/admin/nav/<?= (int) $index ?>/move" class="inline">
              <?= $csrf ?>
              <input type="hidden" name="dir" value="up">
              <button type="submit" class="btn btn-sm btn-icon"<?= $index === 0 ? ' disabled' : '' ?>
                      aria-label="把「<?= hechi_e((string) $item['title']) ?>」上移一位">↑</button>
            </form>
            <form method="post" action="/admin/nav/<?= (int) $index ?>/move" class="inline">
              <?= $csrf ?>
              <input type="hidden" name="dir" value="down">
              <button type="submit" class="btn btn-sm btn-icon"<?= $index === $lastIndex ? ' disabled' : '' ?>
                      aria-label="把「<?= hechi_e((string) $item['title']) ?>」下移一位">↓</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php include __DIR__ . '/_publish_hint.php'; ?>
