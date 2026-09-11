<?php

/**
 * 稿件编辑。正文暂用文本框直接编辑 HTML（旧文多为 <div>/<p> 结构，所见即所得编辑器
 * 随阶段 C 后台完善再接入），保存后需回「概览」点一次发布才会更新静态页。
 *
 * @var array<string, mixed> $article
 * @var bool $saved
 * @var string $csrf
 */

declare(strict_types=1);

$published = (string) ($article['published_at'] ?? '');
$dateValue = $published !== '' ? substr($published, 0, 10) : date('Y-m-d');
$timeValue = $published !== '' ? substr($published, 11, 5) : date('H:i');
$statusLabels = ['published' => '已发布', 'draft' => '草稿', 'offline' => '已下线'];
?>
<h1>编辑稿件</h1>
<p class="muted">
  #<?= (int) $article['article_id'] ?>　栏目：<?= hechi_e($article['channel_inner'] ?? $article['channel_name'] ?? $article['channel_type']) ?>
  （<?= hechi_e((string) $article['channel_type']) ?>）
  <?php if ($saved): ?>　<span class="saved-mark">已保存</span><?php endif; ?>
</p>

<form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>" class="edit-form">
  <?= $csrf ?>

  <label class="full">标题
    <input type="text" name="title" value="<?= hechi_e($article['title']) ?>" required>
  </label>

  <div class="row">
    <label>引题／副标题
      <input type="text" name="subtitle" value="<?= hechi_e($article['subtitle']) ?>">
    </label>
    <label>来源
      <input type="text" name="source" value="<?= hechi_e($article['source']) ?>">
    </label>
  </div>

  <div class="row">
    <label>作者
      <input type="text" name="author" value="<?= hechi_e($article['author']) ?>">
    </label>
    <label>责任编辑
      <input type="text" name="editor" value="<?= hechi_e($article['editor']) ?>">
    </label>
  </div>

  <div class="row">
    <label>发布时间（日期）
      <input type="date" name="published_date" value="<?= hechi_e($dateValue) ?>">
    </label>
    <label>发布时间（时刻）
      <input type="time" name="published_time" value="<?= hechi_e($timeValue) ?>">
    </label>
    <label>状态
      <select name="status">
        <?php foreach ($statusLabels as $key => $label): ?>
          <option value="<?= hechi_e($key) ?>"<?= (string) $article['status'] === $key ? ' selected' : '' ?>><?= hechi_e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check">
      <input type="checkbox" name="is_top" value="1"<?= (int) $article['is_top'] === 1 ? ' checked' : '' ?>> 置顶
    </label>
  </div>

  <label class="full">摘要
    <textarea name="summary" rows="3"><?= hechi_e($article['summary']) ?></textarea>
  </label>

  <label class="full">正文（HTML）
    <textarea name="content_html" rows="18" class="mono"><?= hechi_e($article['content_html']) ?></textarea>
  </label>

  <div class="actions">
    <button type="submit" class="btn-primary">保存</button>
    <a class="btn" href="/admin/articles">返回列表</a>
    <span class="muted">保存只改数据库；要让前台/静态页生效，回概览点「立即发布全站」。</span>
  </div>
</form>
