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
 * @var array<string, mixed>|null $manual 手工建号结果（含未建成的条目）
 * @var list<array<string, mixed>> $credentials 待下载的初始密码清单
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

<?php if ($manual !== null): ?>
  <div class="card">
    <h2>手工新建结果</h2>
    <p>
      成功 <strong><?= (int) ($manual['created_count'] ?? 0) ?></strong> 条、
      失败 <strong><?= count((array) ($manual['failed'] ?? [])) ?></strong> 条。
    </p>
    <?php if ($credentials !== []): ?>
      <p><a class="btn-primary" href="/admin/members/credentials.csv">下载初始密码清单</a></p>
      <p class="muted">
        初始密码只在这里显示一次：先下载清单发给委员，下载后本卡片消失（需要重发用「重置密码」）。
      </p>
      <div class="table-scroll">
        <table class="grid">
          <caption class="visually-hidden">手工新建的账号与初始密码</caption>
          <thead><tr><th>姓名</th><th>登录名</th><th>初始密码</th></tr></thead>
          <tbody>
            <?php foreach ($credentials as $row): ?>
              <tr>
                <td class="nowrap"><?= hechi_e((string) $row['name']) ?></td>
                <td class="nowrap"><?= hechi_e((string) $row['login_name']) ?></td>
                <td class="nowrap"><code><?= hechi_e((string) $row['password']) ?></code></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ((int) ($manual['created_count'] ?? 0) > 0): ?>
      <p class="muted">初始密码清单已下载并清除。如委员没有收到，请用下面的「重置密码」重新生成。</p>
    <?php endif; ?>
    <?php if ((array) ($manual['failed'] ?? []) !== []): ?>
      <h2>没建成的条目</h2>
      <div class="table-scroll">
        <table class="grid">
          <caption class="visually-hidden">没建成的手工建号条目与原因</caption>
          <thead><tr><th>第几条</th><th>姓名</th><th>原因</th></tr></thead>
          <tbody>
            <?php foreach ((array) $manual['failed'] as $row): ?>
              <tr>
                <td class="nowrap">第 <?= (int) $row['row'] ?> 条</td>
                <td class="nowrap"><?= hechi_e((string) $row['name'] !== '' ? (string) $row['name'] : '（未填姓名）') ?></td>
                <td><?= hechi_e((string) $row['reason']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="muted">改好后再提交一次即可；已建成的账号不会重复开通。</p>
    <?php endif; ?>
  </div>
<?php endif; ?>

<p class="muted">
  委员账号与后台账号是两套：委员只能登录提案门户，看不到也不影响内容管理后台。
  共 <?= (int) $total ?> 人，启用 <?= (int) ($counts['enabled'] ?? 0) ?>、停用 <?= (int) ($counts['disabled'] ?? 0) ?>。
</p>

<div class="card">
  <h2>手工新建账号</h2>
  <p class="muted">
    零星增补用这里：一次可填 1 条或多条，姓名与职务必填，界别与联系电话可留空。
    登录名取本人姓名（重名自动补序号），系统为每人生成随机初始密码，只在下发时显示一次。
    整行空白的行会被忽略；人数多时用右上角「导入委员名册」更省事。
  </p>
  <form method="post" action="/admin/members/create">
    <?= $csrf ?>
    <div class="table-scroll">
      <table class="grid">
        <caption class="visually-hidden">手工新建的账号条目</caption>
        <thead>
          <tr><th>姓名（必填）</th><th>界别</th><th>职务（必填）</th><th>联系电话</th><th>操作</th></tr>
        </thead>
        <tbody data-manual-rows>
          <?php for ($i = 0; $i < 3; $i++): ?>
            <tr data-manual-row>
              <td><input type="text" name="name[]" maxlength="64" aria-label="姓名"></td>
              <td><input type="text" name="sector[]" maxlength="64" aria-label="界别"></td>
              <td><input type="text" name="org[]" maxlength="128" aria-label="职务"></td>
              <td><input type="text" name="mobile[]" maxlength="32" inputmode="tel" aria-label="联系电话"></td>
              <td class="col-actions">
                <button type="button" class="btn btn-sm" data-manual-remove>删除本行</button>
              </td>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
    <template data-manual-template>
      <tr data-manual-row>
        <td><input type="text" name="name[]" maxlength="64" aria-label="姓名"></td>
        <td><input type="text" name="sector[]" maxlength="64" aria-label="界别"></td>
        <td><input type="text" name="org[]" maxlength="128" aria-label="职务"></td>
        <td><input type="text" name="mobile[]" maxlength="32" inputmode="tel" aria-label="联系电话"></td>
        <td class="col-actions">
          <button type="button" class="btn btn-sm" data-manual-remove>删除本行</button>
        </td>
      </tr>
    </template>
    <div class="form-actions">
      <button type="button" class="btn btn-sm" data-manual-add>添加一行</button>
      <button type="submit" class="btn-primary">创建账号并生成初始密码</button>
      <span class="muted">一次最多 50 条；密码清单每人一份，只在下发时显示一次。</span>
    </div>
  </form>
</div>

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
