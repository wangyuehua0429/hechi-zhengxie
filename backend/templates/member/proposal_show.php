<?php

/**
 * 提案详情：状态、正文、附件、流转记录与后续动作。
 *
 * @var array<string, mixed> $proposal
 * @var list<array<string, mixed>> $coMembers
 * @var list<array<string, mixed>> $units
 * @var list<array<string,string>> $edges 提案委的改稿记录（摘要 + 时间）
 * @var list<array<string, mixed>> $attachments
 * @var list<array<string, mixed>> $logs
 * @var bool $canEdit
 */

declare(strict_types=1);

$statusKey = \HechiZx\Content\ProposalWorkflow::normalize((string) $proposal['status']);
$proposalId = (int) $proposal['proposal_id'];
?>
<div class="m-page-head">
  <h1><?= hechi_e((string) $proposal['title']) ?></h1>
  <a class="m-btn" href="/member/proposals">返回列表</a>
</div>

<p class="m-status-line">
  当前状态：<span class="m-badge m-badge-<?= hechi_e($statusKey) ?>"><?= hechi_e(\HechiZx\Content\ProposalWorkflow::label($statusKey)) ?></span>
  <span class="m-note">提交时间 <?= hechi_e((string) ($proposal['submitted_at'] ?? '')) ?></span>
</p>

<?php if ($statusKey === 'returned' && trim((string) $proposal['returned_reason']) !== ''): ?>
  <div class="m-notice m-notice-warn">
    <strong>提案委退回意见：</strong><?= hechi_e((string) $proposal['returned_reason']) ?>
  </div>
<?php endif; ?>

<?php if (trim((string) ($proposal['review_note'] ?? '')) !== ''): ?>
  <div class="m-notice">
    <strong>提案委受理意见：</strong><?= hechi_e((string) $proposal['review_note']) ?>
  </div>
<?php endif; ?>

<section class="m-card">
  <h2>提案信息</h2>
  <dl class="m-meta">
    <dt>提案号</dt><dd><?= $proposalId ?></dd>
    <dt>提案人</dt><dd><?= hechi_e((string) $proposal['proposer_name']) ?>
      （<?= hechi_e(\HechiZx\Content\ProposalWorkflow::proposerTypeLabel((string) $proposal['proposer_type'])) ?>）</dd>
    <dt>界别</dt><dd><?= hechi_e((string) $proposal['sector']) ?></dd>
    <dt>专委会</dt><dd><?= hechi_e((string) $proposal['committee']) ?></dd>
    <dt>提案类别</dt><dd><?= hechi_e((string) $proposal['category']) ?></dd>
    <dt>建议承办单位</dt><dd>
      <?= $units === [] ? '—' : hechi_e(implode('、', array_map(static fn (array $row): string => (string) $row['unit_name'], $units))) ?>
    </dd>
    <?php if (trim((string) $proposal['collective_name']) !== ''): ?>
      <dt>集体名称</dt><dd><?= hechi_e((string) $proposal['collective_name']) ?></dd>
    <?php endif; ?>
    <?php if ($coMembers !== []): ?>
      <dt>联名委员</dt>
      <dd>
        <?php foreach ($coMembers as $row): ?>
          <span class="m-sub">
            <?= hechi_e((string) $row['name']) ?>
            <?= trim((string) $row['org_title']) === '' ? '' : '（' . hechi_e((string) $row['org_title']) . '）' ?>
            <?= trim((string) $row['mobile']) === '' ? '' : ' ' . hechi_e((string) $row['mobile']) ?>
          </span>
        <?php endforeach; ?>
      </dd>
    <?php endif; ?>
    <dt>办理联系人</dt>
    <dd>
      <?= hechi_e((string) $proposal['contact_name']) ?>
      <?= trim((string) $proposal['contact_title']) === '' ? '' : '（' . hechi_e((string) $proposal['contact_title']) . '）' ?>
      · <?= hechi_e((string) $proposal['contact_mobile']) ?>
      <span class="m-sub"><?= hechi_e((string) $proposal['contact_org']) ?>
        <?= trim((string) $proposal['contact_address']) === '' ? '' : '　' . hechi_e((string) $proposal['contact_address']) ?>
        <?= trim((string) $proposal['contact_postcode']) === '' ? '' : '（邮编 ' . hechi_e((string) $proposal['contact_postcode']) . '）' ?>
      </span>
    </dd>
    <?php if (trim((string) ($proposal['reviewed_at'] ?? '')) !== ''): ?>
      <dt><?= $statusKey === 'returned' ? '退回时间' : '受理时间' ?></dt><dd><?= hechi_e((string) $proposal['reviewed_at']) ?></dd>
    <?php endif; ?>
  </dl>
</section>

<?php if ($edges !== []): ?>
  <div class="m-notice m-notice-warn">
    <strong>提案委已对内容作了调整。</strong>
    以下内容为调整后的版本：
    <ul>
      <?php foreach ($edges as $edge): ?>
        <li><?= hechi_e($edge['created_at']) ?> 调整了：<?= hechi_e($edge['summary']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<section class="m-card">
  <h2>提案内容</h2>
  <div class="m-body"><?= (string) ($proposal['body_html'] ?? '') ?></div>
</section>

<?php if ($attachments !== []): ?>
  <section class="m-card">
    <h2>附件</h2>
    <ul class="m-files">
      <?php foreach ($attachments as $attachment): ?>
        <li>
          <?= hechi_e((string) $attachment['name']) ?>
          <a href="/member/proposal/<?= $proposalId ?>/attachment/<?= (int) $attachment['attachment_id'] ?>">下载</a>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>

<section class="m-card">
  <h2>办理记录</h2>
  <ol class="m-timeline">
    <?php foreach ($logs as $log): ?>
      <li>
        <span class="m-time"><?= hechi_e((string) $log['created_at']) ?></span>
        <?= hechi_e((string) $log['actor_type'] === 'staff' ? '提案委' : '委员') ?>
        ·<?= hechi_e(\HechiZx\Content\ProposalWorkflow::actionLabel((string) $log['action'])) ?>
        <?php if (trim((string) $log['note']) !== ''): ?>
          <span class="m-sub"><?= hechi_e((string) $log['note']) ?></span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</section>

<div class="m-actions">
  <?php if ($canEdit): ?>
    <a class="m-btn m-btn-primary" href="/member/proposal/<?= $proposalId ?>/edit">修改并重新提交</a>
  <?php endif; ?>
  <a class="m-btn" href="/member/proposal/<?= $proposalId ?>/word">下载提案 Word 版</a>
</div>
