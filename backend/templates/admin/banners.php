<?php

/**
 * 站内横幅：首页 7 个固定图片位。
 *
 * @var list<array{slot:string, label:string, hint:string, row:array<string,mixed>|null}> $slots
 */

declare(strict_types=1);
?>
<div class="page-head">
  <div class="page-title">
    <h1>站内横幅</h1>
    <p class="subtitle">
      首页上 <?= count($slots) ?> 个固定的图片位，位置由前台版式决定，这里只换图、改链接、上下线。
      图片建议用与位置匹配的横图，链接留空则该图不可点。
    </p>
  </div>
  <div class="head-actions">
    <a class="btn" href="/" target="_blank" rel="noopener">打开前台首页</a>
  </div>
</div>

<div class="slot-grid">
  <?php foreach ($slots as $slot): ?>
    <?php
    $row = $slot['row'];
    $status = $row === null ? 'published' : (string) $row['status'];
    ?>
    <section class="card slot-card">
      <div class="card-head">
        <h2><?= hechi_e($slot['label']) ?></h2>
        <span class="tag tag-<?= $status === 'published' ? 'published' : 'offline' ?>"><?= $status === 'published' ? '已上线' : '已下线' ?></span>
      </div>
      <p class="muted">槽位 <code><?= hechi_e($slot['slot']) ?></code> · <?= hechi_e($slot['hint']) ?></p>

      <?php if ($row !== null && (string) $row['image_url'] !== ''): ?>
        <div class="slot-preview"><img src="<?= hechi_e(hechi_asset($row['image_url'])) ?>" alt=""></div>
      <?php else: ?>
        <div class="slot-preview slot-preview--empty">还没有设置图片</div>
      <?php endif; ?>

      <form method="post" action="/admin/banner/<?= hechi_e($slot['slot']) ?>" enctype="multipart/form-data" class="edit-form">
        <?= $csrf ?>
        <label class="full">图片（上传新图会替换当前图）
          <input type="file" name="image" accept="image/*">
        </label>
        <label class="full">图片地址
          <input type="text" name="image_url" value="<?= hechi_e($row === null ? '' : (string) $row['image_url']) ?>">
        </label>
        <label class="full">链接
          <input type="text" name="link_url" value="<?= hechi_e($row === null ? '' : (string) $row['link_url']) ?>" placeholder="留空表示纯图片">
        </label>
        <label class="full">图片说明（alt，无障碍与图片加载失败时显示）
          <input type="text" name="title" value="<?= hechi_e($row === null ? '' : (string) $row['title']) ?>">
        </label>
        <div class="row">
          <label>状态
            <select name="status">
              <option value="published"<?= $status === 'published' ? ' selected' : '' ?>>已上线</option>
              <option value="offline"<?= $status === 'offline' ? ' selected' : '' ?>>已下线</option>
            </select>
          </label>
        </div>
        <div class="actions">
          <button type="submit" class="btn-primary"
                  data-confirm="保存「<?= hechi_e((string) $slot['label']) ?>」？首页这个位置会立刻换成新的图片／链接。">保存</button>
          <span class="muted">jpg／png／gif／webp，单个 ≤ 32 MB。</span>
        </div>
      </form>
    </section>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/_publish_hint.php'; ?>
