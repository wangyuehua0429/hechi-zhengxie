<?php

declare(strict_types=1);

namespace HechiZx\Repository;

use HechiZx\Content\BodyNormalizer;
use HechiZx\Content\PublicScope;
use HechiZx\Support\Db;
use HechiZx\Support\Json;
use HechiZx\Publish\StaticPaths;

/**
 * 栏目仓储：输出结构与 frontend/home/data/channel.json 的单个栏目同构
 * （字段说明见 docs/api-contract.md 第 3.1 节）。
 *
 * 两处容易踩的点：
 *   1. 栏目归属走 cms_article_channel——同一篇稿件可同时挂在多个栏目（县区动态既进
 *      「县区政协工作动态」也进「县（区）政协」），只按 cms_article.channel_type 取会漏内容；
 *   2. 视频、专题这类栏目（home_sourced）的内容来自首页模块，不是稿件表。
 */
final class ChannelRepository
{
    /** layout 到首页模块键的对应关系（home_sourced 栏目用） */
    private const HOME_BLOCK_BY_LAYOUT = [
        'video' => 'videos',
        'topic' => 'topic',
    ];

    public function __construct(private Db $db, private int $siteId, private HomeRepository $home)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(bool $withList = true, int $listSize = 50, ?string $layout = null, ?string $parentType = null): array
    {
        $sql = 'SELECT * FROM sys_channel WHERE site_id = :site AND status = :status';
        $params = ['site' => $this->siteId, 'status' => 'published'];

        if ($layout !== null) {
            $sql .= ' AND layout = :layout';
            $params['layout'] = $layout;
        }
        if ($parentType !== null) {
            $sql .= ' AND parent_type = :parent';
            $params['parent'] = $parentType;
        }
        $sql .= ' ORDER BY sort_no ASC, channel_id ASC';

        $rows = $this->db->select($sql, $params);
        $lists = $withList ? $this->listsForAll($listSize) : [];
        $counts = $this->publicCounts();

        $channels = [];
        foreach ($rows as $row) {
            $list = null;
            if ($withList) {
                $list = (int) $row['home_sourced'] === 1
                    ? $this->homeSourcedList($row, $listSize)
                    : ($lists[(string) $row['type_code']] ?? []);
            }
            $channels[] = $this->map($row, $list, $counts[(string) $row['type_code']] ?? 0);
        }
        return $channels;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byType(string $type, bool $withList = true, int $listSize = 50): ?array
    {
        $row = $this->db->selectOne(
            'SELECT * FROM sys_channel WHERE site_id = :site AND type_code = :type',
            ['site' => $this->siteId, 'type' => $type]
        );
        if ($row === null) {
            return null;
        }
        $list = null;
        if ($withList) {
            $list = (int) $row['home_sourced'] === 1
                ? $this->homeSourcedList($row, $listSize)
                : $this->listFor($type, $listSize);
        }
        return $this->map($row, $list, $this->publicCountFor($type));
    }

    /**
     * 各栏目当前公开稿件数：`total` 必须反映前台真正取得到的条数。
     * 此前用的是 sys_channel.total_count（seed 时的快照常量，904 一直是 825），
     * 迁移后前台显示“共 825 条”却只翻得到 464 条，属于口径不一致。
     *
     * @return array<string, int> 栏目号 => 公开条数
     */
    private function publicCounts(): array
    {
        $rows = $this->db->select(
            'SELECT ac.channel_type AS type_code, COUNT(*) AS n
             FROM cms_article_channel ac
             JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
             WHERE ac.site_id = :site AND a.status = :status AND ' . PublicScope::sql('a') . '
             GROUP BY ac.channel_type',
            ['site' => $this->siteId, 'status' => 'published']
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['type_code']] = (int) $row['n'];
        }
        return $counts;
    }

    private function publicCountFor(string $type): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM cms_article_channel ac
             JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
             WHERE ac.site_id = :site AND ac.channel_type = :type
               AND a.status = :status AND ' . PublicScope::sql('a'),
            ['site' => $this->siteId, 'type' => $type, 'status' => 'published']
        );
    }

    /**
     * 一次取回多个栏目的列表项，避免逐栏目查库。
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function listsForAll(int $listSize): array
    {
        $rows = $this->db->select(
            'SELECT a.*, ac.channel_type AS link_channel
             FROM cms_article_channel ac
             JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
             WHERE ac.site_id = :site AND a.status = :status AND ' . PublicScope::sql('a') . '
             ORDER BY ac.channel_type ASC, ac.is_top DESC, ac.sort_no ASC, a.published_at DESC, a.article_id DESC',
            ['site' => $this->siteId, 'status' => 'published']
        );

        $grouped = [];
        foreach ($rows as $row) {
            $type = (string) $row['link_channel'];
            if (count($grouped[$type] ?? []) >= $listSize) {
                continue;
            }
            $grouped[$type][] = self::mapListItem($row);
        }
        return $grouped;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listFor(string $type, int $listSize): array
    {
        $rows = $this->db->select(
            'SELECT a.* FROM cms_article_channel ac
             JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
             WHERE ac.site_id = :site AND ac.channel_type = :type
               AND a.status = :status AND ' . PublicScope::sql('a') . '
             ORDER BY ac.is_top DESC, ac.sort_no ASC, a.published_at DESC, a.article_id DESC
             LIMIT ' . max(0, $listSize),
            ['site' => $this->siteId, 'type' => $type, 'status' => 'published']
        );
        return array_map([self::class, 'mapListItem'], $rows);
    }

    /**
     * 视频、专题等取自首页模块的栏目：列表由模块数据现算，id 用「栏目号-序号」合成值。
     *
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function homeSourcedList(array $row, int $listSize): array
    {
        $blockKey = self::HOME_BLOCK_BY_LAYOUT[(string) $row['layout']] ?? null;
        if ($blockKey === null || $listSize <= 0) {
            return [];
        }

        $blocks = $this->home->blocks([$blockKey]);
        $items = is_array($blocks[$blockKey] ?? null) ? $blocks[$blockKey] : [];

        $list = [];
        foreach (array_slice(array_values($items), 0, $listSize) as $index => $item) {
            $list[] = [
                'id'       => (string) $row['type_code'] . '-' . ($index + 1),
                'title'    => (string) ($item['title'] ?? ''),
                'url'      => (string) ($item['url'] ?? ''),
                'date'     => '',
                'datetime' => '',
                'source'   => '',
                'views'    => '',
                'img'      => (string) ($item['img'] ?? ''),
                'hasBody'  => false,
            ];
        }
        return $list;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function map(array $row, ?array $list, int $publicTotal = 0): array
    {
        // 视频、专题这类栏目的内容取自首页整块配置（不是稿件表），条数按取到的条目算
        $total = (int) $row['home_sourced'] === 1
            ? ($list !== null ? count($list) : 0)
            : $publicTotal;
        $channel = [
            'type'     => (string) $row['type_code'],
            'columnId' => (string) ($row['parent_type'] !== '' ? $row['parent_type'] : $row['type_code']),
            'slug'     => (string) $row['slug'],
            'name'     => (string) $row['name'],
            'inner'    => (string) $row['inner_name'],
            'intro'    => (string) ($row['intro'] ?? ''),
            'layout'   => (string) $row['layout'],
            'siblings' => Json::decode($row['siblings_json'] ?? null, []),
            'total'    => $total,
        ];

        if ((int) $row['home_sourced'] === 1) {
            $channel['homeSourced'] = true;
        }
        if (($row['note'] ?? '') !== '' && $row['note'] !== null) {
            $channel['note'] = (string) $row['note'];
        }
        if (($row['counties_json'] ?? null) !== null) {
            $channel['counties'] = Json::decode($row['counties_json'], []);
        }
        if (($row['feature_json'] ?? null) !== null) {
            $channel['feature'] = Json::decode($row['feature_json'], []);
        }
        if ($list !== null) {
            $channel['list'] = $list;
        }
        return $channel;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function mapListItem(array $row): array
    {
        $published = (string) ($row['published_at'] ?? '');
        $item = [
            'id'       => (string) $row['article_id'],
            'title'    => (string) $row['title'],
            // 对外唯一地址是发布器产出的静态详情页（2026-09-17 A1）：接口与页面都不再给
            // `detail.html?id=` 这种只在原型期用过的地址。前端预览页仍存在，但只作后台预览。
            'url'      => '/article/' . (string) $row['article_id'] . '.html',
            'date'     => $published !== '' ? substr($published, 0, 10) : '',
            'datetime' => $published,
            'source'   => (string) $row['source'],
            'views'    => is_numeric($row['views']) ? (int) $row['views'] : (string) $row['views'],
            // 缩略图与正文同口径：本地有文件走 /uploads/legacy/…，没有的走同源
            // /uploadfiles/…。库里仍有 http 旧站地址，不归一化会既破图又在 https
            // 站点触发混合内容拦截（与 Publish\Publisher、HomeRepository 出口一致）。
            'img'      => BodyNormalizer::normalizeResourceUrl((string) $row['thumb']),
            'hasBody'  => (int) $row['has_body'] === 1,
        ];
        if (($row['role'] ?? '') !== '') {
            $item['role'] = (string) $row['role'];
        }
        return $item;
    }

    // ---------------------------------------------------------------- 后台

    /**
     * 后台栏目列表：含草稿态的栏目也列出来，并带各自的稿件数。
     *
     * @return list<array<string, mixed>>
     */
    public function adminAll(): array
    {
        return $this->db->select(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM cms_article_channel ac WHERE ac.site_id = c.site_id AND ac.channel_type = c.type_code) AS article_count
             FROM sys_channel c
             WHERE c.site_id = :site
             ORDER BY c.sort_no ASC, c.channel_id ASC',
            ['site' => $this->siteId]
        );
    }

    /**
     * 后台栏目导航条用的分组：一级栏目 + 它的子栏目，顺序与前端主导航一致
     *（sort_no 由 seed 按前端 channel.json 的排列写入）。
     *
     * @return list<array{key:string, title:string, channels:list<array<string,mixed>>}>
     */
    public function navGroups(): array
    {
        $groups = [];
        foreach ($this->adminAll() as $channel) {
            $key = (string) ($channel['parent_type'] !== '' ? $channel['parent_type'] : $channel['type_code']);
            if (!isset($groups[$key])) {
                $groups[$key] = ['key' => $key, 'title' => '', 'channels' => []];
            }
            $groups[$key]['channels'][] = $channel;
            if ((string) $channel['type_code'] === $key) {
                $groups[$key]['title'] = (string) $channel['name'];
            }
        }
        foreach ($groups as $key => $group) {
            if ($group['title'] === '') {
                $groups[$key]['title'] = (string) $group['channels'][0]['name'];
            }
        }
        return array_values($groups);
    }

    /** @return array<string, mixed>|null */
    public function adminFind(string $type): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM sys_channel WHERE site_id = :site AND type_code = :type',
            ['site' => $this->siteId, 'type' => $type]
        );
    }

    /**
     * 同一级栏目下的相邻栏目（按 sort_no 排），用于后台「上移／下移」。
     *
     * @param array<string, mixed> $channel
     * @return list<array<string, mixed>> 含自身在内的组内栏目，顺序与前台导航一致
     */
    public function adminSiblings(array $channel): array
    {
        $key = (string) ($channel['parent_type'] ?? '') !== ''
            ? (string) $channel['parent_type']
            : (string) $channel['type_code'];

        return $this->db->select(
            'SELECT * FROM sys_channel
             WHERE site_id = :site AND (type_code = :key OR parent_type = :key)
             ORDER BY sort_no ASC, channel_id ASC',
            ['site' => $this->siteId, 'key' => $key]
        );
    }

    /**
     * 组内上移／下移：与相邻的同级栏目交换 sort_no。
     * 交换只动这两个值，其它栏目的相对顺序不变，因此前台导航整体顺序不会被打乱。
     *
     * @param array<string, mixed> $channel
     * @return array{ok:bool, message:string, neighbor:string}
     */
    public function moveWithinGroup(array $channel, string $direction): array
    {
        $siblings = $this->adminSiblings($channel);
        $type = (string) $channel['type_code'];
        $index = null;
        foreach ($siblings as $i => $row) {
            if ((string) $row['type_code'] === $type) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return ['ok' => false, 'message' => '没有找到这个栏目在当前分组里的位置。', 'neighbor' => ''];
        }

        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($targetIndex < 0 || $targetIndex >= count($siblings)) {
            return [
                'ok' => false,
                'message' => $direction === 'up' ? '已经是这一组的第一个栏目。' : '已经是这一组的最后一个栏目。',
                'neighbor' => '',
            ];
        }

        $neighbor = $siblings[$targetIndex];
        $newSelf = (int) $neighbor['sort_no'];
        $newNeighbor = (int) $channel['sort_no'];
        if ($newSelf === $newNeighbor) {
            // 排序值相同时交换没有效果，把自身挪到相邻的一格（seed 数据里不会出现相同值）
            $newSelf = $direction === 'up' ? $newNeighbor - 1 : $newNeighbor + 1;
        }

        $this->adminUpdate($type, ['sort_no' => $newSelf]);
        $this->adminUpdate((string) $neighbor['type_code'], ['sort_no' => $newNeighbor]);

        return [
            'ok' => true,
            'message' => '已把「' . $channel['inner_name'] . '」'
                . ($direction === 'up' ? '上移' : '下移') . '，换到「' . $neighbor['inner_name'] . '」'
                . ($direction === 'up' ? '前面' : '后面') . '。',
            'neighbor' => (string) $neighbor['inner_name'],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function adminUpdate(string $type, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = [];
        $params = ['type' => $type, 'site' => $this->siteId, 't' => $this->db->now()];
        foreach ($fields as $column => $value) {
            $sets[] = $column . ' = :f_' . $column;
            $params['f_' . $column] = $value;
        }
        $sets[] = 'updated_at = :t';

        $this->db->execute(
            'UPDATE sys_channel SET ' . implode(', ', $sets) . ' WHERE site_id = :site AND type_code = :type',
            $params
        );
    }

    /**
     * 链接映射用的精简索引：[{type, ids, path}]，等价于前端的 data/channel-index.json。
     * 视频、专题这类取自首页模块的栏目没有稿件 id，返回空数组；
     * `path` 是该栏目的静态页地址（对外唯一地址，规则见 Publish\StaticPaths）。
     *
     * @return list<array{type: string, ids: list<string>, path: string}>
     */
    public function indexMap(int $listSize = 50): array
    {
        $channels = $this->db->select(
            'SELECT type_code, slug, home_sourced FROM sys_channel
             WHERE site_id = :site AND status = :status
             ORDER BY sort_no ASC, channel_id ASC',
            ['site' => $this->siteId, 'status' => 'published']
        );
        $paths = StaticPaths::channelPaths(array_map(
            static fn (array $row): array => ['type' => (string) $row['type_code'], 'slug' => (string) $row['slug']],
            $channels
        ));

        $rows = $this->db->select(
            'SELECT ac.channel_type, ac.article_id
             FROM cms_article_channel ac
             JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
             WHERE ac.site_id = :site AND a.status = :status AND ' . PublicScope::sql('a') . '
             ORDER BY ac.channel_type ASC, ac.is_top DESC, ac.sort_no ASC, a.published_at DESC, a.article_id DESC',
            ['site' => $this->siteId, 'status' => 'published']
        );

        $ids = [];
        foreach ($rows as $row) {
            $type = (string) $row['channel_type'];
            if (count($ids[$type] ?? []) >= $listSize) {
                continue;
            }
            $ids[$type][] = (string) $row['article_id'];
        }

        $index = [];
        foreach ($channels as $channel) {
            $type = (string) $channel['type_code'];
            $index[] = [
                'type' => $type,
                'ids'  => (int) $channel['home_sourced'] === 1 ? [] : ($ids[$type] ?? []),
                'path' => $paths[$type] ?? '',
            ];
        }
        return $index;
    }

    // ---- 新建与删除（2026-09-12）------------------------------------------------

    /** 栏目号是否已被占用（同一站点内唯一，数据库也有 UNIQUE(site_id, type_code) 兜底）。 */
    public function adminTypeExists(string $type): bool
    {
        return $this->db->selectOne(
            'SELECT 1 AS ok FROM sys_channel WHERE site_id = :site AND type_code = :type',
            ['site' => $this->siteId, 'type' => $type]
        ) !== null;
    }

    /**
     * 一级栏目判定：parent_type 为空或等于自身栏目号。
     * seed 写的是自身栏目号（`columnId` 对一级栏目等于自己的 `type`），手建的栏目两种写法都要认。
     */
    public static function isTopLevel(string $type, string $parentType): bool
    {
        return $parentType === '' || $parentType === $type;
    }

    /**
     * 一级栏目清单，新建栏目时用来选归属。
     *
     * @return list<array<string, mixed>>
     */
    public function adminTopChannels(): array
    {
        return $this->db->select(
            "SELECT type_code, name, inner_name, status FROM sys_channel
             WHERE site_id = :site AND (parent_type = '' OR parent_type = type_code)
             ORDER BY sort_no ASC, channel_id ASC",
            ['site' => $this->siteId]
        );
    }

    /**
     * 新建栏目的默认排序值（排到最后）。
     * 挂在某个一级栏目下时算该组的最大值；新建一级栏目时算全站最大值，让它排在导航最后。
     */
    public function adminMaxSortNo(string $parentType): int
    {
        if ($parentType === '') {
            return (int) $this->db->scalar(
                'SELECT COALESCE(MAX(sort_no), 0) FROM sys_channel WHERE site_id = :site',
                ['site' => $this->siteId]
            );
        }
        return (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_no), 0) FROM sys_channel
             WHERE site_id = :site AND (type_code = :key OR parent_type = :key)',
            ['site' => $this->siteId, 'key' => $parentType]
        );
    }

    /**
     * 新建栏目。字段已由控制器校验，这里只负责落库。
     *
     * @param array<string, mixed> $fields
     */
    public function adminCreate(array $fields): void
    {
        $now = $this->db->now();
        $this->db->execute(
            'INSERT INTO sys_channel
               (site_id, type_code, parent_type, slug, name, inner_name, intro, layout,
                total_count, home_sourced, sort_no, status, created_at, updated_at)
             VALUES
               (:site, :type, :parent, :slug, :name, :inner, :intro, :layout,
                0, 0, :sort, :status, :t, :t)',
            [
                'site'   => $this->siteId,
                'type'   => (string) $fields['type_code'],
                'parent' => (string) $fields['parent_type'],
                'slug'   => (string) $fields['slug'],
                'name'   => (string) $fields['name'],
                'inner'  => (string) $fields['inner_name'],
                'intro'  => (string) $fields['intro'],
                'layout' => (string) $fields['layout'],
                'sort'   => (int) $fields['sort_no'],
                'status' => (string) $fields['status'],
                't'      => $now,
            ]
        );
    }

    /**
     * 删除前的关联检查：返回阻止删除的理由，空数组表示可以删。
     * 栏目一删，前台导航、首页模块与 301 映射都会跟着失去目标，所以宁可挡下来让编辑先改归属。
     *
     * @return list<string>
     */
    public function adminBlockers(string $type): array
    {
        $blockers = [];

        $articles = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM cms_article_channel WHERE site_id = :site AND channel_type = :type',
            ['site' => $this->siteId, 'type' => $type]
        );
        if ($articles > 0) {
            $blockers[] = '还有 ' . $articles . ' 篇稿件挂在这个栏目（含草稿与回收站稿件），请先把它们改到别的栏目。';
        }

        $children = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM sys_channel WHERE site_id = :site AND parent_type = :type',
            ['site' => $this->siteId, 'type' => $type]
        );
        if ($children > 0) {
            $blockers[] = '下面还有 ' . $children . ' 个子栏目，请先删掉子栏目或改掉它们的归属。';
        }

        foreach ($this->db->select(
            'SELECT section_key, label, scope_json FROM cms_home_section WHERE site_id = :site',
            ['site' => $this->siteId]
        ) as $section) {
            $scope = Json::decode((string) $section['scope_json'], []);
            if (is_array($scope) && $this->scopeUsesChannel($scope, $type)) {
                $label = (string) $section['label'] !== '' ? (string) $section['label'] : (string) $section['section_key'];
                $blockers[] = '首页「其他栏目」的模块“' . $label . '”绑定了它，请先在首页管理里改绑定。';
            }
        }

        $nav = $this->db->selectOne(
            'SELECT payload_json FROM cms_home_block WHERE site_id = :site AND block_key = :key',
            ['site' => $this->siteId, 'key' => 'nav']
        );
        if ($nav !== null) {
            $items = Json::decode((string) $nav['payload_json'], []);
            foreach (is_array($items) ? $items : [] as $item) {
                $url = is_array($item) ? (string) ($item['url'] ?? '') : '';
                if ($url !== '' && preg_match('/(?:[?&])id=' . preg_quote($type, '/') . '(?![0-9])/', $url) === 1) {
                    $title = is_array($item) ? (string) ($item['title'] ?? '') : '';
                    $blockers[] = '首页顶部导航的“' . $title . '”指向它，请先在“导航栏目”里改链接。';
                }
            }
        }

        return $blockers;
    }

    /**
     * 删除栏目，并清理只跟着它走的附属记录：角色的栏目数据范围、该栏目的旧地址 301 映射。
     * 稿件与首页模块绑定的检查在 adminBlockers() 里，能走到这里说明已经没有关联内容。
     */
    public function adminDelete(string $type): void
    {
        $this->db->execute(
            'DELETE FROM sys_channel WHERE site_id = :site AND type_code = :type',
            ['site' => $this->siteId, 'type' => $type]
        );
        $this->db->execute(
            'DELETE FROM sys_role_channel WHERE site_id = :site AND channel_type = :type',
            ['site' => $this->siteId, 'type' => $type]
        );
        foreach (\HechiZx\Publish\RedirectMap::LIST_SCRIPTS as $script) {
            $this->db->execute(
                'DELETE FROM sys_url_redirect WHERE old_path = :old',
                ['old' => '/' . $script . '?id=' . $type]
            );
        }
    }

    /**
     * 首页模块的绑定范围里是否用到这个栏目（单个栏目 / 标签分组 / 一级栏目含子栏目）。
     *
     * @param array<string, mixed> $scope
     */
    private function scopeUsesChannel(array $scope, string $type): bool
    {
        if ((string) ($scope['parent'] ?? '') === $type) {
            return true;
        }
        $channels = [];
        foreach ((array) ($scope['channels'] ?? []) as $channel) {
            $channels[] = (string) $channel;
        }
        foreach ((array) ($scope['tabs'] ?? []) as $tab) {
            foreach ((array) (is_array($tab) ? ($tab['channels'] ?? []) : []) as $channel) {
                $channels[] = (string) $channel;
            }
        }
        return in_array($type, $channels, true);
    }
}
