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

$navItems = ['dashboard' => ['label' => '概览', 'url' => '/admin']];
if ($can('home.manage')) {
    $navItems['nav'] = ['label' => '导航栏目', 'url' => '/admin/nav'];
    $navItems['slides'] = ['label' => '头条轮换', 'url' => '/admin/slides'];
    $navItems['sections'] = ['label' => '其他栏目', 'url' => '/admin/sections'];
    $navItems['banners'] = ['label' => '站内横幅', 'url' => '/admin/banners'];
}
if ($canAny(['article.edit', 'article.submit', 'article.review', 'article.publish', 'article.delete', 'article.restore'])) {
    $navItems['articles'] = ['label' => '稿件管理', 'url' => '/admin/articles'];
}
if ($can('user.manage')) {
    $navItems['users'] = ['label' => '用户与角色', 'url' => '/admin/users'];
}
// 没有首页维护权限、但能管栏目时，仍保留「栏目管理」入口（四类页面在他那里看不到）
if ($can('channel.manage') && !$can('home.manage')) {
    $navItems['channels'] = ['label' => '栏目管理', 'url' => '/admin/channels'];
}
$roleText = ($userRoleNames ?? []) === [] ? '未分配角色' : implode('、', $userRoleNames);

// 面包屑：章节名 → 地址，页面名取 <title> 里「·」之前的部分
$sections = [
    'dashboard' => ['label' => '概览', 'url' => '/admin'],
    'articles'  => ['label' => '稿件管理', 'url' => '/admin/articles'],
    'channels'  => ['label' => '栏目管理', 'url' => '/admin/channels'],
    'nav'       => ['label' => '导航栏目', 'url' => '/admin/nav'],
    'slides'    => ['label' => '头条轮换', 'url' => '/admin/slides'],
    'sections'  => ['label' => '其他栏目', 'url' => '/admin/sections'],
    'banners'   => ['label' => '站内横幅', 'url' => '/admin/banners'],
    'users'     => ['label' => '用户与角色', 'url' => '/admin/users'],
];
$section = $sections[$current] ?? null;
$pageLabel = trim(explode('·', $title)[0]);
if ($section !== null && ($pageLabel === $section['label'] || $pageLabel === '' || $section['label'] === '概览')) {
    $pageLabel = '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title><?= hechi_e($title) ?> · <?= hechi_e($siteName) ?>后台</title>
  <link rel="stylesheet" href="/assets/admin.css">
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
        <span class="who"><?= hechi_e(($user['real_name'] ?? '') !== '' ? $user['real_name'] : $user['username']) ?><span class="who-role"><?= hechi_e($roleText) ?></span></span>
        <form method="post" action="/admin/logout" class="inline"><?= $csrf ?>
          <button type="submit" class="link-btn">退出</button>
        </form>
      <?php endif; ?>
    </div>
  </header>

  <div class="shell">
    <nav class="sidenav" aria-label="后台主菜单">
      <?php foreach ($navItems as $key => $item): ?>
        <a href="<?= hechi_e($item['url']) ?>"<?= $current === $key ? ' class="active" aria-current="page"' : '' ?>><?= hechi_e($item['label']) ?></a>
      <?php endforeach; ?>
      <span class="sidenav-sep" aria-hidden="true"></span>
      <a class="sidenav-secondary" href="/" target="_blank" rel="noopener">查看站点前台</a>
      <span class="sidenav-note">自检：<a href="/api/v1/health" target="_blank" rel="noopener">接口状态</a></span>
    </nav>

    <main class="main" id="main">
      <nav class="breadcrumb" aria-label="当前位置">
        <?php if ($section === null || $current === 'dashboard'): ?>
          <span class="crumb-current">概览</span>
        <?php else: ?>
          <a href="/admin">概览</a>
          <span class="crumb-sep" aria-hidden="true">/</span>
          <?php if ($pageLabel === ''): ?>
            <span class="crumb-current"><?= hechi_e($section['label']) ?></span>
          <?php else: ?>
            <a href="<?= hechi_e($section['url']) ?>"><?= hechi_e($section['label']) ?></a>
            <span class="crumb-sep" aria-hidden="true">/</span>
            <span class="crumb-current"><?= hechi_e($pageLabel) ?></span>
          <?php endif; ?>
        <?php endif; ?>
      </nav>

      <?php if ($flash): ?>
        <div class="flash flash-<?= hechi_e($flash['type'] === 'ok' ? 'ok' : 'error') ?>" role="<?= $flash['type'] === 'ok' ? 'status' : 'alert' ?>"><?= hechi_e($flash['text']) ?></div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</body>
</html>
