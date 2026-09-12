<?php

declare(strict_types=1);

namespace HechiZx\Publish;

use HechiZx\Support\Db;

/**
 * 旧地址 301 映射：从新库内容反推旧站地址，写进 sys_url_redirect，
 * 并产出 Nginx 片段与可交甲方核对的 CSV。
 *
 * 旧地址形态不是猜的，来自旧站程序与实站链接（2026-09-12 实读
 * `zhengxie2026/gxhczx.gov.cn/`）：
 *   * `news_view.php?id=<稿件号>`、`cq_view.php?id=<稿件号>`——两个脚本都是
 *     `$db->row('news', ['ID'=>$id])`，即按稿件号取一篇详情；
 *   * `news_list.php?id=<栏目号>`、`cq_list.php?id=<栏目号>`、
 *     `news_list_about.php?id=<栏目号>`——按栏目号取列表，栏目号与 `rd_news.Type`
 *     同源，也就是新库 sys_channel.type_code（接口契约第 1 节：ID 沿用旧库号）；
 *   * `html/news-view-<稿件号>.html`——旧站静态文章页（53,542 个文件）；
 *   * `qy_list.php`——县（区）政协聚合页；`zl<日期>/`——旧站 13 个专题目录。
 *
 * 两条口径写在代码里，改口径时同步改这里与 docs/旧地址301映射说明.md：
 *   1. **只登记能打开的新地址**。详情页映射只收「已发布且有正文」的稿件——与
 *      Publisher::articlesWithBody() 同一个条件，也就是发布器真的会产出静态页的那些；
 *      未审、无正文、按数据策略不公开的老稿件不登记，旧地址照旧 404（符合“近 3 年
 *      数据公开访问，更早数据后台留存不公开”）。
 *   2. **目标地址取静态化产物**：详情 `/article/<稿件号>.html`、栏目
 *      `/channel/<目录名>/`（目录名见 StaticPaths）。前端动态地址
 *      `detail.html?id=`／`channel.html?id=` 与静态地址的 canonical 口径仍待甲方确认
 *      （接口契约第 8 节），届时这里一并改。
 */
final class RedirectMap
{
    /** 详情页旧脚本（按稿件号取一篇） */
    public const DETAIL_SCRIPTS = ['news_view.php', 'cq_view.php'];

    /** 列表页旧脚本（按栏目号取一列） */
    public const LIST_SCRIPTS = ['news_list.php', 'cq_list.php', 'news_list_about.php'];

    /** 旧站静态文章页所在目录 */
    public const LEGACY_HTML_DIR = 'html';

    /** 旧站专题目录前缀（实读：zl20180106 … zl20260225 共 13 个） */
    public const TOPIC_DIR_PREFIX = 'zl';

    /** 旧站入口页 → 首页 */
    private const HOME_ENTRIES = ['/index.html', '/home.php', '/news-list.html'];

    /** 旧站县（区）政协聚合页 → 新站「县（区）政协」栏目 */
    private const COUNTY_ENTRY = '/qy_list.php';

    /** 本期不处理、但要写进报告让甲方定夺的旧路径前缀 */
    private const PENDING_PREFIXES = ['/uploadfiles/', '/fupin'];

    /** @var array<string, string>|null 栏目号 => 栏目页地址（懒加载并缓存） */
    private ?array $channelPaths = null;

    public function __construct(
        private Db $db,
        private int $siteId
    ) {
    }

    /**
     * 精确映射：旧路径（含 query）=> 新路径。
     *
     * @return array<string, array{target:string, note:string}>
     */
    public function exact(): array
    {
        $map = [];
        foreach ($this->publishedArticleIds() as $id) {
            $target = '/article/' . $id . '.html';
            $map['/' . self::LEGACY_HTML_DIR . '/news-view-' . $id . '.html'] = [
                'target' => $target,
                'note'   => '旧站静态文章页 → 新站详情静态页',
            ];
            foreach (self::DETAIL_SCRIPTS as $script) {
                $map['/' . $script . '?id=' . $id] = [
                    'target' => $target,
                    'note'   => '旧详情脚本 → 新站详情静态页',
                ];
            }
        }

        $paths = $this->channelPaths();
        foreach ($this->publishedChannelTypes() as $type) {
            if (!isset($paths[$type])) {
                continue;
            }
            foreach (self::LIST_SCRIPTS as $script) {
                $map['/' . $script . '?id=' . $type] = [
                    'target' => $paths[$type],
                    'note'   => '旧栏目列表脚本 → 新站栏目静态页',
                ];
            }
        }

        foreach (self::HOME_ENTRIES as $entry) {
            $map[$entry] = ['target' => '/', 'note' => '旧站入口页 → 新站首页'];
        }
        if (isset($paths['qy'])) {
            $map[self::COUNTY_ENTRY] = ['target' => $paths['qy'], 'note' => '旧县区聚合页 → 新站县（区）政协栏目'];
        }
        // 专题栏目不在（或已下线）时不登记，宁可让旧地址 404，也不要 301 到一个不存在的页
        if (isset($paths['topic'])) {
            foreach ($this->topicDirs() as $dir) {
                $entry = [
                    'target' => $paths['topic'],
                    'note'   => '旧专题目录 → 新站专题栏目（具体专题与旧目录的对应关系待甲方确认）',
                ];
                // 请求路径会被归一化掉结尾斜杠（Request::fromGlobals），两种写法都登记
                $map[$dir] = $entry;
                $map[rtrim($dir, '/')] = $entry;
            }
        }

        return $map;
    }

    /**
     * 规则清单：路径形态 → 处理方式。用于文档、Nginx 片段与「未登记」的说明，
     * 精确到某一条地址的判断始终以 sys_url_redirect 表为准。
     *
     * @return list<array{pattern:string, target:string, note:string}>
     */
    public static function rules(): array
    {
        return [
            [
                'pattern' => '/html/news-view-<稿件号>.html',
                'target'  => '/article/<稿件号>.html',
                'note'    => '旧站静态文章页（53,542 个）；只登记已发布且有正文的稿件',
            ],
            [
                'pattern' => '/news_view.php?id=<稿件号>、/cq_view.php?id=<稿件号>',
                'target'  => '/article/<稿件号>.html',
                'note'    => '旧站详情脚本；带 q=<县区号> 参数属县区子站，本期不处理',
            ],
            [
                'pattern' => '/news_list.php?id=<栏目号>、/cq_list.php、/news_list_about.php',
                'target'  => '/channel/<目录名>/',
                'note'    => '旧站栏目列表脚本；只登记新库中已上线的栏目',
            ],
            [
                'pattern' => '/qy_list.php',
                'target'  => '/channel/<县区政协栏目>/',
                'note'    => '旧站县（区）政协聚合页',
            ],
            [
                'pattern' => '/zl<日期>/',
                'target'  => '/channel/<专题栏目>/',
                'note'    => '旧站专题目录（实读 13 个）',
            ],
            [
                'pattern' => '/index.html、/home.php、/news-list.html',
                'target'  => '/',
                'note'    => '旧站入口页',
            ],
            [
                'pattern' => '/uploadfiles/<年><月>/<文件>',
                'target'  => '待定',
                'note'    => '旧站附件与图片；迁移方案（事项三）落地后再登记，当前不产生规则',
            ],
            [
                'pattern' => '/fupin',
                'target'  => '待定',
                'note'    => '旧站扶贫专题目录，对应新站哪个栏目待确认',
            ],
        ];
    }

    /**
     * 把精确映射写进 sys_url_redirect（按 old_path 增改，保留 hits 计数）。
     *
     * @return array{inserted:int, updated:int, unchanged:int, total:int}
     */
    public function sync(bool $dryRun = false): array
    {
        $exact = $this->exact();
        $stat = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'total' => count($exact)];
        if ($dryRun) {
            foreach ($exact as $old => $row) {
                $current = $this->db->selectOne(
                    'SELECT new_path FROM sys_url_redirect WHERE old_path = :old',
                    ['old' => $old]
                );
                if ($current === null) {
                    $stat['inserted']++;
                } elseif ((string) $current['new_path'] !== $row['target']) {
                    $stat['updated']++;
                } else {
                    $stat['unchanged']++;
                }
            }
            return $stat;
        }

        $now = $this->db->now();
        foreach ($exact as $old => $row) {
            $current = $this->db->selectOne(
                'SELECT new_path FROM sys_url_redirect WHERE old_path = :old',
                ['old' => $old]
            );
            if ($current === null) {
                $this->db->execute(
                    'INSERT INTO sys_url_redirect (old_path, new_path, status_code, hits, created_at, updated_at)
                     VALUES (:old, :new, 301, 0, :t, :t)',
                    ['old' => $old, 'new' => $row['target'], 't' => $now]
                );
                $stat['inserted']++;
                continue;
            }
            if ((string) $current['new_path'] === $row['target']) {
                $stat['unchanged']++;
                continue;
            }
            $this->db->execute(
                'UPDATE sys_url_redirect SET new_path = :new, updated_at = :t WHERE old_path = :old',
                ['new' => $row['target'], 't' => $now, 'old' => $old]
            );
            $stat['updated']++;
        }
        return $stat;
    }

    /**
     * 运行期查表：把请求的路径与 query 归一到 sys_url_redirect 的键上。
     * 旧地址可能带多余参数（如 news_view.php?id=1&q=22），这里只认 id；q 非 22
     * 的属县区子站，本期不映射。
     *
     * @return array{target:string, status:int, key:string}|null
     */
    public function resolve(string $path, string $id = '', string $region = ''): ?array
    {
        foreach ($this->candidateKeys($path, $id, $region) as $key) {
            $row = $this->db->selectOne(
                'SELECT old_path, new_path, status_code FROM sys_url_redirect WHERE old_path = :old',
                ['old' => $key]
            );
            if ($row !== null) {
                return [
                    'target' => (string) $row['new_path'],
                    'status' => (int) $row['status_code'],
                    'key'    => (string) $row['old_path'],
                ];
            }
        }
        return null;
    }

    /** 命中计数（sys_url_redirect.hits，供上线后核对 301 命中率） */
    public function touch(string $key): void
    {
        $this->db->execute(
            'UPDATE sys_url_redirect SET hits = hits + 1 WHERE old_path = :old',
            ['old' => $key]
        );
    }

    /**
     * 未登记清单：拿旧站目录实际存在的路径对照库里的映射，列出缺口。
     * 没有旧站目录时返回空，只在报告里说明「未扫描」。
     *
     * @return array{scanned:bool, html_pages:int, mapped:int, unmapped:list<array{old_path:string, reason:string}>}
     */
    public function auditLegacySite(string $siteRoot): array
    {
        $siteRoot = rtrim($siteRoot, '/');
        $htmlDir = $siteRoot . '/' . self::LEGACY_HTML_DIR;
        $result = ['scanned' => false, 'html_pages' => 0, 'mapped' => 0, 'unmapped' => []];
        if (!is_dir($htmlDir)) {
            return $result;
        }
        $result['scanned'] = true;

        $map = $this->exact();
        $files = glob($htmlDir . '/news-view-*.html') ?: [];
        foreach ($files as $file) {
            if (preg_match('/news-view-(\d+)\.html$/', $file, $m) !== 1) {
                continue;
            }
            $result['html_pages']++;
            $old = '/' . self::LEGACY_HTML_DIR . '/news-view-' . $m[1] . '.html';
            if (isset($map[$old])) {
                $result['mapped']++;
                continue;
            }
            $result['unmapped'][] = [
                'old_path' => $old,
                'reason'   => '新库中没有这条已发布且有正文的稿件（未迁入、未审或按数据策略不公开）',
            ];
        }

        // 专题目录：旧站目录里多出来的 /zl<日期> 也要有对应映射，缺一个就报一个
        foreach (glob($siteRoot . '/' . self::TOPIC_DIR_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (isset($map['/' . $name . '/'])) {
                continue;
            }
            $result['unmapped'][] = [
                'old_path' => '/' . $name . '/',
                'reason'   => '旧专题目录不在已知清单里（已知 13 个，见 RedirectMap::topicDirs）',
            ];
        }
        return $result;
    }

    /**
     * 校验每条映射的目标产物是否真的存在（配 --check=<发布目录> 用），
     * 上线前跑一遍，避免 301 指向 404。
     *
     * @return list<array{old_path:string, target:string, reason:string}>
     */
    public function missingTargets(string $publishDir): array
    {
        $publishDir = rtrim($publishDir, '/');
        $missing = [];
        foreach ($this->exact() as $old => $row) {
            $target = $row['target'];
            $file = $publishDir . ($target === '/' ? '/index.html' : (rtrim($target, '/') . (str_ends_with($target, '/') ? '/index.html' : '')));
            if (!is_file($file)) {
                $missing[] = ['old_path' => $old, 'target' => $target, 'reason' => '发布目录里没有 ' . $file];
            }
        }
        return $missing;
    }

    /** @return string CSV（old_path,new_path,note），给甲方核对用 */
    public function csv(): string
    {
        $lines = ["old_path,new_path,note"];
        foreach ($this->exact() as $old => $row) {
            $lines[] = implode(',', [self::csvCell($old), self::csvCell($row['target']), self::csvCell($row['note'])]);
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * 供 Nginx 用的片段：旧地址形态一律转给 PHP 入口，由入口查 sys_url_redirect
     * 精确判定并累计命中数。这样只登记得起的地址才会 301，不会把没迁移的老稿件
     * 301 到一个 404，也避免在 Nginx 里维护两万行 map。
     */
    public function nginx(string $fastcgiPass = 'php:9000', string $scriptFilename = '/var/www/backend/public/index.php'): string
    {
        $pattern = '^/(?:html/news-view-[0-9]+\.html'
            . '|news_view\.php|news_list\.php|news_list_about\.php'
            . '|cq_view\.php|cq_list\.php|qy_list\.php'
            . '|zl[0-9]+/?)$';
        $lines = [
            '# 旧地址 301（由 php backend/bin/redirects.php --out=<发布目录> 生成，请勿手改）',
            '# 映射明细在 sys_url_redirect 表里，由 PHP 入口按下表精确判定；这里只把旧路径形态转给入口。',
            '# 若上线后旧地址命中量很大，可在本文件底部按注释里的写法加一条正则直跳，绕开 PHP。',
            '',
            'location ~ ' . $pattern . ' {',
            '    include fastcgi_params;',
            '    fastcgi_pass ' . $fastcgiPass . ';',
            '    fastcgi_param SCRIPT_FILENAME ' . $scriptFilename . ';',
            '    fastcgi_param SCRIPT_NAME /index.php;',
            '    fastcgi_param REQUEST_URI $request_uri;',
            '}',
            '',
            '# 命中量上来后可选：静态文章页走纯正则直跳（不查库、不计命中数）',
            '# location ~ ^/html/news-view-([0-9]+)\.html$ { return 301 /article/$1.html; }',
            '',
        ];
        return implode("\n", $lines);
    }

    /**
     * 请求路径可能对应的映射键，按精确度从高到低。
     *
     * @return list<string>
     */
    private function candidateKeys(string $path, string $id, string $region): array
    {
        $path = '/' . ltrim($path, '/');
        $keys = [];

        $id = trim($id);
        $region = trim($region);
        // 县区子站（q 不是主站 22）本期不映射，避免把县区稿件指到主站栏目
        if ($id !== '' && ($region === '' || $region === '22')) {
            $keys[] = $path . '?id=' . $id;
        }

        $keys[] = $path;
        return $keys;
    }

    /** @return list<int> */
    private function publishedArticleIds(): array
    {
        $rows = $this->db->select(
            'SELECT article_id FROM cms_article
             WHERE site_id = :site AND status = :status AND public_scope = :scope AND has_body = 1
             ORDER BY article_id ASC',
            // 与发布器同一个条件：归档稿件不产静态页，也不该登记 301（否则会跳到 404）
            ['site' => $this->siteId, 'status' => 'published', 'scope' => 'public']
        );
        return array_map(static fn (array $row): int => (int) $row['article_id'], $rows);
    }

    /** @return list<string> */
    private function publishedChannelTypes(): array
    {
        $rows = $this->db->select(
            'SELECT type_code FROM sys_channel
             WHERE site_id = :site AND status = :status
             ORDER BY sort_no ASC, channel_id ASC',
            ['site' => $this->siteId, 'status' => 'published']
        );
        return array_map(static fn (array $row): string => (string) $row['type_code'], $rows);
    }

    /** @return array<string, string> 栏目号 => 栏目页地址 */
    private function channelPaths(): array
    {
        if ($this->channelPaths !== null) {
            return $this->channelPaths;
        }
        $rows = $this->db->select(
            'SELECT type_code, slug FROM sys_channel
             WHERE site_id = :site AND status = :status
             ORDER BY sort_no ASC, channel_id ASC',
            ['site' => $this->siteId, 'status' => 'published']
        );
        $channels = [];
        foreach ($rows as $row) {
            $channels[] = ['type' => (string) $row['type_code'], 'slug' => (string) $row['slug']];
        }
        $this->channelPaths = StaticPaths::channelPaths($channels);
        return $this->channelPaths;
    }

    /**
     * 旧站专题目录。旧站目录里有 13 个 zl<日期>，但迁移脚本运行时不一定能读到
     * 旧站目录，所以这里按形态补上已知的日期目录（取自实读目录名）。
     *
     * @return list<string>
     */
    private function topicDirs(): array
    {
        return [
            '/zl20180106/', '/zl20190105/', '/zl20190923/', '/zl20200105/',
            '/zl20210225/', '/zl20210331/', '/zl20211008/', '/zl20220108/',
            '/zl20220331/', '/zl20230206/', '/zl20240131/', '/zl20250206/',
            '/zl20260225/',
        ];
    }

    private static function csvCell(string $value): string
    {
        return str_contains($value, ',') || str_contains($value, '"')
            ? '"' . str_replace('"', '""', $value) . '"'
            : $value;
    }
}
