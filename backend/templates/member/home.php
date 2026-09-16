<?php

/**
 * 门户首页（未登录时唯一可见的页面）：整幅红底图作页面底板，登录框浮在图上，下方接登录指南。
 *
 * 这一页自带完整 HTML 文档，经 MemberView::bare() 渲染，不套 member/layout.php 外壳——
 * 底色图本身就是门面，再叠一层站头与页脚会把整幅底板切碎（与后台登录页同一处理）。
 *
 * @var array<string, mixed>|null $member
 * @var string $login
 * @var string $csrf
 * @var array{type:string,text:string}|null $flash
 */

declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title>政协委员在线提交提案系统 · <?= hechi_e($siteName) ?></title>
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="/assets/member.css">
</head>
<body class="m-portal">
  <a class="skip-link" href="#main">跳到主要内容</a>
  <img class="m-portal-bg" src="/assets/member/header.jpg" alt="">
  <div class="m-portal-shade" aria-hidden="true"></div>

  <main class="m-portal-main" id="main">
    <h1 class="m-visually-hidden">河池市政协提案系统</h1>

    <section class="m-login-card" aria-labelledby="loginTitle">
      <h2 id="loginTitle">委员登录</h2>

      <?php if ($flash): ?>
        <p class="m-flash m-flash-<?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>" role="<?= $flash['type'] === 'ok' ? 'status' : 'alert' ?>">
          <?= hechi_e($flash['text']) ?>
        </p>
      <?php endif; ?>

      <form method="post" action="/member/login" class="m-form">
        <?= $csrf ?>
        <label for="login_name">登录名</label>
        <input type="text" id="login_name" name="login_name" value="<?= hechi_e($login) ?>"
               autocomplete="username" required autofocus>

        <label for="password">密码</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>

        <button type="submit" class="m-btn m-btn-primary">登录</button>
      </form>

      <p class="m-login-help">忘记密码、或提示账号不存在，请联系提案委员会办公室重置。</p>
    </section>

    <section class="m-guide" aria-labelledby="guideTitle">
      <h2 id="guideTitle">登录指南</h2>
      <ol>
        <li>账号由提案委员会统一开通，登录名一般是手机号，初始密码另行告知。</li>
        <li>首次登录须先修改密码，改完才能填写与提交提案。</li>
        <li>密码连续输错 5 次会锁定 15 分钟，请稍后再试。</li>
        <li>提案内容不对外公开，只有本人与提案委员会可以查看。</li>
        <li>提案提交后，可在「我的提案」里查看办理状态与提案委意见。</li>
      </ol>
    </section>
  </main>
</body>
</html>
