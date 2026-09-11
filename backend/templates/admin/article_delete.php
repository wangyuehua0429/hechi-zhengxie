<?php

/**
 * 删除稿件确认页：必须勾选确认才执行。
 *
 * @var array<string, mixed> $article
 * @var list<array<string, mixed>> $attachments
 * @var string $csrf
 */

declare(strict_types=1);
?>
<h1>删除稿件</h1>
<div class="card danger-zone">
  <p>即将删除：<strong>#<?= (int) $article['article_id'] ?>　<?= hechi_e($article['title']) ?></strong></p>
  <p class="muted">
    栏目：<?= hechi_e($article['channel_inner'] ?? $article['channel_name'] ?? $article['channel_type']) ?>　
    状态：<?= hechi_e((string) $article['status']) ?>　
    附件：<?= count($attachments) ?> 个　
    发布时间：<?= hechi_e(substr((string) $article['published_at'], 0, 16)) ?>
  </p>
  <p class="muted">删除后主表记录、栏目归属、附件记录与已上传文件都会一并清除，且不可恢复。</p>

  <form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>/delete">
    <?= $csrf ?>
    <label class="check-line">
      <input type="checkbox" name="confirm" value="delete" required>
      我确认删除这篇稿件
    </label>
    <div class="actions">
      <button type="submit" class="btn-primary btn-danger">确认删除</button>
      <a class="btn" href="/admin/article/<?= (int) $article['article_id'] ?>">取消</a>
    </div>
  </form>
</div>
