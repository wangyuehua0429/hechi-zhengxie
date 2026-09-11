<?php

/**
 * 栏目导航条（后台共用）：按前端主导航顺序列出「一级栏目 + 子栏目」，点击即切换。
 * 下拉菜单在这里不适用——栏目有 43 个、还分两级，做成横向导航条与前台观感一致。
 *
 * 需要外部变量：
 * @var list<array{key:string, title:string, channels:list<array<string,mixed>>}> $navGroups
 * @var string $navUrl   基础地址，如 /admin/articles
 * @var string $navActive 当前选中的栏目号，空串表示「全部」
 * @var array<string, string> $navQuery 需要保留的其它查询参数（状态、关键词、页大小等）
 * @var string $navAllLabel 全部项的文案
 */

declare(strict_types=1);

$navQuery = $navQuery ?? [];
$link = static function (string $type) use ($navUrl, $navQuery): string {
    $params = $navQuery;
    if ($type === '') {
        unset($params['channel']);
    } else {
        $params['channel'] = $type;
    }
    unset($params['page']);
    return $navUrl . ($params === [] ? '' : '?' . http_build_query($params));
};
?>
<nav class="channel-nav" aria-label="栏目导航">
  <a class="channel-chip<?= $navActive === '' ? ' active' : '' ?>"
     href="<?= hechi_e($link('')) ?>"><?= hechi_e($navAllLabel ?? '全部') ?></a>

  <?php foreach ($navGroups as $group): ?>
    <?php $multi = count($group['channels']) > 1; ?>
    <span class="channel-group<?= $multi ? ' channel-group--multi' : '' ?>">
      <?php if ($multi): ?><span class="channel-group-title"><?= hechi_e($group['title']) ?></span><?php endif; ?>
      <?php foreach ($group['channels'] as $channel): ?>
        <?php $type = (string) $channel['type_code']; ?>
        <a class="channel-chip<?= $navActive === $type ? ' active' : '' ?>"
           href="<?= hechi_e($link($type)) ?>"
           title="<?= hechi_e($channel['name'] . ' · ' . $channel['inner_name'] . '（' . $type . '）') ?>"
           <?= $navActive === $type ? 'aria-current="page"' : '' ?>><?= hechi_e($channel['inner_name']) ?></a>
      <?php endforeach; ?>
    </span>
  <?php endforeach; ?>
</nav>
