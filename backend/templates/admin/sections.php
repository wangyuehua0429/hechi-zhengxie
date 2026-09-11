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
      首页正文的 <?= count($sections) ?> 个模块，每个模块绑定到栏目，把该栏目最新的已发布稿件显示在首页；
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
        <span class="muted"><?= $section['kind'] === 'tabs' ? '每个标签显示' : '首页显示' ?> <?= (int) $row['page_size'] ?> 条</span>
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
      · 首页当前显示 <?= (int) $section['preview']['count'] ?> 条
    </p>

    <?php
    // 模块当前显示的稿件：库内条目按头条轮换那样的表格列出来，可直接排序与置顶
    $groups = $section['groups'] ?? [];
    $dbCount = 0;
    foreach ($groups as $group) {
      $dbCount += count($group['rows']);
    }
    $snapshotCount = max(0, (int) $section['preview']['count'] - $dbCount);
    $multiGroup = count($groups) > 1;
    ?>

    <?php foreach ($groups as $group): ?>
      <?php if ($multiGroup && (string) $group['title'] !== ''): ?>
        <h3 class="section-tab-title"><?= hechi_e((string) $group['title']) ?> <span class="muted">（栏目 <?= hechi_e((string) $group['channel']) ?>）</span></h3>
      <?php endif; ?>
      <?php if ($group['rows'] === []): ?>
        <p class="muted">这个<?= $multiGroup ? '标签' : '模块' ?>暂时没有可显示的稿件，检查绑定栏目与稿件状态。</p>
      <?php else: ?>
        <?php
        // 这一组里有没有配图：一条都没有就把「图」列省掉，免得整列都是「无」
        $hasImg = false;
        foreach ($group['rows'] as $row) {
          if ((string) $row['img'] !== '') { $hasImg = true; break; }
        }
        ?>
        <div class="table-scroll">
        <table class="grid article-table section-table">
          <caption class="visually-hidden">模块当前显示的稿件</caption>
          <thead>
            <tr>
              <th scope="col" class="nowrap">序</th>
              <?php if ($hasImg): ?><th scope="col" class="nowrap">图</th><?php endif; ?>
              <th scope="col">稿件</th>
              <th scope="col" class="nowrap">栏目</th>
              <th scope="col" class="nowrap">状态</th>
              <th scope="col" class="col-actions">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($group['rows'] as $index => $row): ?>
              <?php
              $id = (int) $row['id'];
              $channel = (string) $row['channel'];
              $back = '/admin/sections';
              ?>
              <tr>
                <td class="nowrap muted"><?= (int) $index + 1 ?></td>
                <?php if ($hasImg): ?>
                  <td>
                    <?php if ((string) $row['img'] !== ''): ?>
                      <img class="thumb-sm" src="<?= hechi_e(hechi_asset($row['img'])) ?>" alt="">
                    <?php else: ?>
                      <span class="muted">无</span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
                <td>
                  <a class="title-link" href="/admin/article/<?= $id ?>"><?= hechi_e((string) $row['title']) ?></a>
                  <span class="row-meta">#<?= $id ?> · <?= hechi_e((string) $row['date']) ?></span>
                </td>
                <td class="nowrap"><?= hechi_e((string) $row['channel_name']) ?></td>
                <td class="nowrap">
                  <span class="tag tag-published">已发布</span>
                  <?php if ((int) $row['is_top'] === 1): ?><span class="tag tag-top">已置顶</span><?php endif; ?>
                </td>
                <td class="col-actions">
                  <div class="row-actions">
                    <form method="post" action="/admin/article/<?= $id ?>/order" class="inline">
                      <?= $csrf ?>
                      <input type="hidden" name="channel" value="<?= hechi_e($channel) ?>">
                      <input type="hidden" name="back" value="<?= hechi_e($back) ?>">
                      <input type="hidden" name="dir" value="up">
                      <button type="submit" class="btn btn-sm btn-icon" aria-label="把「<?= hechi_e((string) $row['title']) ?>」在本栏目上移一位">↑</button>
                    </form>
                    <form method="post" action="/admin/article/<?= $id ?>/order" class="inline">
                      <?= $csrf ?>
                      <input type="hidden" name="channel" value="<?= hechi_e($channel) ?>">
                      <input type="hidden" name="back" value="<?= hechi_e($back) ?>">
                      <input type="hidden" name="dir" value="down">
                      <button type="submit" class="btn btn-sm btn-icon" aria-label="把「<?= hechi_e((string) $row['title']) ?>」在本栏目下移一位">↓</button>
                    </form>
                    <form method="post" action="/admin/article/<?= $id ?>/top" class="inline">
                      <?= $csrf ?>
                      <input type="hidden" name="channel" value="<?= hechi_e($channel) ?>">
                      <input type="hidden" name="back" value="<?= hechi_e($back) ?>">
                      <input type="hidden" name="value" value="<?= (int) $row['is_top'] === 1 ? '0' : '1' ?>">
                      <button type="submit" class="btn btn-sm"><?= (int) $row['is_top'] === 1 ? '取消置顶' : '置顶' ?></button>
                    </form>
                    <a class="btn btn-sm btn-ghost" href="/admin/article/<?= $id ?>">编辑</a>
                    <a class="btn btn-sm btn-ghost" href="/detail.html?id=<?= $id ?>" target="_blank" rel="noopener">前台</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($snapshotCount > 0): ?>
      <p class="muted">
        另有 <?= (int) $snapshotCount ?> 条来自改版前的快照（这些稿件没有进稿件表，暂时不能在这里排序）。
        在绑定的栏目里发新稿，它们会被逐步顶下去。
      </p>
    <?php endif; ?>

    <?php
    // 上面的稿件表格循环也用 $row，会把模块配置覆盖成最后一篇稿件，
    // 进表单前先取回模块自身那一行，否则标题、显示条数、更多链接会是空的。
    $row = $section['row'];
    ?>
    <details class="advanced">
      <summary>改绑定与显示条数</summary>
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
          <label>首页显示条数（1—30）
            <input type="number" name="page_size" min="1" max="30" value="<?= (int) $row['page_size'] ?>">
            <span class="row-meta">显示绑定栏目里最新的已发布稿件，置顶稿排在最前；分标签模块每个标签各显示这么多篇。</span>
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
