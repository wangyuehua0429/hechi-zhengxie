<?php

/**
 * 移入回收站确认页：必须勾选确认才执行。软删除，可恢复。
 *
 * @var array<string, mixed> $article
 * @var list<array<string, mixed>> $attachments
 * @var string $blockedReason 非空表示当前状态不允许删除
 * @var string $csrf
 */

declare(strict_types=1);

$blockedReason = $blockedReason ?? '';
?>
<h1>移入回收站</h1>
<div class="card danger-zone">
  <p>即将移入回收站：<strong>#<?= (int) $article['article_id'] ?>　<?= hechi_e($article['title']) ?></strong></p>
  <p class="muted">
    栏目：<?= hechi_e($article['channel_inner'] ?? $article['channel_name'] ?? $article['channel_type']) ?>　
    状态：<?= hechi_e((string) $article['status']) ?>　
    附件：<?= count($attachments) ?> 个　
    发布时间：<?= hechi_e(substr((string) $article['published_at'], 0, 16)) ?>
  </p>
  <p class="muted">
    移入回收站后，稿件从后台列表的「已发布／草稿」等稿库移到「回收站」，前台与接口立即可见性变为不可见；
    附件与上传文件保留，之后可由有「恢复」权限的账号还原到删除前的稿库。物理清除需要单独授权，本页不做。
  </p>

  <?php if ($blockedReason !== ''): ?>
    <p class="flash flash-error"><?= hechi_e($blockedReason) ?></p>
    <div class="actions">
      <a class="btn" href="/admin/article/<?= (int) $article['article_id'] ?>">返回稿件</a>
    </div>
  <?php else: ?>
    <form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>/delete">
      <?= $csrf ?>
      <label class="check-line">
        <input type="checkbox" name="confirm" value="delete" required>
        我确认把这篇稿件移入回收站
      </label>
      <div class="actions">
        <button type="submit" class="btn-primary btn-danger">确认移入回收站</button>
        <a class="btn" href="/admin/article/<?= (int) $article['article_id'] ?>">取消</a>
      </div>
    </form>
  <?php endif; ?>
</div>
