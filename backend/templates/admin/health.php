<?php

/**
 * 接口状态自检：探活结果 + 运行环境 + 目录写权限。
 *
 * 数据由 HechiZx\Admin\HealthController 备好；探活那段与 /api/v1/health 共用
 * Support\HealthProbe，页面上显示的和监控拿到的是同一个结论。
 *
 * @var array<string, mixed> $probe      探活结果（status／driver／time）
 * @var string $probeError               探活抛错时的原因，正常时为空
 * @var int $elapsedMs                   本次探活耗时（毫秒）
 * @var string $elapsedText              耗时的可读写法
 * @var string $checkedAt                本次检查时间
 * @var list<array{label:string,value:string,tag:string,tagClass:string}> $environment
 * @var list<array{label:string,value:string,tag:string,tagClass:string}> $storage
 */

declare(strict_types=1);

$ok = $probeError === '' && (string) ($probe['status'] ?? '') === 'ok';
?>
<div class="page-head">
  <div class="page-title">
    <h1>接口状态</h1>
    <p class="subtitle">本站探活与自检：与 <code>/api/v1/health</code> 同源，页面只是把它读成人能看的样子。</p>
  </div>
  <div class="head-actions">
    <a class="btn" href="/admin/health">重新检查</a>
    <a class="btn btn-ghost" href="/api/v1/health" target="_blank" rel="noopener">查看原始 JSON</a>
  </div>
</div>

<p class="health-banner<?= $ok ? '' : ' health-banner--bad' ?>" role="status">
  <span class="health-banner-icon" aria-hidden="true"><?= $ok ? '✔' : '!' ?></span>
  <span>
    <strong><?= $ok ? '接口正常' : '接口异常' ?></strong>
    <span class="health-banner-note">
      <?php if ($ok): ?>
        数据库读得到、接口返回 ok。检查时间 <?= hechi_e($checkedAt) ?>，本次耗时 <?= hechi_e($elapsedText) ?>。
      <?php else: ?>
        <?= hechi_e($probeError !== '' ? $probeError : '接口返回值不是 ok') ?>
        （检查时间 <?= hechi_e($checkedAt) ?>）。先看下面「运行环境」与「数据与目录」，确认是不是库连不上、目录写不了。
      <?php endif; ?>
    </span>
  </span>
</p>

<section class="card">
  <h2>探活结果</h2>
  <dl class="meta-list">
    <dt>状态</dt>
    <dd>
      <span class="tag <?= $ok ? 'tag-published' : 'tag-rejected' ?>"><?= hechi_e((string) ($probe['status'] ?? ($ok ? 'ok' : 'error'))) ?></span>
      <?= $ok ? '接口可用' : '接口不可用' ?>
    </dd>
    <dt>数据库</dt><dd><?= hechi_e((string) ($probe['driver'] ?? '未取到')) ?></dd>
    <dt>服务器时间</dt><dd><?= hechi_e((string) ($probe['time'] ?? '未取到')) ?></dd>
    <dt>检查耗时</dt><dd><?= hechi_e($elapsedText) ?></dd>
    <dt>接口地址</dt>
    <dd><a href="/api/v1/health" target="_blank" rel="noopener">/api/v1/health</a>（给监控用的原始 JSON）</dd>
  </dl>
</section>

<section class="card">
  <h2>运行环境</h2>
  <dl class="meta-list">
    <?php foreach ($environment as $row): ?>
      <dt><?= hechi_e($row['label']) ?></dt>
      <dd><?= hechi_e($row['value']) ?><?php if ($row['tag'] !== ''): ?>
        <span class="tag <?= hechi_e($row['tagClass']) ?>"><?= hechi_e($row['tag']) ?></span><?php endif; ?></dd>
    <?php endforeach; ?>
  </dl>
</section>

<section class="card">
  <h2>数据与目录</h2>
  <dl class="meta-list">
    <?php foreach ($storage as $row): ?>
      <dt><?= hechi_e($row['label']) ?></dt>
      <dd><code><?= hechi_e($row['value']) ?></code>
        <span class="tag <?= hechi_e($row['tagClass']) ?>"><?= hechi_e($row['tag']) ?></span></dd>
    <?php endforeach; ?>
  </dl>
  <p class="muted">发布目录写不了、数据库文件读不到，都会表现成「后台能改、前台不变」，先在这里确认再往下查。</p>
</section>

<p class="muted publish-hint">
  <strong>这不是内容发布：</strong>接口状态只反映服务端此刻能不能用。稿件改完前台看不到，多半是看的静态快照，
  回「概览」点一次「立即发布全站」即可。
</p>
