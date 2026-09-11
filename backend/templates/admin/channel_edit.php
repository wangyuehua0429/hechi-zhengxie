<?php

/**
 * 栏目编辑。
 *
 * @var array<string, mixed> $channel
 * @var list<string> $layouts
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
?>
<h1>编辑栏目</h1>

<form method="post" action="/admin/channel/<?= hechi_e($channel['type_code']) ?>" class="edit-form">
  <?= $csrf ?>

  <div class="row">
    <label>栏目号（不可改）
      <input type="text" value="<?= hechi_e($channel['type_code']) ?>" disabled>
    </label>
    <label>URL 标识（不可改）
      <input type="text" value="<?= hechi_e($channel['slug']) ?>" disabled>
    </label>
  </div>

  <div class="row">
    <label>一级栏目名
      <input type="text" name="name" value="<?= hechi_e($channel['name']) ?>" required>
    </label>
    <label>子栏目名
      <input type="text" name="inner_name" value="<?= hechi_e($channel['inner_name']) ?>" required>
    </label>
  </div>

  <div class="row">
    <label>版式
      <select name="layout">
        <?php foreach ($layouts as $layout): ?>
          <option value="<?= hechi_e($layout) ?>"<?= (string) $channel['layout'] === $layout ? ' selected' : '' ?>><?= hechi_e($layoutLabels[$layout] ?? $layout) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>排序（越小越靠前）
      <input type="number" name="sort_no" value="<?= (int) $channel['sort_no'] ?>">
    </label>
    <label>状态
      <select name="status">
        <option value="published"<?= $channel['status'] === 'published' ? ' selected' : '' ?>>已上线</option>
        <option value="offline"<?= $channel['status'] === 'offline' ? ' selected' : '' ?>>已下线</option>
      </select>
    </label>
  </div>

  <label class="full">栏目简介
    <textarea name="intro" rows="3"><?= hechi_e($channel['intro']) ?></textarea>
  </label>

  <div class="actions">
    <button type="submit" class="btn-primary">保存</button>
    <a class="btn" href="/admin/channels">返回列表</a>
  </div>
</form>
