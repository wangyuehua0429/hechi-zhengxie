<?php

/**
 * 稿件编辑 / 新建。
 *
 * 结构上分成两栏：左边是内容表单（基本信息 / 发布设置 / 摘要与正文），
 * 右边是「随手要用」的东西（稿库流转、稿件信息、附件、正文插图、回收站），
 * 这样改稿时不用在长页面里上下找保存按钮与流转按钮。
 *
 * 正文用富文本编辑器（SunEditor，本地自托管），脚本未加载或初始化失败时退回原来的
 * HTML 文本框；两种方式都保留「预览正文」「切到源码」与字数统计。保存后静态页要走
 * 「立即发布全站」才会更新。
 *
 * @var array<string, mixed>|null $article
 * @var list<array<string, mixed>> $attachments
 * @var bool $canDelete
 * @var bool $canEdit
 * @var bool $saved
 * @var string $csrf
 * @var list<array<string, mixed>>|null $channels
 * @var string|null $defaultChannel
 * @var list<array{key:string,title:string,channels:list<array<string,mixed>>}>|null $navGroups
 * @var string|null $defaultStatus
 * @var list<string> $actions
 * @var array<string, array<string, mixed>> $transitions
 * @var int|null $channelTop 本稿件在所属栏目里是否置顶
 */

declare(strict_types=1);

use HechiZx\Content\ArticleWorkflow;
use HechiZx\Admin\Csrf;

$isNew = $article === null;
$published = (string) ($article['published_at'] ?? '');
$dateValue = $published !== '' ? substr($published, 0, 10) : date('Y-m-d');
$timeValue = $published !== '' ? substr($published, 11, 5) : date('H:i');
$action = $isNew ? '/admin/article/create' : '/admin/article/' . (int) $article['article_id'];
$actions = $actions ?? [];
$transitions = $transitions ?? [];
$canEdit = $canEdit ?? true;
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
$statusKey = $isNew ? ArticleWorkflow::DRAFT : ArticleWorkflow::normalize((string) $article['status']);
?>
<div class="page-head">
  <div class="page-title">
    <h1><?= $isNew ? '新建稿件' : '编辑稿件' ?></h1>
    <p class="subtitle">
      <?php if ($isNew): ?>
        先选栏目，再填内容；保存后会生成稿件号，并自动挂到所选栏目的列表里。
      <?php else: ?>
        #<?= (int) $article['article_id'] ?>
        · <?= hechi_e((string) ($article['channel_name'] ?? '')) ?> › <?= hechi_e((string) ($article['channel_inner'] ?? $article['channel_type'])) ?>
        （<?= hechi_e((string) $article['channel_type']) ?>）
        <span class="tag tag-<?= hechi_e($statusKey) ?>"><?= hechi_e(ArticleWorkflow::label((string) $article['status'])) ?></span>
        <?php if ($saved): ?><span class="saved-mark">已保存</span><?php endif; ?>
      <?php endif; ?>
    </p>
  </div>
  <div class="head-actions">
    <?php if (!$isNew): ?>
      <a class="btn" href="/detail.html?id=<?= (int) $article['article_id'] ?>" target="_blank" rel="noopener">前台预览</a>
      <a class="btn btn-ghost" href="/admin/articles">返回列表</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($isNew): ?>
  <section class="card">
    <h2>选择栏目</h2>
    <?php
    $navUrl = '/admin/article/new';
    $navActive = (string) ($defaultChannel ?? '');
    $navQuery = [];
    $navAllLabel = '未选';
    include __DIR__ . '/_channel_nav.php';
    ?>
    <p class="muted">当前栏目：<strong><?= hechi_e($currentChannelName !== '' ? $currentChannelName : '未选择') ?></strong>（点上面的栏目名切换）</p>
  </section>
<?php endif; ?>

<div class="edit-layout<?= $isNew ? ' edit-layout--single' : '' ?>">
  <div class="edit-main">
    <form method="post" action="<?= hechi_e($action) ?>" class="edit-form" id="article-form"
          data-image-upload-url="/admin/media/image"
          data-video-upload-url="/admin/media/video"
          data-article-id="<?= $isNew ? '' : (int) $article['article_id'] ?>"
          data-csrf-token="<?= hechi_e(Csrf::token()) ?>">
      <?= $csrf ?>
      <?php if ($isNew): ?>
        <input type="hidden" name="channel_type" value="<?= hechi_e((string) ($defaultChannel ?? '')) ?>">
      <?php endif; ?>

      <input type="hidden" name="published_date" value="<?= hechi_e($dateValue) ?>">
      <input type="hidden" name="published_time" value="<?= hechi_e($timeValue) ?>">

      <section class="card">
        <div class="card-head">
          <h2>摘要与正文</h2>
          <div class="card-tools">
            <button type="button" class="btn btn-sm btn-ghost" data-preview-toggle hidden>预览正文</button>
            <button type="button" class="btn btn-sm btn-ghost" data-editor-toggle hidden>切到源码</button>
            <span class="muted" data-content-count></span>
          </div>
        </div>
        <div class="writing-paper">
          <div class="writing-title-line">
            <input type="text" name="title" value="<?= hechi_e($article['title'] ?? '') ?>" class="writing-title"
                   maxlength="64" placeholder="请在这里输入标题" aria-label="标题" required>
            <span class="writing-title-count" data-title-count aria-hidden="true"></span>
          </div>
          <div class="writing-meta">
            <input type="text" name="author" value="<?= hechi_e($article['author'] ?? '') ?>" class="writing-author"
                   placeholder="请输入作者" aria-label="作者">
            <input type="text" name="editor" value="<?= hechi_e($article['editor'] ?? '') ?>" class="writing-author"
                   placeholder="责任编辑" aria-label="责任编辑">
            <input type="text" name="source" value="<?= hechi_e($article['source'] ?? '') ?>" class="writing-author"
                   placeholder="来源（如：广西政协报）" aria-label="来源">
          </div>
          <label class="writing-top"><input type="checkbox" name="is_top" value="1"<?= (int) ($channelTop ?? $article['is_top'] ?? 0) === 1 ? ' checked' : '' ?>> 在本栏目置顶（首页对应模块也跟着排前）</label>
          <div class="writing-orig">
            <span class="muted">原标题（填写后按加粗三行拼在正文最前，不进网页标题与首页）</span>
            <input type="text" name="orig_kicker" value="<?= hechi_e($article['orig_kicker'] ?? '') ?>" placeholder="引题">
            <input type="text" name="orig_title" value="<?= hechi_e($article['orig_title'] ?? '') ?>" placeholder="主标题">
            <input type="text" name="orig_subtitle" value="<?= hechi_e($article['orig_subtitle'] ?? '') ?>" placeholder="副题">
          </div>
          <p class="writing-hint muted">正文（可直接插图与 mp4／webm 视频，图片单个 ≤ 2 MB；粘贴网页或 Word 内容时图片自动上传）</p>
          <textarea name="content_html" rows="18" class="mono"><?= hechi_e($article['content_html'] ?? '') ?></textarea>
          <div class="editor-mount" data-editor-mount hidden></div>
        </div>
        <div class="preview-panel" data-preview-panel hidden>
          <iframe data-preview-frame title="正文预览" sandbox referrerpolicy="no-referrer"></iframe>
        </div>
      </section>

      <div class="form-actions">
        <?php if ($isNew): ?>
          <button type="submit" name="status" value="published" class="btn-primary">保存并发布</button>
          <button type="submit" name="status" value="draft" class="btn">保存为草稿</button>
        <?php else: ?>
          <button type="submit" class="btn-primary">保存稿件</button>
        <?php endif; ?>
        <a class="btn" href="/admin/articles">返回列表</a>
        <span class="muted">保存只改库里的内容，不改稿库状态；需要刷新静态页时回概览点「立即发布全站」。</span>
      </div>
    </form>
  </div>

  <?php if (!$isNew): ?>
    <aside class="edit-side" aria-label="稿件状态与素材">
      <section class="card flow-bar" id="flow">
        <div class="card-head">
          <h2>稿库流转</h2>
          <span class="tag tag-<?= hechi_e($statusKey) ?>"><?= hechi_e(ArticleWorkflow::label((string) $article['status'])) ?></span>
        </div>
        <dl class="meta-list meta-list--tight">
          <?php if ((string) ($article['submitted_at'] ?? '') !== ''): ?>
            <dt>提交</dt><dd><?= hechi_e(substr((string) $article['submitted_at'], 0, 16)) ?></dd>
          <?php endif; ?>
          <?php if ((string) ($article['reviewed_at'] ?? '') !== ''): ?>
            <dt>审核</dt><dd><?= hechi_e(substr((string) $article['reviewed_at'], 0, 16)) ?></dd>
          <?php endif; ?>
          <?php if ((string) ($article['withdrawn_at'] ?? '') !== ''): ?>
            <dt>撤回</dt><dd><?= hechi_e(substr((string) $article['withdrawn_at'], 0, 16)) ?></dd>
          <?php endif; ?>
          <?php if ((string) ($article['deleted_at'] ?? '') !== ''): ?>
            <dt>入回收站</dt><dd><?= hechi_e(substr((string) $article['deleted_at'], 0, 16)) ?></dd>
          <?php endif; ?>
        </dl>
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
                          class="btn<?= !empty($rule['danger']) ? ' btn-danger-outline' : '' ?>"><?= hechi_e((string) $rule['label']) ?></button>
                </span>
              <?php else: ?>
                <button type="submit" name="action" value="<?= hechi_e((string) $flowAction) ?>"
                        class="btn<?= !empty($rule['danger']) ? ' btn-danger-outline' : '' ?>"><?= hechi_e((string) $rule['label']) ?></button>
              <?php endif; ?>
            <?php endforeach; ?>
          </form>
        <?php endif; ?>
        <p class="muted">流转动作立即改变稿件的稿库与前台可见性，并记入操作日志。</p>
      </section>

      <section class="card">
        <h2>稿件信息</h2>
        <dl class="meta-list">
          <dt>稿件号</dt><dd>#<?= (int) $article['article_id'] ?></dd>
          <dt>栏目</dt>
          <dd><?= hechi_e((string) ($article['channel_name'] ?? '')) ?> › <?= hechi_e((string) ($article['channel_inner'] ?? '')) ?>（<?= hechi_e((string) $article['channel_type']) ?>）</dd>
          <dt>创建</dt><dd><?= hechi_e(substr((string) ($article['created_at'] ?? ''), 0, 16)) ?></dd>
          <dt>最近更新</dt><dd><?= hechi_e(substr((string) ($article['updated_at'] ?? ''), 0, 16)) ?></dd>
          <dt>前台</dt>
          <dd><a href="/detail.html?id=<?= (int) $article['article_id'] ?>" target="_blank" rel="noopener">打开详情页</a>
            <a href="/admin/articles?channel=<?= hechi_e((string) $article['channel_type']) ?>">同栏目稿件</a></dd>
        </dl>
      </section>

      <section class="card">
        <h2>附件下载</h2>
        <?php if ($attachments === []): ?>
          <p class="muted">暂无附件。</p>
        <?php else: ?>
          <ul class="attach-list">
            <?php foreach ($attachments as $file): ?>
              <li>
                <a href="<?= hechi_e($file['url']) ?>" target="_blank" rel="noopener"><?= hechi_e($file['name']) ?></a>
                <span class="muted"><?= hechi_e((string) $file['ext']) ?> · <?= $file['size'] > 0 ? hechi_e(number_format($file['size'] / 1024, 1) . ' KB') : '—' ?></span>
                <form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>/attachment/<?= (int) $file['id'] ?>/delete" class="inline">
                  <?= $csrf ?>
                  <button type="submit" class="link-btn">删除</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>/attachment" enctype="multipart/form-data" class="upload-form">
          <?= $csrf ?>
          <input type="file" name="file" required>
          <button type="submit" class="btn btn-sm">上传附件</button>
          <span class="muted">pdf／doc(x)／xls(x)／ppt(x)／zip／rar／txt，单个 ≤ 32 MB。</span>
        </form>
      </section>

      <section class="card">
        <h2>正文插图</h2>
        <p class="muted">上传后自动追加到正文末尾，并登记为图集图片（详情页 2 张以上会出灯箱）。</p>
        <form method="post" action="/admin/article/<?= (int) $article['article_id'] ?>/image" enctype="multipart/form-data" class="upload-form">
          <?= $csrf ?>
          <input type="file" name="image" accept="image/*" required>
          <button type="submit" class="btn btn-sm">插入图片</button>
          <span class="muted">jpg／jpeg／png／gif／webp，单个 ≤ 2 MB。</span>
        </form>
      </section>

      <?php if ($canDelete): ?>
        <section class="card danger-zone">
          <h2>移入回收站</h2>
          <p class="muted">移入回收站后前台与接口立即不可见，附件与上传文件保留，之后可以从回收站恢复；已发布的稿件要先撤回。</p>
          <a class="btn btn-danger-outline" href="/admin/article/<?= (int) $article['article_id'] ?>/delete">移入回收站…</a>
        </section>
      <?php endif; ?>
    </aside>
  <?php endif; ?>
</div>
