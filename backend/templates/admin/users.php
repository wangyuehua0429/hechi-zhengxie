<?php

/**
 * 用户列表：账号、姓名、部门、角色、状态、最后登录。
 *
 * @var list<array<string, mixed>> $users
 * @var list<array<string, mixed>> $roles
 */

declare(strict_types=1);
?>
<div class="page-head">
  <h1>用户管理</h1>
  <a class="btn-primary" href="/admin/user/new">新建账号</a>
</div>

<?php $systemCurrent = 'users'; include __DIR__ . '/_system_nav.php'; ?>

<p class="muted">
  账号的「角色」决定能做什么，「栏目范围」在角色里配置：角色不绑栏目表示不限栏目，绑了就只能管这几个栏目。
  停用账号后本人无法登录，已发的稿件不受影响。
</p>

<div class="table-scroll">
<table class="grid">
  <thead><tr><th>ID</th><th>账号</th><th>姓名</th><th>部门</th><th>角色</th><th>状态</th><th>最后登录</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($users as $user): ?>
      <tr>
        <td class="nowrap"><?= (int) $user['user_id'] ?></td>
        <td class="nowrap"><?= hechi_e($user['username']) ?></td>
        <td class="nowrap"><?= hechi_e($user['real_name'] !== '' ? $user['real_name'] : '—') ?></td>
        <td class="nowrap"><?= hechi_e($user['dept'] !== '' ? $user['dept'] : '—') ?></td>
        <td><?= hechi_e((string) $user['role_names'] !== '' ? (string) $user['role_names'] : '（未分配）') ?></td>
        <td class="nowrap">
          <span class="tag tag-<?= $user['status'] === 'enabled' ? 'published' : 'offline' ?>"><?= $user['status'] === 'enabled' ? '启用' : '停用' ?></span>
        </td>
        <td class="nowrap"><?= hechi_e((string) ($user['last_login_at'] ?? '—')) ?></td>
        <td class="nowrap"><a href="/admin/user/<?= (int) $user['user_id'] ?>">编辑</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($users === []): ?>
      <tr><td colspan="8" class="empty">还没有账号。</td></tr>
    <?php endif; ?>
  </tbody>
</table>
</div>
