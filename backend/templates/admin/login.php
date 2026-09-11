<?php

/**
 * 登录页。
 *
 * @var string $siteName
 * @var string $error
 * @var bool $hasAccount
 * @var string $csrf
 */

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>登录 · <?= hechi_e($siteName) ?>后台</title>
  <link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="login-body">
  <form class="login-card" method="post" action="/admin/login">
    <?= $csrf ?>
    <h1><?= hechi_e($siteName) ?></h1>
    <p class="login-sub">内容管理后台</p>

    <?php if ($error !== ''): ?>
      <div class="flash flash-error"><?= hechi_e($error) ?></div>
    <?php endif; ?>

    <label>账号
      <input type="text" name="username" autocomplete="username" required autofocus>
    </label>
    <label>密码
      <input type="password" name="password" autocomplete="current-password" required>
    </label>

    <button type="submit" class="btn-primary">登录</button>

    <?php if (!$hasAccount): ?>
      <p class="login-hint">
        库里还没有后台账号，先在终端执行：<br>
        <code>php backend/bin/user.php create admin 你的密码</code>
      </p>
    <?php endif; ?>
  </form>
</body>
</html>
