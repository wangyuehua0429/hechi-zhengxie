<?php

/**
 * 系统管理子导航：用户管理 / 角色与权限。
 *
 * @var string $systemCurrent users|roles|logs
 */

declare(strict_types=1);

$systemCurrent = $systemCurrent ?? 'users';
?>
<nav class="system-nav" aria-label="系统管理">
  <a class="system-chip<?= $systemCurrent === 'users' ? ' active' : '' ?>"
     href="/admin/users"<?= $systemCurrent === 'users' ? ' aria-current="page"' : '' ?>>用户管理</a>
  <a class="system-chip<?= $systemCurrent === 'roles' ? ' active' : '' ?>"
     href="/admin/roles"<?= $systemCurrent === 'roles' ? ' aria-current="page"' : '' ?>>角色与权限</a>
  <a class="system-chip<?= $systemCurrent === 'logs' ? ' active' : '' ?>"
     href="/admin/logs"<?= $systemCurrent === 'logs' ? ' aria-current="page"' : '' ?>>操作日志</a>
</nav>
