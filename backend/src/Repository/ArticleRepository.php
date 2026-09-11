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
             WHERE a.site_id = :site AND a.article_id = :id',
            ['site' => $this->siteId, 'id' => (int) $id]
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
}
