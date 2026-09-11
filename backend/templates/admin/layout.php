<?php

/**
 * 后台公共外壳。模板变量见 HechiZx\Admin\View::page()。
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
if ($canAny(['article.edit', 'article.submit', 'article.review', 'article.publish', 'article.delete', 'article.restore'])) {
    $navItems['articles'] = ['label' => '稿件管理', 'url' => '/admin/articles'];
}
if ($can('channel.manage')) {
    $navItems['channels'] = ['label' => '栏目管理', 'url' => '/admin/channels'];
}
if ($can('user.manage')) {
    $navItems['users'] = ['label' => '用户与角色', 'url' => '/admin/users'];
}
$roleText = ($userRoleNames ?? []) === [] ? '未分配角色' : implode('、', $userRoleNames);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= hechi_e($title) ?> · <?= hechi_e($siteName) ?>后台</title>
  <link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
  <header class="topbar">
    <div class="topbar-left">
      <span class="logo">政协</span>
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
    <nav class="sidenav">
      <?php foreach ($navItems as $key => $item): ?>
        <a href="<?= hechi_e($item['url']) ?>"<?= $current === $key ? ' class="active" aria-current="page"' : '' ?>><?= hechi_e($item['label']) ?></a>
      <?php endforeach; ?>
      <span class="sidenav-note">自检：<a href="/api/v1/health" target="_blank" rel="noopener">接口状态</a></span>
    </nav>

    <main class="main">
      <?php if ($flash): ?>
        <div class="flash flash-<?= hechi_e($flash['type'] === 'ok' ? 'ok' : 'error') ?>"><?= hechi_e($flash['text']) ?></div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</body>
</html>
