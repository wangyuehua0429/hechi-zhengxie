<?php

/**
 * 稿件编辑 / 新建。正文暂用文本框直接编辑 HTML（旧文多为 <div>/<p> 结构，所见即所得
 * 编辑器随阶段 C 后台完善再接入）；保存后需回「概览」点一次发布才会更新静态页。
 *
 * @var array<string, mixed>|null $article
 * @var list<array<string, mixed>> $attachments
 * @var bool $canDelete
 * @var bool $saved
 * @var string $csrf
 * @var list<array<string, mixed>>|null $channels
 * @var string|null $defaultChannel
 */

declare(strict_types=1);

$isNew = $article === null;
$published = (string) ($article['published_at'] ?? '');
$dateValue = $published !== '' ? substr($published, 0, 10) : date('Y-m-d');
$timeValue = $published !== '' ? substr($published, 11, 5) : date('H:i');
$statusLabels = ['published' => '已发布', 'draft' => '草稿', 'offline' => '已下线'];
$action = $isNew ? '/admin/article/create' : '/admin/article/' . (int) $article['article_id'];
?>
<h1><?= $isNew ? '新建稿件' : '编辑稿件' ?></h1>
<p class="muted">
  <?php if ($isNew): ?>
    保存后会生成稿件号，并自动挂到所选栏目的列表里。
  <?php else: ?>
    #<?= (int) $article['article_id'] ?>　栏目：<?= hechi_e($article['channel_inner'] ?? $article['channel_name'] ?? $article['channel_type']) ?>
    （<?= hechi_e((string) $article['channel_type']) ?>）
    <?php if ($saved): ?>　<span class="saved-mark">已保存</span><?php endif; ?>
  <?php endif; ?>
</p>

<form method="post" action="<?= hechi_e($action) ?>" class="edit-form">
  <?= $csrf ?>

  <label class="full">标题
    <input type="text" name="title" value="<?= hechi_e($article['title'] ?? '') ?>" required>
  </label>

  <div class="row">
    <?php if ($isNew): ?>
      <label>所属栏目
        <select name="channel_type" required>
          <?php foreach (($channels ?? []) as $ch): ?>
            <option value="<?= hechi_e($ch['type_code']) ?>"<?= ($defaultChannel ?? '') === (string) $ch['type_code'] ? ' selected' : '' ?>>
              <?= hechi_e($ch['inner_name'] . '（' . $ch['type_code'] . '）') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>
    <label>引题／副标题
      <input type="text" name="subtitle" value="<?= hechi_e($article['subtitle'] ?? '') ?>">
    </label>
    <label>来源
      <input type="text" name="source" value="<?= hechi_e($article['source'] ?? '') ?>">
    </label>
  </div>

  <div class="row">
    <label>作者
      <input type="text" name="author" value="<?= hechi_e($article['author'] ?? '') ?>">
    </label>
    <label>责任编辑
      <input type="text" name="editor" value="<?= hechi_e($article['editor'] ?? '') ?>">
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
        <?php $currentStatus = (string) ($article['status'] ?? 'draft'); ?>
        <?php foreach ($statusLabels as $key => $label): ?>
          <option value="<?= hechi_e($key) ?>"<?= $currentStatus === $key ? ' selected' : '' ?>><?= hechi_e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="check">
      <input type="checkbox" name="is_top" value="1"<?= (int) ($article['is_top'] ?? 0) === 1 ? ' checked' : '' ?>> 置顶
    </label>
  </div>

  <label class="full">摘要
    <textarea name="summary" rows="3"><?= hechi_e($article['summary'] ?? '') ?></textarea>
  </label>

  <label class="full">正文（HTML）
    <textarea name="content_html" rows="18" class="mono"><?= hechi_e($article['content_html'] ?? '') ?></textarea>
  </label>

  <div class="actions">
    <button type="submit" class="btn-primary">保存</button>
    <a class="btn" href="/admin/articles">返回列表</a>
    <span class="muted">保存只改数据库；要让前台/静态页生效，回概览点「立即发布全站」。</span>
  </div>
</form>

<?php if (!$isNew): ?>
  <section class="card">
    <h2>附件下载</h2>
    <?php if ($attachments === []): ?>
      <p class="muted">暂无附件。</p>
    <?php else: ?>
      <table class="grid">
        <thead><tr><th>文件名</th><th>类型</th><th>大小</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($attachments as $file): ?>
            <tr>
              <td><a href="<?= hechi_e($file['url']) ?>" target="_blank" rel="noopener"><?= hechi_e($file['name']) ?></a></td>
              <td class="nowrap"><?= hechi_e($file['ext']) ?></td>
              <td class="nowrap"><?= $file['size'] > 0 ? hechi_e(number_format($file['size'] / 1024, 1) . ' KB') : '—' ?></td>
              <td class="nowrap">
                <form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>/attachment/<?= (int) $file['id'] ?>/delete" class="inline">
                  <?= $csrf ?>
                  <button type="submit" class="link-btn">删除</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>/attachment" enctype="multipart/form-data" class="upload-form">
      <?= $csrf ?>
      <input type="file" name="file" required>
      <button type="submit" class="btn">上传附件</button>
      <span class="muted">支持 pdf／doc(x)／xls(x)／ppt(x)／zip／rar／txt，单个不超过 32 MB。</span>
    </form>
  </section>

  <section class="card">
    <h2>正文插图</h2>
    <p class="muted">上传后自动追加到正文末尾，并登记为图集图片（详情页 2 张以上会出灯箱）。</p>
    <form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>/image" enctype="multipart/form-data" class="upload-form">
      <?= $csrf ?>
      <input type="file" name="image" accept="image/*" required>
      <button type="submit" class="btn">插入图片</button>
      <span class="muted">支持 jpg／jpeg／png／gif／webp，单个不超过 32 MB。</span>
    </form>
  </section>

  <?php if ($canDelete): ?>
    <section class="card danger-zone">
      <h2>删除稿件</h2>
      <p class="muted">删除会同时清掉这篇的附件与上传文件，不可恢复。只想停掉对外展示，把状态改成「已下线」即可。</p>
      <a class="btn btn-danger" href="/admin/article/<?= (int) $article['article_id'] ?>/delete">删除这篇稿件…</a>
    </section>
  <?php endif; ?>
<?php endif; ?>
