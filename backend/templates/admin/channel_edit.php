<?php

/**
 * 栏目编辑：名称、版式、排序、上下线、简介。
 *
 * 与旧版相比补了三件编辑最常问的事：这个栏目在整组里排第几、前后各是谁、
 * 改完在哪个前台地址能看到；排序值退到「高级」折叠区，常用的是上移／下移。
 *
 * @var array<string, mixed> $channel
 * @var list<string> $layouts
 * @var int $siblingCount
 * @var int $position
 * @var array<string, mixed>|null $prevChannel
 * @var array<string, mixed>|null $nextChannel
 * @var array<string, mixed>|null $parent
 * @var string $csrf
 */

declare(strict_types=1);

$layoutLabels = [
    'list' => '列表（左侧子栏目 + 稿件列表）',
    'leaders' => '领导（按职务分组的照片卡片）',
    'about' => '一页式（栏目简介 + 内容）',
    'county' => '县（区）政协（站点入口 + 稿件）',
    'gallery' => '图集（图片卡片）',
    'video' => '视频（缩略图卡片）',
    'topic' => '专题（专题卡片）',
    'interactive' => '互动（入口说明 + 来信回复）',
];
$type = (string) $channel['type_code'];
$parentName = $parent !== null ? (string) $parent['inner_name'] : (string) $channel['name'];
$isChild = (string) ($channel['parent_type'] ?? '') !== '';
?>
<div class="page-head">
  <div class="page-title">
    <h1>编辑栏目</h1>
    <p class="subtitle">
      <?php if ($isChild): ?><?= hechi_e($parentName) ?> › <?php endif; ?><strong><?= hechi_e((string) $channel['inner_name']) ?></strong>
      · 栏目号 <?= hechi_e($type) ?> · URL 标识 <?= hechi_e((string) $channel['slug']) ?>
    </p>
  </div>
  <div class="head-actions">
    <a class="btn" href="/channel.html?id=<?= hechi_e($type) ?>" target="_blank" rel="noopener">前台栏目页</a>
    <a class="btn btn-ghost" href="/admin/channels">返回栏目列表</a>
  </div>
</div>

<section class="card">
  <h2>在导航里的位置</h2>
  <p class="muted">
    同一级栏目共 <?= (int) $siblingCount ?> 个，当前是第 <strong><?= (int) $position ?></strong> 个——
    前一个：<?= $prevChannel !== null ? hechi_e((string) $prevChannel['inner_name']) : '（已经在最前）' ?>；
    后一个：<?= $nextChannel !== null ? hechi_e((string) $nextChannel['inner_name']) : '（已经在最后）' ?>。
  </p>
  <div class="row-actions">
    <form method="post" action="/admin/channel/<?= hechi_e($type) ?>/move" class="inline">
      <?= $csrf ?>
      <input type="hidden" name="dir" value="up">
      <button type="submit" class="btn"<?= $prevChannel === null ? ' disabled' : '' ?>>上移一位</button>
    </form>
    <form method="post" action="/admin/channel/<?= hechi_e($type) ?>/move" class="inline">
      <?= $csrf ?>
      <input type="hidden" name="dir" value="down">
      <button type="submit" class="btn"<?= $nextChannel === null ? ' disabled' : '' ?>>下移一位</button>
    </form>
    <span class="muted">上移／下移只交换相邻两个栏目的位置，不改其它栏目的顺序。</span>
  </div>
</section>

<form method="post" action="/admin/channel/<?= hechi_e($type) ?>" class="edit-form">
  <?= $csrf ?>

  <section class="card">
    <h2>名称</h2>
    <div class="row">
      <label>一级栏目名
        <input type="text" name="name" value="<?= hechi_e((string) $channel['name']) ?>" required>
      </label>
      <label>子栏目名
        <input type="text" name="inner_name" value="<?= hechi_e((string) $channel['inner_name']) ?>" required>
      </label>
    </div>
    <p class="muted">前台导航与栏目页标题用「子栏目名」；「一级栏目名」是导航条上的分组名，同一组多个子栏目共用。</p>
    <div class="row">
      <label>栏目号（不可改）
        <input type="text" value="<?= hechi_e($type) ?>" disabled>
      </label>
      <label>URL 标识（不可改）
        <input type="text" value="<?= hechi_e((string) $channel['slug']) ?>" disabled>
      </label>
    </div>
  </section>

  <section class="card">
    <h2>版式与状态</h2>
    <div class="row">
      <label class="grow">版式
        <select name="layout">
          <?php foreach ($layouts as $layout): ?>
            <option value="<?= hechi_e($layout) ?>"<?= (string) $channel['layout'] === $layout ? ' selected' : '' ?>><?= hechi_e($layoutLabels[$layout] ?? $layout) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>状态
        <select name="status">
          <option value="published"<?= $channel['status'] === 'published' ? ' selected' : '' ?>>已上线</option>
          <option value="offline"<?= $channel['status'] === 'offline' ? ' selected' : '' ?>>已下线</option>
        </select>
      </label>
    </div>
    <p class="muted">版式决定内页形态（列表／领导／图集／视频／专题…），换版式不会新建页面，只换渲染方式；下线后前台导航与接口都不再出现这个栏目。</p>

    <details class="advanced">
      <summary>高级：手动指定排序值</summary>
      <label class="field-inline">排序（越小越靠前）
        <input type="number" name="sort_no" value="<?= (int) $channel['sort_no'] ?>">
      </label>
      <p class="muted">一般用上面的「上移／下移」就够了。手填排序值会直接覆盖当前值，其它栏目不动。</p>
    </details>
  </section>

  <section class="card">
    <h2>栏目简介</h2>
    <label class="full">栏目简介（前台栏目页顶部展示）
      <textarea name="intro" rows="3"><?= hechi_e((string) $channel['intro']) ?></textarea>
    </label>
  </section>

  <div class="form-actions">
    <button type="submit" class="btn-primary">保存栏目</button>
    <a class="btn" href="/admin/channels">返回列表</a>
  </div>
</form>
