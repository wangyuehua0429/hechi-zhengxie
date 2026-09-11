<?php

declare(strict_types=1);

namespace HechiZx\Repository;

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
     * 后台列表：可按栏目、状态、关键词筛选，返回原始行（含栏目名）。
     *
     * @param array{channel?:string, status?:string, keyword?:string} $filters
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function adminPaginate(array $filters, int $page, int $size): array
    {
        $where = ['a.site_id = :site'];
        $params = ['site' => $this->siteId];

        if (($filters['channel'] ?? '') !== '') {
            $where[] = 'a.channel_type = :channel';
            $params['channel'] = (string) $filters['channel'];
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'a.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (($filters['keyword'] ?? '') !== '') {
            $where[] = '(a.title LIKE :kw OR a.summary LIKE :kw)';
            $params['kw'] = '%' . (string) $filters['keyword'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM cms_article a WHERE ' . $whereSql, $params);
        $offset = max(0, ($page - 1) * $size);

        $items = $this->db->select(
            'SELECT a.*, c.name AS channel_name, c.inner_name AS channel_inner
             FROM cms_article a
             LEFT JOIN sys_channel c ON c.type_code = a.channel_type AND c.site_id = a.site_id
             WHERE ' . $whereSql . '
             ORDER BY a.published_at DESC, a.article_id DESC
             LIMIT ' . max(1, $size) . ' OFFSET ' . $offset,
            $params
        );

        return ['items' => $items, 'total' => $total];
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
     * 按状态统计，后台首页用。
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $rows = $this->db->select(
            'SELECT status, COUNT(*) AS n FROM cms_article WHERE site_id = :site GROUP BY status',
            ['site' => $this->siteId]
        );
        $counts = ['draft' => 0, 'published' => 0, 'offline' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
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
                published_at, views, status, public_scope, is_top, has_body, created_at, updated_at)
             VALUES
               (:site, :channel, :title, :subtitle, :summary, :content, :source, :author, :editor,
                :published_at, :views, :status, :scope, :is_top, :has_body, :t, :t)',
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
