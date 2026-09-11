<?php

/**
 * 角色编辑：名称说明 + 权限码勾选 + 栏目数据范围勾选。
 *
 * @var array<string, mixed> $role
 * @var list<string> $perms
 * @var list<string> $channels
 * @var array<string, array<string, string>> $permGroups
 * @var list<array<string, mixed>> $allChannels
 * @var string $csrf
 */

declare(strict_types=1);

$isAdminRole = (string) $role['code'] === 'admin';
?>
<h1>编辑角色 · <?= hechi_e((string) $role['name']) ?></h1>

<?php $systemCurrent = 'roles'; include __DIR__ . '/_system_nav.php'; ?>

<form method="post" action="/admin/role/<?= (int) $role['role_id'] ?>" class="edit-form">
  <?= $csrf ?>

  <div class="row">
    <label>角色代码
      <input type="text" value="<?= hechi_e((string) $role['code']) ?>" readonly>
    </label>
    <label>角色名称
      <input type="text" name="name" value="<?= hechi_e((string) $role['name']) ?>" required>
    </label>
  </div>

  <div class="row">
    <label class="full">说明
      <input type="text" name="description" value="<?= hechi_e((string) $role['description']) ?>">
    </label>
    <label class="full">备注（责任人、使用场景等）
      <input type="text" name="remark" value="<?= hechi_e((string) $role['remark']) ?>">
    </label>
  </div>

  <fieldset class="check-set">
    <legend>
      权限<?= $isAdminRole ? '（管理员角色固定拥有全部权限，不可取消）' : '' ?>
    </legend>
    <?php foreach ($permGroups as $groupName => $items): ?>
      <div class="check-group">
        <div class="check-group-title"><?= hechi_e((string) $groupName) ?></div>
        <?php foreach ($items as $code => $label): ?>
          <label class="check-line">
            <input type="checkbox" name="perms[]" value="<?= hechi_e((string) $code) ?>"
              <?= ($isAdminRole || in_array((string) $code, $perms, true)) ? ' checked' : '' ?>
              <?= $isAdminRole ? ' disabled' : '' ?>>
            <strong><?= hechi_e((string) $label) ?></strong>
            <span class="muted"><?= hechi_e((string) $code) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </fieldset>

  <fieldset class="check-set">
    <legend>栏目范围（全不勾＝不限栏目）</legend>
    <p class="muted">勾选后，这个角色只能看到并操作这些栏目下的稿件。</p>
    <div class="channel-picker">
      <?php foreach ($allChannels as $channel): ?>
        <label class="check-line check-inline">
          <input type="checkbox" name="channels[]" value="<?= hechi_e((string) $channel['type_code']) ?>"
            <?= in_array((string) $channel['type_code'], $channels, true) ? ' checked' : '' ?>>
          <?= hechi_e((string) $channel['inner_name']) ?>
          <span class="muted"><?= hechi_e((string) $channel['type_code']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <div class="actions">
    <button type="submit" class="btn-primary">保存</button>
    <a class="btn" href="/admin/roles">返回角色列表</a>
    <a class="btn" href="/admin/users">去管理账号</a>
  </div>
</form>
