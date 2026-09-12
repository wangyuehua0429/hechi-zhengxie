<?php

declare(strict_types=1);

namespace HechiZx\Publish;

use HechiZx\Repository\ArticleRepository;
use HechiZx\Repository\ChannelRepository;
use HechiZx\Repository\HomeRepository;
use HechiZx\Content\HtmlSanitizer;
use HechiZx\Support\Db;
use HechiZx\Support\Json;

/**
 * 静态化发布器：把库里的内容产出为「数据快照 + 全文静态页」到输出目录，
 * 由 Nginx 直出（路径约定见 docs/api-contract.md 第 5 节）。
 *
 * 本期范围：
 *   * data/*.json    与阶段 A 的快照同构，前端把取数地址换成接口或这组静态文件即可
 *   * *.html        由 backend/templates/page.php 渲染的最小可读页面
 * 正式模板（把 frontend/home 的结构搬进服务端）在阶段 C 内替换 templates/page.php，
 * 发布器的对外行为不变。
 */
final class Publisher
{
    /** 数据快照里每个栏目带的列表条数：够前端自己分页，又不至于把快照撑大 */
    private const CHANNEL_LIST_SIZE = 50;

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

        // 首页
        $this->write('index.html', $this->render([
            'title'       => $this->siteName,
            'description' => '中国人民政治协商会议河池市委员会官方网站',
            'heading'     => $this->siteName,
            'bodyHtml'    => $this->homeHtml($home),
            'canonical'   => '/',
        ]));
        $entries[] = ['loc' => '/', 'priority' => '1.0'];

        // 栏目页
        foreach ($channels as $channel) {
            $slug = $channel['slug'] !== '' ? $channel['slug'] : $channel['type'];
            $path = 'channel/' . $slug . '/index.html';
            $this->write($path, $this->render([
                'title'       => $channel['inner'] . ' · ' . $this->siteName,
                'description' => $channel['intro'] !== '' ? $channel['intro'] : $channel['inner'],
                'heading'     => $channel['inner'],
                'bodyHtml'    => $this->channelHtml($channel),
                'canonical'   => '/channel/' . $slug . '/',
            ]));
            $entries[] = ['loc' => '/channel/' . $slug . '/', 'priority' => '0.8'];
        }

        // 详情页
        foreach ($articles as $article) {
            $path = 'article/' . $article['id'] . '.html';
            $this->write($path, $this->render([
                'title'       => $article['title'] . ' · ' . $this->siteName,
                'description' => $article['summary'],
                'heading'     => $article['title'],
                'bodyHtml'    => $this->articleHtml($article),
                'canonical'   => '/article/' . $article['id'] . '.html',
            ]));
            $entries[] = ['loc' => '/article/' . $article['id'] . '.html', 'priority' => '0.6'];
        }

        $this->write('sitemap.xml', $this->sitemap($entries));

        return [
            'html_pages' => count($entries),
            'sitemap'    => 1,
        ];
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
             WHERE site_id = :site AND status = :status AND has_body = 1
             ORDER BY published_at DESC, article_id DESC',
            ['site' => $this->siteId, 'status' => 'published']
        );

        $articles = [];
        foreach ($rows as $row) {
            $article = $this->articles()->byId((string) $row['article_id']);
            if ($article !== null) {
                unset($article['hasBody']);
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

    /** @param array<string, mixed> $article */
    private function articleHtml(array $article): string
    {
        $meta = '发布时间：' . htmlspecialchars((string) $article['date'], ENT_QUOTES);
        if (($article['source'] ?? '') !== '') {
            $meta .= '　来源：' . htmlspecialchars((string) $article['source'], ENT_QUOTES);
        }
        $html = '<p class="meta">' . $meta . '</p>';
        // 出口兜底：静态页直接对外，正文统一过白名单（覆盖编辑器上线前的历史正文）
        $html .= '<div class="article-body">' . HtmlSanitizer::clean((string) $article['content']) . '</div>';

        $attachments = $article['attachments'] ?? [];
        if ($attachments !== []) {
            $html .= '<h2>附件下载</h2><ul>';
            foreach ($attachments as $file) {
                $html .= '<li><a href="' . htmlspecialchars((string) $file['url'], ENT_QUOTES) . '">'
                    . htmlspecialchars((string) $file['name'], ENT_QUOTES) . '</a></li>';
            }
            $html .= '</ul>';
        }
        return $html;
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
