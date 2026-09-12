<?php

/**
 * 删除栏目确认页：有稿件、子栏目、首页模块绑定或导航指向时挡下来，改完再来。
 *
 * @var array<string, mixed> $channel
 * @var list<string> $blockers
 * @var array<string, mixed>|null $parent
 * @var string $csrf
 */

declare(strict_types=1);

$type = (string) $channel['type_code'];
$entryId = (string) $channel['slug'] !== '' ? (string) $channel['slug'] : $type;
?>
<div class="page-head">
  <div class="page-title">
    <h1>删除栏目</h1>
    <p class="subtitle">
      <?php if ($parent !== null): ?><?= hechi_e((string) $parent['inner_name']) ?> › <?php endif; ?><strong><?= hechi_e((string) $channel['inner_name']) ?></strong>
      · 栏目号 <?= hechi_e($type) ?>
    </p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/admin/channel/<?= hechi_e($type) ?>">返回栏目</a>
  </div>
</div>

<div class="card danger-zone">
  <p class="muted">
    栏目号 <?= hechi_e($type) ?>　版式 <?= hechi_e((string) $channel['layout']) ?>　
    状态 <?= $channel['status'] === 'published' ? '已上线' : '已下线' ?>　
    静态页 <code>/channel/<?= hechi_e($entryId) ?>/</code>
  </p>
  <p class="muted">
    删除会同时清掉：这个栏目的 301 映射记录、角色里勾选的栏目数据范围。
    前台这页要等下一次发布才消失（后台发稿走接口即时生效，静态页与 sitemap 由发布器重建）。
  </p>

  <?php if ($blockers !== []): ?>
    <p class="flash flash-error">这个栏目还有关联内容，暂不能删除：</p>
    <ul>
      <?php foreach ($blockers as $blocker): ?>
        <li><?= hechi_e($blocker) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="actions">
      <a class="btn" href="/admin/channel/<?= hechi_e($type) ?>">返回栏目</a>
      <a class="btn btn-ghost" href="/admin/channels">返回栏目列表</a>
    </div>
  <?php else: ?>
    <form method="post" action="/admin/channel/<?= hechi_e($type) ?>/delete">
      <?= $csrf ?>
      <label class="check-line">
        <input type="checkbox" name="confirm" value="delete" required>
        我确认删除栏目「<?= hechi_e((string) $channel['inner_name']) ?>」
      </label>
      <div class="actions">
        <button type="submit" class="btn-primary btn-danger">确认删除</button>
        <a class="btn" href="/admin/channel/<?= hechi_e($type) ?>">取消</a>
      </div>
    </form>
  <?php endif; ?>
</div>
