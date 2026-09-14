<?php

/**
 * 我的提案：状态筛选 + 列表 + 分页。
 *
 * @var list<array<string, mixed>> $rows
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var string $status
 */

declare(strict_types=1);
?>
<div class="m-page-head">
  <h1>我的提案</h1>
  <a class="m-btn m-btn-primary" href="/member/proposal/new">填写提案</a>
</div>

<p class="m-note">共 <?= (int) $total ?> 件提案。</p>

<nav class="m-chips" aria-label="按状态筛选">
  <?php
  $chips = ['' => '全部'] + \HechiZx\Content\ProposalWorkflow::places();
  foreach ($chips as $key => $label):
      $url = '/member/proposals' . ($key === '' ? '' : '?status=' . urlencode((string) $key));
      ?>
    <a href="<?= hechi_e($url) ?>"<?= (string) $key === $status ? ' class="active" aria-current="true"' : '' ?>><?= hechi_e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($rows === []): ?>
  <p class="m-empty">还没有提案。点右上角「填写提案」开始填写。</p>
<?php else: ?>
  <table class="m-table">
    <caption class="m-visually-hidden">我的提案列表</caption>
    <thead>
      <tr>
        <th scope="col">案由</th>
        <th scope="col">类别</th>
        <th scope="col">状态</th>
        <th scope="col">提交时间</th>
        <th scope="col">操作</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $row): ?>
        <?php $statusKey = \HechiZx\Content\ProposalWorkflow::normalize((string) $row['status']); ?>
        <tr>
          <td>
            <a href="/member/proposal/<?= (int) $row['proposal_id'] ?>"><?= hechi_e((string) $row['title']) ?></a>
            <?php if ($statusKey === 'returned' && trim((string) $row['returned_reason']) !== ''): ?>
              <span class="m-sub">退回意见：<?= hechi_e((string) $row['returned_reason']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= hechi_e((string) $row['category']) ?></td>
          <td><span class="m-badge m-badge-<?= hechi_e($statusKey) ?>"><?= hechi_e(\HechiZx\Content\ProposalWorkflow::label($statusKey)) ?></span></td>
          <td><?= hechi_e((string) ($row['submitted_at'] ?? '')) ?></td>
          <td><a href="/member/proposal/<?= (int) $row['proposal_id'] ?>">查看</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($pages > 1): ?>
    <nav class="m-pager" aria-label="分页">
      <?php if ($page > 1): ?>
        <a href="/member/proposals?page=<?= (int) ($page - 1) ?><?= $status === '' ? '' : '&status=' . urlencode($status) ?>">上一页</a>
      <?php endif; ?>
      <span>第 <?= (int) $page ?> / <?= (int) $pages ?> 页</span>
      <?php if ($page < $pages): ?>
        <a href="/member/proposals?page=<?= (int) ($page + 1) ?><?= $status === '' ? '' : '&status=' . urlencode($status) ?>">下一页</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>
