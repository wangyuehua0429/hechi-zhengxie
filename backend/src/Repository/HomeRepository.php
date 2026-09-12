<?php

declare(strict_types=1);

namespace HechiZx\Repository;

use HechiZx\Support\Db;
use HechiZx\Support\Json;

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

        return $blocks;
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
                 WHERE ac.site_id = :site AND a.status = :status AND a.public_scope = :scope
                   AND ac.channel_type IN (' . implode(', ', $placeholders) . ')
                 ORDER BY ac.is_top DESC, ac.sort_no ASC, a.published_at DESC, a.article_id DESC',
                $params + ['status' => 'published', 'scope' => 'public']
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
        return '/channel.html?id=' . $type;
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
             WHERE ac.site_id = :site AND a.status = :status AND a.public_scope = :scope
               AND ac.channel_type IN (' . implode(', ', $placeholders) . ')
             ORDER BY ac.is_top DESC, ac.sort_no ASC, a.published_at DESC, a.article_id DESC',
            $params + ['status' => 'published', 'scope' => 'public']
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
                'img'          => (string) $row['thumb'],
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
                $link = $link !== '' ? $link : 'detail.html?id=' . $articleId;
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

    /** @return array<string, mixed>|null */
    private function publishedArticle(int $id): ?array
    {
        return $this->db->selectOne(
            "SELECT article_id, title, summary, thumb, content_html FROM cms_article
             WHERE site_id = :site AND article_id = :id AND status = 'published' AND public_scope = 'public'",
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
