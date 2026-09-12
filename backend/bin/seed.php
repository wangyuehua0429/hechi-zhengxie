#!/usr/bin/env php
<?php

/**
 * 把阶段 A 的静态快照灌进数据库（栏目、稿件、正文图片、附件、首页模块）。
 * 幂等：可反复执行，按主键覆盖。
 *
 *   php backend/bin/seed.php
 *   php backend/bin/seed.php --home-only     只跑迁移 + 回填首页四大类配置（不动稿件与栏目）
 *   php backend/bin/seed.php --snapshots=/path/to/frontend/home/data
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use HechiZx\Support\Db;
use HechiZx\Support\Json;

$options = getopt('', ['snapshots::', 'home-only', 'force', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "用法：php backend/bin/seed.php [--snapshots=<目录>] [--home-only] [--force]\n"
        . "  --home-only  只执行数据库迁移并回填首页四大类（模块 / 头条轮换 / 横幅）的初始配置，\n"
        . "               不重灌栏目与稿件；老库升级到 003 后用这条。\n"
        . "  --force      库里已有快照之外的稿件时仍然按快照重灌（会把已迁移内容的正文与\n"
        . "               公开范围覆盖回样例，并清空重写栏目归属，请先备份）。\n");
    exit(0);
}

$config = hechi_config();
$snapshotDir = rtrim((string) ($options['snapshots'] ?? $config->get('paths.snapshots')), '/');
$siteId = (int) $config->get('site.site_id', 1);
$now = date('Y-m-d H:i:s');

$db = new Db((array) $config->get('db'));

// --home-only：老库升级用。先补迁移，再只回填首页四大类的初始配置，不碰稿件与栏目。
if (isset($options['home-only'])) {
    $migrator = new HechiZx\Support\Migrator($db, (string) $config->get('paths.migrations') . '/' . $db->driver());
    $applied = $migrator->run();
    fwrite(STDOUT, $applied === []
        ? "数据库结构已是最新，无需迁移。\n"
        : '本次执行迁移：' . implode('、', $applied) . "\n");

    $home = [];
    $homeFile = $snapshotDir . '/home.json';
    if (is_file($homeFile)) {
        $decoded = json_decode((string) file_get_contents($homeFile), true);
        $home = is_array($decoded) ? $decoded : [];
    }
    fwrite(STDOUT, seedHomeConfig($db, $siteId, $home, $now));
    fwrite(STDOUT, "提示：首页模块的绑定关系来自 " . $homeFile . "，可在后台「其他栏目」页随时调整。\n");
    exit(0);
}

/**
 * 按主键判断插入或更新：SQLite 与 MySQL 的 upsert 语法不同，这里走可移植写法。
 *
 * @param array<string, mixed> $where
 * @param array<string, mixed> $values
 */
function upsert(Db $db, string $table, array $where, array $values): void
{
    $whereSql = implode(' AND ', array_map(static fn (string $k): string => $k . ' = :w_' . $k, array_keys($where)));
    $exists = $db->selectOne('SELECT 1 AS ok FROM ' . $table . ' WHERE ' . $whereSql, prefixKeys($where, 'w_'));

    if ($exists !== null) {
        $sets = implode(', ', array_map(static fn (string $k): string => $k . ' = :v_' . $k, array_keys($values)));
        $db->execute(
            'UPDATE ' . $table . ' SET ' . $sets . ' WHERE ' . $whereSql,
            prefixKeys($values, 'v_') + prefixKeys($where, 'w_')
        );
        return;
    }

    $columns = array_merge(array_keys($where), array_keys($values));
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES ('
        . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')';
    $db->execute($sql, $where + $values);
}

/** @param array<string, mixed> $params @return array<string, mixed> */
function prefixKeys(array $params, string $prefix): array
{
    $out = [];
    foreach ($params as $key => $value) {
        $out[$prefix . $key] = $value;
    }
    return $out;
}

/** @return array<string, mixed> */
function readSnapshot(string $dir, string $file): array
{
    $data = Json::readFile($dir . '/' . $file);
    return is_array($data) ? $data : [];
}

$home = readSnapshot($snapshotDir, 'home.json');
$channelData = readSnapshot($snapshotDir, 'channel.json');
$articleData = readSnapshot($snapshotDir, 'article.json');
/** @var list<array<string, mixed>> $channels */
$channels = $channelData['channels'] ?? [];
/** @var list<array<string, mixed>> $articles */
$articles = $articleData['articles'] ?? [];

// 安全闸：库里如果已经有快照之外的稿件，说明跑过迁移或有编辑在写稿，这时按快照重灌
// 会把正文、公开范围与栏目归属覆盖回样例（实测会把 4,500+ 条归属砍回 400 多条）。
$snapshotIds = [];
foreach ($articles as $article) {
    $snapshotIds[(string) ($article['id'] ?? '')] = true;
}
$unknown = 0;
$existingTotal = 0;
foreach ($db->select('SELECT article_id FROM cms_article WHERE site_id = :site', ['site' => $siteId]) as $row) {
    $existingTotal++;
    if (!isset($snapshotIds[(string) $row['article_id']])) {
        $unknown++;
    }
}
if ($unknown > 0 && !isset($options['force'])) {
    fwrite(STDERR, "已停止：库里已有 {$existingTotal} 篇稿件，其中 {$unknown} 篇不在样例快照里（多为迁移导入或后台新建）。\n"
        . "继续跑会把样例覆盖到同号稿件上，并清空重写栏目归属。\n"
        . "如果确实要按样例重灌，先备份数据库再加 --force；只想补首页四大类配置请用 --home-only。\n");
    exit(1);
}

// 1) 站点
$meta = is_array($home['meta'] ?? null) ? $home['meta'] : [];
upsert($db, 'sys_site', ['code' => (string) $config->get('site.code', 'main')], [
    'name'       => (string) ($meta['title'] ?? $config->get('site.name')),
    'domain'     => (string) ($meta['domain'] ?? $config->get('site.domain')),
    'owner'      => (string) ($meta['owner'] ?? ''),
    'icp'        => (string) ($meta['icp'] ?? ''),
    'theme'      => 'default',
    'status'     => 'enabled',
    'updated_at' => $now,
]);

// 2) 栏目
foreach ($channels as $channelIndex => $channel) {
    upsert($db, 'sys_channel', ['site_id' => $siteId, 'type_code' => (string) $channel['type']], [
        'parent_type'   => (string) ($channel['columnId'] ?? ''),
        'slug'          => (string) ($channel['slug'] ?? ''),
        'name'          => (string) ($channel['name'] ?? ''),
        'inner_name'    => (string) ($channel['inner'] ?? ''),
        'intro'         => (string) ($channel['intro'] ?? ''),
        'layout'        => (string) ($channel['layout'] ?? 'list'),
        'total_count'   => (int) ($channel['total'] ?? 0),
        'home_sourced'  => !empty($channel['homeSourced']) ? 1 : 0,
        // sort_no 就是前端栏目顺序（channel.json 的排列，与主导航一致），后台按它排
        'sort_no'       => $channelIndex + 1,
        'status'        => 'published',
        'siblings_json' => Json::encode($channel['siblings'] ?? []),
        'counties_json' => isset($channel['counties']) ? Json::encode($channel['counties']) : null,
        'note'          => (string) ($channel['note'] ?? ''),
        'feature_json'  => isset($channel['feature']) ? Json::encode($channel['feature']) : null,
        'updated_at'    => $now,
    ]);
}

// 3) 稿件：先收列表项（含无正文的），再用正文覆盖
//    说明：视频、专题等栏目取自首页模块，列表项的 id 是 <栏目号>-<序号> 这类原型合成值，
//    不是旧库稿件号，因此不进 cms_article；这些栏目的列表由接口从 cms_home_block 现算。
$rows = [];
$links = [];
$primary = [];
foreach ($channels as $channel) {
    $position = 0;
    foreach (($channel['list'] ?? []) as $item) {
        $id = (string) $item['id'];
        if (!ctype_digit($id)) {
            continue;
        }
        $position++;
        $channelType = (string) $channel['type'];

        if (!isset($rows[$id])) {
            $rows[$id] = [
                'channel_type' => $channelType,
                'title'        => (string) $item['title'],
                'published_at' => (string) ($item['datetime'] ?? $item['date'] ?? ''),
                'source'       => (string) ($item['source'] ?? ''),
                'views'        => (string) ($item['views'] ?? '0'),
                'thumb'        => (string) ($item['img'] ?? ''),
                'role'         => (string) ($item['role'] ?? ''),
                'has_body'     => 0,
            ];
        }

        $links[] = ['id' => (int) $id, 'channel' => $channelType, 'sort' => $position];
        if (!isset($primary[$id])) {
            $primary[$id] = $channelType;
        }
    }
}

$imageCount = 0;
$attachmentCount = 0;
foreach ($articles as $article) {
    $id = (string) $article['id'];
    $base = $rows[$id] ?? [
        'channel_type' => (string) $article['channelType'],
        'title'        => (string) $article['title'],
        'published_at' => (string) ($article['date'] ?? ''),
        'source'       => (string) ($article['source'] ?? ''),
        'views'        => (string) ($article['views'] ?? '0'),
        'thumb'        => '',
        'role'         => '',
    ];

    // 正文时间以列表项为准（列表带秒，正文只到分），避免列表时间被截断
    $rows[$id] = $base;
    $rows[$id]['has_body'] = 1;
    $rows[$id]['channel_type'] = (string) $article['channelType'];
    $primary[$id] = (string) $article['channelType'];
    $rows[$id]['subtitle'] = (string) ($article['subtitle'] ?? '');
    $rows[$id]['summary'] = (string) ($article['summary'] ?? '');
    $rows[$id]['content_html'] = (string) ($article['content'] ?? '');
    $rows[$id]['author'] = (string) ($article['author'] ?? '');
    $rows[$id]['editor'] = (string) ($article['editor'] ?? '');
}

foreach ($rows as $id => $row) {
    upsert($db, 'cms_article', ['article_id' => (int) $id], [
        'site_id'      => $siteId,
        'channel_type' => $row['channel_type'],
        'title'        => $row['title'],
        'subtitle'     => (string) ($row['subtitle'] ?? ''),
        'summary'      => (string) ($row['summary'] ?? ''),
        'content_html' => (string) ($row['content_html'] ?? ''),
        'source'       => $row['source'],
        'author'       => (string) ($row['author'] ?? ''),
        'editor'       => (string) ($row['editor'] ?? ''),
        'published_at' => $row['published_at'],
        'views'        => $row['views'],
        'role'         => (string) ($row['role'] ?? ''),
        'thumb'        => (string) ($row['thumb'] ?? ''),
        'has_body'     => (int) $row['has_body'],
        'status'       => 'published',
        'public_scope' => 'public',
        'updated_at'   => $now,
    ]);
}

// 4) 栏目归属：清掉重灌，保证重跑结果一致
$db->execute('DELETE FROM cms_article_channel WHERE site_id = :site', ['site' => $siteId]);
$linked = [];
foreach ($links as $link) {
    $key = $link['id'] . '|' . $link['channel'];
    if (isset($linked[$key])) {
        continue;
    }
    $linked[$key] = true;
    upsert(
        $db,
        'cms_article_channel',
        ['article_id' => $link['id'], 'site_id' => $siteId, 'channel_type' => $link['channel']],
        [
            'sort_no'    => $link['sort'],
            'is_primary' => ($primary[(string) $link['id']] ?? '') === $link['channel'] ? 1 : 0,
        ]
    );
}
// 注：只出现在详情、没进任何栏目列表的稿件（如「机构设置」「政协章程」这类单页）
// 不补栏目归属——它们在旧站本来就不出现在栏目列表里，详情页靠 cms_article.channel_type 取面包屑。

// 5) 正文图片与附件（整篇覆盖，保证重跑结果一致）
foreach ($articles as $article) {
    $id = (int) $article['id'];
    $db->execute('DELETE FROM cms_article_image WHERE article_id = :id', ['id' => $id]);
    $db->execute('DELETE FROM cms_attachment WHERE article_id = :id', ['id' => $id]);

    foreach (array_values($article['images'] ?? []) as $index => $path) {
        $db->execute(
            'INSERT INTO cms_article_image (article_id, path, sort_no) VALUES (:id, :path, :sort)',
            ['id' => $id, 'path' => (string) $path, 'sort' => $index]
        );
        $imageCount++;
    }

    foreach (array_values($article['attachments'] ?? []) as $index => $file) {
        $db->execute(
            'INSERT INTO cms_attachment (article_id, name, url, ext, sort_no) VALUES (:id, :name, :url, :ext, :sort)',
            [
                'id'   => $id,
                'name' => (string) ($file['name'] ?? ''),
                'url'  => (string) ($file['url'] ?? ''),
                'ext'  => (string) ($file['ext'] ?? ''),
                'sort' => $index,
            ]
        );
        $attachmentCount++;
    }
}

// 6) 首页模块：按 home.json 顶层键整块存
$blockIndex = 0;
foreach ($home as $key => $payload) {
    upsert($db, 'cms_home_block', ['site_id' => $siteId, 'block_key' => (string) $key], [
        'sort_no'      => $blockIndex++,
        'payload_json' => Json::encode($payload),
        'updated_at'   => $now,
    ]);
}

fwrite(STDOUT, sprintf(
    "已灌入：栏目 %d 个、稿件 %d 篇（含正文 %d 篇）、栏目归属 %d 条、正文图片 %d 张、附件 %d 条、首页模块 %d 个\n",
    count($channels),
    count($rows),
    count($articles),
    count($linked),
    $imageCount,
    $attachmentCount,
    $blockIndex
));
fwrite(STDOUT, seedHomeConfig($db, $siteId, $home, $now));
fwrite(STDOUT, '数据源：' . $snapshotDir . "\n");

/**
 * 7) 首页四大类的初始配置：把阶段 A 快照里的绑定关系固化成可维护的配置。
 *
 * 只在目标表为空时写入（幂等）：这张表一旦有数据就说明后台已经接手维护，
 * 再跑 seed 不能把编辑改过的绑定、头条、横幅覆盖掉。
 *
 * @param array<string, mixed> $home
 */
function seedHomeConfig(Db $db, int $siteId, array $home, string $now): string
{
    $report = [];

    if ((int) $db->scalar('SELECT COUNT(*) FROM cms_home_section WHERE site_id = :s', ['s' => $siteId]) === 0) {
        // [模块键, 标题, scope, group_by, 更多链接, 取几条]
        $sections = [
            ['zxdt', '政协动态', ['tabs' => [
                ['channel' => '904', 'label' => '市政协动态'],
                ['channel' => '903', 'label' => '广西政协动态'],
                ['channel' => '902', 'label' => '全国政协动态'],
            ]], 'child', '', 10],
            ['zxMeeting', '政协会议', ['tabs' => [
                ['channel' => '404', 'label' => '其它会议'],
                ['channel' => '403', 'label' => '主席会议'],
                ['channel' => '402', 'label' => '常委会议'],
                ['channel' => '401', 'label' => '全体会议'],
            ]], 'child', '', 8],
            ['sxNews', '时政要闻', ['channels' => ['306']], '', 'https://www.gxhczx.gov.cn/news_list.php?id=306', 10],
            ['notice', '公告通知', ['channels' => ['302']], '', 'https://www.gxhczx.gov.cn/news_list.php?id=302', 4],
            ['bookCity', '网上书院', ['channels' => ['1301']], '', 'https://www.gxhczx.gov.cn/news_list.php?id=1301', 4],
            ['antiGang', '扫黑除恶', ['channels' => ['400']], '', 'https://www.gxhczx.gov.cn/news_list.php?id=400', 3],
            ['zwhWork', '专委会工作', ['channels' => ['308']], '', 'https://www.gxhczx.gov.cn/news_list.php?id=308', 9],
            ['partyGroups', '党派团体', ['parent' => '601'], '', 'https://www.gxhczx.gov.cn/news_list.php?id=601', 9],
            ['theory', '理论研究', ['channels' => ['317']], '', 'https://www.gxhczx.gov.cn/news_list.php?id=317', 9],
            ['imageNews', '图片新闻', ['channels' => ['314']], '', 'https://www.gxhczx.gov.cn/news_list.php?id=314', 8],
            ['scenery', '河池风光', ['channels' => ['901']], '', 'https://www.gxhczx.gov.cn/news_list.php?id=901', 7],
            ['memberWindow', '委员之窗', ['channels' => ['311']], '', 'https://www.gxhczx.gov.cn/news_list.php?id=311', 14],
            ['countyZx', '县（区）政协动态', ['channels' => ['906']], '', 'https://www.gxhczx.gov.cn/qy_list.php', 12],
        ];
        $blockOrder = array_flip(array_keys($home));
        $count = 0;
        foreach ($sections as [$key, $label, $scope, $groupBy, $moreUrl, $pageSize]) {
            $db->execute(
                'INSERT INTO cms_home_section
                   (section_key, site_id, label, scope_json, page_size, group_by, more_url, status, sort_no, created_at, updated_at)
                 VALUES (:key, :site, :label, :scope, :size, :group, :more, :status, :sort, :t, :t)',
                [
                    'key'    => $key,
                    'site'   => $siteId,
                    'label'  => $label,
                    'scope'  => Json::encode($scope),
                    'size'   => $pageSize,
                    'group'  => $groupBy,
                    'more'   => $moreUrl,
                    'status' => 'published',
                    'sort'   => (int) ($blockOrder[$key] ?? 99),
                    't'      => $now,
                ]
            );
            $count++;
        }
        $report[] = sprintf('首页模块 %d 个', $count);
    }

    if ((int) $db->scalar('SELECT COUNT(*) FROM cms_home_slide WHERE site_id = :s', ['s' => $siteId]) === 0) {
        $index = 0;
        foreach ((array) ($home['slides'] ?? []) as $slide) {
            if (!is_array($slide)) {
                continue;
            }
            $url = (string) ($slide['url'] ?? '');
            $articleId = preg_match('/[?&]id=(\d+)/', $url, $m) === 1 ? (int) $m[1] : 0;
            // 只有稿件库里真有这篇稿子才建立引用；否则按外链条目存原地址，
            // 免得引用了不存在的稿件号，轮播里这条直接消失。
            if ($articleId > 0 && $db->selectOne(
                'SELECT 1 AS ok FROM cms_article WHERE site_id = :site AND article_id = :id',
                ['site' => $siteId, 'id' => $articleId]
            ) === null) {
                $articleId = 0;
            }
            $db->execute(
                'INSERT INTO cms_home_slide
                   (site_id, article_id, title, summary, image_url, link_url, sort_no, status, created_at, updated_at)
                 VALUES (:site, :aid, :title, :summary, :img, :link, :sort, :status, :t, :t)',
                [
                    'site'    => $siteId,
                    'aid'     => $articleId,
                    'title'   => (string) ($slide['title'] ?? ''),
                    'summary' => (string) ($slide['summary'] ?? ''),
                    'img'     => (string) ($slide['img'] ?? ''),
                    // 外链条目保留原地址；引用稿件的条目留空，取稿件自身的详情页
                    'link'    => $articleId === 0 ? $url : '',
                    'sort'    => ++$index,
                    'status'  => 'published',
                    't'       => $now,
                ]
            );
        }
        $report[] = sprintf('头条轮换 %d 条', $index);
    }

    if ((int) $db->scalar('SELECT COUNT(*) FROM cms_home_banner WHERE site_id = :s', ['s' => $siteId]) === 0) {
        $banners = [
            ['hero-1', '提案填报系统', 'images/2026091102.jpg', 'channel.html?id=501'],
            ['hero-2', '协商在河池', 'images/2025030102.jpg', 'https://www.gxhczx.gov.cn/zl20220331/'],
            ['body-1', '政治协商', 'images/chatu.gif', ''],
            ['body-2', '党史学习教育专栏', 'images/head_i.jpg', 'https://www.gxhczx.gov.cn/zl20210331'],
            ['body-3', '政协干部队伍作风大查摆大整治活动专栏', 'images/zl320.jpg', 'https://www.gxhczx.gov.cn/news_list.php?id=320'],
            ['body-4', '协商在河池', 'images/xs.jpg', 'https://www.gxhczx.gov.cn/zl20220331/'],
            ['body-5', '联站到家进室办实事 助力乡村振兴委员行', 'images/fupin.jpg', 'https://www.gxhczx.gov.cn/fupin'],
        ];
        $index = 0;
        foreach ($banners as [$slot, $title, $img, $link]) {
            $db->execute(
                'INSERT INTO cms_home_banner (site_id, slot_key, title, image_url, link_url, sort_no, status, created_at, updated_at)
                 VALUES (:site, :slot, :title, :img, :link, :sort, :status, :t, :t)',
                [
                    'site'   => $siteId,
                    'slot'   => $slot,
                    'title'  => $title,
                    'img'    => $img,
                    'link'   => $link,
                    'sort'   => ++$index,
                    'status' => 'published',
                    't'      => $now,
                ]
            );
        }
        $report[] = sprintf('站内横幅 %d 个槽位', $index);
    }

    return $report === [] ? "首页四大类配置已存在，未覆盖。\n" : '首页配置：' . implode('、', $report) . "。\n";
}
