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

    /** 侧栏「图片新闻」固定取这个栏目（与前端 js/shell.js 的 IMAGE_NEWS_TYPE 一致） */
    private const IMAGE_NEWS_TYPE = '314';

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

        // 栏目页
        foreach ($channels as $channel) {
            $channelPath = $channelPaths[(string) $channel['type']] ?? '/channel/' . $channel['type'] . '/';
            $path = ltrim($channelPath, '/') . 'index.html';
            $this->write($path, $this->render($shell + [
                'kind'        => 'channel',
                'title'       => $channel['inner'] . ' · ' . $this->siteName,
                'description' => $channel['intro'] !== '' ? $channel['intro'] : $channel['inner'],
                'heading'     => $channel['inner'],
                'bodyHtml'    => $this->channelHtml($channel),
                'canonical'   => $channelPath,
                'crumb'       => $this->channelCrumb($channel, $channels, $channelPaths),
            ]));
            $entries[] = ['loc' => $channelPath, 'priority' => '0.8'];
            $keep[] = $path;
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
        }

        $this->write('sitemap.xml', $this->sitemap($entries));
        $keep[] = 'sitemap.xml';

        // 清掉这一轮不再产出的静态页：稿件转 archive、栏目改名或下线后，
        // 旧文件如果留着，Nginx 会继续直出，等于绕过了“归档不出静态页”的口径。
        // 只动自己管的 article/*.html 与 channel/*/index.html，不碰输出目录里的其它东西。
        $pruned = $this->prune($keep);

        return [
            'html_pages' => count($entries),
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
                    : $name === 'index.html';
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

    /** @param array<string, mixed> $channel */
    private function channelHtml(array $channel): string
    {
        $html = '';
        if (($channel['intro'] ?? '') !== '') {
            $html .= '<p class="intro">' . htmlspecialchars((string) $channel['intro'], ENT_QUOTES) . '</p>';
        }
        $list = $channel['list'] ?? [];
        if ($list === []) {
            return $html . '<p>该栏目暂无公开稿件。</p>';
        }
        $html .= '<ul class="article-rows">';
        foreach ($list as $item) {
            $title = htmlspecialchars((string) $item['title'], ENT_QUOTES);
            $date = htmlspecialchars((string) $item['date'], ENT_QUOTES);
            $html .= '<li><a href="/article/' . (string) $item['id'] . '.html">' . $title . '</a>'
                . '<time>' . $date . '</time></li>';
        }
        return $html . '</ul>';
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
            'latest' => $this->latestNews(10),
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
        $parent = (string) ($channel['columnId'] ?? '');
        if ($parent !== '' && $parent !== $type && isset($channelPaths[$parent])) {
            $crumb[] = ['label' => $this->channelLabel($channels, $parent), 'url' => $channelPaths[$parent]];
        }
        $crumb[] = ['label' => (string) $channel['inner'], 'url' => ''];
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
        return array_map(static fn (array $row): array => [
            'title' => (string) $row['title'],
            'url'   => '/article/' . (string) $row['article_id'] . '.html',
        ], $rows);
    }

    /** 侧栏「图片新闻」：只取图片新闻栏目（314）里有图的稿件 */
    private function imageNews(int $limit): array
    {
        $rows = $this->db->select(
            "SELECT article_id, title, thumb FROM cms_article
             WHERE site_id = :site AND status = 'published' AND public_scope = 'public'
               AND channel_type = :type AND thumb <> ''
             ORDER BY published_at DESC, article_id DESC
             LIMIT " . max(1, $limit),
            ['site' => $this->siteId, 'type' => self::IMAGE_NEWS_TYPE]
        );
        return array_map(static fn (array $row): array => [
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
