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
        $keep = [];   // 本轮真正产出的静态页，发布收尾时用它清掉不再产出的旧文件
        // 栏目页地址统一由 StaticPaths 决定：slug 重复的一级栏目会补上栏目号，
        // 保证 43 个栏目 43 个路径，不互相覆盖（301 映射表也用同一份结果）。
        $channelPaths = StaticPaths::channelPaths($channels);

        // 首页
        $this->write('index.html', $this->render([
            'title'       => $this->siteName,
            'description' => '中国人民政治协商会议河池市委员会官方网站',
            'heading'     => $this->siteName,
            'bodyHtml'    => $this->homeHtml($home),
            'canonical'   => '/',
        ]));
        $entries[] = ['loc' => '/', 'priority' => '1.0'];
        $keep[] = 'index.html';

        // 栏目页
        foreach ($channels as $channel) {
            $channelPath = $channelPaths[(string) $channel['type']] ?? '/channel/' . $channel['type'] . '/';
            $path = ltrim($channelPath, '/') . 'index.html';
            $this->write($path, $this->render([
                'title'       => $channel['inner'] . ' · ' . $this->siteName,
                'description' => $channel['intro'] !== '' ? $channel['intro'] : $channel['inner'],
                'heading'     => $channel['inner'],
                'bodyHtml'    => $this->channelHtml($channel),
                'canonical'   => $channelPath,
            ]));
            $entries[] = ['loc' => $channelPath, 'priority' => '0.8'];
            $keep[] = $path;
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
                    (string) $article['title']
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

    /** @param array<string, mixed> $article */
    private function articleHtml(array $article): string
    {
        // 发布时间只到年月日（与前台详情页同口径，时分不上页面）
        $meta = '发布时间：' . htmlspecialchars(substr((string) ($article['dateText'] ?? $article['date']), 0, 10), ENT_QUOTES);
        if (($article['source'] ?? '') !== '') {
            $meta .= '　来源：' . htmlspecialchars((string) $article['source'], ENT_QUOTES);
        }
        $html = '<p class="meta">' . $meta . '</p>';
        // 正文在 articlesWithBody() 里已过白名单并归一化（静态页与快照同一份结果），这里直接输出
        $html .= '<div class="article-body">' . (string) $article['content'] . '</div>';

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
