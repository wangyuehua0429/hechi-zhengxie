<?php

/**
 * 角色列表：每个角色能做什么、能管哪些栏目、挂了几个账号。
 *
 * @var list<array<string, mixed>> $roles
 */

declare(strict_types=1);
?>
<h1>角色与权限</h1>

<?php $systemCurrent = 'roles'; include __DIR__ . '/_system_nav.php'; ?>

<p class="muted">
  权限码固定在代码里，这里只做勾选；「栏目范围」为 0 表示不限栏目。
  管理员角色恒有全部权限，不受勾选影响。
</p>

<div class="table-scroll">
<table class="grid">
  <thead><tr><th>ID</th><th>代码</th><th>名称</th><th>说明</th><th>权限</th><th>栏目范围</th><th>账号</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($roles as $role): ?>
      <tr>
        <td class="nowrap"><?= (int) $role['role_id'] ?></td>
        <td class="nowrap"><?= hechi_e((string) $role['code']) ?></td>
        <td class="nowrap"><?= hechi_e((string) $role['name']) ?></td>
        <td><?= hechi_e((string) $role['description']) ?></td>
        <td class="nowrap"><?= (int) $role['perm_count'] ?> 项<?= (int) $role['is_system'] === 1 ? '（内置）' : '' ?></td>
        <td class="nowrap"><?= (int) $role['channel_count'] === 0 ? '不限' : (int) $role['channel_count'] . ' 个栏目' ?></td>
        <td class="nowrap"><?= (int) $role['user_count'] ?></td>
        <td class="nowrap"><a href="/admin/role/<?= (int) $role['role_id'] ?>">编辑</a></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
