<?php

/**
 * 修改密码：首次登录（required=true）必须先改密才能进业务页。
 *
 * @var array<string, mixed> $member
 * @var bool $required
 * @var string $csrf
 */

declare(strict_types=1);
?>
<section class="m-card m-narrow">
  <h1>修改密码</h1>
  <?php if ($required): ?>
    <p class="m-notice">这是首次登录，请先修改初始密码后再使用系统。</p>
  <?php endif; ?>
  <p class="m-note">当前账号：<?= hechi_e((string) ($member['name'] ?? '')) ?>
    （登录名 <?= hechi_e((string) ($member['login_name'] ?? '')) ?>）</p>

  <form method="post" action="/member/password" class="m-form">
    <?= $csrf ?>
    <label for="current_password">原密码</label>
    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>

    <label for="new_password">新密码</label>
    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>

    <label for="confirm_password">确认新密码</label>
    <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
    <p class="m-hint">至少 8 位，建议字母与数字混用，不要与其他网站相同。</p>

    <button type="submit" class="m-btn m-btn-primary">保存新密码</button>
  </form>
</section>
