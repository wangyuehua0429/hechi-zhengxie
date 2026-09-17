<?php

declare(strict_types=1);

namespace HechiZx\Publish;

use HechiZx\Repository\ArticleRepository;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Repository\HomeRepository;
use HechiZx\Content\BodyNormalizer;
use HechiZx\Content\HtmlSanitizer;
use HechiZx\Support\Db;
use HechiZx\Support\Json;

/**
 * 静态化发布器：把库里的内容产出为「数据快照 + 全文静态页」到输出目录，
 * 由 Nginx 直出（路径约定见 docs/api-contract.md 第 5 节）。
 *
 * 本期范围：
 *   * *.html        由 backend/templates/page.php 渲染的最小可读页面
 *   * data/*.json   与阶段 A 的快照同构，只在显式 `--data-only` 时产出（离线预览与契约对拍；
 *                   默认发布不再产出，见 backend/bin/publish.php 顶部说明）
 * 正式模板（把 frontend/home 的结构搬进服务端）在阶段 C 内替换 templates/page.php，
 * 发布器的对外行为不变。
 */
final class Publisher
{
    /** 数据快照里每个栏目带的列表条数：够前端自己分页，又不至于把快照撑大 */
    private const CHANNEL_LIST_SIZE = 50;

    /** 栏目页每页条数：与动态端（js/channel.js 的 PAGE_SIZE）保持一致，2026-09-17 定 */
    private const CHANNEL_PAGE_SIZE = 20;

    /** 侧栏「最新新闻」条数：栏目页与动态端基准一致取 8，详情页沿用详情页策略取 10 */
    private const CHANNEL_SIDE_LATEST = 8;
    private const ARTICLE_SIDE_LATEST = 10;

    /** 侧栏「图片新闻」固定取这个栏目（与前端 js/shell.js 的 IMAGE_NEWS_TYPE 一致） */
    private const IMAGE_NEWS_TYPE = '314';

    /** 侧栏数据缓存：一次发布要渲染上百个栏目分页，侧栏内容全站同一份，不能每页重查 */
    private array $latestCache = [];
    private ?array $imageNewsCache = null;

    public function __construct(
        private Db $db,
        private int $siteId,
        private string $outDir,
        private string $templateFile,
        private string $siteName,
        private string $domain
    ) {
    }

    /** @return array<string, int> 各类产物的数量 */
    public function publishDataSnapshots(): array
    {
        $channels = $this->channels()->all(true, self::CHANNEL_LIST_SIZE);
        $home = $this->home()->blocks();
        $articles = $this->articlesWithBody();

        $index = [];
        foreach ($channels as $channel) {
            $index[] = [
                'type' => $channel['type'],
                'ids'  => array_map(static fn (array $item): string => (string) $item['id'], $channel['list'] ?? []),
            ];
        }

        $this->write('data/home.json', $this->pretty($home));
        $this->write('data/channel.json', $this->pretty(['channels' => $channels]));
        $this->write('data/article.json', $this->pretty(['articles' => $articles]));
        $this->write('data/channel-index.json', $this->pretty(['channels' => $index]));

        return [
            'home_blocks' => count($home),
            'channels'    => count($channels),
            'articles'    => count($articles),
        ];
    }

    /** @return array<string, int> */
    public function publishHtml(): array
    {
        $channels = $this->channels()->all(true, self::CHANNEL_LIST_SIZE);
        $home = $this->home()->blocks();
        $articles = $this->articlesWithBody();

        $entries = [];
        $keep = [];   // 本轮真正产出的静态页，发布收尾时用它清掉不再产出的旧文件
        $capped = []; // 触发单页上限的栏目（内容可能被截断时提醒，不静默）
        // 栏目页地址统一由 StaticPaths 决定：slug 重复的一级栏目会补上栏目号，
        // 保证 43 个栏目 43 个路径，不互相覆盖（301 映射表也用同一份结果）。
        $channelPaths = StaticPaths::channelPaths($channels);
        $shell = $this->shellData();

        // 首页
        $this->write('index.html', $this->render($shell + [
            'kind'        => 'home',
            'title'       => $this->siteName,
            'description' => '中国人民政治协商会议河池市委员会官方网站',
            'heading'     => $this->siteName,
            'bodyHtml'    => $this->homeHtml($home),
            'canonical'   => '/',
            'crumb'       => [],
        ]));
        $entries[] = ['loc' => '/', 'priority' => '1.0'];
        $keep[] = 'index.html';
        $htmlCount = 1;

        // 栏目页：第 1 页是 index.html，其余是 page-<n>.html，每页 20 条（与动态端一致）
        foreach ($channels as $channel) {
            $channelPath = $channelPaths[(string) $channel['type']] ?? '/channel/' . $channel['type'] . '/';
            $siblings = array_values(array_filter((array) ($channel['siblings'] ?? []), 'is_array'));
            $first = $this->channelPage($channel, 1);
            $pages = max(1, (int) ceil($first['total'] / self::CHANNEL_PAGE_SIZE));
            // 隐式上限体检：home_sourced 栏目的列表来自快照（上限 CHANNEL_LIST_SIZE），领导型一次取 200；
            // 触顶说明内容可能被截断，报出来而不是静默少内容
            if (!empty($channel['homeSourced']) && (int) $first['total'] >= self::CHANNEL_LIST_SIZE) {
                $capped[] = (string) $channel['type'] . '（视频／专题类栏目按 ' . self::CHANNEL_LIST_SIZE . ' 条上限输出）';
            } elseif ((string) $channel['layout'] === 'leaders' && (int) $first['total'] > 200) {
                $capped[] = (string) $channel['type'] . '（领导型栏目一次最多 200 条）';
            }
            for ($page = 1; $page <= $pages; $page++) {
                $data = $page === 1 ? $first : $this->channelPage($channel, $page);
                $path = ltrim($channelPath, '/') . ($page === 1 ? 'index.html' : 'page-' . $page . '.html');
                $this->write($path, $this->render($shell + [
                    'kind'        => 'channel',
                    'title'       => ($page > 1 ? '第 ' . $page . ' 页 · ' : '') . $channel['inner'] . ' · ' . $this->siteName,
                    'description' => trim((string) $channel['intro']) !== '' ? $channel['intro'] : $channel['inner'],
                    'heading'     => (string) $channel['inner'],
                    'bodyHtml'    => $this->channelListHtml((array) $data['items'], (string) $channel['layout'], $channel),
                    'canonical'   => $page === 1 ? $channelPath : $channelPath . 'page-' . $page . '.html',
                    'crumb'       => $this->channelCrumb($channel, $channels, $channelPaths),
                    'latest'      => $this->latestNews(self::CHANNEL_SIDE_LATEST),
                    'channelPanel' => [
                        'name'     => (string) $channel['inner'],
                        'layout'   => (string) $channel['layout'],
                        'total'    => (int) $data['total'],
                        'page'     => $page,
                        'pages'    => $pages,
                        'base'     => $channelPath,
                        'proposal' => (string) ($channel['columnId'] ?? '') === '501',
                        'counties' => array_values(array_filter((array) ($channel['counties'] ?? []), 'is_array')),
                        'hidePager' => (string) $channel['layout'] === 'leaders',
                        'leaders'  => (string) $channel['layout'] === 'leaders',
                        'siblings' => $this->localSiblings($siblings, $channelPaths),
                        'current'  => (string) $channel['type'],
                    ],
                ]));
                $keep[] = $path;
                $htmlCount++;
            }
            $entries[] = ['loc' => $channelPath, 'priority' => '0.8'];   // sitemap 只收栏目第一页
        }

        // 详情页
        foreach ($articles as $article) {
            $path = 'article/' . $article['id'] . '.html';
            $this->write($path, $this->render($shell + [
                'kind'        => 'article',
                'title'       => $article['title'] . ' · ' . $this->siteName,
                'description' => trim((string) $article['summary']) !== '' ? $article['summary'] : $article['title'],
                'heading'     => $article['title'],
                'bodyHtml'    => (string) $article['content'],
                'canonical'   => '/article/' . $article['id'] . '.html',
                'crumb'       => $this->articleCrumb($article, $channels, $channelPaths),
                'latest'      => $this->latestNews(self::ARTICLE_SIDE_LATEST),
                'articleMeta' => [
                    'date'   => substr((string) ($article['dateText'] ?? $article['date']), 0, 10),
                    'source' => (string) ($article['source'] ?? ''),
                    'author' => (string) ($article['author'] ?? ''),
                ],
                'editor'      => (string) ($article['editor'] ?? ''),
                'attachments' => array_values((array) ($article['attachments'] ?? [])),
            ]));
            $entries[] = ['loc' => '/article/' . $article['id'] . '.html', 'priority' => '0.6'];
            $keep[] = $path;
            $htmlCount++;
        }

        $this->write('sitemap.xml', $this->sitemap($entries));
        $keep[] = 'sitemap.xml';

        // 清掉这一轮不再产出的静态页：稿件转 archive、栏目改名或下线后，
        // 旧文件如果留着，Nginx 会继续直出，等于绕过了“归档不出静态页”的口径。
        // 只动自己管的 article/*.html 与 channel/*/index.html，不碰输出目录里的其它东西。
        $pruned = $this->prune($keep);

        return [
            // 实际写出的 HTML 数（含栏目分页页）；sitemap 只收 1 + 43 + 详情
            'html_pages' => $htmlCount,
            'channel_pages' => $htmlCount - 1 - count($channels) - count($articles),
            'capped'     => $capped,
            'sitemap_urls' => count($entries),
            'channels'   => count($channels),
            'articles'   => count($articles),
            'sitemap'    => 1,
            'pruned'     => $pruned,
        ];
    }

    /**
     * @param list<string> $keep 相对输出目录的路径
     */
    private function prune(array $keep): int
    {
        $root = rtrim($this->outDir, '/');
        $keepSet = array_flip($keep);
        $removed = 0;
        foreach (['article', 'channel'] as $dir) {
            $base = $root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                /** @var \SplFileInfo $item */
                $relative = substr($item->getPathname(), strlen($root) + 1);
                if ($item->isDir()) {
                    @rmdir($item->getPathname());   // 只删空目录
                    continue;
                }
                $name = $item->getFilename();
                $managed = $dir === 'article'
                    ? (bool) preg_match('/^\d+\.html$/', $name)
                    // 栏目目录下管两种文件：栏目首页与分页页（page-<n>.html）
                    : ($name === 'index.html' || (bool) preg_match('/^page-\d+\.html$/', $name));
                if (!$managed || isset($keepSet[$relative])) {
                    continue;
                }
                if (@unlink($item->getPathname())) {
                    $removed++;
                }
            }
        }
        return $removed;
    }

    public function outDir(): string
    {
        return $this->outDir;
    }

    /**
     * 增量发布：只重发一篇稿件的静态详情页，并重建 sitemap（后台保存稿件后自动调用）。
     *
     * 稿件不在公开范围（草稿／待审／归档／删除／无正文）时，把可能残留的静态页删掉，
     * 与全量发布的 prune 口径保持一致。
     *
     * @return bool 是否产出了页面（false = 已移除或本就不该有）
     */
    public function publishArticlePage(string $articleId, bool $rebuildSitemap = true): bool
    {
        // 稿件号必须是纯数字：它同时用于 SQL 参数与输出文件路径，非数字一律拒绝
        if ($articleId === '' || !ctype_digit($articleId)) {
            return false;
        }
        $relative = 'article/' . $articleId . '.html';
        $file = rtrim($this->outDir, '/') . '/' . $relative;
        $public = $this->db->selectOne(
            "SELECT article_id FROM cms_article
             WHERE site_id = :site AND article_id = :id
               AND status = 'published' AND public_scope = 'public' AND has_body = 1",
            ['site' => $this->siteId, 'id' => $articleId]
        );
        if ($public === null) {
            if (is_file($file)) {
                @unlink($file);
            }
            if ($rebuildSitemap) {
                $this->rebuildSitemap();
            }
            return false;
        }

        $article = $this->articles()->byId($articleId);
        if ($article === null) {
            return false;
        }
        unset($article['hasBody']);
        $article['content'] = BodyNormalizer::normalize(
            HtmlSanitizer::clean((string) $article['content']),
            (string) $article['title'],
            (string) ($article['author'] ?? '')
        );
        $article['images'] = BodyNormalizer::normalizeImages((array) $article['images']);

        $channels = $this->channels()->all(true, 1);
        $channelPaths = StaticPaths::channelPaths($channels);
        $this->write($relative, $this->render($this->shellData() + [
            'kind'        => 'article',
            'title'       => $article['title'] . ' · ' . $this->siteName,
            'description' => trim((string) $article['summary']) !== '' ? $article['summary'] : $article['title'],
            'heading'     => $article['title'],
            'bodyHtml'    => (string) $article['content'],
            'canonical'   => '/article/' . $article['id'] . '.html',
            'crumb'       => $this->articleCrumb($article, $channels, $channelPaths),
            'latest'      => $this->latestNews(self::ARTICLE_SIDE_LATEST),
            'articleMeta' => [
                'date'   => substr((string) ($article['dateText'] ?? $article['date']), 0, 10),
                'source' => (string) ($article['source'] ?? ''),
                'author' => (string) ($article['author'] ?? ''),
            ],
            'editor'      => (string) ($article['editor'] ?? ''),
            'attachments' => array_values((array) ($article['attachments'] ?? [])),
        ]));
        if ($rebuildSitemap) {
            $this->rebuildSitemap();
        }
        return true;
    }

    /**
     * 只重建 sitemap：列表取自库（1 首页 + 43 栏目 + 全部公开稿件），不重发任何页面。
     * 全量发布与增量发布都走这一份口径。sitemap 只收栏目第一页（分页页不进）。
     */
    public function rebuildSitemap(): void
    {
        $entries = [['loc' => '/', 'priority' => '1.0']];
        foreach (StaticPaths::channelPaths($this->channels()->all(true, 1)) as $path) {
            $entries[] = ['loc' => $path, 'priority' => '0.8'];
        }
        foreach ($this->publishedArticleIds() as $id) {
            $entries[] = ['loc' => '/article/' . $id . '.html', 'priority' => '0.6'];
        }
        $this->write('sitemap.xml', $this->sitemap($entries));
    }

    /** @return list<string> 全部可公开且有正文的稿件号 */
    private function publishedArticleIds(): array
    {
        $rows = $this->db->select(
            "SELECT article_id FROM cms_article
             WHERE site_id = :site AND status = 'published' AND public_scope = 'public' AND has_body = 1
             ORDER BY published_at DESC, article_id DESC",
            ['site' => $this->siteId]
        );
        return array_map(static fn (array $row): string => (string) $row['article_id'], $rows);
    }

    /** @return list<array<string, mixed>> */
    private function articlesWithBody(): array
    {
        $rows = $this->db->select(
            'SELECT article_id FROM cms_article
             WHERE site_id = :site AND status = :status AND public_scope = :scope AND has_body = 1
             ORDER BY published_at DESC, article_id DESC',
            // 归档（public_scope=archive）的稿件只留后台，不产静态页：口径见
            // docs/稿库与内容状态设计.md 第 2 节（近 3 年公开，更早后台留存）。
            ['site' => $this->siteId, 'status' => 'published', 'scope' => 'public']
        );

        $articles = [];
        foreach ($rows as $row) {
            $article = $this->articles()->byId((string) $row['article_id']);
            if ($article !== null) {
                unset($article['hasBody']);
                // 出口归一化：静态页与 data/article.json 快照走同一份结果，口径见 BodyNormalizer 注释
                $article['content'] = BodyNormalizer::normalize(
                    HtmlSanitizer::clean((string) $article['content']),
                    (string) $article['title'],
                    (string) ($article['author'] ?? '')
                );
                $article['images'] = BodyNormalizer::normalizeImages((array) $article['images']);
                $articles[] = $article;
            }
        }
        return $articles;
    }

    /** @param array<string, mixed> $home */
    private function homeHtml(array $home): string
    {
        $html = '<p>首页数据模块：' . htmlspecialchars(implode('、', array_keys($home)), ENT_QUOTES) . '</p>';
        $nav = $home['nav'] ?? [];
        if (is_array($nav) && $nav !== []) {
            $html .= '<h2>栏目导航</h2><ul>';
            foreach ($nav as $item) {
                $title = htmlspecialchars((string) ($item['title'] ?? ''), ENT_QUOTES);
                $url = htmlspecialchars((string) ($item['url'] ?? '#'), ENT_QUOTES);
                $html .= '<li><a href="' . $url . '">' . $title . '</a></li>';
            }
            $html .= '</ul>';
        }
        return $html;
    }

    /**
     * 栏目某一页的稿件。
     *
     * 取自首页模块的栏目（视频／专题／互动，home_sourced）没有稿件表行，按模块带来的 list
     * 本地分页；其余栏目走稿件表真分页，排序与接口一致（置顶 → 栏目内排序 → 发布时间）。
     *
     * @param array<string, mixed> $channel
     * @return array{items: list<array<string, mixed>>, total: int, pages: int}
     */
    private function channelPage(array $channel, int $page): array
    {
        // 领导型栏目一次列全（与动态端 js/channel.js 的 size=200 一致），其余每页 20 条
        $size = (string) ($channel['layout'] ?? '') === 'leaders' ? 200 : self::CHANNEL_PAGE_SIZE;
        if (!empty($channel['homeSourced'])) {
            $list = array_values(array_filter((array) ($channel['list'] ?? []), 'is_array'));
            $total = count($list);
            return [
                'items' => array_slice($list, max(0, $page - 1) * $size, $size),
                'total' => $total,
                'pages' => max(1, (int) ceil($total / $size)),
            ];
        }
        $result = $this->articles()->paginate([(string) $channel['type']], $page, $size);
        $items = array_values(array_filter((array) ($result['items'] ?? []), 'is_array'));
        $total = (int) ($result['total'] ?? count($items));
        return [
            'items' => $items,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $size)),
        ];
    }

    /**
     * 栏目列表：按 layout 出与动态端 js/channel.js 同款的标记。
     * gallery／video／topic 走卡片网格，leaders 按职务分组，interactive 出说明块，其余是行列表。
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed>       $channel
     */
    private function channelListHtml(array $items, string $layout = 'list', array $channel = []): string
    {
        $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);
        $title = static fn (array $item): string => (string) ($item['title'] ?? '');
        $url = static function (array $item): string {
            $link = (string) ($item['url'] ?? '');
            if ($link === '' && (string) ($item['id'] ?? '') !== '') {
                $link = '/article/' . (string) $item['id'] . '.html';
            }
            return $link;
        };
        // 图片地址：首页模块带来的条目写的是前台相对路径（images/…），在 /channel/… 下会 404，
        // 这里统一补成站点根绝对路径；旧站地址仍走 BodyNormalizer 的本地改写／强制 https。
        $img = static function (array $item): string {
            $value = trim((string) ($item['img'] ?? ''));
            if ($value === '') {
                return '';
            }
            if (preg_match('~^https?://~i', $value) === 1) {
                return BodyNormalizer::normalizeResourceUrl($value);
            }
            if (str_starts_with($value, '//')) {
                return 'https:' . $value;
            }
            return str_starts_with($value, '/') ? $value : '/' . ltrim($value, './');
        };
        $date = static fn (array $item): string => substr((string) ($item['date'] ?? ''), 0, 10);
        $time = static function (array $item) use ($esc): string {
            $shown = substr((string) ($item['date'] ?? ''), 0, 10);
            if ($shown === '') {
                return '';
            }
            $datetime = (string) ($item['datetime'] ?? $shown);
            return '<time datetime="' . $esc($datetime) . '">' . $esc($shown) . '</time>';
        };
        $external = static fn (string $link): string => preg_match('~^https?:~i', $link) === 1 ? ' target="_blank" rel="noopener"' : '';

        if ($items === [] && $layout !== 'interactive') {
            $empty = [
                'gallery' => '该栏目暂无图片。',
                'video'   => '该栏目暂无视频。',
                'topic'   => '该栏目暂无专题。',
                'leaders' => '该栏目暂无领导信息。',
            ][$layout] ?? '该栏目暂无公开稿件。';
            return '<div class="empty-state">' . $empty . '</div>';
        }

        if ($layout === 'gallery') {
            $html = '<div class="gallery-grid">';
            foreach ($items as $item) {
                $name = $esc($title($item));
                $html .= '<figure class="gallery-card"><a href="' . $esc($url($item)) . '" title="' . $name . '">'
                    . ($img($item) !== ''
                        ? '<img src="' . $esc($img($item)) . '" alt="' . $name . '" loading="lazy">'
                        : '<span class="gallery-noimg" aria-hidden="true">暂无图片</span>')
                    . '<figcaption>' . $name . '</figcaption></a>' . $time($item) . '</figure>';
            }
            return $html . '</div>';
        }

        if ($layout === 'video') {
            $html = '<div class="video-grid">';
            foreach ($items as $item) {
                $name = $esc($title($item));
                $link = $url($item);
                $html .= '<figure class="video-card"><a href="' . $esc($link) . '" title="' . $name . '"' . $external($link) . '>'
                    . ($img($item) !== ''
                        ? '<img src="' . $esc($img($item)) . '" alt="' . $name . '" loading="lazy">'
                        : '<span class="video-noimg" aria-hidden="true">视频</span>')
                    . '<span class="video-play" aria-hidden="true"></span><figcaption>' . $name . '</figcaption></a>'
                    . $time($item) . '</figure>';
            }
            return $html . '</div>';
        }

        if ($layout === 'topic') {
            $html = '<div class="topic-grid">';
            foreach ($items as $item) {
                $name = $esc($title($item));
                $link = $url($item);
                $html .= '<a class="topic-card" href="' . $esc($link) . '" title="' . $name . '"' . $external($link) . '>'
                    . ($img($item) !== '' ? '<img src="' . $esc($img($item)) . '" alt="' . $name . '" loading="lazy">' : '')
                    . '<span class="topic-card-title">' . $name . '</span></a>';
            }
            return $html . '</div>';
        }

        if ($layout === 'leaders') {
            // 与动态端同口径：只把"有职务"的当花名册，按主席 → 副主席 → 秘书长排序分组
            $roster = array_values(array_filter($items, static fn (array $item): bool => trim((string) ($item['role'] ?? '')) !== ''));
            $items = $roster !== [] ? $roster : $items;
            $order = ['主席', '副主席', '秘书长'];
            $groups = [];
            foreach ($items as $item) {
                $role = trim((string) ($item['role'] ?? '')) !== '' ? (string) $item['role'] : '副主席';
                $groups[$role][] = $item;
            }
            uksort($groups, static function (string $a, string $b) use ($order): int {
                $ia = array_search($a, $order, true);
                $ib = array_search($b, $order, true);
                return ($ia === false ? count($order) : $ia) <=> ($ib === false ? count($order) : $ib);
            });
            $html = '';
            foreach ($groups as $role => $members) {
                $single = $role !== '副主席';
                $html .= '<section class="leader-group"><h3 class="leader-group-title">' . $esc((string) $role) . '</h3>'
                    . '<ul class="leader-grid' . ($single ? ' leader-grid--single' : '') . '">';
                foreach ($members as $item) {
                    $name = $esc($title($item));
                    $link = $esc($url($item));
                    $html .= '<li class="leader-card"><a class="leader-photo" href="' . $link . '" title="' . $name . '">'
                        . ($img($item) !== ''
                            ? '<img src="' . $esc($img($item)) . '" alt="' . $name . '" loading="lazy">'
                            : '<span class="leader-noimg" aria-hidden="true">' . $name . '</span>')
                        . '</a><a class="leader-name" href="' . $link . '">' . $name . '</a></li>';
                }
                $html .= '</ul></section>';
            }
            return $html === '' ? '<div class="empty-state">该栏目暂无领导信息。</div>' : $html;
        }

        if ($layout === 'interactive') {
            $note = trim((string) ($channel['note'] ?? ''));
            $html = '<div class="interactive-box">'
                . ($note !== '' ? '<p class="interactive-note">' . $esc($note) . '</p>' : '')
                . '<div class="interactive-actions"><span class="interactive-hint">在线提交与回复查询待后端接入后开放。</span></div></div>';
            if ($items === []) {
                return $html . '<div class="empty-state">本栏目暂无可公开的来信与回复。</div>';
            }
            $html .= '<ul class="article-rows">';
            foreach ($items as $item) {
                $html .= '<li><a href="' . $esc($url($item)) . '" title="' . $esc($title($item)) . '">'
                    . $esc($title($item)) . '</a>' . $time($item) . '</li>';
            }
            return $html . '</ul>';
        }

        $html = '<ul class="article-rows">';
        foreach ($items as $item) {
            $html .= '<li><a href="' . $esc($url($item)) . '">' . $esc($title($item)) . '</a>'
                . '<time>' . $esc($date($item)) . '</time></li>';
        }
        return $html . '</ul>';
    }

    /**
     * 左栏子栏目按钮：与动态端一致，栏目组内互相切换，指向静态栏目页。
     *
     * @param list<array<string, mixed>> $siblings
     * @param array<string, string>      $channelPaths
     * @return list<array{label: string, url: string, current: bool}>
     */
    private function localSiblings(array $siblings, array $channelPaths): array
    {
        $out = [];
        foreach ($siblings as $sibling) {
            $type = (string) ($sibling['type'] ?? '');
            $url = (string) ($sibling['url'] ?? '');
            if (isset($channelPaths[$type])) {
                $url = $channelPaths[$type];
            } elseif (preg_match('~channel\.html\?id=([A-Za-z0-9_]+)~', $url, $match) === 1 && isset($channelPaths[$match[1]])) {
                $url = $channelPaths[$match[1]];
            }
            $out[] = [
                'label'   => (string) ($sibling['name'] ?? ''),
                'url'     => $url,
                'current' => (bool) ($sibling['current'] ?? false),
            ];
        }
        return $out;
    }

    /**
     * 静态页外壳数据：站头导航、页脚备案信息与侧栏（最新新闻／图片新闻）。
     * 结构与前端内页同源（frontend/home/js/shell.js 的 renderNav／renderFooter／paintSide），
     * 静态页直接复用 frontend/home 的 CSS。
     *
     * @return array<string, mixed>
     */
    private function shellData(): array
    {
        $blocks = $this->home()->blocks(['nav', 'meta']);
        return [
            // 导航地址在 HomeRepository 出口已经换成本站静态栏目页地址，这里直接用
            'nav'    => is_array($blocks['nav'] ?? null) ? $blocks['nav'] : [],
            'meta'   => is_array($blocks['meta'] ?? null) ? $blocks['meta'] : [],
            'thumbs' => $this->imageNews(4),
        ];
    }

    /**
     * @param list<array<string, mixed>> $channels
     * @param array<string, string>      $channelPaths
     * @return list<array{label:string, url:string}>
     */
    private function channelCrumb(array $channel, array $channels, array $channelPaths): array
    {
        $type = (string) $channel['type'];
        $crumb = [['label' => '首页', 'url' => '/']];
        // 与动态端 js/channel.js 的 renderCrumb 同口径：一级栏目名（name）与子栏目名（inner）
        // 不同时显示两级，第二级链接回一级栏目页；相同时只有一级。
        $name = (string) ($channel['name'] ?? '');
        $inner = (string) ($channel['inner'] ?? '');
        if ($inner !== '' && $name !== '' && $inner !== $name) {
            $parent = (string) ($channel['columnId'] ?? '') ?: $type;
            $crumb[] = ['label' => $name, 'url' => $channelPaths[$parent] ?? $channelPaths[$type] ?? ''];
            $crumb[] = ['label' => $inner, 'url' => ''];
            return $crumb;
        }
        $crumb[] = ['label' => $inner !== '' ? $inner : $name, 'url' => ''];
        return $crumb;
    }

    /**
     * @param array<string, mixed>       $article
     * @param list<array<string, mixed>> $channels
     * @param array<string, string>      $channelPaths
     * @return list<array{label:string, url:string}>
     */
    private function articleCrumb(array $article, array $channels, array $channelPaths): array
    {
        $type = (string) ($article['channelType'] ?? '');
        $crumb = [['label' => '首页', 'url' => '/']];
        $channel = null;
        foreach ($channels as $item) {
            if ((string) $item['type'] === $type) {
                $channel = $item;
                break;
            }
        }
        if ($channel !== null) {
            $parent = (string) ($channel['columnId'] ?? '');
            if ($parent !== '' && $parent !== $type && isset($channelPaths[$parent])) {
                $crumb[] = ['label' => $this->channelLabel($channels, $parent), 'url' => $channelPaths[$parent]];
            }
            if (isset($channelPaths[$type])) {
                $crumb[] = ['label' => (string) $channel['inner'], 'url' => $channelPaths[$type]];
            }
        } elseif ((string) ($article['channelName'] ?? '') !== '') {
            $crumb[] = ['label' => (string) $article['channelName'], 'url' => ''];
        }
        $crumb[] = ['label' => '正文', 'url' => ''];
        return $crumb;
    }

    /** @param list<array<string, mixed>> $channels */
    private function channelLabel(array $channels, string $type): string
    {
        foreach ($channels as $channel) {
            if ((string) $channel['type'] === $type) {
                return (string) $channel['inner'];
            }
        }
        return $type;
    }

    /**
     * 侧栏「最新新闻」：各栏目稿件汇总，排除首页聚合栏目与领导简介（与前端 js/shell.js 同口径）。
     *
     * @return list<array{title:string, url:string}>
     */
    private function latestNews(int $limit): array
    {
        if (isset($this->latestCache[$limit])) {
            return $this->latestCache[$limit];
        }
        $rows = $this->db->select(
            "SELECT a.article_id, a.title FROM cms_article a
             WHERE a.site_id = :site AND a.status = 'published' AND a.public_scope = 'public' AND a.has_body = 1
               AND NOT EXISTS (SELECT 1 FROM sys_channel c
                               WHERE c.site_id = a.site_id AND c.type_code = a.channel_type
                                 AND (c.home_sourced = 1 OR c.layout = 'leaders'))
             ORDER BY a.published_at DESC, a.article_id DESC
             LIMIT " . max(1, $limit),
            ['site' => $this->siteId]
        );
        return $this->latestCache[$limit] = array_map(static fn (array $row): array => [
            'title' => (string) $row['title'],
            'url'   => '/article/' . (string) $row['article_id'] . '.html',
        ], $rows);
    }

    /** 侧栏「图片新闻」：只取图片新闻栏目（314）里有图的稿件 */
    private function imageNews(int $limit): array
    {
        if ($this->imageNewsCache !== null) {
            return $this->imageNewsCache;
        }
        $rows = $this->db->select(
            "SELECT article_id, title, thumb FROM cms_article
             WHERE site_id = :site AND status = 'published' AND public_scope = 'public'
               AND channel_type = :type AND thumb <> ''
             ORDER BY published_at DESC, article_id DESC
             LIMIT " . max(1, $limit),
            ['site' => $this->siteId, 'type' => self::IMAGE_NEWS_TYPE]
        );
        return $this->imageNewsCache = array_map(static fn (array $row): array => [
            'title' => (string) $row['title'],
            'url'   => '/article/' . (string) $row['article_id'] . '.html',
            // 缩略图也要走正文同一套资源地址归一化：本地有文件走站内 /uploads/legacy，
            // 没有的强制 https，避免静态页里再出现 http 旧站地址（混合内容）
            'img'   => BodyNormalizer::normalizeResourceUrl((string) $row['thumb']),
        ], $rows);
    }

    /** @param list<array{loc:string, priority:string}> $entries */
    private function sitemap(array $entries): string
    {
        $base = 'https://' . $this->domain;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($entries as $entry) {
            $xml .= '  <url><loc>' . $base . $entry['loc'] . '</loc>'
                . '<priority>' . $entry['priority'] . '</priority></url>' . "\n";
        }
        return $xml . '</urlset>' . "\n";
    }

    /** @param array<string, string> $page */
    private function render(array $page): string
    {
        $page['siteName'] = $this->siteName;
        $page['domain'] = $this->domain;
        $page['generatedAt'] = date('Y-m-d H:i:s');

        ob_start();
        (static function (array $page, string $template): void {
            extract($page, EXTR_SKIP);
            include $template;
        })($page, $this->templateFile);
        return (string) ob_get_clean();
    }

    private function write(string $relative, string $contents): void
    {
        $file = rtrim($this->outDir, '/') . '/' . ltrim($relative, '/');
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($file, $contents);
    }

    private function pretty(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    private function channels(): ChannelRepository
    {
        return new ChannelRepository($this->db, $this->siteId, new HomeRepository($this->db, $this->siteId));
    }

    private function articles(): ArticleRepository
    {
        return new ArticleRepository($this->db, $this->siteId);
    }

    private function home(): HomeRepository
    {
        return new HomeRepository($this->db, $this->siteId);
    }
}
