<?php

declare(strict_types=1);

namespace HechiZx\Repository;

use HechiZx\Support\Db;
use HechiZx\Support\Json;

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

        $channels = [];
        foreach ($rows as $row) {
            $list = null;
            if ($withList) {
                $list = (int) $row['home_sourced'] === 1
                    ? $this->homeSourcedList($row, $listSize)
                    : ($lists[(string) $row['type_code']] ?? []);
            }
            $channels[] = $this->map($row, $list);
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
        return $this->map($row, $list);
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
             WHERE ac.site_id = :site AND a.status = :status AND a.public_scope = :scope
             ORDER BY ac.channel_type ASC, ac.sort_no ASC, a.published_at DESC, a.article_id DESC',
            ['site' => $this->siteId, 'status' => 'published', 'scope' => 'public']
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
               AND a.status = :status AND a.public_scope = :scope
             ORDER BY ac.sort_no ASC, a.published_at DESC, a.article_id DESC
             LIMIT ' . max(0, $listSize),
            ['site' => $this->siteId, 'type' => $type, 'status' => 'published', 'scope' => 'public']
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
    private function map(array $row, ?array $list): array
    {
        $channel = [
            'type'     => (string) $row['type_code'],
            'columnId' => (string) ($row['parent_type'] !== '' ? $row['parent_type'] : $row['type_code']),
            'slug'     => (string) $row['slug'],
            'name'     => (string) $row['name'],
            'inner'    => (string) $row['inner_name'],
            'intro'    => (string) ($row['intro'] ?? ''),
            'layout'   => (string) $row['layout'],
            'siblings' => Json::decode($row['siblings_json'] ?? null, []),
            'total'    => (int) $row['total_count'],
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
            'url'      => 'detail.html?id=' . (string) $row['article_id'],
            'date'     => $published !== '' ? substr($published, 0, 10) : '',
            'datetime' => $published,
            'source'   => (string) $row['source'],
            'views'    => is_numeric($row['views']) ? (int) $row['views'] : (string) $row['views'],
            'img'      => (string) $row['thumb'],
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
             ORDER BY c.parent_type ASC, c.sort_no ASC, c.channel_id ASC',
            ['site' => $this->siteId]
        );
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
     * 链接映射用的精简索引：[{type, ids}]，等价于前端的 data/channel-index.json。
     * 视频、专题这类取自首页模块的栏目没有稿件 id，返回空数组。
     *
     * @return list<array{type: string, ids: list<string>}>
     */
    public function indexMap(int $listSize = 50): array
    {
        $channels = $this->db->select(
            'SELECT type_code, home_sourced FROM sys_channel
             WHERE site_id = :site AND status = :status
             ORDER BY sort_no ASC, channel_id ASC',
            ['site' => $this->siteId, 'status' => 'published']
        );

        $rows = $this->db->select(
            'SELECT ac.channel_type, ac.article_id
             FROM cms_article_channel ac
             JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
             WHERE ac.site_id = :site AND a.status = :status AND a.public_scope = :scope
             ORDER BY ac.channel_type ASC, ac.sort_no ASC, a.published_at DESC, a.article_id DESC',
            ['site' => $this->siteId, 'status' => 'published', 'scope' => 'public']
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
            ];
        }
        return $index;
    }
}
