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
?>
<h1>概览</h1>

<div class="stats">
  <div class="stat"><span class="stat-num"><?= (int) $total ?></span><span class="stat-label">稿件总数</span></div>
  <?php foreach ($places as $place): ?>
    <a class="stat" href="/admin/articles?status=<?= hechi_e((string) $place['status']) ?>">
      <span class="stat-num"><?= (int) ($statusCount[(string) $place['status']] ?? 0) ?></span>
      <span class="stat-label"><?= hechi_e((string) $place['label']) ?></span>
    </a>
  <?php endforeach; ?>
  <div class="stat"><span class="stat-num"><?= $channelCount ?></span><span class="stat-label">栏目数</span></div>
</div>

<section class="card">
  <h2>发布全站</h2>
  <p class="muted">
    把后台的内容重新生成一遍静态文件——文章详情页、站点地图与一份数据快照，生成后可以直接对外提供，
    不再走实时查询。
  </p>
  <p class="muted">
    <strong>平时发稿不用点这里。</strong>稿件状态是「已发布」，前台立刻就看到了，走的是实时内容接口；
    这一步只影响上面的静态文件，改过栏目名称或顺序、想刷一遍详情页与站点地图时再点一次。
  </p>
  <?php if (in_array('publish.run', $userPerms ?? [], true)): ?>
    <form method="post" action="/admin/publish"><?= $csrf ?>
      <button type="submit" class="btn-primary">立即发布全站</button>
    </form>
  <?php else: ?>
    <p class="muted">当前账号没有「生成静态页与数据快照」权限，发布按钮不可用。</p>
  <?php endif; ?>
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
    <br>
    文件生成位置：<code><?= hechi_e($publishDir) ?></code>
  </p>
</section>

<section class="card">
  <h2>最近稿件</h2>
  <table class="grid">
    <thead><tr><th>发布时间</th><th>标题</th><th>栏目</th><th>状态</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($recent as $item): ?>
        <tr>
          <td class="nowrap"><?= hechi_e(substr((string) $item['published_at'], 0, 16)) ?></td>
          <td><?= hechi_e($item['title']) ?></td>
          <td class="nowrap"><?= hechi_e($item['channel_inner'] ?? $item['channel_name'] ?? '') ?></td>
          <td class="nowrap"><span class="tag tag-<?= hechi_e(ArticleWorkflow::normalize((string) $item['status'])) ?>"><?= hechi_e(ArticleWorkflow::label((string) $item['status'])) ?></span></td>
          <td class="nowrap"><a href="/admin/article/<?= (int) $item['article_id'] ?>">编辑</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted">
    <a href="/admin/articles">查看全部稿件 →</a>　
    操作日志累计 <?= (int) $logCount ?> 条 · <a href="/admin/logs">查看日志</a>
  </p>
</section>
