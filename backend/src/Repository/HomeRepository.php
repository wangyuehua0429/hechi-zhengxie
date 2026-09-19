<?php

declare(strict_types=1);

namespace HechiZx\Repository;

use HechiZx\Support\Db;
use HechiZx\Support\Json;
use HechiZx\Content\BodyNormalizer;
use HechiZx\Content\PublicScope;
use HechiZx\Publish\StaticPaths;

/**
 * 首页数据仓储。
 *
 * 首页分四大类（导航栏目 / 头条轮换 / 其他栏目 / 站内横幅），数据来自两处：
 *   1. cms_home_block：早期整块快照，仍在用的有 meta / nav / leaders / ranking / links /
 *      videos / topic 以及委员之窗、县区政协里非稿件的部分；
 *   2. cms_home_section + cms_home_slide + cms_home_banner：后台可维护的稿件模块、头条轮换与横幅。
 *
 * 稿件模块的列表按「库内稿件优先、快照兜底」组装：先取绑定栏目下已发布稿件
 *（置顶在前、再按栏目内排序、再按发布时间），不足 page_size 时用同名快照里的历史条目补足，
 * 这样编辑发稿立刻出现在首页最前，同时不会因为稿件库里暂时没有而那些模块变空。
 *
 * 兜底条目同样受公开口径约束：只有「已发布 + public」的稿件才允许补进来。快照块里的
 * 老条目多为超出公开年限的归档稿（2,145 篇只留后台、对公众不可见），不加这道校验会
 * 绕过 sectionItems 的 SQL 过滤重新露到首页。
 */
final class HomeRepository
{
    /** 稿件模块的输出形状：tabs=按子栏目分标签；member=委员之窗；county=县（区）政协 */
    private const SECTION_SHAPES = [
        'zxdt'         => 'tabs',
        'zxMeeting'    => 'tabs',
        'memberWindow' => 'member',
        'countyZx'     => 'county',
    ];

    /** 委员之窗的图片滚动条固定取前几张带图稿件（与前端既有版式一致） */
    private const MEMBER_GALLERY_SIZE = 4;

    /** 003 迁移是否已应用（没应用时首页退回纯快照，绝不因为缺表把整站打挂） */
    private ?bool $homeTablesReady = null;

    /** 稿件公开口径的查库缓存：快照兜底与出口过滤共用，避免同一个 id 反复查库 */
    private array $articleVisibilityCache = [];

    /** 栏目静态页地址缓存：栏目号 => /channel/<目录名>/（对外唯一地址，规则见 Publish\StaticPaths） */
    private ?array $channelStaticPaths = null;

    public function __construct(private Db $db, private int $siteId)
    {
    }

    /**
     * 首页数据：前端按顶层键取用，键名与结构必须与阶段 A 的快照一致。
     *
     * @param list<string>|null $keys 只取指定模块（栏目页取视频/专题列表时用）
     * @return array<string, mixed>
     */
    public function blocks(?array $keys = null): array
    {
        $blocks = $this->snapshotBlocks($keys);

        foreach ($this->sectionRows() as $section) {
            $key = (string) $section['section_key'];
            if ($keys !== null && !in_array($key, $keys, true)) {
                continue;
            }
            if ((string) $section['status'] !== 'published') {
                // 后台显式下线的模块：连快照兜底一起撤掉，前台整块不显示。
                // （快照兜底只服务于“库里没有这个模块配置”的情况，不能把下线覆盖掉。）
                unset($blocks[$key]);
                continue;
            }
            $blocks[$key] = $this->buildSection($section, $blocks[$key] ?? null);
        }

        if ($keys === null || in_array('slides', $keys, true)) {
            $slides = $this->homeSlides();
            if ($slides !== []) {
                $blocks['slides'] = $slides;
            } elseif ($this->homeTablesReady() && $this->slideRows() !== []) {
                // 库里配过头条、但此刻一条都没有上线：给空数组，让前台把轮播收起来。
                // 不能回落到快照里那 6 条样例——那是“没跑 003 迁移”时的兜底，
                // 一旦回落，后台的“下线”就等于没生效。
                $blocks['slides'] = [];
            }
        }
        // 没跑 003 迁移时不返回 banners 键，前端保持 index.html 里写死的兜底内容
        if (($keys === null || in_array('banners', $keys, true)) && $this->homeTablesReady()) {
            $blocks['banners'] = $this->homeBanners();
        }

        // 导航项里的旧站栏目地址在出口换成本站静态栏目页地址（API 与静态页共用同一份结果）
        if (isset($blocks['nav']) && is_array($blocks['nav'])) {
            $blocks['nav'] = $this->localNavLinks($blocks['nav']);
        }

        // 先批量预热稿件可见性：出口过滤要按稿件逐条判断，逐条查库时首页 160 条链接就是
        // 160 次 SELECT（实测占 /api/v1/home 耗时的绝大部分，2026-09-17 审查 P2）
        $this->preloadArticleVisibility($blocks);

        return $this->pruneNonPublicArticleLinks($blocks);
    }

    /**
     * 003 迁移是否已应用：首页三大类依赖 cms_home_section / cms_home_slide / cms_home_banner。
     * 未应用时首页退回快照（表现与改版前一致），后台四类页给出「先执行迁移」的提示。
     */
    public function homeTablesReady(): bool
    {
        if ($this->homeTablesReady === null) {
            try {
                $this->db->scalar('SELECT 1 FROM cms_home_section LIMIT 1');
                $this->homeTablesReady = true;
            } catch (\PDOException $e) {
                $this->homeTablesReady = false;
            }
        }
        return $this->homeTablesReady;
    }

    /**
     * 首页模块配置（后台维护用，含未上线的）。
     *
     * @return list<array<string, mixed>>
     */
    public function sectionRows(): array
    {
        if (!$this->homeTablesReady()) {
            return [];
        }
        return $this->db->select(
            'SELECT * FROM cms_home_section WHERE site_id = :site ORDER BY sort_no ASC, section_key ASC',
            ['site' => $this->siteId]
        );
    }

    /** @return array<string, mixed>|null */
    public function sectionFind(string $key): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_home_section WHERE site_id = :site AND section_key = :key',
            ['site' => $this->siteId, 'key' => $key]
        );
    }

    /**
     * 头条轮换条目；后台列表要看到已下线的，所以默认全部返回。
     *
     * @return list<array<string, mixed>>
     */
    public function slideRows(bool $publishedOnly = false): array
    {
        if (!$this->homeTablesReady()) {
            return [];
        }
        $sql = 'SELECT * FROM cms_home_slide WHERE site_id = :site';
        if ($publishedOnly) {
            $sql .= " AND status = 'published'";
        }
        $sql .= ' ORDER BY sort_no ASC, slide_id ASC';
        return $this->db->select($sql, ['site' => $this->siteId]);
    }

    /** @return array<string, mixed>|null */
    public function slideFind(int $id): ?array
    {
        if (!$this->homeTablesReady()) {
            return null;
        }
        return $this->db->selectOne(
            'SELECT * FROM cms_home_slide WHERE site_id = :site AND slide_id = :id',
            ['site' => $this->siteId, 'id' => $id]
        );
    }

    /**
     * 站内横幅：按槽位归组，返回槽位 => 条目列表。
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function homeBanners(): array
    {
        if (!$this->homeTablesReady()) {
            return [];
        }
        $rows = $this->db->select(
            "SELECT slot_key, title, image_url, link_url FROM cms_home_banner
             WHERE site_id = :site AND status = 'published'
             ORDER BY sort_no ASC, banner_id ASC",
            ['site' => $this->siteId]
        );
        $out = [];
        foreach ($rows as $row) {
            $slot = (string) $row['slot_key'];
            $out[$slot][] = [
                'img'   => (string) $row['image_url'],
                'url'   => (string) $row['link_url'],
                'title' => (string) $row['title'],
            ];
        }
        return $out;
    }

    /**
     * 横幅槽位表（后台维护用，含未上线的）。
     *
     * @return list<array<string, mixed>>
     */
    public function bannerRows(): array
    {
        if (!$this->homeTablesReady()) {
            return [];
        }
        return $this->db->select(
            'SELECT * FROM cms_home_banner WHERE site_id = :site ORDER BY sort_no ASC, banner_id ASC',
            ['site' => $this->siteId]
        );
    }

    /** @return array<string, mixed>|null */
    public function bannerFind(int $id): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_home_banner WHERE site_id = :site AND banner_id = :id',
            ['site' => $this->siteId, 'id' => $id]
        );
    }

    /**
     * 读一个首页块的原始快照（首页导航条维护用）。
     */
    public function block(string $key): mixed
    {
        $json = $this->db->scalar(
            'SELECT payload_json FROM cms_home_block WHERE site_id = :site AND block_key = :key',
            ['site' => $this->siteId, 'key' => $key]
        );
        return is_string($json) ? Json::decode($json, null) : null;
    }

    /**
     * 写回一个首页块；块不存在时插到末尾。
     */
    public function saveBlock(string $key, mixed $payload): void
    {
        $now = $this->db->now();
        $exists = $this->db->selectOne(
            'SELECT block_id FROM cms_home_block WHERE site_id = :site AND block_key = :key',
            ['site' => $this->siteId, 'key' => $key]
        );
        if ($exists !== null) {
            $this->db->execute(
                'UPDATE cms_home_block SET payload_json = :payload, updated_at = :t
                 WHERE site_id = :site AND block_key = :key',
                ['payload' => Json::encode($payload), 't' => $now, 'site' => $this->siteId, 'key' => $key]
            );
            return;
        }
        $next = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_no), 0) + 1 FROM cms_home_block WHERE site_id = :site',
            ['site' => $this->siteId]
        );
        $this->db->execute(
            'INSERT INTO cms_home_block (site_id, block_key, sort_no, payload_json, updated_at)
             VALUES (:site, :key, :sort, :payload, :t)',
            ['site' => $this->siteId, 'key' => $key, 'sort' => $next, 'payload' => Json::encode($payload), 't' => $now]
        );
    }

    // ------------------------------------------------------------ 内部实现

    /**
     * cms_home_block 的原样快照。
     *
     * @param list<string>|null $keys
     * @return array<string, mixed>
     */
    private function snapshotBlocks(?array $keys): array
    {
        $rows = $this->db->select(
            'SELECT block_key, payload_json FROM cms_home_block WHERE site_id = :site ORDER BY sort_no ASC, block_id ASC',
            ['site' => $this->siteId]
        );

        $blocks = [];
        foreach ($rows as $row) {
            $key = (string) $row['block_key'];
            if ($keys !== null && !in_array($key, $keys, true)) {
                continue;
            }
            $blocks[$key] = Json::decode($row['payload_json'], null);
        }
        return $blocks;
    }

    /**
     * 组装一个稿件模块：形状沿用快照，内容=库内稿件 + 快照兜底。
     *
     * @param array<string, mixed> $section
     * @param mixed $snapshot 同名快照（可能为 null）
     * @return array<string, mixed>
     */
    private function buildSection(array $section, mixed $snapshot): array
    {
        $key = (string) $section['section_key'];
        $scope = Json::decode((string) $section['scope_json'], []);
        $scope = is_array($scope) ? $scope : [];
        $pageSize = max(1, (int) $section['page_size']);
        $shape = self::SECTION_SHAPES[$key] ?? 'list';

        // 1) 多标签模块：每个标签一个栏目
        if ($shape === 'tabs') {
            $snapshotTabs = is_array($snapshot['tabs'] ?? null) ? $snapshot['tabs'] : [];
            $tabs = is_array($scope['tabs'] ?? null) ? $scope['tabs'] : [];
            if ($tabs === []) {
                foreach ((array) ($scope['channels'] ?? []) as $channel) {
                    $tabs[] = ['channel' => (string) $channel, 'label' => ''];
                }
            }
            $out = [];
            foreach ($tabs as $index => $tab) {
                $channel = (string) ($tab['channel'] ?? '');
                if ($channel === '') {
                    continue;
                }
                $fallback = is_array($snapshotTabs[$index]['items'] ?? null) ? $snapshotTabs[$index]['items'] : [];
                $out[] = [
                    'title' => (string) ($tab['label'] ?? '') !== ''
                        ? (string) $tab['label']
                        : $this->channelName($channel),
                    'url'   => $this->channelUrl($channel),
                    'items' => $this->sectionItems([$channel], $pageSize, $fallback),
                ];
            }
            return ['tabs' => $out];
        }

        $types = $this->scopeChannels($scope);

        // 2) 委员之窗：列表与图片滚动条都用稿件，其余字段留在快照里
        if ($shape === 'member') {
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $list = $this->sectionItems($types, $pageSize, is_array($snapshot['list'] ?? null) ? $snapshot['list'] : []);
            $gallery = [];
            foreach ($list as $item) {
                if (count($gallery) >= self::MEMBER_GALLERY_SIZE) {
                    break;
                }
                if ((string) ($item['img'] ?? '') !== '') {
                    $gallery[] = $item;
                }
            }
            $snapshot['list'] = $list;
            $snapshot['gallery'] = $gallery !== []
                ? $gallery
                : (is_array($snapshot['gallery'] ?? null) ? $snapshot['gallery'] : []);
            return $snapshot;
        }

        // 3) 县（区）政协：只换动态列表，地图与栏目标题留在快照里
        if ($shape === 'county') {
            $snapshot = is_array($snapshot) ? $snapshot : [];
            $snapshot['dynamic'] = $this->sectionItems(
                $types,
                $pageSize,
                is_array($snapshot['dynamic'] ?? null) ? $snapshot['dynamic'] : []
            );
            return $snapshot;
        }

        // 4) 普通列表
        return $this->sectionItems($types, $pageSize, is_array($snapshot) ? $snapshot : []);
    }

    /**
     * 取一组栏目下的稿件列表，不足 page_size 时用快照条目补足。
     *
     * @param list<string> $types
     * @param list<array<string, mixed>> $fallback
     * @return list<array<string, mixed>>
     */
    private function sectionItems(array $types, int $limit, array $fallback = []): array
    {
        $types = array_values(array_filter($types, static fn (string $type): bool => $type !== ''));
        $items = [];
        $seen = [];

        if ($types !== []) {
            $placeholders = [];
            $params = ['site' => $this->siteId];
            foreach ($types as $index => $type) {
                $placeholders[] = ':ch' . $index;
                $params['ch' . $index] = $type;
            }
            $rows = $this->db->select(
                'SELECT a.*, ac.channel_type AS link_channel, ac.is_top AS link_top, ac.sort_no AS link_sort,
                        ac.is_highlight, ac.badge_text
                 FROM cms_article_channel ac
                 JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
                 WHERE ac.site_id = :site AND a.status = :status AND ' . PublicScope::sql('a') . '
                   AND ac.channel_type IN (' . implode(', ', $placeholders) . ')
                 ORDER BY ac.is_top DESC, ac.sort_no ASC, a.published_at DESC, a.article_id DESC',
                $params + ['status' => 'published']
            );
            foreach ($rows as $row) {
                $id = (string) $row['article_id'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                if (count($items) >= $limit) {
                    continue;
                }
                $item = ChannelRepository::mapListItem($row);
                // 首页模块的展示标记：首页管理里设置的高亮（正红加粗）与徽标
                $item['is_highlight'] = (int) ($row['is_highlight'] ?? 0);
                $item['badge'] = (string) ($row['badge_text'] ?? '');
                $items[] = $item;
            }
        }

        if (count($items) >= $limit || $fallback === []) {
            return $items;
        }

        // 快照兜底：同一篇稿件不重复出现；库里的条目缺图/缺日期时用快照补齐
        $fallbackById = [];
        foreach ($fallback as $item) {
            $fid = $this->itemId($item);
            if ($fid !== '') {
                $fallbackById[$fid] = $item;
            }
        }
        foreach ($items as $index => $item) {
            $fid = $this->itemId($item);
            $source = $fid === '' ? null : ($fallbackById[$fid] ?? null);
            if ($source === null) {
                continue;
            }
            foreach (['img', 'date', 'source', 'views'] as $field) {
                if ((string) ($items[$index][$field] ?? '') === '' && (string) ($source[$field] ?? '') !== '') {
                    $items[$index][$field] = $source[$field];
                }
            }
        }
        foreach ($fallback as $item) {
            if (count($items) >= $limit) {
                break;
            }
            $fid = $this->itemId($item);
            if ($fid !== '' && isset($seen[$fid])) {
                continue;
            }
            // 带稿件号的兜底条目必须先在库里确认是「已发布 + 公开」；归档稿（超出公开
            // 年限只留后台）与已下线/删除稿一律不补，避免快照把非公开内容带回首页。
            if ($fid !== '' && !$this->isPublicArticle($fid)) {
                continue;
            }
            if ($fid !== '') {
                $seen[$fid] = true;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * 模块绑定的栏目集合；{"parent":"601"} 展开为该一级栏目及其全部已上线子栏目。
     *
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    private function scopeChannels(array $scope): array
    {
        $types = [];
        foreach ((array) ($scope['channels'] ?? []) as $channel) {
            $types[] = (string) $channel;
        }
        $parent = (string) ($scope['parent'] ?? '');
        if ($parent !== '') {
            $rows = $this->db->select(
                "SELECT type_code FROM sys_channel
                 WHERE site_id = :site AND status = 'published' AND (type_code = :parent OR parent_type = :parent)
                 ORDER BY sort_no ASC, channel_id ASC",
                ['site' => $this->siteId, 'parent' => $parent]
            );
            foreach ($rows as $row) {
                $types[] = (string) $row['type_code'];
            }
        }
        return array_values(array_unique(array_filter($types, static fn (string $type): bool => $type !== '')));
    }

    private function channelName(string $type): string
    {
        $name = $this->db->scalar(
            'SELECT inner_name FROM sys_channel WHERE site_id = :site AND type_code = :type',
            ['site' => $this->siteId, 'type' => $type]
        );
        return is_string($name) && $name !== '' ? $name : $type;
    }

    private function channelUrl(string $type): string
    {
        // 对外唯一地址是发布器产出的静态栏目页（2026-09-17 A1），取不到时退回旧写法
        return $this->channelStaticPaths()[$type] ?? ('/channel.html?id=' . $type);
    }

    /**
     * 栏目号 => 静态栏目页地址。与发布器、301 映射共用同一套规则（Publish\StaticPaths）。
     *
     * @return array<string, string>
     */
    private function channelStaticPaths(): array
    {
        if ($this->channelStaticPaths === null) {
            $rows = $this->db->select(
                // 与发布器、301 表同口径：只算已上线栏目，否则新建一个离线栏目复用同名 slug，
                // 首页给出的静态路径会与发布产物分叉（2026-09-17 独立评审 P2）
                "SELECT type_code, slug FROM sys_channel WHERE site_id = :site AND status = 'published'",
                ['site' => $this->siteId]
            );
            $this->channelStaticPaths = StaticPaths::channelPaths(array_map(
                static fn (array $row): array => ['type' => (string) $row['type_code'], 'slug' => (string) $row['slug']],
                $rows
            ));
        }
        return $this->channelStaticPaths;
    }

    /**
     * 首页导航（cms_home_block 的 nav 块）里的旧站栏目地址换成本站静态栏目页地址。
     * 换不掉的（真外站、区县子站、专题目录）保持原样，避免点了打不开。
     *
     * @param list<array<string, mixed>> $nav
     * @return list<array<string, mixed>>
     */
    private function localNavLinks(array $nav): array
    {
        $paths = $this->channelStaticPaths();
        foreach ($nav as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = (string) ($item['url'] ?? '');
            if ($url === '') {
                continue;
            }
            if (preg_match('~gxhczx\.gov\.cn/?$|gxhczx\.gov\.cn/index\.html$|/home\.php$~i', $url) === 1) {
                $nav[$index]['url'] = '/';
                continue;
            }
            if (str_contains($url, 'qy_list.php') && isset($paths['qy'])) {
                $nav[$index]['url'] = $paths['qy'];
                continue;
            }
            if (preg_match('~(?:news_list|news_list_about|cq_list)\.php\?[^"\']*?\bid=([A-Za-z0-9_]+)~', $url, $match) === 1
                && isset($paths[$match[1]])) {
                $nav[$index]['url'] = $paths[$match[1]];
            }
        }
        return $nav;
    }

    /**
     * 后台「其他栏目」页用：模块当前取到的**库内稿件**，按首页同一套顺序分组。
     *
     * 与首页组装的区别：这里带出每条稿件的栏目号与置顶标记，供页面上的上移／下移／置顶按钮使用；
     * 只列稿件表里有的条目——快照兜底的历史条目标不了顺序，由页面另行说明。
     *
     * @param array<string, mixed> $section
     * @return list<array{title:string, channel:string, rows:list<array<string,mixed>>}>
     */
    public function sectionAdminGroups(array $section): array
    {
        if (!$this->homeTablesReady()) {
            return [];
        }

        $scope = Json::decode((string) $section['scope_json'], []);
        $scope = is_array($scope) ? $scope : [];
        $pageSize = max(1, (int) $section['page_size']);
        $key = (string) $section['section_key'];
        $shape = self::SECTION_SHAPES[$key] ?? 'list';

        // 分标签模块：一个标签一个栏目，各自取 page_size 条
        if ($shape === 'tabs' || (string) $section['group_by'] === 'child') {
            $tabs = is_array($scope['tabs'] ?? null) ? $scope['tabs'] : [];
            if ($tabs === []) {
                foreach ((array) ($scope['channels'] ?? []) as $channel) {
                    $tabs[] = ['channel' => (string) $channel, 'label' => ''];
                }
            }
            $groups = [];
            foreach ($tabs as $tab) {
                $channel = (string) ($tab['channel'] ?? '');
                if ($channel === '') {
                    continue;
                }
                $groups[] = [
                    'title'   => (string) ($tab['label'] ?? '') !== '' ? (string) $tab['label'] : $this->channelName($channel),
                    'channel' => $channel,
                    'rows'    => $this->adminRows([$channel], $pageSize),
                ];
            }
            return $groups;
        }

        $types = $this->scopeChannels($scope);
        return [[
            'title'   => '',
            'channel' => $types[0] ?? '',
            'rows'    => $this->adminRows($types, $pageSize),
        ]];
    }

    /**
     * 取一组栏目下的稿件行（含栏目号与置顶标记），同一篇稿件只出现一次。
     *
     * @param list<string> $types
     * @return list<array<string,mixed>>
     */
    private function adminRows(array $types, int $limit): array
    {
        $types = array_values(array_filter($types, static fn (string $type): bool => $type !== ''));
        if ($types === []) {
            return [];
        }

        $placeholders = [];
        $params = ['site' => $this->siteId];
        foreach ($types as $index => $type) {
            $placeholders[] = ':ch' . $index;
            $params['ch' . $index] = $type;
        }
        $rows = $this->db->select(
            'SELECT a.article_id, a.title, a.thumb, a.published_at, a.channel_type AS primary_channel,
                    ac.channel_type AS link_channel, ac.is_top, ac.sort_no,
                    ac.is_highlight, ac.badge_text
             FROM cms_article_channel ac
             JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
             WHERE ac.site_id = :site AND a.status = :status AND ' . PublicScope::sql('a') . '
               AND ac.channel_type IN (' . implode(', ', $placeholders) . ')
             ORDER BY ac.is_top DESC, ac.sort_no ASC, a.published_at DESC, a.article_id DESC',
            $params + ['status' => 'published']
        );

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) $row['article_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (count($out) >= $limit) {
                continue;
            }
            // 排序与置顶按栏目生效：优先用稿件的主栏目，主栏目不在范围内就用命中的第一个
            $primary = (string) $row['primary_channel'];
            $channel = in_array($primary, $types, true) ? $primary : (string) $row['link_channel'];
            $out[] = [
                'id'           => $id,
                'title'        => (string) $row['title'],
                // 后台「其他栏目」预览也用同一个出口：库里仍有 http 旧站地址，
                // 而后台 CSP 的 img-src 是 'self' data:，不归一化会直接拦掉（与 ChannelRepository 一致）
                'img'          => BodyNormalizer::normalizeResourceUrl((string) $row['thumb']),
                'date'         => substr((string) $row['published_at'], 0, 16),
                'channel'      => $channel,
                'channel_name' => $this->channelName($channel),
                'is_top'       => (int) $row['is_top'],
                'is_highlight' => (int) ($row['is_highlight'] ?? 0),
                'badge'        => (string) ($row['badge_text'] ?? ''),
            ];
        }
        return $out;
    }

    /** 条目 id：库里条目是 id，快照条目要从 url 里抠 */
    private function itemId(mixed $item): string
    {
        if (!is_array($item)) {
            return '';
        }
        $id = (string) ($item['id'] ?? '');
        if ($id !== '') {
            return $id;
        }
        $url = (string) ($item['url'] ?? '');
        return preg_match('/[?&]id=(\d+)/', $url, $m) === 1 ? $m[1] : '';
    }

    /** 稿件是否「已发布 + 公开」：快照兜底条目的公开口径校验（结果按 id 缓存） */
    private function isPublicArticle(string $articleId): bool
    {
        return $this->articleVisibility($articleId) === 'public';
    }

    /**
     * 稿件在库里的对外可见性。
     *
     * @return string public=已发布且对外；private=在库但不能对外（撤回／草稿／回收站，以及
     *                年限口径下的归档稿）；missing=库里没有
     */
    private function articleVisibility(string $articleId): string
    {
        if ($articleId === '' || !ctype_digit($articleId)) {
            return 'missing';
        }
        if (!array_key_exists($articleId, $this->articleVisibilityCache)) {
            $row = $this->db->selectOne(
                'SELECT status, public_scope FROM cms_article WHERE site_id = :site AND article_id = :id',
                ['site' => $this->siteId, 'id' => $articleId]
            );
            if ($row === null) {
                $this->articleVisibilityCache[$articleId] = 'missing';
            } elseif ((string) $row['status'] === 'published' && PublicScope::allows((string) $row['public_scope'])) {
                $this->articleVisibilityCache[$articleId] = 'public';
            } else {
                $this->articleVisibilityCache[$articleId] = 'private';
            }
        }
        return $this->articleVisibilityCache[$articleId];
    }

    /**
     * 对外数据的出口归一：快照块（nav／leaders／topic／links 等）里的稿件地址，
     *   ① 库里存在但不能公开的（归档／撤回／草稿／回收站）——整条摘掉，避免首页与栏目页把
     *      只留后台的稿件当成可点链接露出去；
     *   ② 指向旧站稿件页的可公开稿件——改写成对外唯一地址 `/article/<id>.html`
     *      （2026-09-17 A1，接口与静态页共用同一份结果）。
     * 真外站地址、栏目地址与库里没有的旧稿件地址保持原样。
     */
    private function pruneNonPublicArticleLinks(mixed $node): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        $out = [];
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $value = $this->pruneNonPublicArticleLinks($value);
                if (isset($value['url'])) {
                    $url = (string) $value['url'];
                    if ($this->isPrivateArticleLink($url)) {
                        continue;
                    }
                    $id = $this->articleIdInUrl($url);
                    if ($id !== '' && str_contains($url, 'gxhczx.gov.cn')) {
                        $value['url'] = '/article/' . $id . '.html';
                    }
                }
                // 图片地址与正文同口径（本地有文件走 /uploads/legacy/…，其余走同源 /uploadfiles/…）：
                // 首页「友情链接」等处的 logo 仍是旧站 http 地址，不处理会在 https 站点上混合内容告警
                if (isset($value['img']) && is_string($value['img']) && $value['img'] !== '') {
                    $value['img'] = BodyNormalizer::normalizeResourceUrl($value['img']);
                }
            }
            $out[$key] = $value;
        }
        // 摘掉条目后要重排索引：PHP 数组带空洞会被 json_encode 成对象，
        // 前端对 leaders.viceChairmen 这类字段直接调 map()，拿到对象就报错。
        return array_is_list($node) ? array_values($out) : $out;
    }

    /** 地址是否指向「库里存在、但不能公开」的稿件 */
    private function isPrivateArticleLink(string $url): bool
    {
        $id = $this->articleIdInUrl($url);
        return $id !== '' && $this->articleVisibility($id) === 'private';
    }

    /**
     * 批量预热稿件可见性：递归收集出口数据里出现的稿件号，按批查回后写进缓存，
     * 后续 articleVisibility() 全部命中缓存，不再逐条查库。
     */
    private function preloadArticleVisibility(mixed $node): void
    {
        $ids = [];
        $this->collectArticleIds($node, $ids);
        $pending = [];
        foreach (array_keys($ids) as $id) {
            if (!array_key_exists($id, $this->articleVisibilityCache)) {
                $pending[] = $id;
            }
        }
        if ($pending === []) {
            return;
        }
        // SQLite 默认变量上限 999，分批留出余量
        foreach (array_chunk($pending, 400) as $chunk) {
            $placeholders = [];
            $params = ['site' => $this->siteId];
            foreach ($chunk as $index => $id) {
                $placeholders[] = ':id' . $index;
                $params['id' . $index] = $id;
            }
            foreach ($chunk as $id) {
                $this->articleVisibilityCache[$id] = 'missing';
            }
            $rows = $this->db->select(
                'SELECT article_id, status, public_scope FROM cms_article
                 WHERE site_id = :site AND article_id IN (' . implode(', ', $placeholders) . ')',
                $params
            );
            foreach ($rows as $row) {
                $this->articleVisibilityCache[(string) $row['article_id']] =
                    ((string) $row['status'] === 'published' && PublicScope::allows((string) $row['public_scope']))
                        ? 'public' : 'private';
            }
        }
    }

    /** @param array<string, bool> $ids 收集出口数据里出现的稿件号（键即稿件号） */
    private function collectArticleIds(mixed $node, array &$ids): void
    {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }
            if (isset($value['url'])) {
                $id = $this->articleIdInUrl((string) $value['url']);
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
            $this->collectArticleIds($value, $ids);
        }
    }

    /**
     * 从地址里取新站己方的稿件号：旧站详情脚本／旧站静态详情页／新站详情页与静态详情页。
     * 其它站点、栏目地址（news_list*.php?id=／channel.html?id=）一律返回空串。
     */
    private function articleIdInUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $url) === 1) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (!in_array($host, ['gxhczx.gov.cn', 'www.gxhczx.gov.cn'], true)) {
                return '';
            }
            $url = (string) parse_url($url, PHP_URL_PATH) . '?' . (string) parse_url($url, PHP_URL_QUERY);
        }
        if (preg_match('~(?:news_view|cq_view)\.php\?[^"\'\s]*\bid=(\d+)~i', $url, $match) === 1
            || preg_match('~news-view-(\d+)\.html~i', $url, $match) === 1
            || preg_match('~detail\.html\?[^"\'\s]*?\bid=(\d+)~i', $url, $match) === 1
            || preg_match('~^/?article/(\d+)\.html~i', $url, $match) === 1) {
            return (string) $match[1];
        }
        return '';
    }

    /**
     * 首屏头条轮换：引用稿件的取稿件字段，外链条目用自己填的。
     *
     * @return list<array<string, mixed>>
     */
    private function homeSlides(): array
    {
        $slides = [];
        foreach ($this->slideRows(true) as $row) {
            $articleId = (int) $row['article_id'];
            $article = $articleId > 0 ? $this->publishedArticle($articleId) : null;
            if ($articleId > 0 && $article === null) {
                // 稿件已下线/撤回/删除，轮播里不再露出来
                continue;
            }
            $title = trim((string) $row['title']);
            $summary = trim((string) $row['summary']);
            $image = trim((string) $row['image_url']);
            $link = trim((string) $row['link_url']);

            if ($article !== null) {
                $title = $title !== '' ? $title : (string) $article['title'];
                $summary = $summary !== '' ? $summary : (string) $article['summary'];
                $image = $image !== '' ? $image : $this->firstImage($article);
                $link = $link !== '' ? $link : '/article/' . $articleId . '.html';
            } else {
                // 外链条目里若是旧站稿件地址（news_view.php?id= / html/news-view-<id>.html），
                // 且这篇已在新库公开发布，就改指新站详情页，网站内部不再跳回旧站
                $link = $this->localArticleUrl($link);
            }

            $slides[] = [
                'title'   => $title,
                'url'     => $link,
                'img'     => $image,
                'summary' => $summary,
            ];
        }
        return $slides;
    }

    /**
     * 旧站稿件地址 → 新站详情页地址。对不上（稿件还没入库、或本来就是真外站）时原样返回，
     * 避免把轮播指向一个打不开的空页。
     */
    private function localArticleUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host !== '' && !in_array($host, ['gxhczx.gov.cn', 'www.gxhczx.gov.cn'], true)) {
            return $url;
        }
        $id = 0;
        if (preg_match('~(?:news_view|cq_view)\.php\?[^"\'#]*?\bid=(\d+)~', $url, $match) === 1
            || preg_match('~news-view-(\d+)\.html~', $url, $match) === 1) {
            $id = (int) $match[1];
        }
        if ($id <= 0) {
            return $url;
        }
        return $this->publishedArticle($id) !== null ? '/article/' . $id . '.html' : $url;
    }

    /** @return array<string, mixed>|null */
    private function publishedArticle(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT article_id, title, summary, thumb, content_html FROM cms_article
             WHERE site_id = :site AND article_id = :id AND status = 'published' AND " . PublicScope::sql(),
            ['site' => $this->siteId, 'id' => $id]
        );
    }

    /** 缩略图缺省时取正文第一张图 */
    private function firstImage(array $article): string
    {
        $thumb = trim((string) ($article['thumb'] ?? ''));
        if ($thumb !== '') {
            return $thumb;
        }
        $html = (string) ($article['content_html'] ?? '');
        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m) === 1) {
            return $m[1];
        }
        return '';
    }
}
