<?php

/**
 * 栏目列表。
 *
 * @var list<array<string, mixed>> $channels
 */

declare(strict_types=1);

$layoutLabels = [
    'list' => '列表', 'leaders' => '领导', 'about' => '一页式', 'county' => '县区',
    'gallery' => '图集', 'video' => '视频', 'topic' => '专题', 'interactive' => '互动',
];
?>
<h1>栏目管理</h1>
<p class="muted">共 <?= count($channels) ?> 个栏目。版式决定内页形态，改版式不会新建页面，只换渲染方式。</p>

<table class="grid">
  <thead><tr><th>栏目号</th><th>一级栏目</th><th>子栏目</th><th>版式</th><th>排序</th><th>稿件数</th><th>状态</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($channels as $ch): ?>
      <tr>
        <td class="nowrap"><?= hechi_e($ch['type_code']) ?></td>
        <td><?= hechi_e($ch['name']) ?></td>
        <td><?= hechi_e($ch['inner_name']) ?></td>
        <td class="nowrap"><?= hechi_e($layoutLabels[(string) $ch['layout']] ?? $ch['layout']) ?></td>
        <td class="nowrap"><?= (int) $ch['sort_no'] ?></td>
        <td class="nowrap"><?= (int) $ch['article_count'] ?></td>
        <td class="nowrap"><span class="tag tag-<?= $ch['status'] === 'published' ? 'published' : 'offline' ?>"><?= $ch['status'] === 'published' ? '已上线' : '已下线' ?></span></td>
        <td class="nowrap"><a href="/admin/channel/<?= hechi_e($ch['type_code']) ?>">编辑</a></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
