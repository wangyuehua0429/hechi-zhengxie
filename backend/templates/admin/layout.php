<?php

/**
 * 后台公共外壳。模板变量见 HechiZx\Admin\View::page()。
 *
 * 面包屑由 $current 与 $title 推导，控制器不用逐个传：
 * 「概览 / 稿件管理 / 编辑稿件」——在后台任何一页都知道自己在哪、上一层是什么。
 *
 * @var string $title
 * @var string $siteName
 * @var array<string, mixed>|null $user
 * @var string $content
 * @var array{type:string,text:string}|null $flash
 * @var string $current
 * @var string $csrf
 * @var list<string> $userRoleNames
 * @var list<string> $userPerms
 */

declare(strict_types=1);

$can = static fn (string $perm): bool => in_array($perm, $userPerms ?? [], true);
$canAny = static function (array $perms) use ($can): bool {
    foreach ($perms as $perm) {
        if ($can((string) $perm)) {
            return true;
        }
    }
    return false;
};

// 侧栏：概览 / 首页管理（树形，默认展开）/ 稿件管理 / 用户管理 / 操作日志
$adminIcons = require __DIR__ . '/_icons.php';

$navItems = ['dashboard' => ['label' => '概览', 'url' => '/admin', 'icon' => 'dashboard']];

$homeChildren = [
    'nav'     => ['label' => '导航栏目', 'url' => '/admin/nav'],
    'notice'  => ['label' => '滚动公告', 'url' => '/admin/notice'],
    'slides'  => ['label' => '头条轮换', 'url' => '/admin/slides'],
    'sections' => ['label' => '其他栏目', 'url' => '/admin/sections'],
    'banners' => ['label' => '站内横幅', 'url' => '/admin/banners'],
];
if (!$can('home.manage')) {
    $homeChildren = [];
}
// 只有栏目权限、没有首页维护权限时，首页管理里保留「全部栏目」这一个入口
if ($can('channel.manage') && !$can('home.manage')) {
    $homeChildren['channels'] = ['label' => '全部栏目', 'url' => '/admin/channels'];
}
if ($homeChildren !== []) {
    $navItems['home'] = ['label' => '首页管理', 'icon' => 'home', 'children' => $homeChildren];
}

if ($canAny(['article.edit', 'article.submit', 'article.review', 'article.publish', 'article.delete', 'article.restore'])) {
    $navItems['articles'] = ['label' => '稿件管理', 'url' => '/admin/articles', 'icon' => 'articles'];
}
if ($can('user.manage')) {
    $navItems['users'] = ['label' => '用户管理', 'url' => '/admin/users', 'icon' => 'users'];
}
if ($can('log.view')) {
    $navItems['logs'] = ['label' => '操作日志', 'url' => '/admin/logs', 'icon' => 'logs'];
}
$roleText = ($userRoleNames ?? []) === [] ? '未分配角色' : implode('、', $userRoleNames);
// 姓名与角色名撞在一起时（管理员账号常见）不重复显示两遍
$displayName = (string) (($user['real_name'] ?? '') !== '' ? $user['real_name'] : ($user['username'] ?? ''));
$showRole = $roleText !== '' && $roleText !== $displayName;

// 面包屑：章节名 → 地址，页面名取 <title> 里「·」之前的部分
$homeCrumb = ['label' => '首页管理', 'url' => '/admin/nav'];
$sections = [
    'dashboard' => ['label' => '概览', 'url' => '/admin'],
    'articles'  => ['label' => '稿件管理', 'url' => '/admin/articles'],
    'channels'  => ['label' => '全部栏目', 'url' => '/admin/channels', 'parent' => $homeCrumb],
    'nav'       => ['label' => '导航栏目', 'url' => '/admin/nav', 'parent' => $homeCrumb],
    'notice'    => ['label' => '滚动公告', 'url' => '/admin/notice', 'parent' => $homeCrumb],
    'slides'    => ['label' => '头条轮换', 'url' => '/admin/slides', 'parent' => $homeCrumb],
    'sections'  => ['label' => '其他栏目', 'url' => '/admin/sections', 'parent' => $homeCrumb],
    'banners'   => ['label' => '站内横幅', 'url' => '/admin/banners', 'parent' => $homeCrumb],
    'users'     => ['label' => '用户管理', 'url' => '/admin/users'],
    'logs'      => ['label' => '操作日志', 'url' => '/admin/logs'],
    'health'    => ['label' => '接口状态', 'url' => '/admin/health'],
];
$section = $sections[$current] ?? null;
$pageLabel = trim(explode('·', $title)[0]);
if ($section !== null && ($pageLabel === $section['label'] || $pageLabel === '' || $section['label'] === '概览')) {
    $pageLabel = '';
}
// 面包屑第一层：首页管理下的页面（导航栏目／头条轮换／其他栏目／站内横幅／全部栏目）
// 从「首页管理」起，其余页面仍从「概览」起。
$rootCrumb = isset($section['parent']) ? $section['parent'] : ['label' => '概览', 'url' => '/admin'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title><?= hechi_e($title) ?> · <?= hechi_e($siteName) ?>后台</title>
  <link rel="stylesheet" href="/assets/admin.css">
  <?php if (!empty($pageHead)): ?><?= $pageHead ?><?php endif; ?>
  <script src="/assets/admin.js" defer></script>
</head>
<body>
  <a class="skip-link" href="#main">跳到主要内容</a>
  <header class="topbar">
    <div class="topbar-left">
      <span class="logo" aria-hidden="true">政协</span>
      <span class="site"><?= hechi_e($siteName) ?> · 内容管理后台</span>
    </div>
    <div class="topbar-right">
      <?php if ($user): ?>
        <span class="who"><?= hechi_e($displayName) ?><?php if ($showRole): ?><span class="who-role"><?= hechi_e($roleText) ?></span><?php endif; ?></span>
        <form method="post" action="/admin/logout" class="inline"><?= $csrf ?>
          <button type="submit" class="link-btn">退出</button>
        </form>
      <?php endif; ?>
    </div>
  </header>

  <div class="shell">
    <nav class="sidenav" aria-label="后台主菜单">
      <?php foreach ($navItems as $key => $item): ?>
        <?php if (isset($item['children'])): ?>
          <?php
          // 树形分组：默认展开（details open），子项里有一个是当前页就高亮组标题
          $groupActive = isset($item['children'][$current]);
          ?>
          <details class="sidenav-group<?= $groupActive ? ' is-active' : '' ?>" open>
            <summary><?= $adminIcons[(string) ($item['icon'] ?? '')] ?? '' ?><span><?= hechi_e($item['label']) ?></span></summary>
            <div class="sidenav-children">
              <?php foreach ($item['children'] as $childKey => $child): ?>
                <a href="<?= hechi_e($child['url']) ?>"<?= $current === $childKey ? ' class="active" aria-current="page"' : '' ?>><?= hechi_e($child['label']) ?></a>
              <?php endforeach; ?>
            </div>
          </details>
        <?php else: ?>
          <a href="<?= hechi_e($item['url']) ?>"<?= $current === $key ? ' class="active" aria-current="page"' : '' ?>><?= $adminIcons[(string) ($item['icon'] ?? '')] ?? '' ?><span><?= hechi_e($item['label']) ?></span></a>
        <?php endif; ?>
      <?php endforeach; ?>
      <span class="sidenav-sep" aria-hidden="true"></span>
      <a class="sidenav-secondary" href="/" target="_blank" rel="noopener">查看站点前台</a>
      <span class="sidenav-note">自检：<a href="/admin/health">接口状态</a></span>
    </nav>

    <main class="main" id="main">
      <?php /* 概览页不再显示面包屑：只有一层，与下面的 H1「概览」重复 */ ?>
      <?php if ($section !== null && $current !== 'dashboard'): ?>
        <nav class="breadcrumb" aria-label="当前位置">
          <a href="<?= hechi_e($rootCrumb['url']) ?>"><?= hechi_e($rootCrumb['label']) ?></a>
          <span class="crumb-sep" aria-hidden="true">/</span>
          <?php if ($pageLabel === ''): ?>
            <span class="crumb-current"><?= hechi_e($section['label']) ?></span>
          <?php else: ?>
            <a href="<?= hechi_e($section['url']) ?>"><?= hechi_e($section['label']) ?></a>
            <span class="crumb-sep" aria-hidden="true">/</span>
            <span class="crumb-current"><?= hechi_e($pageLabel) ?></span>
          <?php endif; ?>
        </nav>
      <?php endif; ?>

      <?php if ($flash): ?>
        <div class="flash flash-<?= hechi_e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="<?= $flash['type'] === 'ok' ? 'status' : 'alert' ?>">
          <span class="flash-icon" aria-hidden="true"><?= $adminIcons[$flash['type'] === 'ok' ? 'flash-ok' : 'flash-error'] ?></span>
          <span><?= hechi_e($flash['text']) ?></span>
        </div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>

  <?php /* 长页面（如「其他栏目」）回到顶部：默认隐藏，脚本滚过一屏后才让它出现 */ ?>
  <button type="button" class="to-top" data-to-top hidden>
    <span aria-hidden="true">↑</span> 回到顶部
  </button>
</body>
</html>
