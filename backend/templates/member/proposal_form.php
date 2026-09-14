<?php

/**
 * 提案表单：新建与「退回后修改重交」共用。
 *
 * @var array<string, mixed>|null $proposal
 * @var int $proposalId
 * @var array<string, string> $values
 * @var list<string> $errors
 * @var list<string> $categories
 * @var array<string, string> $proposerTypes
 * @var list<array<string, mixed>> $attachments
 * @var int $maxCount
 * @var int $maxMb
 */

declare(strict_types=1);

$isEdit = $proposalId > 0;
$action = $isEdit ? '/member/proposal/' . $proposalId . '/submit' : '/member/proposal/create';
?>
<h1><?= $isEdit ? '修改提案' : '填写提案' ?></h1>

<?php if ($isEdit && trim((string) ($proposal['returned_reason'] ?? '')) !== ''): ?>
  <div class="m-notice m-notice-warn">
    <strong>退回意见：</strong><?= hechi_e((string) $proposal['returned_reason']) ?>
  </div>
<?php endif; ?>

<?php if ($errors !== []): ?>
  <div class="m-notice m-notice-error" role="alert">
    <strong>请检查以下内容：</strong>
    <ul>
      <?php foreach ($errors as $error): ?>
        <li><?= hechi_e($error) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" action="<?= hechi_e($action) ?>" class="m-form m-form-wide" enctype="multipart/form-data">
  <?= $csrf ?>

  <fieldset>
    <legend>提案人信息</legend>

    <div class="m-row">
      <div class="m-col">
        <label for="proposer_type">提案人类别</label>
        <select id="proposer_type" name="proposer_type">
          <?php foreach ($proposerTypes as $key => $label): ?>
            <option value="<?= hechi_e($key) ?>"<?= $values['proposer_type'] === $key ? ' selected' : '' ?>><?= hechi_e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="m-col">
        <label for="proposer_name">提案人</label>
        <input type="text" id="proposer_name" name="proposer_name" value="<?= hechi_e($values['proposer_name']) ?>" maxlength="64">
      </div>
    </div>

    <div class="m-row">
      <div class="m-col">
        <label for="co_members">联名委员（联名提案填写）</label>
        <input type="text" id="co_members" name="co_members" value="<?= hechi_e($values['co_members']) ?>"
               maxlength="500" placeholder="如：张三、李四">
      </div>
      <div class="m-col">
        <label for="collective_name">集体名称（集体提案填写）</label>
        <input type="text" id="collective_name" name="collective_name" value="<?= hechi_e($values['collective_name']) ?>"
               maxlength="128" placeholder="如：经济界、某专门委员会">
      </div>
    </div>

    <div class="m-row">
      <div class="m-col">
        <label for="sector">界别</label>
        <input type="text" id="sector" name="sector" value="<?= hechi_e($values['sector']) ?>" maxlength="64">
      </div>
      <div class="m-col">
        <label for="committee">专委会</label>
        <input type="text" id="committee" name="committee" value="<?= hechi_e($values['committee']) ?>" maxlength="64">
      </div>
      <div class="m-col">
        <label for="contact_mobile">联系电话</label>
        <input type="text" id="contact_mobile" name="contact_mobile" value="<?= hechi_e($values['contact_mobile']) ?>" maxlength="32">
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>提案内容</legend>

    <label for="title">案由（标题）</label>
    <input type="text" id="title" name="title" value="<?= hechi_e($values['title']) ?>" maxlength="50" required>
    <p class="m-hint">不超过 50 个字，写清要解决的事项。</p>

    <label for="category">提案类别</label>
    <select id="category" name="category" required>
      <option value="">请选择</option>
      <?php foreach ($categories as $category): ?>
        <option value="<?= hechi_e($category) ?>"<?= $values['category'] === $category ? ' selected' : '' ?>><?= hechi_e($category) ?></option>
      <?php endforeach; ?>
    </select>

    <label for="problem_text">一、情况与问题</label>
    <textarea id="problem_text" name="problem_text" rows="8" required><?= hechi_e($values['problem_text']) ?></textarea>
    <p class="m-hint">每段不超过 3000 字，按段落换行即可，无需排版。</p>

    <label for="analysis_text">二、分析</label>
    <textarea id="analysis_text" name="analysis_text" rows="6"><?= hechi_e($values['analysis_text']) ?></textarea>

    <label for="suggestion_text">三、建议</label>
    <textarea id="suggestion_text" name="suggestion_text" rows="8" required><?= hechi_e($values['suggestion_text']) ?></textarea>
  </fieldset>

  <fieldset>
    <legend>附件</legend>
    <?php if ($attachments !== []): ?>
      <ul class="m-files">
        <?php foreach ($attachments as $attachment): ?>
          <li>
            <?= hechi_e((string) $attachment['name']) ?>
            <a href="/member/proposal/<?= (int) $proposalId ?>/attachment/<?= (int) $attachment['attachment_id'] ?>">下载</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <input type="file" id="attachments" name="attachments[]" multiple>
    <p class="m-hint">可选，最多 <?= (int) $maxCount ?> 个，单个不超过 <?= (int) $maxMb ?> MB；
      支持 PDF、Word、Excel、PowerPoint、压缩包与文本文件。</p>
  </fieldset>

  <div class="m-actions">
    <button type="submit" class="m-btn m-btn-primary"><?= $isEdit ? '保存并重新提交' : '提交提案' ?></button>
    <a class="m-btn" href="<?= $isEdit ? '/member/proposal/' . $proposalId : '/member/proposals' ?>">取消</a>
  </div>
</form>
