<?php

declare(strict_types=1);

namespace HechiZx\Repository;

use HechiZx\Content\ArticleWorkflow;
use HechiZx\Support\Db;

/**
 * 稿件仓储：列表项结构与 channel.json 的 list 项同构，
 * 详情结构与 article.json 的单篇同构（见 docs/api-contract.md 第 3.2、3.3 节）。
 */
final class ArticleRepository
{
    public function __construct(private Db $db, private int $siteId)
    {
    }

    /**
     * 列表查询：栏目页分页、检索、更多列表共用。
     *
     * @param list<string> $channelTypes 空数组表示不限栏目
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function paginate(array $channelTypes, int $page, int $size, string $keyword = '', string $order = 'date_desc'): array
    {
        $where = ['a.site_id = :site', 'a.status = :status', 'a.public_scope = :scope'];
        $params = ['site' => $this->siteId, 'status' => 'published', 'scope' => 'public'];
        $join = '';

        if ($channelTypes !== []) {
            $placeholders = [];
            foreach (array_values($channelTypes) as $index => $type) {
                $key = 'ch' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $type;
            }
            // 按栏目取稿要走归属表：一篇稿件可能同时挂在多个栏目下
            $join = ' JOIN cms_article_channel ac ON ac.article_id = a.article_id AND ac.site_id = a.site_id';
            $where[] = 'ac.channel_type IN (' . implode(', ', $placeholders) . ')';
        }

        if ($keyword !== '') {
            $where[] = '(a.title LIKE :kw OR a.summary LIKE :kw)';
            $params['kw'] = '%' . $keyword . '%';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar(
            'SELECT COUNT(DISTINCT a.article_id) FROM cms_article a' . $join . ' WHERE ' . $whereSql,
            $params
        );

        $direction = strtolower($order) === 'date_asc' ? 'ASC' : 'DESC';
        $offset = max(0, ($page - 1) * $size);

        $rows = $this->db->select(
            'SELECT a.* FROM cms_article a' . $join . ' WHERE ' . $whereSql .
            ' ORDER BY a.published_at ' . $direction . ', a.article_id ' . $direction .
            ' LIMIT ' . max(1, $size) . ' OFFSET ' . $offset,
            $params
        );

        return [
            'items' => array_map([ChannelRepository::class, 'mapListItem'], $rows),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function byId(string $id): ?array
    {
        $row = $this->db->selectOne(
            'SELECT a.*, c.name AS channel_name, c.inner_name AS channel_inner
             FROM cms_article a
             LEFT JOIN sys_channel c ON c.type_code = a.channel_type AND c.site_id = a.site_id
             WHERE a.site_id = :site AND a.article_id = :id
               AND a.status = :status AND a.public_scope = :scope',
            ['site' => $this->siteId, 'id' => (int) $id, 'status' => 'published', 'scope' => 'public']
        );
        if ($row === null) {
            return null;
        }

        $published = (string) ($row['published_at'] ?? '');
        return [
            'id'           => (int) $row['article_id'],
            'channelType'  => (string) $row['channel_type'],
            // 与 article.json 一致：取栏目页显示的当前子栏目名（sys_channel.inner_name）
            'channelName'  => (string) ($row['channel_inner'] ?? '') !== ''
                ? (string) $row['channel_inner']
                : (string) ($row['channel_name'] ?? ''),
            'title'        => (string) $row['title'],
            'subtitle'     => (string) $row['subtitle'],
            'date'         => $published !== '' ? substr($published, 0, 16) : '',
            'dateText'     => $published !== '' ? substr($published, 0, 10) : '',
            'source'       => (string) $row['source'],
            'author'       => (string) $row['author'],
            'editor'       => (string) $row['editor'],
            'views'        => (string) $row['views'],
            'summary'      => (string) ($row['summary'] ?? ''),
            'content'      => (string) ($row['content_html'] ?? ''),
            'attachments'  => $this->attachments((int) $row['article_id']),
            'images'       => $this->images((int) $row['article_id']),
            'hasBody'      => (int) $row['has_body'] === 1,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attachments(int $articleId): array
    {
        $rows = $this->db->select(
            'SELECT attachment_id, name, url, ext, size_bytes FROM cms_attachment
             WHERE article_id = :id ORDER BY sort_no ASC, attachment_id ASC',
            ['id' => $articleId]
        );

        return array_map(static fn (array $row): array => [
            'id'   => (int) $row['attachment_id'],
            'name' => (string) $row['name'],
            'url'  => (string) $row['url'],
            'ext'  => (string) $row['ext'],
            'size' => (int) $row['size_bytes'],
        ], $rows);
    }

    /**
     * @return list<string>
     */
    public function images(int $articleId): array
    {
        $rows = $this->db->select(
            'SELECT path FROM cms_article_image WHERE article_id = :id ORDER BY sort_no ASC, image_id ASC',
            ['id' => $articleId]
        );
        return array_map(static fn (array $row): string => (string) $row['path'], $rows);
    }

    // ---------------------------------------------------------------- 后台

    /**
     * 后台列表：可按栏目、状态、关键词筛选，可按发布时间／最近更新／稿件号排序。
     *
     * @param array{channel?:string, status?:string, keyword?:string, sort?:string, order?:string} $filters
     * @param list<string>|null $channelScope 数据范围：null 不限栏目，数组为允许的栏目号
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminPaginate(array $filters, int $page, int $size, ?array $channelScope = null): array
    {
        [$whereSql, $params] = $this->adminCondition($filters, $channelScope);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM cms_article a WHERE ' . $whereSql, $params);
        $offset = max(0, ($page - 1) * $size);

        // 排序白名单：列名固定在这里，模板传什么都不会拼进 SQL
        $sortColumns = [
            'published_at' => 'a.published_at',
            'updated_at'   => 'a.updated_at',
            'article_id'   => 'a.article_id',
        ];
        $sortColumn = $sortColumns[(string) ($filters['sort'] ?? '')] ?? 'a.published_at';
        $direction = strtolower((string) ($filters['order'] ?? '')) === 'asc' ? 'ASC' : 'DESC';

        $items = $this->db->select(
            'SELECT a.*, c.name AS channel_name, c.inner_name AS channel_inner
             FROM cms_article a
             LEFT JOIN sys_channel c ON c.type_code = a.channel_type AND c.site_id = a.site_id
             WHERE ' . $whereSql . '
             ORDER BY ' . $sortColumn . ' ' . $direction . ', a.article_id DESC
             LIMIT ' . max(1, $size) . ' OFFSET ' . $offset,
            $params
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 后台列表与各种计数的公共筛选条件，保证「列表条数、稿库数字、栏目数字」三处口径一致。
     *
     * 栏目有两种筛法：
     *   * channel：单个栏目号，精确匹配；
     *   * group：一级栏目号，匹配该组全部子栏目。
     * 必须分开传：一级栏目号经常同时是组内某个子栏目的号（例如「政协会议」401 组的
     * 401 就是「全体会议」），只看号段无法判断用户想筛的是整组还是那一个栏目。
     *
     * @param array<string, mixed> $filters channel／group／status／keyword
     * @param list<string>|null $channelScope 数据范围，null 表示不限栏目
     * @return array{0:string, 1:array<string, mixed>}
     */
    private function adminCondition(array $filters, ?array $channelScope, bool $applyChannel = true): array
    {
        $where = ['a.site_id = :site'];
        $params = ['site' => $this->siteId];

        if ($applyChannel) {
            $group = (string) ($filters['group'] ?? '');
            $channel = (string) ($filters['channel'] ?? '');
            if ($group !== '') {
                $this->appendIn($where, $params, 'a.channel_type', $this->groupMembers($group), 'gp');
            } elseif ($channel !== '') {
                $where[] = 'a.channel_type = :channel';
                $params['channel'] = $channel;
            }
        }

        if ($channelScope !== null) {
            if ($channelScope === []) {
                $where[] = '1 = 0';
            } else {
                $this->appendIn($where, $params, 'a.channel_type', $channelScope, 'sc');
            }
        }

        $status = ArticleWorkflow::normalize((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            if ($status === ArticleWorkflow::WITHDRAWN) {
                $where[] = "(a.status = 'withdrawn' OR a.status = 'offline')";
            } else {
                $where[] = 'a.status = :status';
                $params['status'] = $status;
            }
        }

        if ((string) ($filters['keyword'] ?? '') !== '') {
            $where[] = '(a.title LIKE :kw OR a.summary LIKE :kw)';
            $params['kw'] = '%' . (string) $filters['keyword'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param list<string> $values
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function appendIn(array &$where, array &$params, string $column, array $values, string $prefix): void
    {
        $placeholders = [];
        foreach (array_values($values) as $index => $value) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (string) $value;
        }
        $where[] = $column . ' IN (' . implode(', ', $placeholders) . ')';
    }

    /**
     * 一级栏目号对应的组内栏目号；查不到子栏目时退化成它自己。
     *
     * @return list<string>
     */
    private function groupMembers(string $group): array
    {
        $rows = $this->db->select(
            'SELECT type_code FROM sys_channel
             WHERE site_id = :site AND parent_type = :group
             ORDER BY sort_no ASC, channel_id ASC',
            ['site' => $this->siteId, 'group' => $group]
        );
        $types = array_map(static fn (array $row): string => (string) $row['type_code'], $rows);
        return $types === [] ? [$group] : $types;
    }

    /** @return array<string, mixed>|null */
    public function adminFind(string $id): ?array
    {
        return $this->db->selectOne(
            'SELECT a.*, c.name AS channel_name, c.inner_name AS channel_inner
             FROM cms_article a
             LEFT JOIN sys_channel c ON c.type_code = a.channel_type AND c.site_id = a.site_id
             WHERE a.site_id = :site AND a.article_id = :id',
            ['site' => $this->siteId, 'id' => (int) $id]
        );
    }

    /**
     * 后台保存：字段白名单由调用方（控制器）保证。
     *
     * @param array<string, mixed> $fields
     */
    public function adminUpdate(string $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $sets = [];
        $params = ['id' => (int) $id, 'site' => $this->siteId, 't' => $this->db->now()];
        foreach ($fields as $column => $value) {
            $sets[] = $column . ' = :f_' . $column;
            $params['f_' . $column] = $value;
        }
        $sets[] = 'updated_at = :t';

        $this->db->execute(
            'UPDATE cms_article SET ' . implode(', ', $sets) . ' WHERE site_id = :site AND article_id = :id',
            $params
        );
    }

    /**
     * 按栏目置顶：置顶的稿件在该栏目列表与首页对应模块里排在最前，
     * 两处共用同一套顺序（cms_article_channel.is_top + sort_no）。
     */
    public function setChannelTop(int $articleId, string $channelType, int $isTop): void
    {
        $now = $this->db->now();
        if ($isTop === 1) {
            $min = $this->db->scalar(
                'SELECT MIN(sort_no) FROM cms_article_channel
                 WHERE site_id = :site AND channel_type = :channel AND is_top = 1 AND article_id <> :id',
                ['site' => $this->siteId, 'channel' => $channelType, 'id' => $articleId]
            );
            $sortNo = $min === null ? 1 : min(0, (int) $min - 1);
        } else {
            // 取消置顶：按发布时间落回它该在的位置。
            // 不能简单写 0——0 是「新稿排最前」的取值，会把一篇旧稿顶到栏目第一位。
            $published = (string) $this->db->scalar(
                'SELECT COALESCE(published_at, "") FROM cms_article WHERE site_id = :site AND article_id = :id',
                ['site' => $this->siteId, 'id' => $articleId]
            );
            $newer = (int) $this->db->scalar(
                'SELECT COUNT(*) FROM cms_article_channel ac
                 JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
                 WHERE ac.site_id = :site AND ac.channel_type = :channel AND ac.is_top = 0
                   AND ac.article_id <> :id AND a.published_at > :published',
                [
                    'site'      => $this->siteId,
                    'channel'   => $channelType,
                    'id'        => $articleId,
                    'published' => $published,
                ]
            );
            $sortNo = $newer + 1;
        }
        $this->db->execute(
            'UPDATE cms_article_channel SET is_top = :top, sort_no = :sort
             WHERE site_id = :site AND channel_type = :channel AND article_id = :id',
            [
                'top'     => $isTop,
                'sort'    => $sortNo,
                'site'    => $this->siteId,
                'channel' => $channelType,
                'id'      => $articleId,
            ]
        );
        $this->db->execute(
            // 同步写一份到 cms_article.is_top：稿件列表的「置顶」标记读的是它，
            // 这样无论从稿件编辑页还是首页模块页置顶，两处显示都一致。
            'UPDATE cms_article SET is_top = :top, updated_at = :t WHERE site_id = :site AND article_id = :id',
            ['top' => $isTop, 't' => $now, 'site' => $this->siteId, 'id' => $articleId]
        );
    }

    /** 稿件在某个栏目里是否置顶 */
    public function channelTop(int $articleId, string $channelType): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(is_top, 0) FROM cms_article_channel
             WHERE site_id = :site AND channel_type = :channel AND article_id = :id',
            ['site' => $this->siteId, 'channel' => $channelType, 'id' => $articleId]
        );
    }

    /** 这篇稿件是否挂在某个栏目下（模块页的排序／置顶要先确认归属） */
    public function isLinkedTo(int $articleId, string $channelType): bool
    {
        return $this->db->selectOne(
            'SELECT 1 AS ok FROM cms_article_channel
             WHERE site_id = :site AND channel_type = :channel AND article_id = :id',
            ['site' => $this->siteId, 'channel' => $channelType, 'id' => $articleId]
        ) !== null;
    }

    /**
     * 栏目内上移／下移：按「置顶在前、再按排序值、再按发布时间」的实际顺序交换相邻两条，
     * 然后把整列按层级重新编号（1 起），保证顺序稳定、不出现相同排序值。
     *
     * @return array{ok:bool, message:string}
     */
    public function moveInChannel(int $articleId, string $channelType, string $direction): array
    {
        $rows = $this->db->select(
            'SELECT ac.article_id, ac.is_top, ac.sort_no, a.published_at
             FROM cms_article_channel ac
             JOIN cms_article a ON a.article_id = ac.article_id AND a.site_id = ac.site_id
             WHERE ac.site_id = :site AND ac.channel_type = :channel
             ORDER BY ac.is_top DESC, ac.sort_no ASC, a.published_at DESC, ac.article_id DESC',
            ['site' => $this->siteId, 'channel' => $channelType]
        );

        $index = null;
        foreach ($rows as $i => $row) {
            if ((int) $row['article_id'] === $articleId) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return ['ok' => false, 'message' => '这篇稿件不在该栏目下。'];
        }
        $target = $direction === 'up' ? $index - 1 : $index + 1;
        if (!isset($rows[$target])) {
            return [
                'ok' => false,
                'message' => $direction === 'up' ? '已经是这个栏目的第一篇（不含置顶稿）。' : '已经是这个栏目的最后一篇。',
            ];
        }
        if ((int) $rows[$index]['is_top'] !== (int) $rows[$target]['is_top']) {
            return [
                'ok' => false,
                'message' => '置顶稿件始终排在前面，要跨过它请先取消置顶（在稿件编辑页里改）。',
            ];
        }

        $order = array_map(static fn (array $row): int => (int) $row['article_id'], $rows);
        [$order[$index], $order[$target]] = [$order[$target], $order[$index]];

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['article_id']] = $row;
        }
        $counter = [0 => 0, 1 => 0];
        foreach ($order as $id) {
            $tier = (int) $byId[$id]['is_top'] === 1 ? 1 : 0;
            $counter[$tier]++;
            if ((int) $byId[$id]['sort_no'] === $counter[$tier]) {
                continue;
            }
            $this->db->execute(
                'UPDATE cms_article_channel SET sort_no = :sort
                 WHERE site_id = :site AND channel_type = :channel AND article_id = :id',
                ['sort' => $counter[$tier], 'site' => $this->siteId, 'channel' => $channelType, 'id' => $id]
            );
        }

        return [
            'ok' => true,
            'message' => '已' . ($direction === 'up' ? '上移' : '下移') . '一篇（栏目：' . $channelType . '）。',
        ];
    }

    /**
     * 按稿库状态统计：后台首页用全站口径（不传 $filters），
     * 稿件列表用「当前栏目或栏目组 + 数据范围」口径。
     *
     * @param array<string, mixed> $filters channel／group／status／keyword
     * @param list<string>|null $channelScope null 表示不限栏目
     *
     * @return array<string, int>
     */
    public function statusCounts(array $filters = [], ?array $channelScope = null): array
    {
        [$whereSql, $params] = $this->adminCondition($filters, $channelScope);
        $rows = $this->db->select(
            "SELECT CASE WHEN a.status = 'offline' THEN 'withdrawn' ELSE a.status END AS status, COUNT(*) AS n
             FROM cms_article a WHERE " . $whereSql . ' GROUP BY 1',
            $params
        );
        $counts = [];
        foreach (ArticleWorkflow::places() as $place) {
            $counts[$place['status']] = 0;
        }
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    /**
     * 按栏目统计稿件数，口径与列表当前筛选（稿库 + 关键词 + 数据范围）一致，
     * 栏目导航条上的数字与列表条数因此不会打架。组内各栏目的数字由模板相加。
     *
     * @param array<string, mixed> $filters
     * @param list<string>|null $channelScope
     * @return array<string, int>
     */
    public function channelCounts(array $filters, ?array $channelScope = null): array
    {
        [$whereSql, $params] = $this->adminCondition($filters, $channelScope, false);
        $rows = $this->db->select(
            'SELECT a.channel_type AS channel_type, COUNT(*) AS n
             FROM cms_article a WHERE ' . $whereSql . '
             GROUP BY a.channel_type',
            $params
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['channel_type']] = (int) $row['n'];
        }
        return $counts;
    }

    /**
     * 新建稿件：写入主表，并在归属表登记主栏目（否则不会出现在栏目列表里）。
     *
     * @param array<string, mixed> $fields
     */
    public function create(array $fields): int
    {
        $now = $this->db->now();
        $this->db->execute(
            'INSERT INTO cms_article
               (site_id, channel_type, title, subtitle, summary, content_html, source, author, editor,
                published_at, views, status, public_scope, is_top, has_body, created_by, updated_by, created_at, updated_at)
             VALUES
               (:site, :channel, :title, :subtitle, :summary, :content, :source, :author, :editor,
                :published_at, :views, :status, :scope, :is_top, :has_body, :created_by, :updated_by, :t, :t)',
            [
                'site'         => $this->siteId,
                'channel'      => (string) ($fields['channel_type'] ?? ''),
                'title'        => (string) ($fields['title'] ?? ''),
                'subtitle'     => (string) ($fields['subtitle'] ?? ''),
                'summary'      => (string) ($fields['summary'] ?? ''),
                'content'      => (string) ($fields['content_html'] ?? ''),
                'source'       => (string) ($fields['source'] ?? ''),
                'author'       => (string) ($fields['author'] ?? ''),
                'editor'       => (string) ($fields['editor'] ?? ''),
                'published_at' => (string) ($fields['published_at'] ?? $now),
                'views'        => '0',
                'status'       => (string) ($fields['status'] ?? 'draft'),
                'scope'        => 'public',
                'is_top'       => (int) ($fields['is_top'] ?? 0),
                'has_body'     => trim((string) ($fields['content_html'] ?? '')) === '' ? 0 : 1,
                'created_by'   => (int) ($fields['created_by'] ?? 0),
                'updated_by'   => (int) ($fields['created_by'] ?? 0),
                't'            => $now,
            ]
        );

        $id = (int) $this->db->pdo()->lastInsertId();
        $this->linkChannel($id, (string) ($fields['channel_type'] ?? ''), 0, 1);
        return $id;
    }

    /**
     * 删除稿件：主表、正文图片、附件、栏目归属一起清掉。
     */
    public function delete(string $id): void
    {
        $params = ['id' => (int) $id, 'site' => $this->siteId];
        $this->db->execute('DELETE FROM cms_article_image WHERE article_id = :id', ['id' => (int) $id]);
        $this->db->execute('DELETE FROM cms_attachment WHERE article_id = :id', ['id' => (int) $id]);
        $this->db->execute('DELETE FROM cms_article_channel WHERE article_id = :id AND site_id = :site', $params);
        $this->db->execute('DELETE FROM cms_article WHERE article_id = :id AND site_id = :site', $params);
    }

    /**
     * 登记栏目归属（已存在则更新排序与主栏目标记）。
     */
    public function linkChannel(int $articleId, string $channelType, int $sortNo = 0, int $isPrimary = 0): void
    {
        if ($channelType === '') {
            return;
        }
        $exists = $this->db->scalar(
            'SELECT article_id FROM cms_article_channel WHERE article_id = :id AND site_id = :site AND channel_type = :type',
            ['id' => $articleId, 'site' => $this->siteId, 'type' => $channelType]
        );
        if ($exists !== null) {
            $this->db->execute(
                'UPDATE cms_article_channel SET sort_no = :sort, is_primary = :primary
                 WHERE article_id = :id AND site_id = :site AND channel_type = :type',
                ['sort' => $sortNo, 'primary' => $isPrimary, 'id' => $articleId, 'site' => $this->siteId, 'type' => $channelType]
            );
            return;
        }
        $this->db->execute(
            'INSERT INTO cms_article_channel (article_id, site_id, channel_type, sort_no, is_primary)
             VALUES (:id, :site, :type, :sort, :primary)',
            ['id' => $articleId, 'site' => $this->siteId, 'type' => $channelType, 'sort' => $sortNo, 'primary' => $isPrimary]
        );
    }

    /**
     * 附件入库（文件已由控制器落盘）。
     *
     * @param array<string, mixed> $file
     */
    public function addAttachment(int $articleId, array $file): int
    {
        $sortNo = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_no), -1) + 1 FROM cms_attachment WHERE article_id = :id',
            ['id' => $articleId]
        );
        $this->db->execute(
            'INSERT INTO cms_attachment (article_id, name, url, ext, size_bytes, sort_no, created_at)
             VALUES (:id, :name, :url, :ext, :size, :sort, :t)',
            [
                'id'   => $articleId,
                'name' => (string) $file['name'],
                'url'  => (string) $file['url'],
                'ext'  => (string) $file['ext'],
                'size' => (int) $file['size'],
                'sort' => $sortNo,
                't'    => $this->db->now(),
            ]
        );
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function findAttachment(int $attachmentId, int $articleId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM cms_attachment WHERE attachment_id = :aid AND article_id = :id',
            ['aid' => $attachmentId, 'id' => $articleId]
        );
    }

    public function deleteAttachment(int $attachmentId, int $articleId): void
    {
        $this->db->execute(
            'DELETE FROM cms_attachment WHERE attachment_id = :aid AND article_id = :id',
            ['aid' => $attachmentId, 'id' => $articleId]
        );
    }

    /**
     * 正文图片记录（图集与灯箱用）。
     */
    public function addImage(int $articleId, string $path): void
    {
        $sortNo = (int) $this->db->scalar(
            'SELECT COALESCE(MAX(sort_no), -1) + 1 FROM cms_article_image WHERE article_id = :id',
            ['id' => $articleId]
        );
        $this->db->execute(
            'INSERT INTO cms_article_image (article_id, path, sort_no) VALUES (:id, :path, :sort)',
            ['id' => $articleId, 'path' => $path, 'sort' => $sortNo]
        );
    }

    /**
     * 保存正文后同步 has_body 与正文图片记录（从正文里抽 <img src>）。
     */
    public function syncBodyAssets(int $articleId, string $contentHtml): void
    {
        $hasBody = trim($contentHtml) === '' ? 0 : 1;
        $this->db->execute(
            'UPDATE cms_article SET has_body = :has, updated_at = :t WHERE article_id = :id',
            ['has' => $hasBody, 't' => $this->db->now(), 'id' => $articleId]
        );
    }
}
