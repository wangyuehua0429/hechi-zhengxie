<?php

/**
 * 头条轮换：首屏大图轮播的条目维护。
 *
 * @var list<array<string, mixed>> $slides
 * @var string $keyword 稿件检索词
 * @var list<array<string, mixed>> $found 检索到的已发布稿件
 */

declare(strict_types=1);

use HechiZx\Content\ArticleWorkflow;

$publishedCount = 0;
foreach ($slides as $slide) {
    if ((string) $slide['status'] === 'published') {
        $publishedCount++;
    }
}
?>
<div class="page-head">
  <div class="page-title">
    <h1>头条轮换</h1>
    <p class="subtitle">
      首屏大图轮播，共 <?= count($slides) ?> 条，其中已上线 <?= $publishedCount ?> 条。
      顺序即前台轮播顺序；引用的稿件一旦撤回或下线，这条会自动从轮播里消失。
    </p>
  </div>
  <div class="head-actions">
    <a class="btn" href="/" target="_blank" rel="noopener">打开前台首页</a>
  </div>
</div>

<?php if ($publishedCount > 0): ?>
  <section class="card">
    <h2>首屏效果预览</h2>
    <div class="slide-strip">
      <?php foreach ($slides as $slide): ?>
        <?php if ((string) $slide['status'] !== 'published') { continue; } ?>
        <figure class="slide-chip">
          <?php if ((string) $slide['image_url'] !== ''): ?>
            <img src="<?= hechi_e(hechi_asset($slide['image_url'])) ?>" alt="">
          <?php else: ?>
            <span class="slide-chip-empty">无图<br>（取稿件配图）</span>
          <?php endif; ?>
          <figcaption><?= hechi_e((string) $slide['title']) ?></figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
    <p class="muted">前台按这个顺序轮播；图片为空时取稿件缩略图或正文首图。</p>
  </section>
<?php endif; ?>

<div class="two-col">
  <section class="card">
    <h2>从已发布稿件里选</h2>
    <form class="filter-form" method="get" action="/admin/slides">
      <label class="filter-field">按标题找稿件
        <input type="search" name="q" value="<?= hechi_e($keyword) ?>" placeholder="如：政协">
      </label>
      <button type="submit" class="btn">检索</button>
    </form>
    <?php if ($searched && $found === []): ?>
      <p class="muted">没有找到标题含「<?= hechi_e($keyword) ?>」的已发布稿件。</p>
    <?php else: ?>
      <p class="muted">
        <?php if ($searched): ?>
          检索到 <?= count($found) ?> 条已发布稿件，点「加入轮播」即可引用（标题、摘要、链接自动带过来）。
        <?php else: ?>
          下面是最近发布的稿件（最多 10 条），点「加入轮播」即可引用（标题、摘要、链接自动带过来）。
        <?php endif; ?>
      </p>
      <ul class="pick-list">
        <?php foreach ($found as $article): ?>
          <?php $id = (int) $article['article_id']; ?>
          <li>
            <span class="pick-title"><?= hechi_e((string) $article['title']) ?></span>
            <span class="muted">#<?= $id ?> · <?= hechi_e((string) ($article['channel_inner'] ?? '')) ?></span>
            <form method="post" action="/admin/slides/create" class="inline">
              <?= $csrf ?>
              <input type="hidden" name="article_id" value="<?= $id ?>">
              <button type="submit" class="btn btn-sm">加入轮播</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>手工新增外链条目</h2>
    <form method="post" action="/admin/slides/create" enctype="multipart/form-data" class="edit-form">
      <?= $csrf ?>
      <label class="full">标题
        <input type="text" name="title" required>
      </label>
      <label class="full">链接（专题页、推广页等）
        <input type="text" name="link_url" placeholder="https://… 或 channel.html?id=501" required>
      </label>
      <label class="full">摘要
        <textarea name="summary" rows="2"></textarea>
      </label>
      <label class="full">图片
        <input type="file" name="image" accept="image/*">
      </label>
      <div class="actions">
        <button type="submit" class="btn-primary">新增条目</button>
        <span class="muted">图片支持 jpg／png／gif／webp，单个 ≤ 32 MB。</span>
      </div>
    </form>
  </section>
</div>

<?php if ($slides === []): ?>
  <p class="empty-card">还没有轮播条目，用上面的两种方式添加。</p>
<?php else: ?>
  <div class="table-scroll">
  <table class="grid article-table">
    <caption class="visually-hidden">头条轮换条目</caption>
    <thead>
      <tr>
        <th scope="col" class="nowrap">序</th>
        <th scope="col" class="nowrap">图</th>
        <th scope="col">标题</th>
        <th scope="col">来源</th>
        <th scope="col" class="nowrap">状态</th>
        <th scope="col" class="col-actions">操作</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($slides as $index => $slide): ?>
        <?php
        $id = (int) $slide['slide_id'];
        $articleId = (int) $slide['article_id'];
        $status = (string) $slide['status'];
        ?>
        <tr>
          <td class="nowrap muted"><?= (int) $index + 1 ?></td>
          <td>
            <?php if ((string) $slide['image_url'] !== ''): ?>
              <img class="thumb-sm" src="<?= hechi_e(hechi_asset($slide['image_url'])) ?>" alt="">
            <?php else: ?>
              <span class="muted">无</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="title-link"><?= hechi_e((string) $slide['title']) ?></span>
            <?php if (trim((string) $slide['summary']) !== ''): ?>
              <span class="row-sub"><?= hechi_e(mb_substr((string) $slide['summary'], 0, 60)) ?></span>
            <?php endif; ?>
            <?php if (trim((string) $slide['link_url']) !== ''): ?>
              <span class="row-meta"><?= hechi_e((string) $slide['link_url']) ?></span>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <?php if ($articleId > 0): ?>
              <a href="/admin/article/<?= $articleId ?>">稿件 #<?= $articleId ?></a>
              <span class="tag tag-<?= hechi_e((string) ($slide['article'] !== null ? ArticleWorkflow::normalize((string) $slide['article']['status']) : 'deleted')) ?>">
                <?= hechi_e((string) ($slide['article'] !== null ? ArticleWorkflow::label((string) $slide['article']['status']) : '稿件不存在')) ?>
              </span>
            <?php else: ?>
              <span class="muted">外链</span>
            <?php endif; ?>
          </td>
          <td class="nowrap">
            <span class="tag tag-<?= $status === 'published' ? 'published' : 'offline' ?>"><?= $status === 'published' ? '已上线' : '已下线' ?></span>
          </td>
          <td class="col-actions">
            <div class="row-actions">
              <form method="post" action="/admin/slides/<?= $id ?>/move" class="inline">
                <?= $csrf ?>
                <input type="hidden" name="dir" value="up">
                <button type="submit" class="btn btn-sm btn-icon"<?= $index === 0 ? ' disabled' : '' ?> aria-label="上移">↑</button>
              </form>
              <form method="post" action="/admin/slides/<?= $id ?>/move" class="inline">
                <?= $csrf ?>
                <input type="hidden" name="dir" value="down">
                <button type="submit" class="btn btn-sm btn-icon"<?= $index === count($slides) - 1 ? ' disabled' : '' ?> aria-label="下移">↓</button>
              </form>
              <form method="post" action="/admin/slides/<?= $id ?>/status" class="inline">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm"><?= $status === 'published' ? '下线' : '上线' ?></button>
              </form>
              <form method="post" action="/admin/slides/<?= $id ?>/delete" class="inline">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm btn-danger-outline">删除</button>
              </form>
            </div>
          </td>
        </tr>
        <tr class="row-detail">
          <td colspan="6">
            <details>
              <summary>编辑这条（标题／摘要／链接／图片）</summary>
              <form method="post" action="/admin/slides/<?= $id ?>" enctype="multipart/form-data" class="edit-form">
                <?= $csrf ?>
                <div class="row">
                  <label>标题
                    <input type="text" name="title" value="<?= hechi_e((string) $slide['title']) ?>" required>
                  </label>
                  <label>链接
                    <input type="text" name="link_url" value="<?= hechi_e((string) $slide['link_url']) ?>" placeholder="留空则用稿件详情页">
                  </label>
                </div>
                <label class="full">摘要
                  <textarea name="summary" rows="2"><?= hechi_e((string) $slide['summary']) ?></textarea>
                </label>
                <div class="row">
                  <label>图片地址
                    <input type="text" name="image_url" value="<?= hechi_e((string) $slide['image_url']) ?>">
                  </label>
                  <label>或上传新图
                    <input type="file" name="image" accept="image/*">
                  </label>
                </div>
                <div class="actions">
                  <button type="submit" class="btn-primary">保存</button>
                </div>
              </form>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/_publish_hint.php'; ?>
