<?php

/**
 * 提案表单：新建与「退回后修改重交」共用。
 *
 * @var array<string, mixed>|null $proposal
 * @var int $proposalId
 * @var array<string, mixed> $values
 * @var list<string> $errors
 * @var list<string> $categories
 * @var array<string, string> $proposerTypes
 * @var list<array<string, mixed>> $unitOptions
 * @var list<int> $selectedUnits
 * @var list<array<string, mixed>> $coRows
 * @var int $maxUnits
 * @var int $bodyLimit
 * @var int $bodyCount
 * @var list<array<string, mixed>> $attachments
 * @var int $maxCount
 * @var int $maxMb
 * @var string $csrf
 */

declare(strict_types=1);

$isEdit = $proposalId > 0;
$action = $isEdit ? '/member/proposal/' . $proposalId . '/submit' : '/member/proposal/create';
$returnTo = $isEdit ? '/member/proposal/' . $proposalId . '/edit' : '/member/proposal/new';
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

<form method="post" action="<?= hechi_e($action) ?>" id="proposal-form" class="m-form m-form-wide"
      enctype="multipart/form-data" data-body-limit="<?= (int) $bodyLimit ?>">
  <?= $csrf ?>
  <input type="hidden" name="return_to" value="<?= hechi_e($returnTo) ?>">

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
        <input type="text" id="proposer_name" name="proposer_name" value="<?= hechi_e((string) $values['proposer_name']) ?>" maxlength="64">
      </div>
      <div class="m-col">
        <label for="collective_name">集体名称（集体提案填写）</label>
        <input type="text" id="collective_name" name="collective_name" value="<?= hechi_e((string) $values['collective_name']) ?>"
               maxlength="128" placeholder="如：经济界、某专门委员会">
      </div>
    </div>

    <div class="m-row">
      <div class="m-col">
        <label for="sector">界别</label>
        <input type="text" id="sector" name="sector" value="<?= hechi_e((string) $values['sector']) ?>" maxlength="64">
      </div>
      <div class="m-col">
        <label for="committee">专委会</label>
        <input type="text" id="committee" name="committee" value="<?= hechi_e((string) $values['committee']) ?>" maxlength="64">
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>联名委员</legend>
    <p class="m-hint">选「联名」提案时至少填写一位；填姓名时可从委员名册带出单位职务与电话，名册外的人员直接手工填写。</p>
    <datalist id="roster-list"></datalist>
    <div data-member-rows>
      <?php foreach ($coRows as $row): ?>
        <div class="m-member-row" data-member-row>
          <label>姓名
            <input type="text" name="co_name[]" value="<?= hechi_e((string) ($row['name'] ?? '')) ?>" maxlength="64"
                   list="roster-list" autocomplete="off">
          </label>
          <label>单位及职务
            <input type="text" name="co_org[]" value="<?= hechi_e((string) ($row['org_title'] ?? '')) ?>" maxlength="128">
          </label>
          <label>联系电话
            <input type="text" name="co_mobile[]" value="<?= hechi_e((string) ($row['mobile'] ?? '')) ?>" maxlength="32">
          </label>
          <button type="button" class="m-btn m-btn-sm m-row-del" data-member-remove>删除</button>
        </div>
      <?php endforeach; ?>
    </div>
    <template data-member-template>
      <div class="m-member-row" data-member-row>
        <label>姓名
          <input type="text" name="co_name[]" maxlength="64" list="roster-list" autocomplete="off">
        </label>
        <label>单位及职务
          <input type="text" name="co_org[]" maxlength="128">
        </label>
        <label>联系电话
          <input type="text" name="co_mobile[]" maxlength="32">
        </label>
        <button type="button" class="m-btn m-btn-sm m-row-del" data-member-remove>删除</button>
      </div>
    </template>
    <button type="button" class="m-btn m-btn-sm" data-member-add>再加一位联名委员</button>
  </fieldset>

  <fieldset>
    <legend>建议承办单位</legend>
    <?php if ($unitOptions === []): ?>
      <p class="m-hint">市直单位名单还没配置，请联系提案委；这一栏可以留空，提案委在收件时会按内容确定承办单位。</p>
    <?php else: ?>
      <label for="units-search">建议承办单位（最多选 <?= (int) $maxUnits ?> 个）</label>
      <div class="unit-picker" data-unit-picker data-max="<?= (int) $maxUnits ?>"
           data-placeholder="输入单位名称的关键词，点开选择">
        <div class="unit-picker-field" data-unit-field>
          <input type="text" class="unit-picker-input" id="units-search" data-unit-search
                 autocomplete="off" placeholder="输入单位名称的关键词，点开选择">
        </div>
        <div class="unit-picker-panel" data-unit-panel hidden>
          <ul class="unit-picker-list" data-unit-list></ul>
          <p class="unit-picker-empty" data-unit-empty hidden>没有匹配的单位，换个关键词试试。</p>
        </div>
        <div data-unit-values></div>
        <!-- 没有脚本时显示的就是这个原生多选，照旧能用、照旧提交 -->
        <select id="units" data-unit-native name="units[]" multiple size="8">
          <?php foreach ($unitOptions as $option): ?>
            <option value="<?= (int) $option['unit_id'] ?>"<?= in_array((int) $option['unit_id'], $selectedUnits, true) ? ' selected' : '' ?>>
              <?= hechi_e((string) $option['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <p class="m-hint" data-unit-hint>已选 0 / <?= (int) $maxUnits ?></p>
      </div>
      <p class="m-hint">不确定由哪个单位承办时可以留空，由提案委在收件时确定。</p>
    <?php endif; ?>
  </fieldset>

  <fieldset>
    <legend>提案内容</legend>

    <label for="title">案由（标题）</label>
    <input type="text" id="title" name="title" value="<?= hechi_e((string) $values['title']) ?>" maxlength="50" required>
    <p class="m-hint">不超过 50 个字，写清要解决的事项。</p>

    <label for="category">提案类别</label>
    <select id="category" name="category" required>
      <option value="">请选择</option>
      <?php foreach ($categories as $category): ?>
        <option value="<?= hechi_e($category) ?>"<?= $values['category'] === $category ? ' selected' : '' ?>><?= hechi_e($category) ?></option>
      <?php endforeach; ?>
    </select>

    <div class="m-editor-bar">
      <label for="body_html">正文</label>
      <span class="m-count" data-body-counter><?= (int) $bodyCount ?> / <?= (int) $bodyLimit ?> 字</span>
    </div>
    <textarea id="body_html" name="body_html" rows="12" required
              aria-describedby="body-hint"
              placeholder="提案一事一案，简明扼要，字数不超过 <?= (int) $bodyLimit ?> 字，否则无法上传，有关材料可作为附件提交。"><?= hechi_e((string) $values['body_html']) ?></textarea>
    <div class="m-editor-mount" data-editor-mount hidden></div>
    <p class="m-hint" id="body-hint">可用工具条做加粗、下划线与列表；正文只保留这些格式，导出提案表时版式统一。</p>

    <div class="m-actions">
      <button type="submit" class="m-btn" formaction="/member/proposal/check-text" data-proofread>
        错别字勘误（待接入）
      </button>
    </div>
  </fieldset>

  <fieldset>
    <legend>提案办理联系人</legend>
    <p class="m-hint">六项均为必填，未填写无法提交。首次填写后系统会记住，下次填提案自动带出，需要修改时直接在这里改。</p>
    <div class="m-row">
      <div class="m-col">
        <label for="contact_name">姓名</label>
        <input type="text" id="contact_name" name="contact_name" value="<?= hechi_e((string) $values['contact_name']) ?>" maxlength="64" required>
      </div>
      <div class="m-col">
        <label for="contact_org">单位</label>
        <input type="text" id="contact_org" name="contact_org" value="<?= hechi_e((string) $values['contact_org']) ?>" maxlength="128" required>
      </div>
      <div class="m-col">
        <label for="contact_title">职务</label>
        <input type="text" id="contact_title" name="contact_title" value="<?= hechi_e((string) $values['contact_title']) ?>" maxlength="128" required>
      </div>
    </div>
    <div class="m-row">
      <div class="m-col">
        <label for="contact_address">联系地址</label>
        <input type="text" id="contact_address" name="contact_address" value="<?= hechi_e((string) $values['contact_address']) ?>" maxlength="255" required>
      </div>
      <div class="m-col">
        <label for="contact_postcode">邮政编码</label>
        <input type="text" id="contact_postcode" name="contact_postcode" value="<?= hechi_e((string) $values['contact_postcode']) ?>"
               maxlength="6" inputmode="numeric" required>
      </div>
      <div class="m-col">
        <label for="contact_mobile">联系电话</label>
        <input type="text" id="contact_mobile" name="contact_mobile" value="<?= hechi_e((string) $values['contact_mobile']) ?>" maxlength="32" required>
      </div>
    </div>
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
    <a class="m-btn" href="<?= hechi_e($returnTo === '/member/proposal/new' ? '/member/proposals' : '/member/proposal/' . $proposalId) ?>">取消</a>
  </div>
</form>
