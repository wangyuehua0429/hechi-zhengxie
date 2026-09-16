<?php

/**
 * 门户首页（未登录时唯一可见的页面）：整幅红底纹样作页面底板，标题与登录框浮在图上，下方接登录指南。
 *
 * 这一页自带完整 HTML 文档，经 MemberView::bare() 渲染，不套 member/layout.php 外壳——
 * 底色图本身就是门面，再叠一层站头与页脚会把整幅底板切碎（与后台登录页同一处理）。
 * 未登录时没有工作台导航可展示，机构署名与联系方式改由本页页尾一行承担。
 *
 * 标题是真实文本，不再烧进底图：底图 header-art.jpg 由 header.jpg 裁去原图里烧好的标题带得到，
 * 这样标题能随字号缩放、读屏与翻译拿得到，窄屏也不会被裁掉。
 *
 * @var array<string, mixed>|null $member
 * @var string $login
 * @var string $contactPhone
 * @var string $csrf
 * @var array{type:string,text:string}|null $flash
 */

declare(strict_types=1);

// 从失败态回来时登录名已经回填，焦点直接落在密码框，省一次 Tab（与后台登录页把焦点交给
// role="alert" 错误摘要不同：这里是表单页，密码框才是用户下一步要动的地方）
$hasLoginName = trim($login) !== '';
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
  <img class="m-portal-bg" src="/assets/member/header-art.jpg" alt="">
  <div class="m-portal-shade" aria-hidden="true"></div>

  <main class="m-portal-main" id="main">
    <h1 class="m-portal-title">
      <span class="m-portal-title-main">政协委员在线提交提案系统</span>
      <span class="m-portal-title-sub"><?= hechi_e($siteName) ?></span>
    </h1>

    <section class="m-login-card" aria-labelledby="loginTitle">
      <h2 id="loginTitle">委员登录</h2>

      <?php if ($flash): ?>
        <p class="m-flash m-flash-<?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>" role="<?= $flash['type'] === 'ok' ? 'status' : 'alert' ?>">
          <?= hechi_e($flash['text']) ?>
        </p>
      <?php endif; ?>

      <form method="post" action="/member/login" class="m-form">
        <?= $csrf ?>
        <label for="login_name">登录名 <span class="m-label-note">本人姓名</span></label>
        <input type="text" id="login_name" name="login_name" value="<?= hechi_e($login) ?>"
               autocomplete="username"<?= $hasLoginName ? '' : ' autofocus' ?> required>

        <label for="password">密码</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password"<?= $hasLoginName ? ' autofocus' : '' ?> required>

        <button type="submit" class="m-btn m-btn-primary">登录</button>
      </form>

      <p class="m-login-rule">密码连续输错 5 次将锁定 15 分钟，请稍后再试。</p>
      <p class="m-login-help">忘记密码或提示账号不存在，请联系提案委员会办公室重置<?= $contactPhone === '' ? '' : '：<a href="tel:' . hechi_e($contactPhone) . '">' . hechi_e($contactPhone) . '</a>' ?>。</p>
    </section>

    <section class="m-guide" aria-labelledby="guideTitle">
      <h2 id="guideTitle">登录指南</h2>
      <ol>
        <li>账号由提案委员会统一开通，登录名用委员本人姓名；重名的，用提案委告知的带序号登录名。</li>
        <li>首次登录须先修改密码，改完才能填写与提交提案。</li>
        <li>提案内容不对外公开，只有本人与提案委员会可以查看。</li>
        <li>提案提交后，可在「我的提案」里查看办理状态与提案委意见。</li>
      </ol>
    </section>

    <p class="m-portal-sign">版权所有：中国人民政治协商会议河池市委员会 · <?= hechi_e($siteName) ?></p>
  </main>
</body>
</html>
