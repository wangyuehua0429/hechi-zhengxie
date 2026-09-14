<?php

/**
 * 委员门户外壳。模板变量见 HechiZx\Member\MemberView::page()。
 *
 * @var string $title
 * @var string $siteName
 * @var array<string, mixed>|null $member
 * @var string $content
 * @var array{type:string,text:string}|null $flash
 * @var string $csrf
 * @var string $current
 */

declare(strict_types=1);

$current = $current ?? '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light">
  <title><?= hechi_e($title) ?> · <?= hechi_e($siteName) ?>提案系统</title>
  <link rel="stylesheet" href="/assets/member.css">
</head>
<body>
  <a class="skip-link" href="#main">跳到主要内容</a>
  <header class="m-head">
    <div class="m-head-inner">
      <a class="m-brand" href="/member">
        <span class="m-brand-mark" aria-hidden="true">提案</span>
        <span class="m-brand-text">
          <strong>河池市政协提案系统</strong>
          <small><?= hechi_e($siteName) ?></small>
        </span>
      </a>
      <?php if ($member): ?>
        <nav class="m-nav" aria-label="委员工作台">
          <a href="/member/proposals"<?= $current === 'list' ? ' class="active" aria-current="page"' : '' ?>>我的提案</a>
          <a href="/member/proposal/new"<?= $current === 'new' ? ' class="active" aria-current="page"' : '' ?>>填写提案</a>
          <a href="/member/password">修改密码</a>
          <form method="post" action="/member/logout" class="m-inline"><?= $csrf ?>
            <button type="submit" class="m-link-btn">退出</button>
          </form>
        </nav>
        <span class="m-who"><?= hechi_e((string) ($member['name'] ?? '')) ?></span>
      <?php endif; ?>
    </div>
  </header>

  <main id="main" class="m-main">
    <?php if ($flash): ?>
      <div class="m-flash m-flash-<?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>" role="<?= $flash['type'] === 'ok' ? 'status' : 'alert' ?>">
        <?= hechi_e($flash['text']) ?>
      </div>
    <?php endif; ?>
    <?= $content ?>
  </main>

  <footer class="m-foot">
    <p>政协委员在线提交提案系统 · <?= hechi_e($siteName) ?></p>
    <p class="m-foot-note">账号由提案委员会统一开通。如遇登录问题或需要重置密码，请联系提案委员会办公室。</p>
  </footer>
</body>
</html>
