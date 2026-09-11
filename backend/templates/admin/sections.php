<?php

/**
 * 其他栏目：首页正文各稿件模块的绑定维护 + 未进导航的栏目清单。
 *
 * @var list<array{row:array<string,mixed>, kind:string, scopeText:string, channels:list<string>, preview:array{tabs:list<array{title:string,count:int,titles:list<string>}>, titles:list<string>, count:int}}> $sections
 * @var array<string, string> $channelNames
 * @var list<array{key:string, title:string, channels:list<array<string,mixed>>}> $otherGroups
 */

declare(strict_types=1);

$kindLabels = [
    'channels' => '指定栏目',
    'parent'   => '一级栏目（含全部子栏目）',
    'tabs'     => '分标签（每个子栏目一个标签）',
];
?>
<div class="page-head">
  <div class="page-title">
    <h1>其他栏目</h1>
    <p class="subtitle">
      首页正文的 <?= count($sections) ?> 个模块，每个模块绑定到栏目，取该栏目最新若干条已发布稿件；
      置顶稿排在前面，其余按发布时间。绑定的栏目列表里有新稿，首页立刻跟着变。
    </p>
  </div>
  <div class="head-actions">
    <a class="btn" href="/" target="_blank" rel="noopener">打开前台首页</a>
    <a class="btn btn-ghost" href="/admin/channels">全部栏目</a>
  </div>
</div>

<?php foreach ($sections as $section): ?>
  <?php
  $row = $section['row'];
  $key = (string) $row['section_key'];
  $status = (string) $row['status'];
  ?>
  <section class="card">
    <div class="card-head">
      <h2>
        <?= hechi_e((string) $row['label'] !== '' ? (string) $row['label'] : $key) ?>
        <span class="muted">（<?= hechi_e($key) ?>）</span>
      </h2>
      <div class="card-tools">
        <span class="tag tag-<?= $status === 'published' ? 'published' : 'offline' ?>"><?= $status === 'published' ? '已上线' : '已下线' ?></span>
        <span class="muted">取 <?= (int) $row['page_size'] ?> 条</span>
        <?php if ((string) $section['firstChannel'] !== ''): ?>
          <a class="btn btn-sm btn-ghost" href="/admin/articles?channel=<?= hechi_e((string) $section['firstChannel']) ?>">进稿件管理</a>
        <?php endif; ?>
      </div>
    </div>

    <p class="muted">
      绑定：<?= hechi_e(implode('、', $section['channels'])) ?>
      <?php if ((string) $row['more_url'] !== ''): ?>
        · 更多链接：<code><?= hechi_e((string) $row['more_url']) ?></code>
      <?php endif; ?>
      · 当前取到 <?= (int) $section['preview']['count'] ?> 条
    </p>

    <?php if ($section['preview']['tabs'] !== []): ?>
      <ul class="preview-list">
        <?php foreach ($section['preview']['tabs'] as $tab): ?>
          <li>
            <strong><?= hechi_e($tab['title']) ?></strong>
            <span class="muted"><?= (int) $tab['count'] ?> 条</span>
            <?php if ($tab['titles'] !== []): ?>
              <span class="preview-titles"><?= hechi_e(implode(' ／ ', $tab['titles'])) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php elseif ($section['preview']['titles'] !== []): ?>
      <p class="preview-titles"><?= hechi_e(implode(' ／ ', $section['preview']['titles'])) ?></p>
    <?php else: ?>
      <p class="muted">这个模块当前没有取到稿件，检查绑定栏目与稿件状态。</p>
    <?php endif; ?>

    <details class="advanced">
      <summary>改绑定与条数</summary>
      <form method="post" action="/admin/section/<?= hechi_e($key) ?>" class="edit-form">
        <?= $csrf ?>
        <div class="row">
          <label>模块标题（前台卡片标题）
            <input type="text" name="label" value="<?= hechi_e((string) $row['label']) ?>">
          </label>
          <label>绑定方式
            <select name="kind">
              <?php foreach ($kindLabels as $kind => $label): ?>
                <option value="<?= hechi_e($kind) ?>"<?= $section['kind'] === $kind ? ' selected' : '' ?>><?= hechi_e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <div class="row">
          <label>栏目号（绑定方式为「指定栏目」时填多个，用逗号分隔；为「一级栏目」时只填一个）
            <input type="text" name="scope" value="<?= $section['kind'] === 'tabs' ? '' : hechi_e($section['scopeText']) ?>" placeholder="如：306 或 306,317">
          </label>
        </div>
        <label class="full">分标签（绑定方式为「分标签」时用，每行一条：栏目号|标签名）
          <textarea name="scope_tabs" rows="4" placeholder="904|市政协动态"><?= $section['kind'] === 'tabs' ? hechi_e($section['scopeText']) : '' ?></textarea>
        </label>
        <div class="row">
          <label>取几条（1—30）
            <input type="number" name="page_size" min="1" max="30" value="<?= (int) $row['page_size'] ?>">
          </label>
          <label>状态
            <select name="status">
              <option value="published"<?= $status === 'published' ? ' selected' : '' ?>>已上线</option>
              <option value="offline"<?= $status === 'offline' ? ' selected' : '' ?>>已下线</option>
            </select>
          </label>
          <label>「更多」链接（先记录，前台仍按原规则）
            <input type="text" name="more_url" value="<?= hechi_e((string) $row['more_url']) ?>">
          </label>
        </div>
        <div class="actions">
          <button type="submit" class="btn-primary">保存模块</button>
          <span class="muted">可用栏目号见页面底部的栏目清单。</span>
        </div>
      </form>
    </details>
  </section>
<?php endforeach; ?>

<section class="card">
  <div class="card-head">
    <h2>未进首页导航的栏目</h2>
    <span class="muted">这些栏目的稿件不进首页模块，但可以在栏目页里看到，点栏目名直接进稿件列表。</span>
  </div>
  <?php foreach ($otherGroups as $group): ?>
    <div class="other-group">
      <div class="other-group-title"><?= hechi_e((string) $group['title']) ?></div>
      <ul class="other-channels">
        <?php foreach ($group['channels'] as $channel): ?>
          <?php $type = (string) $channel['type_code']; ?>
          <li>
            <a href="/admin/articles?channel=<?= hechi_e($type) ?>"><?= hechi_e((string) $channel['inner_name']) ?></a>
            <span class="muted"><?= hechi_e($type) ?> · <?= (int) $channel['article_count'] ?> 篇</span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
</section>

<?php include __DIR__ . '/_publish_hint.php'; ?>
