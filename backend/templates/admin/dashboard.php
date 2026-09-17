<?php

/**
 * 后台首页：数据概览 + 最近稿件 + 发布。
 *
 * @var array<string, int> $statusCount
 * @var int $channelCount
 * @var list<array<string, mixed>> $recent
 * @var int $total
 * @var string $publishDir
 * @var array{at:string,by:string,pages:int,channels:int,articles:int}|null $lastPublish
 * @var int $logCount
 * @var string $csrf
 */

declare(strict_types=1);

use HechiZx\Content\ArticleWorkflow;

$places = ArticleWorkflow::places();
$adminIcons = require __DIR__ . '/_icons.php';
?>
<h1>概览</h1>

<div class="stats">
  <?php
  // 待办优先：红色只留给「需要处理」的稿库（待审／退回），其余状态收进一行摘要 + 折叠区。
  // 概览页要回答的是「今天有什么要处理」，而不是「这个系统一共有多少东西」。
  $todoStatuses = [ArticleWorkflow::PENDING, ArticleWorkflow::REJECTED];
  $todoPlaces = [];
  $restPlaces = [];
  foreach ($places as $place) {
      if (in_array((string) $place['status'], $todoStatuses, true)) {
          $todoPlaces[] = $place;
      } else {
          $restPlaces[] = $place;
      }
  }
  $todoTotal = 0;
  $restSummary = [];
  foreach ($todoPlaces as $place) {
      $todoTotal += (int) ($statusCount[(string) $place['status']] ?? 0);
  }
  foreach ($restPlaces as $place) {
      $restSummary[] = (string) $place['label'] . ' ' . (int) ($statusCount[(string) $place['status']] ?? 0);
  }
  $restSummary[] = '栏目 ' . (int) $channelCount;
  ?>
  <?php foreach ($todoPlaces as $place): ?>
    <?php $placeCount = (int) ($statusCount[(string) $place['status']] ?? 0); ?>
    <a class="stat stat--todo<?= $placeCount === 0 ? ' stat--zero' : '' ?>"
       href="/admin/articles?status=<?= hechi_e((string) $place['status']) ?>">
      <span class="stat-num"><?= $placeCount ?></span>
      <span class="stat-label"><?= hechi_e((string) $place['label']) ?></span>
      <span class="stat-hint"><?= $placeCount === 0 ? '暂无待处理' : '等待处理' ?></span>
    </a>
  <?php endforeach; ?>
  <div class="stat">
    <span class="stat-num"><?= (int) $total ?></span>
    <span class="stat-label">稿件总数</span>
    <span class="stat-hint"><?= $todoTotal === 0 ? '待处理已清空' : '其中 ' . (int) $todoTotal . ' 篇待处理' ?></span>
  </div>
</div>

<details class="stats-more">
  <summary>
    <span>按稿库看全部状态</span>
    <span class="stats-more-nums"><?= hechi_e(implode(' · ', $restSummary)) ?></span>
  </summary>
  <div class="stats">
    <?php foreach ($restPlaces as $place): ?>
      <?php $placeCount = (int) ($statusCount[(string) $place['status']] ?? 0); ?>
      <a class="stat<?= $placeCount === 0 ? ' stat--zero' : '' ?>" href="/admin/articles?status=<?= hechi_e((string) $place['status']) ?>">
        <span class="stat-num"><?= $placeCount ?></span>
        <span class="stat-label"><?= hechi_e((string) $place['label']) ?></span>
      </a>
    <?php endforeach; ?>
    <div class="stat"><span class="stat-num"><?= $channelCount ?></span><span class="stat-label">栏目数</span></div>
  </div>
</details>

<section class="card">
  <h2>发布全站</h2>
  <p class="muted prose"><strong>平时发稿不用点这里。</strong>稿件状态是「已发布」，前台立刻就能看到。</p>
  <?php if (in_array('publish.run', $userPerms ?? [], true)): ?>
    <form method="post" action="/admin/publish"><?= $csrf ?>
      <button type="submit" class="btn-primary">立即发布全站</button>
    </form>
  <?php else: ?>
    <p class="muted">当前账号没有「生成静态页与数据快照」权限，发布按钮不可用。</p>
  <?php endif; ?>
  <details class="advanced publish-advanced">
    <summary>什么时候才要点「发布全站」？</summary>
    <p class="muted prose">
      这一步会把后台的内容重新生成一遍静态文件——文章详情页、站点地图与一份数据快照，生成后可以直接对外提供，
      不再走实时查询。它只影响这些静态文件：改过栏目名称或顺序、想刷一遍详情页与站点地图时点一次。
    </p>
    <?php if (in_array('publish.run', $userPerms ?? [], true)): ?>
      <p class="muted prose">静态文件生成在服务器上的 <code><?= hechi_e($publishDir) ?></code>。</p>
    <?php endif; ?>
  </details>
  <p class="muted publish-meta">
    <?php if ($lastPublish): ?>
      上次发布：<?= hechi_e($lastPublish['at']) ?>（<?= hechi_e($lastPublish['by']) ?><?php
        if (($lastPublish['pages'] ?? 0) > 0) {
            echo '，生成 ' . (int) $lastPublish['pages'] . ' 个页面、' . (int) $lastPublish['channels'] . ' 个栏目、' . (int) $lastPublish['articles'] . ' 篇稿件';
        }
      ?>）
      · <a href="/admin/logs">查看发布记录</a>
    <?php else: ?>
      还没有发布过。首次发布前前台不受影响，仍在走实时内容接口。
    <?php endif; ?>
  </p>
</section>

<section class="card">
  <h2>最近稿件</h2>
  <div class="table-scroll">
  <table class="grid">
    <thead><tr><th>发布时间</th><th>标题</th><th>栏目</th><th>状态</th><th class="col-actions"></th></tr></thead>
    <tbody>
      <?php foreach ($recent as $item): ?>
        <tr>
          <td class="nowrap"><?= hechi_e(substr((string) $item['published_at'], 0, 16)) ?></td>
          <td><?= hechi_e($item['title']) ?></td>
          <td class="nowrap"><?= hechi_e($item['channel_inner'] ?? $item['channel_name'] ?? '') ?></td>
          <td class="nowrap"><span class="tag tag-<?= hechi_e(ArticleWorkflow::normalize((string) $item['status'])) ?>"><?= hechi_e(ArticleWorkflow::label((string) $item['status'])) ?></span></td>
          <td class="nowrap col-actions"><a href="/admin/article/<?= (int) $item['article_id'] ?>">编辑</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($recent === []): ?>
        <tr>
          <td colspan="5" class="empty">
            <span class="empty-icon" aria-hidden="true"><?= $adminIcons['empty'] ?></span>
            <span class="empty-title">还没有稿件。</span>
            <span class="empty-hint">点「稿件管理 → 新建稿件」写下第一篇。</span>
          </td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="muted">
    <a href="/admin/articles">查看全部稿件 →</a>　
    操作日志累计 <?= (int) $logCount ?> 条 · <a href="/admin/logs">查看日志</a>
  </p>
</section>
