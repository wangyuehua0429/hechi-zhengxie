<?php

/**
 * 栏目导航条（后台共用）：两级呈现，一级项一行铺开，子栏目按需展开。
 *
 * 为什么不做成下拉：栏目有 43 个、还分两级，你之前定过「用导航条、不用下拉」；
 * 但 43 个 chip 一次铺开会占掉五分之一屏，把稿件表格挤出首屏，所以改成
 * 「先列一级，选中哪一组就展开哪一组」——形态仍是导航条，选项仍是点一下就筛。
 *
 * 一级项与子栏目分开传参的原因：一级栏目号经常同时是组内某个子栏目的号
 * （「政协会议」401 组的 401 就是「全体会议」），只靠栏目号无法区分
 * 「筛整个一级栏目」和「筛某一个子栏目」，因此一级项走 group，子项走 channel。
 *
 * 需要外部变量：
 * @var list<array{key:string,title:string,channels:list<array<string,mixed>>}> $navGroups
 * @var string $navUrl     基础地址，如 /admin/articles
 * @var string $navActive  当前精确筛选的栏目号，空串表示没有
 * @var string $navGroup   当前按一级栏目整组筛选的栏目号，空串表示没有
 * @var array<string, string> $navQuery 需要保留的其它查询参数（稿库、关键词等）
 * @var string $navAllLabel 「全部」项的文案
 * @var array<string, int> $navCounts  各栏目稿件数，口径与当前稿库／关键词筛选一致；空数组则不显示数字
 */

declare(strict_types=1);

$navQuery = $navQuery ?? [];
$navCounts = $navCounts ?? [];
$navActive = (string) ($navActive ?? '');
$navGroup = (string) ($navGroup ?? '');
$showCounts = $navCounts !== [];

$link = static function (array $override) use ($navUrl, $navQuery): string {
    $params = array_filter(
        array_merge($navQuery, $override),
        static fn ($value): bool => (string) $value !== ''
    );
    return $navUrl . ($params === [] ? '' : '?' . http_build_query($params));
};
$count = static fn (string $type): int => (int) ($navCounts[$type] ?? 0);
$isAll = $navActive === '' && $navGroup === '';
?>
<nav class="channel-nav" aria-label="栏目导航">
  <a class="channel-chip<?= $isAll ? ' active' : '' ?>"
     href="<?= hechi_e($link(['channel' => '', 'group' => ''])) ?>"
     <?= $isAll ? 'aria-current="page"' : '' ?>><?= hechi_e($navAllLabel ?? '全部') ?></a>

  <?php foreach ($navGroups as $group): ?>
    <?php
    $members = $group['channels'];
    $key = (string) $group['key'];
    // 只有一个栏目的组直接当普通 chip 用，不再套一层
    $isMulti = count($members) > 1;
    // 当前选中的栏目落在这个组里（或整组被选中）时展开，子栏目才出现
    $containsActive = $navGroup === $key;
    foreach ($members as $member) {
        if ((string) $member['type_code'] === $navActive) {
            $containsActive = true;
        }
    }
    ?>

    <?php if (!$isMulti): ?>
      <?php $channel = $members[0]; $type = (string) $channel['type_code']; ?>
      <a class="channel-chip<?= $navActive === $type ? ' active' : '' ?>"
         href="<?= hechi_e($link(['channel' => $type, 'group' => ''])) ?>"
         title="<?= hechi_e($channel['name'] . ' · ' . $channel['inner_name'] . '（' . $type . '）') ?>"
         <?= $navActive === $type ? 'aria-current="page"' : '' ?>><?= hechi_e($channel['inner_name']) ?><?php if ($showCounts): ?><span class="chip-num"><?= $count($type) ?></span><?php endif; ?></a>
    <?php else: ?>
      <?php
      $groupCount = 0;
      foreach ($members as $member) {
          $groupCount += $count((string) $member['type_code']);
      }
      // 母栏目是开关：点它 = 按整组筛选并把子栏目展开；已经是整组筛选时再点一次 = 收起子栏目，回到「全部栏目」。
      $groupIsFilter = $navGroup === $key;
      $groupHref = $groupIsFilter
          ? $link(['channel' => '', 'group' => ''])
          : $link(['channel' => '', 'group' => $key]);
      $groupTitle = (string) $group['title'] . '（' . count($members) . ' 个子栏目）'
          . ($groupIsFilter ? '，再点一次收起子栏目' : '，点开按整个一级栏目筛选并展开子栏目');
      ?>
      <a class="channel-chip channel-chip--group<?= $containsActive ? ' is-open' : '' ?><?= $groupIsFilter ? ' active' : '' ?>"
         href="<?= hechi_e($groupHref) ?>"
         aria-expanded="<?= $containsActive ? 'true' : 'false' ?>"
         title="<?= hechi_e($groupTitle) ?>">
        <?= hechi_e((string) $group['title']) ?><?php if ($showCounts): ?><span class="chip-num"><?= $groupCount ?></span><?php endif; ?>
      </a>
      <?php if ($containsActive): ?>
        <span class="channel-group">
          <?php foreach ($members as $member): ?>
            <?php $type = (string) $member['type_code']; ?>
            <a class="channel-chip channel-chip--child<?= $navActive === $type ? ' active' : '' ?>"
               href="<?= hechi_e($link(['channel' => $type, 'group' => ''])) ?>"
               title="<?= hechi_e($member['name'] . ' · ' . $member['inner_name'] . '（' . $type . '）') ?>"
               <?= $navActive === $type ? 'aria-current="page"' : '' ?>><?= hechi_e($member['inner_name']) ?><?php if ($showCounts): ?><span class="chip-num"><?= $count($type) ?></span><?php endif; ?></a>
          <?php endforeach; ?>
        </span>
      <?php endif; ?>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>
