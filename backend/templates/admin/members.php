<?php

/**
 * 委员管理：名册列表、启停、重置密码、名册导入入口。
 *
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var array<string, string> $filters
 * @var array<string, int> $counts
 * @var array<string, string>|null $reset
 * @var string $csrf
 */

declare(strict_types=1);
?>
<div class="page-head">
  <h1>委员管理</h1>
  <a class="btn-primary" href="/admin/members/import">导入委员名册</a>
</div>

<?php if ($reset !== null): ?>
  <p class="notice notice-warn" role="status">
    <strong>新密码（只显示这一次）：</strong>
    <?= hechi_e($reset['name']) ?> · 登录名 <?= hechi_e($reset['login_name']) ?> · 新密码
    <code><?= hechi_e($reset['password']) ?></code>
    —— 请线下告知本人，委员首次登录后须自行修改。
  </p>
<?php endif; ?>

<p class="muted">
  委员账号与后台账号是两套：委员只能登录提案门户，看不到也不影响内容管理后台。
  共 <?= (int) $total ?> 人，启用 <?= (int) ($counts['enabled'] ?? 0) ?>、停用 <?= (int) ($counts['disabled'] ?? 0) ?>。
</p>

<form class="filters" method="get" action="/admin/members">
  <label>关键词
    <input type="text" name="keyword" value="<?= hechi_e($filters['keyword']) ?>" placeholder="姓名、登录名或手机号">
  </label>
  <label>状态
    <select name="status">
      <option value="">全部</option>
      <option value="enabled"<?= $filters['status'] === 'enabled' ? ' selected' : '' ?>>启用</option>
      <option value="disabled"<?= $filters['status'] === 'disabled' ? ' selected' : '' ?>>停用</option>
    </select>
  </label>
  <button type="submit" class="btn-primary">筛选</button>
</form>

<div class="table-scroll">
  <table class="grid">
    <caption class="visually-hidden">委员账号列表</caption>
    <thead>
      <tr>
        <th>姓名</th>
        <th>登录名</th>
        <th>界别</th>
        <th>专委会</th>
        <th>手机号</th>
        <th>提案数</th>
        <th>状态</th>
        <th>最后登录</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td class="nowrap"><?= hechi_e((string) $row['name']) ?></td>
          <td class="nowrap"><?= hechi_e((string) $row['login_name']) ?></td>
          <td class="nowrap"><?= hechi_e((string) $row['sector']) ?></td>
          <td class="nowrap"><?= hechi_e((string) $row['committee']) ?></td>
          <td class="nowrap"><?= hechi_e((string) $row['mobile']) ?></td>
          <td class="nowrap"><?= (int) $row['proposal_count'] ?></td>
          <td class="nowrap">
            <span class="tag tag-<?= (string) $row['status'] === 'enabled' ? 'published' : 'offline' ?>">
              <?= (string) $row['status'] === 'enabled' ? '启用' : '停用' ?>
            </span>
          </td>
          <td class="nowrap"><?= hechi_e((string) ($row['last_login_at'] ?? '—')) ?></td>
          <td class="nowrap actions-cell">
            <form method="post" action="/admin/member/<?= (int) $row['member_id'] ?>/password" class="inline">
              <?= $csrf ?>
              <button type="submit" class="btn btn-sm">重置密码</button>
            </form>
            <form method="post" action="/admin/member/<?= (int) $row['member_id'] ?>/status" class="inline">
              <?= $csrf ?>
              <button type="submit" class="btn btn-sm"><?= (string) $row['status'] === 'enabled' ? '停用' : '启用' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($rows === []): ?>
        <tr><td colspan="9" class="empty">还没有委员账号。点右上角「导入委员名册」上传名单即可开通。</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
  <nav class="pager" aria-label="分页">
    <?php if ($page > 1): ?>
      <a class="btn btn-sm" href="/admin/members?page=<?= (int) ($page - 1) ?><?= $filters['keyword'] === '' ? '' : '&keyword=' . urlencode($filters['keyword']) ?>">上一页</a>
    <?php endif; ?>
    <span>第 <?= (int) $page ?> / <?= (int) $pages ?> 页</span>
    <?php if ($page < $pages): ?>
      <a class="btn btn-sm" href="/admin/members?page=<?= (int) ($page + 1) ?><?= $filters['keyword'] === '' ? '' : '&keyword=' . urlencode($filters['keyword']) ?>">下一页</a>
    <?php endif; ?>
  </nav>
<?php endif; ?>
