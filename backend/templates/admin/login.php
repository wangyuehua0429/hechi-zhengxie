<?php

/**
 * 登录页。
 *
 * 可访问性处理（依据 ui-ux-pro-max ux 规则）：
 *   * 失败时把提示做成 role="alert" 的错误摘要并接管焦点，键盘与读屏用户第一时间听得到；
 *   * 表单字段保留原生 required 校验，不靠只会变红的边框表达错误；
 *   * 密码可见性开关是有 accessible name 与 aria-pressed 的按钮，且默认 hidden，由脚本来启用；
 *   * 允许密码管理器与粘贴，不用 onpaste 之类的拦截。
 *
 * @var string $siteName
 * @var string $error
 * @var bool $hasAccount
 * @var string $csrf
 */

declare(strict_types=1);

$hasError = $error !== '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex, nofollow">
  <title>登录 · <?= hechi_e($siteName) ?>后台</title>
  <?php /* 与后台外壳同一份主题引导：登录页也要跟着用户选过的深浅色 */ ?>
  <script src="/assets/theme.js"></script>
  <link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="login-body">
  <main class="login-shell">
    <form class="login-card" method="post" action="/admin/login">
      <?= $csrf ?>

      <div class="login-brand">
        <span class="login-logo" aria-hidden="true">政协</span>
        <span class="login-titles">
          <h1><?= hechi_e($siteName) ?></h1>
          <span class="login-sub">内容管理后台</span>
        </span>
      </div>

      <?php if ($hasError): ?>
        <div class="login-error" id="login-error" role="alert" tabindex="-1" autofocus>
          <strong>登录没有成功</strong>
          <span><?= hechi_e($error) ?></span>
        </div>
      <?php endif; ?>

      <label class="login-field">账号
        <input type="text" name="username" autocomplete="username" required<?= $hasError ? '' : ' autofocus' ?>>
      </label>

      <label class="login-field">密码
        <span class="pw-wrap">
          <input type="password" id="password" name="password" autocomplete="current-password" required>
          <button type="button" class="pw-toggle" id="pw-toggle" aria-controls="password" aria-pressed="false" aria-label="显示密码" hidden>
            <svg class="pw-icon pw-icon--on" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"></path>
              <circle cx="12" cy="12" r="3"></circle>
            </svg>
            <svg class="pw-icon pw-icon--off" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M3 3l18 18"></path>
              <path d="M10.6 5.2A10.9 10.9 0 0 1 12 5c6.4 0 10 7 10 7a17.8 17.8 0 0 1-3.2 4.3M6.3 6.9A17.6 17.6 0 0 0 2 12s3.6 7 10 7a10.7 10.7 0 0 0 4.3-.9"></path>
              <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
            </svg>
          </button>
        </span>
      </label>

      <button type="submit" class="btn-primary login-submit" id="login-submit">登录</button>

      <p class="login-help">
        忘记密码请联系系统管理员，在后台「用户与角色」里重置；本系统仅限授权人员使用，操作会记入日志。
      </p>

      <?php if (!$hasAccount): ?>
        <p class="login-hint">
          库里还没有后台账号，先在终端执行：<br>
          <code>php backend/bin/user.php create admin 你的密码</code>
        </p>
      <?php endif; ?>
    </form>
  </main>

  <script>
    (function () {
      var password = document.getElementById('password');
      var toggle = document.getElementById('pw-toggle');
      if (password && toggle) {
        toggle.hidden = false;
        toggle.addEventListener('click', function () {
          var reveal = password.type === 'password';
          password.type = reveal ? 'text' : 'password';
          toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
          toggle.setAttribute('aria-label', reveal ? '隐藏密码' : '显示密码');
          toggle.classList.toggle('is-revealed', reveal);
          password.focus();
        });
      }

      var form = document.querySelector('.login-card');
      var submit = document.getElementById('login-submit');
      if (form && submit) {
        form.addEventListener('submit', function () {
          submit.disabled = true;
          submit.textContent = '登录中…';
        });
      }
    })();
  </script>
</body>
</html>
