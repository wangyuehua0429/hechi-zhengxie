<?php

/**
 * 操作日志列表（最近 100 条一页）。
 *
 * @var list<array<string, mixed>> $logs
 * @var int $total
 * @var int $page
 * @var int $pages
 */

declare(strict_types=1);

$actionLabels = [
    'login' => '登录', 'logout' => '退出',
    'article.create' => '新建稿件', 'article.update' => '修改稿件',
    'article.submit' => '提交审核', 'article.approve' => '审核通过', 'article.reject' => '退回',
    'article.withdraw' => '撤回', 'article.republish' => '重新发布',
    'article.delete' => '移入回收站', 'article.restore' => '从回收站恢复',
    'attachment.create' => '上传附件', 'attachment.delete' => '删除附件', 'image.create' => '插入正文图片',
    'article.order' => '调整栏目内顺序',
    'channel.update' => '修改栏目', 'channel.move' => '调整栏目顺序', 'publish.all' => '一键发布',
    'nav.update' => '修改导航项', 'nav.move' => '调整导航顺序',
    'slide.create' => '新增头条轮换', 'slide.update' => '修改头条轮换', 'slide.move' => '调整轮换顺序',
    'slide.status' => '轮换上下线', 'slide.delete' => '删除头条轮换',
    'section.update' => '修改首页模块', 'banner.update' => '修改站内横幅',
    'user.create' => '新建账号', 'user.update' => '修改账号', 'user.reset_password' => '重置密码',
    'role.update' => '修改角色权限',
];
?>
<h1>操作日志</h1>

<?php $systemCurrent = 'logs'; include __DIR__ . '/_system_nav.php'; ?>

<p class="muted">共 <?= (int) $total ?> 条，第 <?= (int) $page ?>/<?= (int) $pages ?> 页。日志按时间倒序，保留策略见发布运维文档。</p>

<table class="grid">
  <thead><tr><th>时间</th><th>账号</th><th>动作</th><th>对象</th><th>说明</th><th>来源 IP</th></tr></thead>
  <tbody>
    <?php foreach ($logs as $log): ?>
      <tr>
        <td class="nowrap"><?= hechi_e((string) $log['created_at']) ?></td>
        <td class="nowrap"><?= hechi_e((string) ($log['real_name'] ?? '') !== '' ? (string) $log['real_name'] : (string) ($log['username'] ?? '—')) ?></td>
        <td class="nowrap"><?= hechi_e($actionLabels[(string) $log['action']] ?? (string) $log['action']) ?></td>
        <td class="nowrap"><?= hechi_e((string) $log['target_type'] . ' ' . (string) $log['target_id']) ?></td>
        <td class="muted"><?= hechi_e((string) ($log['detail_json'] ?? '')) ?></td>
        <td class="nowrap"><?= hechi_e((string) $log['ip']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($logs === []): ?>
      <tr><td colspan="6" class="empty">还没有日志。</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php if ($pages > 1): ?>
  <nav class="pager">
    <?php if ($page > 1): ?><a href="/admin/logs?page=<?= $page - 1 ?>">上一页</a><?php endif; ?>
    <span>第 <?= (int) $page ?> 页 / 共 <?= (int) $pages ?> 页</span>
    <?php if ($page < $pages): ?><a href="/admin/logs?page=<?= $page + 1 ?>">下一页</a><?php endif; ?>
  </nav>
<?php endif; ?>
