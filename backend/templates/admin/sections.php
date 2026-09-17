<?php

/**
 * 其他栏目：首页正文各稿件模块的绑定维护 + 未进导航的栏目清单。
 *
 * @var list<array{row:array<string,mixed>, kind:string, scopeText:string, channels:list<string>, preview:array{tabs:list<array{title:string,count:int,titles:list<string>}>, titles:list<string>, count:int}}> $sections
 * @var array{blocks:list<array{code:string,title:string,chips:list<array<string,mixed>>}>,standalone:list<array<string,mixed>>,total:int,marked:int} $indexGroups
 * @var list<array{key:string, title:string, channels:list<array<string,mixed>>}> $otherGroups
 * @var list<string> $badgePresets 首页徽标的预设文字
 * @var string $badgeCustomKey 下拉里「自定义…」那一项的取值
 * @var int $badgeMaxLength 徽标文字长度上限
 */

declare(strict_types=1);

$kindLabels = [
    'channels' => '指定栏目',
    'parent'   => '一级栏目（含全部子栏目）',
    'tabs'     => '分标签（每个子栏目一个标签）',
];

// 顶部栏目索引里的一个 chip（控制器已算好落点与提示，这里只负责渲染）
$renderChip = static function (array $chip): string {
    $class = 'channel-chip' . (!empty($chip['marked']) ? ' channel-chip--module' : '');
    return '<a class="' . $class . '" href="' . hechi_e((string) $chip['href']) . '" title="' . hechi_e((string) $chip['hint']) . '">'
        . hechi_e((string) $chip['name']) . '</a>';
};
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

<section class="card channel-index" id="channel-index">
  <div class="card-head">
    <h2>栏目索引</h2>
    <span class="muted">共 <?= (int) $indexGroups['total'] ?> 个栏目，按一级栏目分组。</span>
  </div>

  <p class="index-legend">
    <span class="index-dot" aria-hidden="true"></span>
    表示这个栏目正被首页模块使用（共 <?= (int) $indexGroups['marked'] ?> 个），鼠标停在上面能看到是哪个模块；
    点栏目名跳到对应位置。
  </p>

  <?php if ($indexGroups['blocks'] !== []): ?>
    <div class="index-groups">
      <?php foreach ($indexGroups['blocks'] as $block): ?>
        <div class="index-group">
          <div class="index-group-title" title="一级栏目 <?= hechi_e((string) $block['code']) ?>"><?= hechi_e((string) $block['title']) ?></div>
          <div class="channel-nav">
            <?php foreach ($block['chips'] as $chip): ?><?= $renderChip($chip) ?><?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($indexGroups['standalone'] !== []): ?>
    <div class="index-row">
      <span class="index-label">独立栏目</span>
      <span class="channel-nav">
        <?php foreach ($indexGroups['standalone'] as $chip): ?><?= $renderChip($chip) ?><?php endforeach; ?>
      </span>
      <span class="row-meta">这些栏目本身就是一级栏目，下面没有子栏目。</span>
    </div>
  <?php endif; ?>
</section>

<?php foreach ($sections as $section): ?>
  <?php
  $row = $section['row'];
  $key = (string) $row['section_key'];
  $status = (string) $row['status'];
  ?>
  <section class="card" id="section-<?= hechi_e($key) ?>">
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
        <a class="btn btn-sm btn-ghost" href="#channel-index" title="回到页面顶部的栏目索引">回到索引</a>
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
                <td class="section-title">
                  <a class="title-link" href="/admin/article/<?= $id ?>"><?= hechi_e((string) $row['title']) ?></a>
                  <span class="row-meta">#<?= $id ?> · <?= hechi_e((string) $row['date']) ?></span>
                </td>
                <td class="nowrap"><?= hechi_e((string) $row['channel_name']) ?></td>
                <td class="nowrap">
                  <span class="tag tag-published">已发布</span>
                  <?php if ((int) $row['is_top'] === 1): ?><span class="tag tag-top">已置顶</span><?php endif; ?>
                  <?php if ((int) ($row['is_highlight'] ?? 0) === 1): ?><span class="tag tag-highlight">已高亮</span><?php endif; ?>
                  <?php /* 仓储给这一层的字段名是 badge（不是库里的 badge_text） */ ?>
                  <?php $badge = trim((string) ($row['badge'] ?? '')); ?>
                  <?php if ($badge !== ''): ?>
                    <span class="tag tag-badge">徽标：<?= hechi_e($badge) ?></span>
                  <?php endif; ?>
                </td>
                <td class="col-actions">
                  <div class="row-actions">
                    <form method="post" action="/admin/article/<?= $id ?>/order" class="inline"
                          data-confirm="把「<?= hechi_e((string) $row['title']) ?>」在本栏目上移一位？该栏目与首页模块的排序会立刻跟着变。">
                      <?= $csrf ?>
                      <input type="hidden" name="channel" value="<?= hechi_e($channel) ?>">
                      <input type="hidden" name="back" value="<?= hechi_e($back) ?>">
                      <input type="hidden" name="dir" value="up">
                      <button type="submit" class="btn btn-sm btn-icon" aria-label="把「<?= hechi_e((string) $row['title']) ?>」在本栏目上移一位">↑</button>
                    </form>
                    <form method="post" action="/admin/article/<?= $id ?>/order" class="inline"
                          data-confirm="把「<?= hechi_e((string) $row['title']) ?>」在本栏目下移一位？该栏目与首页模块的排序会立刻跟着变。">
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
                      <button type="submit" class="btn btn-sm"
                              data-confirm="<?= (int) $row['is_top'] === 1
                                ? '取消置顶「' . hechi_e((string) $row['title']) . '」？它会从该栏目和首页模块的最前面落回按时间排序的位置。'
                                : '置顶「' . hechi_e((string) $row['title']) . '」？它会排到该栏目和首页模块的最前面。' ?>"><?= (int) $row['is_top'] === 1 ? '取消置顶' : '置顶' ?></button>
                    </form>
                    <form method="post" action="/admin/article/<?= $id ?>/flags" class="inline">
                      <?= $csrf ?>
                      <input type="hidden" name="channel" value="<?= hechi_e($channel) ?>">
                      <input type="hidden" name="back" value="<?= hechi_e($back) ?>">
                      <input type="hidden" name="badge" value="<?= hechi_e((string) ($row['badge'] ?? '')) ?>">
                      <input type="hidden" name="highlight" value="<?= (int) ($row['is_highlight'] ?? 0) === 1 ? '0' : '1' ?>">
                      <button type="submit" class="btn btn-sm"
                              data-confirm="<?= (int) ($row['is_highlight'] ?? 0) === 1
                                ? '取消高亮「' . hechi_e((string) $row['title']) . '」？首页标题会恢复常规颜色。'
                                : '高亮「' . hechi_e((string) $row['title']) . '」？首页标题会显示为正红加粗。' ?>"><?= (int) ($row['is_highlight'] ?? 0) === 1 ? '取消高亮' : '高亮' ?></button>
                    </form>
                    <?php
                    // 徽标：下拉给预设，另外留一个自定义文本框。文本框不默认隐藏——
                    // 没有脚本时它就是唯一入口；有脚本时由 admin.js 按下拉选择显隐。
                    $badgeIsCustom = $badge !== '' && !in_array($badge, $badgePresets, true);
                    ?>
                    <form method="post" action="/admin/article/<?= $id ?>/flags" class="inline badge-form" data-badge-form>
                      <?= $csrf ?>
                      <input type="hidden" name="channel" value="<?= hechi_e($channel) ?>">
                      <input type="hidden" name="back" value="<?= hechi_e($back) ?>">
                      <input type="hidden" name="highlight" value="<?= (int) ($row['is_highlight'] ?? 0) ?>">
                      <select name="badge_preset" data-badge-preset aria-label="徽标文字（可选预设或自定义）">
                        <option value="">不显示徽标</option>
                        <?php foreach ($badgePresets as $preset): ?>
                          <option value="<?= hechi_e($preset) ?>"<?= $badge === $preset ? ' selected' : '' ?>><?= hechi_e($preset) ?></option>
                        <?php endforeach; ?>
                        <option value="<?= hechi_e($badgeCustomKey) ?>"<?= $badgeIsCustom ? ' selected' : '' ?>>自定义…</option>
                      </select>
                      <input type="text" name="badge" class="badge-custom" data-badge-custom
                             value="<?= $badgeIsCustom ? hechi_e($badge) : '' ?>"
                             maxlength="<?= (int) $badgeMaxLength ?>" size="6"
                             placeholder="自定义徽标"
                             aria-label="自定义徽标文字（最多 <?= (int) $badgeMaxLength ?> 个字）">
                      <button type="submit" class="btn btn-sm"
                              data-confirm="保存「<?= hechi_e((string) $row['title']) ?>」在本栏目与首页模块的徽标？首页该条标题后面的小标记会立刻跟着变。">存徽标</button>
                    </form>
                    <?php if ($badge !== ''): ?>
                      <form method="post" action="/admin/article/<?= $id ?>/flags" class="inline">
                        <?= $csrf ?>
                        <input type="hidden" name="channel" value="<?= hechi_e($channel) ?>">
                        <input type="hidden" name="back" value="<?= hechi_e($back) ?>">
                        <input type="hidden" name="highlight" value="<?= (int) ($row['is_highlight'] ?? 0) ?>">
                        <input type="hidden" name="badge_preset" value="">
                        <input type="hidden" name="badge" value="">
                        <button type="submit" class="btn btn-sm btn-ghost"
                                data-confirm="删除「<?= hechi_e((string) $row['title']) ?>」的徽标「<?= hechi_e($badge) ?>」？首页这条标题后面就只显示标题了。"
                                aria-label="删除徽标「<?= hechi_e($badge) ?>」">删徽标</button>
                      </form>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-ghost" href="/admin/article/<?= $id ?>">编辑</a>
                    <a class="btn btn-sm btn-ghost" href="/detail.html?id=<?= $id ?>" target="_blank" rel="noopener">预览</a>
                    <button type="button" class="btn btn-sm btn-ghost" data-copy-link="/article/<?= $id ?>.html">复制链接</button>
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
          <label class="field--sm">首页显示条数（1—30）
            <input type="number" name="page_size" min="1" max="30" value="<?= (int) $row['page_size'] ?>">
          </label>
          <label class="field--sm">状态
            <select name="status">
              <option value="published"<?= $status === 'published' ? ' selected' : '' ?>>已上线</option>
              <option value="offline"<?= $status === 'offline' ? ' selected' : '' ?>>已下线</option>
            </select>
          </label>
          <label>「更多」链接（先记录，前台仍按原规则）
            <input type="text" name="more_url" value="<?= hechi_e((string) $row['more_url']) ?>">
          </label>
        </div>
        <?php /* 长提示挪出字段本身：宽度档只管控件，提示跟着整行铺开才不会折成五行 */ ?>
        <p class="field-hint">显示绑定栏目里最新的已发布稿件，置顶稿排在最前；分标签模块每个标签各显示这么多篇。</p>
        <div class="actions">
          <button type="submit" class="btn-primary"
                  data-confirm="保存模块「<?= hechi_e((string) $row['label']) ?>」？首页这张卡片会立刻按新的绑定与条数显示。">保存模块</button>
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
          <li id="ch-<?= hechi_e($type) ?>">
            <a href="/admin/articles?channel=<?= hechi_e($type) ?>" title="栏目 <?= hechi_e($type) ?>"><?= hechi_e((string) $channel['inner_name']) ?></a>
            <span class="muted"><?= (int) $channel['article_count'] ?> 篇</span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
</section>

<?php include __DIR__ . '/_publish_hint.php'; ?>
