<?php

/**
 * 账号编辑 / 新建：基本资料 + 角色勾选 + 初始密码（或重置密码）。
 *
 * @var array<string, mixed>|null $user
 * @var list<int> $userRoles
 * @var list<array<string, mixed>> $roles
 * @var string $csrf
 */

declare(strict_types=1);

$isNew = $user === null;
$action = $isNew ? '/admin/user/create' : '/admin/user/' . (int) $user['user_id'];
?>
<h1><?= $isNew ? '新建账号' : '编辑账号' ?></h1>

<?php $systemCurrent = 'users'; include __DIR__ . '/_system_nav.php'; ?>

<form method="post" action="<?= hechi_e($action) ?>" class="edit-form">
  <?= $csrf ?>

  <div class="row">
    <label class="field--md">登录账号
      <input type="text" name="username" value="<?= hechi_e((string) ($user['username'] ?? '')) ?>"<?= $isNew ? ' required' : ' readonly' ?>>
    </label>
    <label class="field--md">姓名
      <input type="text" name="real_name" value="<?= hechi_e((string) ($user['real_name'] ?? '')) ?>">
    </label>
    <label class="field--md">部门
      <input type="text" name="dept" value="<?= hechi_e((string) ($user['dept'] ?? '')) ?>" placeholder="如：办公室">
    </label>
  </div>

  <div class="row">
    <label class="field--md">手机
      <input type="text" name="mobile" value="<?= hechi_e((string) ($user['mobile'] ?? '')) ?>">
    </label>
    <label class="field--md">邮箱
      <input type="text" name="email" value="<?= hechi_e((string) ($user['email'] ?? '')) ?>">
    </label>
    <label class="field--sm">状态
      <select name="status">
        <option value="enabled"<?= (string) ($user['status'] ?? 'enabled') === 'enabled' ? ' selected' : '' ?>>启用</option>
        <option value="disabled"<?= (string) ($user['status'] ?? '') === 'disabled' ? ' selected' : '' ?>>停用</option>
      </select>
    </label>
  </div>

  <div class="row">
    <label class="field--md"><?= $isNew ? '初始密码（至少 8 位）' : '重置密码（留空表示不改）' ?>
      <input type="password" name="password" autocomplete="new-password"<?= $isNew ? ' required' : '' ?>>
    </label>
    <label class="field--full">备注
      <input type="text" name="remark" value="<?= hechi_e((string) ($user['remark'] ?? '')) ?>" placeholder="如：负责市政协动态栏目">
    </label>
  </div>

  <fieldset class="check-set">
    <legend>角色（可多选；没有任何角色的账号进后台什么都做不了）</legend>
    <?php foreach ($roles as $role): ?>
      <label class="check-line">
        <input type="checkbox" name="roles[]" value="<?= (int) $role['role_id'] ?>"
          <?= in_array((int) $role['role_id'], $userRoles, true) ? ' checked' : '' ?>>
        <strong><?= hechi_e((string) $role['name']) ?></strong>
        <span class="muted"><?= hechi_e((string) $role['code']) ?>　<?= hechi_e((string) $role['description']) ?></span>
      </label>
    <?php endforeach; ?>
  </fieldset>

  <div class="actions">
    <button type="submit" class="btn-primary">保存</button>
    <a class="btn" href="/admin/users">返回列表</a>
    <?php if (!$isNew): ?>
      <a class="btn" href="/admin/roles">去配置角色权限</a>
    <?php endif; ?>
  </div>
</form>
