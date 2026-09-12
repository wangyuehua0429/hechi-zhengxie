<?php

/**
 * 新建栏目：选归属（一级栏目）→ 栏目号 → 名称 → 版式与状态。
 *
 * 两级栏目：归属留空就是新的一级栏目；挂在某个一级栏目下时，“一级栏目名”跟随父栏目，
 * 避免同一组里出现两个不同的一级名（前台导航条按一级名分组、共用一条横条）。
 *
 * @var list<string> $layouts
 * @var list<array<string, mixed>> $parents
 * @var array<string, string> $defaults
 * @var string $error
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
<div class="page-head">
  <div class="page-title">
    <h1>新建栏目</h1>
    <p class="subtitle">
      新栏目默认排在所在分组的最后，建好后可在列表里上移／下移；栏目号与 URL 标识建好后不再改（旧地址 301 与前台链接都按它们走）。
    </p>
  </div>
  <div class="head-actions">
    <a class="btn btn-ghost" href="/admin/channels">返回栏目列表</a>
  </div>
</div>

<?php if ($error !== ''): ?>
  <p class="flash flash-error"><?= hechi_e($error) ?></p>
<?php endif; ?>

<form method="post" action="/admin/channel/create" class="edit-form">
  <?= $csrf ?>

  <section class="card">
    <h2>归属</h2>
    <label class="full">挂在哪个一级栏目下
      <select name="parent_type">
        <option value="">（不挂——新建一个一级栏目）</option>
        <?php foreach ($parents as $parent): ?>
          <?php $parentType = (string) $parent['type_code']; ?>
          <option value="<?= hechi_e($parentType) ?>"<?= $defaults['parent_type'] === $parentType ? ' selected' : '' ?>>
            <?= hechi_e((string) $parent['name']) ?>（栏目号 <?= hechi_e($parentType) ?><?= $parent['status'] === 'published' ? '' : '，已下线' ?>）
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <p class="muted">
      挂在某个一级栏目下时，会沿用它的“一级栏目名”；系统只做两级栏目，不能挂在子栏目下面。
    </p>
  </section>

  <section class="card">
    <h2>栏目号与名称</h2>
    <div class="row">
      <label>栏目号
        <input type="text" name="type_code" value="<?= hechi_e($defaults['type_code']) ?>"
               required maxlength="32" pattern="[A-Za-z0-9_-]{1,32}" placeholder="例如 320">
      </label>
      <label>URL 标识（选填）
        <input type="text" name="slug" value="<?= hechi_e($defaults['slug']) ?>"
               maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9._-]*" placeholder="留空则用栏目号">
      </label>
    </div>
    <p class="muted">
      栏目号要与旧站栏目号一致才对得上旧地址 301；新开的栏目号用一个没被占用的数字或短代码即可。
      静态页地址是 <code>/channel/&lt;URL 标识&gt;/</code>，同名的标识会自动补上栏目号。
    </p>
    <div class="row">
      <label>一级栏目名
        <input type="text" name="name" value="<?= hechi_e($defaults['name']) ?>" maxlength="64">
      </label>
      <label>子栏目名
        <input type="text" name="inner_name" value="<?= hechi_e($defaults['inner_name']) ?>" required maxlength="64">
      </label>
    </div>
    <p class="muted">
      前台导航与栏目页标题用“子栏目名”；“一级栏目名”是导航条上的分组名。挂在已有分组下时留空即可，保存时以父栏目名为准。
    </p>
  </section>

  <section class="card">
    <h2>版式与状态</h2>
    <div class="row">
      <label class="grow">版式
        <select name="layout">
          <?php foreach ($layouts as $layout): ?>
            <option value="<?= hechi_e($layout) ?>"<?= $defaults['layout'] === $layout ? ' selected' : '' ?>><?= hechi_e($layoutLabels[$layout] ?? $layout) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>状态
        <select name="status">
          <option value="published"<?= $defaults['status'] === 'published' ? ' selected' : '' ?>>已上线</option>
          <option value="offline"<?= $defaults['status'] === 'offline' ? ' selected' : '' ?>>已下线</option>
        </select>
      </label>
    </div>
    <p class="muted">先建成“已下线”也可以，等内容准备好再上线，避免前台出现空栏目。</p>
  </section>

  <section class="card">
    <h2>栏目简介</h2>
    <label class="full">栏目简介（前台栏目页顶部展示）
      <textarea name="intro" rows="3"><?= hechi_e($defaults['intro']) ?></textarea>
    </label>
  </section>

  <div class="form-actions">
    <button type="submit" class="btn-primary">新建栏目</button>
    <a class="btn" href="/admin/channels">取消</a>
  </div>
</form>
