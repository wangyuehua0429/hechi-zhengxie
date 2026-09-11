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
 * @var list<array{key:string,title:string,channels:list<array<string,mixed>>}>|null $navGroups
 * @var string|null $defaultStatus
 */

declare(strict_types=1);

use HechiZx\Content\ArticleWorkflow;

$isNew = $article === null;
$published = (string) ($article['published_at'] ?? '');
$dateValue = $published !== '' ? substr($published, 0, 10) : date('Y-m-d');
$timeValue = $published !== '' ? substr($published, 11, 5) : date('H:i');
$action = $isNew ? '/admin/article/create' : '/admin/article/' . (int) $article['article_id'];
$actions = $actions ?? [];
$transitions = $transitions ?? [];
$currentChannelName = '';
if ($isNew && ($navGroups ?? []) !== []) {
    foreach ($navGroups as $group) {
        foreach ($group['channels'] as $item) {
            if ((string) $item['type_code'] === (string) ($defaultChannel ?? '')) {
                $currentChannelName = (string) $item['inner_name'];
            }
        }
    }
}
?>
<h1><?= $isNew ? '新建稿件' : '编辑稿件' ?></h1>
<p class="muted">
  <?php if ($isNew): ?>
    先选栏目，再填内容；保存后会生成稿件号，并自动挂到所选栏目的列表里。
  <?php else: ?>
    #<?= (int) $article['article_id'] ?>　栏目：<?= hechi_e($article['channel_inner'] ?? $article['channel_name'] ?? $article['channel_type']) ?>
    （<?= hechi_e((string) $article['channel_type']) ?>）
    <?php if ($saved): ?>　<span class="saved-mark">已保存</span><?php endif; ?>
  <?php endif; ?>
</p>

<?php if (!$isNew): ?>
  <section class="card flow-bar" id="flow">
    <div class="flow-head">
      <span class="tag tag-<?= hechi_e(ArticleWorkflow::normalize((string) $article['status'])) ?>"><?= hechi_e(ArticleWorkflow::label((string) $article['status'])) ?></span>
      <span class="muted">当前稿库</span>
      <?php if ((string) ($article['submitted_at'] ?? '') !== ''): ?>
        <span class="muted">提交：<?= hechi_e(substr((string) $article['submitted_at'], 0, 16)) ?></span>
      <?php endif; ?>
      <?php if ((string) ($article['reviewed_at'] ?? '') !== ''): ?>
        <span class="muted">审核：<?= hechi_e(substr((string) $article['reviewed_at'], 0, 16)) ?></span>
      <?php endif; ?>
      <?php if ((string) ($article['withdrawn_at'] ?? '') !== ''): ?>
        <span class="muted">撤回：<?= hechi_e(substr((string) $article['withdrawn_at'], 0, 16)) ?></span>
      <?php endif; ?>
      <?php if ((string) ($article['deleted_at'] ?? '') !== ''): ?>
        <span class="muted">入回收站：<?= hechi_e(substr((string) $article['deleted_at'], 0, 16)) ?></span>
      <?php endif; ?>
    </div>
    <?php if ((string) ($article['review_note'] ?? '') !== ''): ?>
      <p class="flow-note-text">退回意见：<?= hechi_e((string) $article['review_note']) ?></p>
    <?php endif; ?>
    <?php if ((string) ($article['withdraw_reason'] ?? '') !== ''): ?>
      <p class="flow-note-text">撤回原因：<?= hechi_e((string) $article['withdraw_reason']) ?></p>
    <?php endif; ?>
    <?php if ($actions === []): ?>
      <p class="muted">当前状态下，这个账号没有可执行的流转动作（可能是权限不足，或稿件已在该流程的终点）。</p>
    <?php else: ?>
      <form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>/flow" class="flow-form">
        <?= $csrf ?>
        <?php foreach ($actions as $flowAction): ?>
          <?php $rule = $transitions[$flowAction] ?? null; ?>
          <?php if ($rule === null): ?><?php continue; ?><?php endif; ?>
          <?php if (!empty($rule['needNote'])): ?>
            <span class="flow-note">
              <input type="text" name="note" maxlength="200" placeholder="<?= hechi_e((string) $rule['noteLabel']) ?>">
              <button type="submit" name="action" value="<?= hechi_e((string) $flowAction) ?>"
                      class="btn<?= !empty($rule['danger']) ? ' btn-danger' : '' ?>"><?= hechi_e((string) $rule['label']) ?></button>
            </span>
          <?php else: ?>
            <button type="submit" name="action" value="<?= hechi_e((string) $flowAction) ?>"
                    class="btn<?= !empty($rule['danger']) ? ' btn-danger' : '' ?>"><?= hechi_e((string) $rule['label']) ?></button>
          <?php endif; ?>
        <?php endforeach; ?>
      </form>
    <?php endif; ?>
    <p class="muted">流转动作会立即改变稿件的稿库与前台可见性，并记入操作日志。</p>
  </section>
<?php endif; ?>

<?php if ($isNew): ?>
  <?php
  $navUrl = '/admin/article/new';
  $navActive = (string) ($defaultChannel ?? '');
  $navQuery = [];
  $navAllLabel = '未选';
  include __DIR__ . '/_channel_nav.php';
  ?>
  <p class="muted">当前栏目：<strong><?= hechi_e($currentChannelName !== '' ? $currentChannelName : '未选择') ?></strong></p>
<?php endif; ?>

<form method="post" action="<?= hechi_e($action) ?>" class="edit-form">
  <?= $csrf ?>

  <label class="full">标题
    <input type="text" name="title" value="<?= hechi_e($article['title'] ?? '') ?>" required>
  </label>

  <div class="row">
    <?php if ($isNew): ?>
      <input type="hidden" name="channel_type" value="<?= hechi_e((string) ($defaultChannel ?? '')) ?>">
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
    <label class="check">
      <input type="checkbox" name="is_top" value="1"<?= (int) ($article['is_top'] ?? 0) === 1 ? ' checked' : '' ?>> 置顶
    </label>
    <label class="hint-line">稿库说明：只有「已发布」的内容会出现在前台与接口；草稿、待审、退回、已撤回、回收站都只在后台可见。稿件状态不在这个表单里改，请用上方的稿库流转按钮。</label>
  </div>

  <label class="full">摘要
    <textarea name="summary" rows="3"><?= hechi_e($article['summary'] ?? '') ?></textarea>
  </label>

  <label class="full">正文（可直接写纯文本，系统按空行自动分段；也支持 HTML）
    <textarea name="content_html" rows="18" class="mono"><?= hechi_e($article['content_html'] ?? '') ?></textarea>
  </label>

  <div class="actions">
    <?php if ($isNew): ?>
      <button type="submit" name="status" value="published" class="btn-primary">保存并发布</button>
      <button type="submit" name="status" value="draft" class="btn">保存为草稿</button>
    <?php else: ?>
      <button type="submit" class="btn-primary">保存</button>
    <?php endif; ?>
    <a class="btn" href="/admin/articles">返回列表</a>
    <span class="muted">保存只改数据库内容，不改稿库状态；让静态页生效请回概览点「立即发布全站」。</span>
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
      <h2>移入回收站</h2>
      <p class="muted">移入回收站后前台与接口立即不可见，附件与上传文件保留，之后可以从回收站恢复；已发布的稿件要先撤回。</p>
      <a class="btn btn-danger" href="/admin/article/<?= (int) $article['article_id'] ?>/delete">移入回收站…</a>
    </section>
  <?php endif; ?>
<?php endif; ?>
