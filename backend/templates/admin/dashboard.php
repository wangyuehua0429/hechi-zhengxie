<?php

/**
 * 后台首页：数据概览 + 最近稿件 + 发布。
 *
 * @var array<string, int> $statusCount
 * @var int $channelCount
 * @var list<array<string, mixed>> $recent
 * @var int $total
 * @var string $publishDir
 * @var string|null $publishedAt
 * @var int $logCount
 * @var string $csrf
 */

declare(strict_types=1);

$statusLabels = ['published' => '已发布', 'draft' => '草稿', 'offline' => '已下线'];
?>
<h1>概览</h1>

<div class="stats">
  <div class="stat"><span class="stat-num"><?= (int) $total ?></span><span class="stat-label">稿件总数</span></div>
  <?php foreach ($statusLabels as $key => $label): ?>
    <div class="stat"><span class="stat-num"><?= (int) ($statusCount[$key] ?? 0) ?></span><span class="stat-label"><?= hechi_e($label) ?></span></div>
  <?php endforeach; ?>
  <div class="stat"><span class="stat-num"><?= $channelCount ?></span><span class="stat-label">栏目数</span></div>
</div>

<section class="card">
  <h2>发布</h2>
  <p class="muted">
    发布 = 把库里的内容重新生成静态页与数据快照，输出到 <code><?= hechi_e($publishDir) ?></code>。<br>
    上次发布：<?= $publishedAt ? hechi_e($publishedAt) : '尚未发布过' ?>　操作日志：<?= $logCount ?> 条
  </p>
  <form method="post" action="/admin/publish"><?= $csrf ?>
    <button type="submit" class="btn-primary">立即发布全站</button>
  </form>
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
          <td class="nowrap"><span class="tag tag-<?= hechi_e((string) $item['status']) ?>"><?= hechi_e($statusLabels[(string) $item['status']] ?? $item['status']) ?></span></td>
          <td class="nowrap"><a href="/admin/article/<?= (int) $item['article_id'] ?>">编辑</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted"><a href="/admin/articles">查看全部稿件 →</a></p>
</section>
