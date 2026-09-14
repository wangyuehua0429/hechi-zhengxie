<?php

/**
 * 提案详情：正文、附件、委员档案、流转记录，以及受理／退回两个动作。
 *
 * @var array<string, mixed> $proposal
 * @var list<array<string, mixed>> $attachments
 * @var list<array<string, mixed>> $logs
 * @var array<string, mixed>|null $member
 * @var list<string> $actions
 * @var bool $canExport
 * @var string $csrf
 */

declare(strict_types=1);

use HechiZx\Content\ProposalWorkflow;

$proposalId = (int) $proposal['proposal_id'];
$status = ProposalWorkflow::normalize((string) $proposal['status']);
?>
<div class="page-head">
  <h1><?= hechi_e((string) $proposal['title']) ?></h1>
  <div>
    <?php if ($canExport): ?>
      <a class="btn btn-sm" href="/admin/proposal/<?= $proposalId ?>/word">导出 Word 提案表</a>
    <?php endif; ?>
    <a class="btn btn-sm" href="/admin/proposals">返回列表</a>
  </div>
</div>

<p class="muted">
  提案号 <?= $proposalId ?> ·
  <span class="tag tag-<?= hechi_e($status) ?>"><?= hechi_e(ProposalWorkflow::label($status)) ?></span>
  · 提交时间 <?= hechi_e((string) ($proposal['submitted_at'] ?? '')) ?>
  <?php if (trim((string) ($proposal['reviewed_at'] ?? '')) !== ''): ?>
    · 办理时间 <?= hechi_e((string) $proposal['reviewed_at']) ?>
  <?php endif; ?>
</p>

<?php if ($actions !== []): ?>
  <div class="card">
    <h2>办理</h2>
    <div class="actions-row">
      <?php if (in_array('accept', $actions, true)): ?>
        <form method="post" action="/admin/proposal/<?= $proposalId ?>/accept" class="inline-form">
          <?= $csrf ?>
          <label class="inline-label">受理意见（可选）
            <input type="text" name="review_note" maxlength="200" placeholder="如：予以立案，转有关部门研究办理">
          </label>
          <button type="submit" class="btn-primary">受理</button>
        </form>
      <?php endif; ?>
      <?php if (in_array('return', $actions, true)): ?>
        <form method="post" action="/admin/proposal/<?= $proposalId ?>/return" class="inline-form">
          <?= $csrf ?>
          <label class="inline-label">退回意见（必填）
            <input type="text" name="returned_reason" maxlength="500" required placeholder="说明需要补充或修改的内容">
          </label>
          <button type="submit" class="btn btn-danger-outline">退回补充</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if (trim((string) $proposal['returned_reason']) !== ''): ?>
  <p class="notice notice-warn"><strong>退回意见：</strong><?= hechi_e((string) $proposal['returned_reason']) ?></p>
<?php endif; ?>
<?php if (trim((string) $proposal['review_note']) !== ''): ?>
  <p class="notice"><strong>受理意见：</strong><?= hechi_e((string) $proposal['review_note']) ?></p>
<?php endif; ?>

<div class="card">
  <h2>提案信息</h2>
  <table class="grid">
    <tbody>
      <tr><th scope="row">提案人类别</th><td><?= hechi_e(ProposalWorkflow::proposerTypeLabel((string) $proposal['proposer_type'])) ?></td>
          <th scope="row">提案人</th><td><?= hechi_e((string) $proposal['proposer_name']) ?></td></tr>
      <tr><th scope="row">界别</th><td><?= hechi_e((string) $proposal['sector']) ?></td>
          <th scope="row">专委会</th><td><?= hechi_e((string) $proposal['committee']) ?></td></tr>
      <tr><th scope="row">联系电话</th><td><?= hechi_e((string) $proposal['contact_mobile']) ?></td>
          <th scope="row">提案类别</th><td><?= hechi_e((string) $proposal['category']) ?></td></tr>
      <?php if (trim((string) $proposal['co_members']) !== ''): ?>
        <tr><th scope="row">联名委员</th><td colspan="3"><?= hechi_e((string) $proposal['co_members']) ?></td></tr>
      <?php endif; ?>
      <?php if (trim((string) $proposal['collective_name']) !== ''): ?>
        <tr><th scope="row">集体名称</th><td colspan="3"><?= hechi_e((string) $proposal['collective_name']) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>一、情况与问题</h2>
  <div class="body-text"><?= nl2br(hechi_e((string) $proposal['problem_text'])) ?></div>
  <h2>二、分析</h2>
  <div class="body-text"><?= nl2br(hechi_e((string) $proposal['analysis_text'])) ?></div>
  <h2>三、建议</h2>
  <div class="body-text"><?= nl2br(hechi_e((string) $proposal['suggestion_text'])) ?></div>
</div>

<div class="card">
  <h2>附件</h2>
  <?php if ($attachments === []): ?>
    <p class="muted">没有附件。</p>
  <?php else: ?>
    <ul>
      <?php foreach ($attachments as $attachment): ?>
        <li>
          <a href="/admin/proposal/<?= $proposalId ?>/attachment/<?= (int) $attachment['attachment_id'] ?>"><?= hechi_e((string) $attachment['name']) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<?php if ($member !== null): ?>
  <div class="card">
    <h2>委员账号</h2>
    <p class="muted">
      <?= hechi_e((string) $member['name']) ?>（登录名 <?= hechi_e((string) $member['login_name']) ?>）·
      <?= hechi_e((string) $member['sector']) ?> · <?= hechi_e((string) $member['committee']) ?> ·
      手机 <?= hechi_e((string) $member['mobile']) ?> ·
      <?= (string) $member['status'] === 'enabled' ? '已启用' : '已停用' ?>
    </p>
  </div>
<?php endif; ?>

<div class="card">
  <h2>办理记录</h2>
  <ul>
    <?php foreach ($logs as $log): ?>
      <li>
        <span class="muted"><?= hechi_e((string) $log['created_at']) ?></span>
        <?= (string) $log['actor_type'] === 'staff' ? '提案委' : '委员' ?>
        · <?= hechi_e(ProposalWorkflow::actionLabel((string) $log['action'])) ?>
        <?php if (trim((string) $log['note']) !== ''): ?>
          —— <?= hechi_e((string) $log['note']) ?>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
