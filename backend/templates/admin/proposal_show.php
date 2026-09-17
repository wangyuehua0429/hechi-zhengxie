<?php

/**
 * 提案详情：正文、附件、委员档案、流转记录，以及受理／退回两个动作。
 *
 * @var array<string, mixed> $proposal
 * @var list<array<string, mixed>> $coMembers
 * @var list<array<string, mixed>> $units
 * @var list<array<string, mixed>> $revisions
 * @var list<array<string, mixed>> $unitOptions
 * @var bool $editable
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
$selectedUnits = array_map(static fn (array $row): int => (int) $row['unit_id'], $units);
$coRows = $coMembers === [] ? [['name' => '', 'org_title' => '', 'mobile' => '']] : $coMembers;
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
  <?php if (trim((string) ($proposal['edited_at'] ?? '')) !== ''): ?>
    · 内容调整 <?= hechi_e((string) $proposal['edited_at']) ?>
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
      <tr><th scope="row">建议承办单位</th><td colspan="3">
        <?= $units === [] ? '—' : hechi_e(implode('、', array_map(static fn (array $row): string => (string) $row['unit_name'], $units))) ?>
      </td></tr>
      <?php if (trim((string) $proposal['collective_name']) !== ''): ?>
        <tr><th scope="row">集体名称</th><td colspan="3"><?= hechi_e((string) $proposal['collective_name']) ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <?php if ($coMembers !== []): ?>
    <h3>联名委员</h3>
    <table class="grid">
      <thead><tr><th>姓名</th><th>单位及职务</th><th>联系电话</th></tr></thead>
      <tbody>
        <?php foreach ($coMembers as $row): ?>
          <tr>
            <td class="nowrap"><?= hechi_e((string) $row['name']) ?></td>
            <td><?= hechi_e((string) $row['org_title']) ?></td>
            <td class="nowrap"><?= hechi_e((string) $row['mobile']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h3>提案办理联系人</h3>
  <table class="grid">
    <tbody>
      <tr>
        <th scope="row">姓名</th><td><?= hechi_e((string) $proposal['contact_name']) ?></td>
        <th scope="row">单位</th><td><?= hechi_e((string) $proposal['contact_org']) ?></td>
      </tr>
      <tr>
        <th scope="row">职务</th><td><?= hechi_e((string) $proposal['contact_title']) ?></td>
        <th scope="row">联系电话</th><td><?= hechi_e((string) $proposal['contact_mobile']) ?></td>
      </tr>
      <tr>
        <th scope="row">联系地址</th><td colspan="3"><?= hechi_e((string) $proposal['contact_address']) ?>
          <?php if ((string) $proposal['contact_postcode'] !== ''): ?>
            （邮编 <?= hechi_e((string) $proposal['contact_postcode']) ?>）
          <?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>提案内容（<?= \HechiZx\Content\ProposalBody::charCount((string) ($proposal['body_html'] ?? '')) ?> 字）</h2>
  <div class="body-text"><?= (string) ($proposal['body_html'] ?? '') ?></div>
</div>

<?php if ($editable): ?>
  <div class="card">
    <div class="card-head">
      <h2>调整提案</h2>
      <span class="muted">受理前可改案由、正文、建议承办单位、联名委员与办理联系人；保存后委员端会看到「提案委已调整内容」</span>
    </div>
    <form method="post" action="/admin/proposal/<?= $proposalId ?>/edit" class="edit-form" id="proposal-edit-form">
      <?= $csrf ?>
      <div class="row">
        <label class="grow">案由
          <input type="text" name="title" value="<?= hechi_e((string) $proposal['title']) ?>" maxlength="50" required>
        </label>
        <label>提案类别
          <select name="category" required>
            <?php foreach (\HechiZx\Content\ProposalWorkflow::categories() as $category): ?>
              <option value="<?= hechi_e($category) ?>"<?= (string) $proposal['category'] === $category ? ' selected' : '' ?>><?= hechi_e($category) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="grow">集体名称（集体提案填）
          <input type="text" name="collective_name" value="<?= hechi_e((string) $proposal['collective_name']) ?>" maxlength="128">
        </label>
      </div>

      <div class="full">
        <label for="body_html">提案内容（纯文字不超过 <?= \HechiZx\Content\ProposalBody::MAX_CHARS ?> 字，工具条可做加粗、下划线与列表）</label>
        <textarea id="body_html" name="body_html" rows="14" required><?= hechi_e((string) ($proposal['body_html'] ?? '')) ?></textarea>
        <div class="editor-mount" data-editor-mount hidden></div>
      </div>

      <div class="full">
        <span class="unit-picker-label">建议承办单位（可搜索，最多 <?= \HechiZx\Repository\UnitRepository::MAX_PER_PROPOSAL ?> 个）</span>
        <div class="unit-picker" data-unit-picker data-max="<?= \HechiZx\Repository\UnitRepository::MAX_PER_PROPOSAL ?>"
             data-placeholder="输入单位名称的关键词，点开选择">
          <div class="unit-picker-field" data-unit-field>
            <input type="text" class="unit-picker-input" data-unit-search aria-label="建议承办单位：输入关键词搜索"
                   autocomplete="off" placeholder="输入单位名称的关键词，点开选择">
          </div>
          <div class="unit-picker-panel" data-unit-panel hidden>
            <ul class="unit-picker-list" data-unit-list></ul>
            <p class="unit-picker-empty" data-unit-empty hidden>没有匹配的单位，换个关键词试试。</p>
          </div>
          <div data-unit-values></div>
          <!-- 没有脚本时显示的就是这个原生多选，照旧能用、照旧提交 -->
          <select data-unit-native name="units[]" multiple size="6">
            <?php foreach ($unitOptions as $option): ?>
              <option value="<?= (int) $option['unit_id'] ?>"<?= in_array((int) $option['unit_id'], $selectedUnits, true) ? ' selected' : '' ?>>
                <?= hechi_e((string) $option['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <p class="unit-picker-hint" data-unit-hint>已选 0 / <?= \HechiZx\Repository\UnitRepository::MAX_PER_PROPOSAL ?></p>
        </div>
      </div>

      <h3>联名委员</h3>
      <div class="row">
        <?php foreach ($coRows as $index => $row): ?>
          <label>姓名
            <input type="text" name="co_name[]" value="<?= hechi_e((string) $row['name']) ?>" maxlength="64">
          </label>
          <label class="grow">单位及职务
            <input type="text" name="co_org[]" value="<?= hechi_e((string) $row['org_title']) ?>" maxlength="128">
          </label>
          <label>联系电话
            <input type="text" name="co_mobile[]" value="<?= hechi_e((string) $row['mobile']) ?>" maxlength="32">
          </label>
        <?php endforeach; ?>
      </div>

      <h3>提案办理联系人</h3>
      <div class="row">
        <label>姓名
          <input type="text" name="contact_name" value="<?= hechi_e((string) $proposal['contact_name']) ?>" maxlength="64">
        </label>
        <label class="grow">单位
          <input type="text" name="contact_org" value="<?= hechi_e((string) $proposal['contact_org']) ?>" maxlength="128">
        </label>
        <label class="grow">职务
          <input type="text" name="contact_title" value="<?= hechi_e((string) $proposal['contact_title']) ?>" maxlength="128">
        </label>
      </div>
      <div class="row">
        <label class="grow">联系地址
          <input type="text" name="contact_address" value="<?= hechi_e((string) $proposal['contact_address']) ?>" maxlength="255">
        </label>
        <label>邮政编码
          <input type="text" name="contact_postcode" value="<?= hechi_e((string) $proposal['contact_postcode']) ?>" maxlength="6" inputmode="numeric">
        </label>
        <label>联系电话
          <input type="text" name="contact_mobile" value="<?= hechi_e((string) $proposal['contact_mobile']) ?>" maxlength="32">
        </label>
      </div>

      <div class="row">
        <label class="check">&nbsp;
          <button type="submit" class="btn-primary">保存调整</button>
        </label>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php if ($revisions !== []): ?>
  <div class="card">
    <h2>内容调整记录</h2>
    <table class="grid">
      <thead><tr><th>时间</th><th>改动内容</th></tr></thead>
      <tbody>
        <?php foreach ($revisions as $revision): ?>
          <tr>
            <td class="nowrap"><?= hechi_e((string) $revision['created_at']) ?></td>
            <td><?= hechi_e((string) $revision['summary']) ?>（改前内容已留档）</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

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
