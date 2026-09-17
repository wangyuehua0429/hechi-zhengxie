<?php

declare(strict_types=1);

namespace HechiZx\Content;

/**
 * 正文展示归一化：只作用于「对外出口」，不改库。
 *
 * 为什么需要它（2026-09-14 按开发库 2773 篇公开稿实测）：
 * - 空段：2574 篇正文在段落之间留了 `<div><br></div>`，配上段落下边距 16px，
 *   相邻两段之间出现 63px 空档（约两行）；2522 一篇则因此浪费 2047px 竖向空间；
 * - 双倍缩进：669 篇正文自带「　　」全角缩进，与样式里的 `text-indent: 2em` 叠加成 4 字；
 * - 嵌套块：旧库正文里 `div` 套 `div`、`strong` 包块很常见，样式只命中直接子元素，
 *   于是「有的段落缩进、有的不缩进」；
 * - 资源地址：720 篇正文与 2063 条图集记录仍指向旧站 `gxhczx.gov.cn`，且是 http，
 *   上线 https 会被混合内容拦掉；而本地 `uploads/legacy` 只有 227/2102 个文件，
 *   一律改写会造成约 89% 破图，所以只对「本地确实有文件」的资源改写成
 *   `/uploads/legacy/…`，本地没有的改写成同源 `/uploadfiles/…`——旧站域名切到新站后，
 *   指向旧站的绝对地址必然 404，同源地址至少由 `/uploadfiles/` 兼容路由兜住
 *   （路由见 deploy/nginx/default.conf 与 backend/public/router.php）；
 * - 标题抄在首行：1058 篇正文以加粗标题行开头，其中与 h1 标题一字不差的那部分会在页面上
 *   同一句话出现两次，只把这种「完全重复」的首行去掉；引题属于正文内容，留在正文开头第一行。
 * - 末尾署名：旧站正文结尾普遍带「(黄荞丹 覃可论)」「口黄正华」这类署名，与标题下的作者栏重复，
 *   按 {@see AuthorSignature} 的口径删掉（只删与作者栏对得上的，职务说明与图片署名不动）。
 *
 * 调用位置：`Api\ArticleController::show()` 与 `Publish\Publisher`，都紧跟
 * {@see HtmlSanitizer::clean()} 之后。后台编辑回填读的是库里的原文，不经过这里。
 */
final class BodyNormalizer
{
    /** 根容器 id：加载片段时套一层，便于取回正文内容 */
    private const ROOT_ID = 'hzx-body-root';

    /** 会被拍平到父级的容器标签（列表、标题等保持原结构不动） */
    private const CONTAINER_TAGS = ['div', 'p'];

    /** 块级标签：出现在行内标签里属于非法嵌套，需要提升为兄弟节点 */
    private const BLOCK_TAGS = ['div', 'p', 'ul', 'ol', 'blockquote', 'h2', 'h3', 'h4'];

    /** 行内标签（旧库正文里 common 的「<strong><div>…」写法就靠这组修） */
    private const INLINE_TAGS = ['strong', 'b', 'em', 'i', 'u', 's', 'del', 'span', 'a', 'small', 'sub', 'sup'];

    /** 需要裁掉首部缩进的块级标签 */
    private const INDENT_TAGS = ['div', 'p', 'blockquote', 'h2', 'h3', 'h4'];

    /** 正文题区最多看几行（引题＋主标题＋副题） */
    private const TITLE_ZONE_MAX = 3;

    /**
     * 题区行的首行缩进：两个全角空格。
     *
     * 必须写在 `<strong>` 里面——实测后台富文本编辑器（SunEditor）同步内容时会把
     * strong 外侧的行首空白丢掉（64049 打开编辑页后首行缩进消失、保存后库里也没了）。
     */
    public const TITLE_INDENT = "\u{3000}\u{3000}";

    /** 视为「有内容」的标签：块里只要有它们就不算空段 */
    private const CONTENT_TAGS = ['img', 'video', 'source', 'iframe', 'table', 'embed', 'object', 'canvas', 'svg', 'hr'];

    /** 视频扩展名：图集出口只剔除这些，其余（含没扩展名的）一律当图片保留 */
    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'wmv', 'flv', 'mkv', 'webm', 'm4v', 'mpeg', 'mpg', 'rmvb', 'ogv'];

    /** @var array<string, bool> 本地资源存在性缓存，避免同一图片反复 stat */
    private static array $fileExistsCache = [];

    /**
     * 归一化一段正文（不改库，只作用于展示出口；标题只用于判断首行是否完全重复）。
     */
    public static function normalize(string $html, string $title = '', string $author = ''): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        // 末尾署名先按字符串删（署名可能跨 <div>/<br> 节点），删空的块交给下面的 dropEmptyBlocks
        $html = AuthorSignature::strip($html, $author);
        if ($html === '') {
            return '';
        }

        $doc = self::load($html);
        if ($doc === null) {
            return $html;
        }
        $root = $doc->getElementById(self::ROOT_ID);
        if ($root === null) {
            return $html;
        }

        self::flattenBlocks($root);
        self::hoistBlocksOutOfInline($root);
        self::dropEmptyInlineTags($root);
        self::dropEmptyBlocks($root);
        self::trimIndents($root);
        self::rewriteResourceUrls($root);
        self::dropTitleDuplicate($root, $title);

        return trim(self::innerHtml($doc, $root));
    }

    /** 图集出口：只保留图片文件（旧库里 50 条图集记录是 mp4，渲染成空白格） */
    public static function isImagePath(string $path): bool
    {
        $clean = strtok($path, '?');
        $ext = strtolower((string) pathinfo((string) $clean, PATHINFO_EXTENSION));

        return !in_array($ext, self::VIDEO_EXTENSIONS, true);
    }

    /**
     * 拆出正文开头的题区（最多 $limit 行整块加粗）。
     *
     * 后台编辑页用它做两件事：把题区回填进「原标题」三个输入框、让编辑器里的正文不再重复显示题区；
     * 保存时再用字段重新拼回正文最前，所以库里「题区＋正文」的形态不变，前台照旧从正文里读题区。
     *
     * @return array{lines: list<string>, rest: string} lines 为题区文字（按行），rest 为去掉题区后的正文
     */
    public static function splitTitleZone(string $html, int $limit = self::TITLE_ZONE_MAX): array
    {
        $html = trim($html);
        if ($html === '') {
            return ['lines' => [], 'rest' => ''];
        }
        $doc = self::load($html);
        $root = $doc?->getElementById(self::ROOT_ID);
        if ($doc === null || $root === null) {
            return ['lines' => [], 'rest' => $html];
        }

        $lines = [];
        $nodes = [];
        foreach (iterator_to_array($root->childNodes) as $child) {
            if ($child instanceof \DOMText) {
                if (self::visibleText($child->nodeValue ?? '') !== '') {
                    break;
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if (!in_array(strtolower($child->tagName), self::CONTAINER_TAGS, true) || !self::isWholeBold($child)) {
                break;
            }
            $text = self::collapse($child->textContent);
            if ($text === '') {
                continue;
            }
            $lines[] = $text;
            $nodes[] = $child;
            if (count($lines) >= max(1, $limit)) {
                break;
            }
        }
        if ($lines === []) {
            return ['lines' => [], 'rest' => $html];
        }
        foreach ($nodes as $node) {
            $node->parentNode?->removeChild($node);
        }

        return ['lines' => $lines, 'rest' => trim(self::innerHtml($doc, $root))];
    }

    /**
     * 图集出口：剔掉视频文件，并按正文同一规则改写资源地址。
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public static function normalizeImages(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            if (!self::isImagePath($path)) {
                continue;
            }
            $out[] = self::normalizeResourceUrl($path);
        }

        return $out;
    }

    // ---------------------------------------------------------------- 解析

    private static function load(string $html): ?\DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // 片段没有 html/body，靠 meta 声明编码，避免 DOMDocument 把中文按 ISO-8859-1 处理
        $ok = $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
            . '<div id="' . self::ROOT_ID . '">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $ok ? $doc : null;
    }

    /** 把 div/p 里的块级子元素提升为兄弟节点，直到没有可提升的为止 */
    private static function flattenBlocks(\DOMNode $root): void
    {
        do {
            $changed = false;
            foreach ([...self::elementsByName($root, 'div'), ...self::elementsByName($root, 'p')] as $node) {
                if ($node->parentNode === null) {
                    continue;
                }
                $hoisted = [];
                foreach (iterator_to_array($node->childNodes) as $child) {
                    if ($child instanceof \DOMElement
                        && in_array(strtolower($child->tagName), self::CONTAINER_TAGS, true)
                        && !in_array($child, $hoisted, true)) {
                        $hoisted[] = $child;
                    }
                }
                if ($hoisted === []) {
                    continue;
                }
                $ref = $node->nextSibling;
                foreach ($hoisted as $child) {
                    $node->parentNode->insertBefore($child, $ref);
                }
                $changed = true;
            }
        } while ($changed);
    }

    /**
     * 把行内标签里的块级元素提升出来。
     *
     * 旧库正文里「<strong><div>…</div></strong>」这类写法很常见，浏览器会自行拆分，
     * 于是同一篇稿子里「有的段落缩进、有的不缩进」；这里先按 DOM 语义修好再输出。
     */
    private static function hoistBlocksOutOfInline(\DOMNode $root): void
    {
        do {
            $changed = false;
            foreach (self::BLOCK_TAGS as $tag) {
                foreach (self::elementsByName($root, $tag) as $node) {
                    $parent = $node->parentNode;
                    if (!$parent instanceof \DOMElement
                        || !in_array(strtolower($parent->tagName), self::INLINE_TAGS, true)) {
                        continue;
                    }
                    $target = $parent;
                    while ($target->parentNode instanceof \DOMElement
                        && in_array(strtolower($target->parentNode->tagName), self::INLINE_TAGS, true)) {
                        $target = $target->parentNode;
                    }
                    $grand = $target->parentNode;
                    if ($grand === null) {
                        continue;
                    }
                    $grand->insertBefore($node, $target->nextSibling);
                    $changed = true;
                }
            }
        } while ($changed);
    }

    /** 清掉提升块级元素后留下的空行内标签（如空 <strong>） */
    private static function dropEmptyInlineTags(\DOMNode $root): void
    {
        do {
            $removed = false;
            foreach (self::INLINE_TAGS as $tag) {
                foreach (self::elementsByName($root, $tag) as $node) {
                    if ($node->parentNode === null || $node->getElementsByTagName('img')->length > 0) {
                        continue;
                    }
                    if (self::visibleText($node->textContent) !== '') {
                        continue;
                    }
                    $node->parentNode->removeChild($node);
                    $removed = true;
                }
            }
        } while ($removed);
    }

    /** 删除只含空白／<br>／&nbsp; 的空段 */
    private static function dropEmptyBlocks(\DOMNode $root): void
    {
        do {
            $removed = false;
            foreach ([...self::elementsByName($root, 'div'), ...self::elementsByName($root, 'p')] as $node) {
                if ($node->parentNode === null || !self::isVisuallyEmpty($node)) {
                    continue;
                }
                $node->parentNode->removeChild($node);
                $removed = true;
            }
        } while ($removed);
    }

    private static function isVisuallyEmpty(\DOMElement $element): bool
    {
        foreach (self::CONTENT_TAGS as $tag) {
            if ($element->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }

        return self::visibleText($element->textContent) === '';
    }

    /** 去掉块级元素开头的空白、全角空格、&emsp; 与 <br>，缩进统一交给样式表 */
    private static function trimIndents(\DOMNode $root): void
    {
        foreach (self::INDENT_TAGS as $tag) {
            foreach (self::elementsByName($root, $tag) as $element) {
                self::trimLeading($element);
            }
        }
    }

    private static function trimLeading(\DOMElement $element): void
    {
        while (($first = $element->firstChild) !== null) {
            if ($first instanceof \DOMElement && strtolower($first->tagName) === 'br') {
                $element->removeChild($first);
                continue;
            }
            // 首部缩进可能包在加粗／行内标签里（旧库名单稿常见 <div><strong>　　…</strong></div>）
            if ($first instanceof \DOMElement && in_array(strtolower($first->tagName), self::INLINE_TAGS, true)) {
                self::trimLeading($first);
                if (self::visibleText($first->textContent) === '') {
                    $element->removeChild($first);
                    continue;
                }
            }
            if ($first instanceof \DOMText) {
                $trimmed = (string) preg_replace('/^[\s\x{00a0}\x{2000}-\x{200b}\x{202f}\x{205f}\x{3000}]+/u', '', $first->nodeValue ?? '');
                if ($trimmed === '') {
                    $element->removeChild($first);
                    continue;
                }
                if ($trimmed !== $first->nodeValue) {
                    $first->nodeValue = $trimmed;
                }
            }
            break;
        }
    }

    /** 旧站资源地址改写：本地有文件换成 `/uploads/legacy/…`，否则换成同源 `/uploadfiles/…` */
    private static function rewriteResourceUrls(\DOMNode $root): void
    {
        foreach (['img' => ['src'], 'video' => ['src', 'poster'], 'source' => ['src']] as $tag => $attrs) {
            foreach (self::elementsByName($root, $tag) as $element) {
                foreach ($attrs as $attr) {
                    $value = $element->getAttribute($attr);
                    if ($value === '') {
                        continue;
                    }
                    $rewritten = self::normalizeResourceUrl($value);
                    if ($rewritten !== $value) {
                        $element->setAttribute($attr, $rewritten);
                    }
                }
            }
        }
    }

    /** 单个资源地址：本地有文件换 `/uploads/legacy/…`，否则换同源 `/uploadfiles/…`（兼容路由兜底） */
    public static function normalizeResourceUrl(string $url): string
    {
        if (!preg_match('~^https?://(?:www\.)?gxhczx\.gov\.cn/uploadfiles/(.+)$~i', $url, $matches)) {
            return $url;
        }
        $relative = $matches[1];
        // 只有真正的「上跳路径段」才跳过本地文件判定：文件名里带 `..`（如 附件1..pdf）是合法名字，
        // 不能连坐。带 `..` 段时也不回旧站绝对地址——给同源路径，浏览器会先把 `..` 折叠掉，
        // 最坏落到站点根 404，不会再指向旧站域名；兼容路由自己也会拒绝越出 legacy 根的请求。
        if (in_array('..', explode('/', $relative), true)) {
            return '/uploadfiles/' . $relative;
        }

        $uploads = (string) hechi_config('paths.uploads', dirname(__DIR__, 2) . '/public/uploads');
        $local = rtrim($uploads, '/') . '/legacy/uploadfiles/' . $relative;
        if (self::fileExists($local)) {
            return '/uploads/legacy/uploadfiles/' . $relative;
        }

        // 本地没有文件：仍给同源路径。旧站域名切过来后，指向旧站的绝对地址必然 404，
        // 且 http 写法在 https 站点会被按混合内容拦掉；同源地址则由 `/uploadfiles/`
        // 兼容路由指向同一份 legacy 目录，图片补齐后无需重新发布即可生效。
        return '/uploadfiles/' . $relative;
    }

    private static function fileExists(string $path): bool
    {
        if (!array_key_exists($path, self::$fileExistsCache)) {
            self::$fileExistsCache[$path] = is_file($path);
        }

        return self::$fileExistsCache[$path];
    }

    /**
     * 标题重复行处理：按「正文题区」的行数判断，而不是拿首行单独跟标题比。
     *
     * 背景（2026-09-14 定稿，开发库 2773 篇公开稿实测）：
     * - 旧库正文开头常带「引题＋主标题」两行加粗题区（题区 2 行 982 篇、3 行及以上 89 篇），
     *   其中一部分引题与网页标题恰好相同（例如 63904「黄恩率队到都安开展专题调研」）；
     * - 也有只把标题抄一行进正文的（题区 1 行且与标题完全相同 28 篇，例如 154《中国人民政治协商会议章程》）；
     * - 还有 1660 篇正文开头根本没有题区。
     *
     * 规则：
     * - 题区 1 行且与标题一字不差（含前面加破折号的写法）→ 删掉，正文不再重复网页标题；
     * - 题区 2 行及以上 → 整段保留，即使引题与网页标题相同（引题是正文内容的一部分）；
     * - 其余情况（无题区、单行但与标题不同）→ 不动。
     */
    private static function dropTitleDuplicate(\DOMNode $root, string $title): void
    {
        $zone = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMText) {
                if (self::visibleText($child->nodeValue ?? '') !== '') {
                    return;   // 正文以裸文本开头，不谈题区
                }
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if (!in_array(strtolower($child->tagName), self::CONTAINER_TAGS, true) || !self::isWholeBold($child)) {
                break;        // 题区到此结束
            }
            if (self::collapse($child->textContent) !== '') {
                $zone[] = $child;
            }
            if (count($zone) >= self::TITLE_ZONE_MAX) {
                break;
            }
        }

        // 没有题区，或题区是「引题＋主标题」结构：整段保留
        if (count($zone) !== 1) {
            return;
        }

        $plain = self::collapse($title);
        $text = self::collapse($zone[0]->textContent);
        if ($plain === '' || $text === '') {
            return;
        }
        $stripped = (string) preg_replace('/^[—\-]{2,}/u', '', $text);
        if ($text !== $plain && $stripped !== $plain) {
            return;
        }

        $parent = $zone[0]->parentNode;
        if ($parent !== null) {
            $parent->removeChild($zone[0]);
        }
    }

    /** 判断块内是否只有一个加粗元素、没有其他可见文本 */
    private static function isWholeBold(\DOMElement $element): bool
    {
        $bold = null;
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                if ($bold !== null || !in_array(strtolower($child->tagName), ['strong', 'b'], true)) {
                    return false;
                }
                $bold = $child;
                continue;
            }
            if ($child instanceof \DOMText && self::visibleText($child->nodeValue ?? '') !== '') {
                return false;
            }
        }
        if ($bold === null) {
            return false;
        }
        foreach (self::CONTAINER_TAGS as $tag) {
            if ($bold->getElementsByTagName($tag)->length > 0) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------- 工具

    private static function visibleText(string $text): string
    {
        return (string) preg_replace('/[\s\x{00a0}\x{2000}-\x{200b}\x{202f}\x{205f}\x{3000}]+/u', '', $text);
    }

    private static function collapse(string $text): string
    {
        $text = (string) preg_replace('/[\x{00a0}\x{2002}\x{2003}\x{2009}\x{3000}]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return list<\DOMElement>
     */
    private static function elementsByName(\DOMNode $root, string $tag): array
    {
        $nodes = [];
        foreach ($root->getElementsByTagName($tag) as $node) {
            if ($node instanceof \DOMElement) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private static function innerHtml(\DOMDocument $doc, \DOMNode $root): string
    {
        $html = '';
        foreach ($root->childNodes as $child) {
            $html .= (string) $doc->saveHTML($child);
        }

        return $html;
    }
}
