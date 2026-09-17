<?php

/**
 * 稿件编辑 / 新建。
 *
 * 结构上分成两栏：左边是内容表单（写作纸：网页标题 / 原标题 / 来源与作者 / 正文 / 素材），
 * 右边是「随手要用」的东西（稿库流转、稿件信息、回收站），
 * 这样改稿时不用在长页面里上下找保存按钮与流转按钮。
 *
 * 图片与附件的提交都在左边写作纸里：插图走工具栏的「图片」按钮（插在光标处），
 * 附件走写作纸里的素材条（2026-09-17 从右栏并入，右栏不再单列这两张卡片）。
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
  <section class="card card--pick">
    <div class="card-head">
      <h2><span class="step-no" aria-hidden="true">1</span>选择栏目</h2>
      <?php if ($currentChannelName !== ''): ?>
        <p class="pick-current">当前：<strong><?= hechi_e($currentChannelName) ?></strong></p>
      <?php else: ?>
        <p class="pick-current pick-current--empty">还没选栏目，选好才存得下稿</p>
      <?php endif; ?>
    </div>
    <?php
    $navUrl = '/admin/article/new';
    $navActive = (string) ($defaultChannel ?? '');
    $navQuery = [];
    $navAllLabel = '未选';
    include __DIR__ . '/_channel_nav.php';
    ?>
    <p class="pick-note muted">点栏目名即选中；带箭头的是含子栏目的一级栏目，点开后接着选具体子栏目。</p>
  </section>
<?php endif; ?>

<div class="edit-layout<?= $isNew ? ' edit-layout--single' : '' ?>">
  <div class="edit-main">
    <form method="post" action="<?= hechi_e($action) ?>" class="edit-form" id="article-form"
          enctype="multipart/form-data"
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
          <h2><?php if ($isNew): ?><span class="step-no" aria-hidden="true">2</span><?php endif; ?>摘要与正文</h2>
          <div class="card-tools">
            <button type="button" class="btn btn-sm btn-ghost" data-preview-toggle hidden>预览正文</button>
            <button type="button" class="btn btn-sm btn-ghost" data-editor-toggle hidden>切到源码</button>
            <span class="count-chip" data-content-count></span>
          </div>
        </div>
        <div class="writing-paper">
          <div class="writing-title-line">
            <span class="writing-title-label" id="writing-title-label">网页标题</span>
            <input type="text" name="title" value="<?= hechi_e($article['title'] ?? '') ?>" class="writing-title"
                   maxlength="64" placeholder="请在这里输入标题" aria-labelledby="writing-title-label" required>
            <span class="writing-title-count" data-title-count aria-hidden="true"></span>
          </div>
          <div class="writing-orig">
            <div class="writing-orig-head">
              <span class="writing-orig-name">原标题</span>
              <span class="writing-orig-tag">印刷版式题区</span>
              <span class="writing-orig-note">保存时按加粗三行拼在正文最前，每行首行空两格</span>
            </div>
            <div class="writing-orig-fields">
              <label class="writing-field">
                <span class="writing-field-label">引题</span>
                <input type="text" name="orig_kicker" value="<?= hechi_e($article['orig_kicker'] ?? '') ?>" placeholder="题区第一行，可留空">
              </label>
              <label class="writing-field">
                <span class="writing-field-label">主标题</span>
                <input type="text" name="orig_title" value="<?= hechi_e($article['orig_title'] ?? '') ?>" placeholder="题区第二行，可留空">
              </label>
              <label class="writing-field">
                <span class="writing-field-label">副题</span>
                <input type="text" name="orig_subtitle" value="<?= hechi_e($article['orig_subtitle'] ?? '') ?>" placeholder="题区第三行，可留空">
              </label>
            </div>
            <p class="writing-orig-hint">正文里已有的题区行会在打开本页时自动收进这三列，正文不再重复；三列都留空则不输出题区，题区也不进网页标题与首页。</p>
          </div>
          <div class="writing-meta">
            <label class="writing-field writing-field--inline">
              <span class="writing-field-label">来源</span>
              <input type="text" name="source" value="<?= hechi_e($article['source'] ?? '') ?>" class="writing-author"
                     placeholder="如：广西政协报">
            </label>
            <label class="writing-field writing-field--inline">
              <span class="writing-field-label">作者</span>
              <input type="text" name="author" value="<?= hechi_e($article['author'] ?? '') ?>" class="writing-author"
                     placeholder="如：黄正华；留空则详情页不显示作者">
            </label>
          </div>
          <p class="writing-hint"><span class="writing-hint-name">正文</span>可直接插图与 mp4／webm 视频，图片单个 ≤ 2 MB；粘贴网页或 Word 内容时图片自动上传。</p>
          <textarea name="content_html" rows="18" class="mono"><?= hechi_e($article['content_html'] ?? '') ?></textarea>
          <div class="editor-mount" data-editor-mount hidden></div>
          <!-- 素材（附件）并进写作纸：右栏不再单列上传卡片，图片统一走工具栏的「图片」按钮，插在光标处 -->
          <div class="writing-media" data-editor-media>
            <div class="writing-media-head">
              <span class="writing-media-name">附件</span>
              <?php if ($isNew): ?>
                <!-- 新建页还没有稿件号：附件随稿件表单一起提交，建稿后由 store() 落盘并登记（不嵌 <form>，见下条注释） -->
                <div class="writing-media-form">
                  <input type="file" name="attachments[]" multiple>
                </div>
                <span class="writing-media-note">pdf／doc／docx／xls／xlsx／ppt／pptx／zip／rar／txt，单个 ≤ 32 MB；随「保存并发布」一起上传，保存后可在素材条里继续增删。</span>
              <?php else: ?>
                <!-- 控件用 form="…" 关联到 .edit-main 末尾的独立表单：
                     写作纸在稿件表单里，这里再嵌一个 <form> 会被浏览器丢弃，并把外层表单提前闭合 -->
                <div class="writing-media-form">
                  <input type="file" name="file" form="writing-media-form" required>
                  <button type="submit" form="writing-media-form" class="btn btn-sm">上传附件</button>
                </div>
                <span class="writing-media-note">pdf／doc／docx／xls／xlsx／ppt／pptx／zip／rar／txt，单个 ≤ 32 MB；上传后在文末「附件下载」区显示。</span>
              <?php endif; ?>
            </div>
            <?php if (!$isNew): ?>
              <?php if ($attachments === []): ?>
                <p class="writing-media-empty">暂无附件。</p>
              <?php else: ?>
                <ul class="attach-list attach-list--inline">
                  <?php foreach ($attachments as $file): ?>
                    <li>
                      <a href="<?= hechi_e($file['url']) ?>" target="_blank" rel="noopener"><?= hechi_e($file['name']) ?></a>
                      <span class="muted"><?= hechi_e((string) $file['ext']) ?> · <?= $file['size'] > 0 ? hechi_e(number_format($file['size'] / 1024, 1) . ' KB') : '—' ?></span>
                      <?php /* formnovalidate：删除时不该被上面那个必填的文件框拦住 */ ?>
                      <button type="submit" form="writing-media-form" formnovalidate class="link-btn"
                              formaction="/admin/article/<?= (int) $article['article_id'] ?>/attachment/<?= (int) $file['id'] ?>/delete">删除</button>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <p class="writing-media-tip">插图请点工具栏上的「图片」，图片插在光标所在位置；图集灯箱在详情页自动生效。</p>
            <?php endif; ?>
          </div>
        </div>
        <div class="preview-panel" data-preview-panel hidden>
          <iframe data-preview-frame title="正文预览" sandbox referrerpolicy="no-referrer"></iframe>
        </div>
      </section>

      <div class="form-actions">
        <div class="action-buttons">
          <?php if ($isNew): ?>
            <button type="submit" name="status" value="published" class="btn-primary">保存并发布</button>
            <button type="submit" name="status" value="draft" class="btn">保存为草稿</button>
          <?php else: ?>
            <button type="submit" class="btn-primary">保存稿件</button>
          <?php endif; ?>
          <a class="btn" href="/admin/articles">返回列表</a>
        </div>
        <p class="action-hint muted">
          <span class="hint-wide"><kbd>Ctrl</kbd>/<kbd>⌘</kbd>+<kbd>S</kbd> 保存；只改库里的内容，不改稿库状态，前台更新回概览点「立即发布全站」。</span>
          <span class="hint-narrow">只改库里内容；前台更新回概览发布。</span>
        </p>
      </div>
    </form>
    <?php if (!$isNew): ?>
      <?php /* 素材条的附件表单：挂在稿件表单之外，控件用 form="writing-media-form" 关联进来 */ ?>
      <form id="writing-media-form" method="post" enctype="multipart/form-data"
            action="/admin/article/<?= (int) $article['article_id'] ?>/attachment">
        <?= $csrf ?>
      </form>
    <?php endif; ?>
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
