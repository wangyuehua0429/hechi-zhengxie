<?php

declare(strict_types=1);

namespace HechiZx\Content;

/**
 * 正文清洗：全链路唯一出口。
 *
 * 为什么需要它：正文此前没有任何清洗——含标签的正文原样入库（`ArticleController::normalizeContent()`），
 * 之后接口、静态页、前台详情页三处原样输出。上富文本编辑器会放大这个风险，所以清洗层与编辑器同批落地。
 *
 * 白名单按本机实测确定，几处与直觉不同，改动前请先看原因：
 * - 允许 `div`：存量正文里 `div` 出现 1958 处，是最主要的块级容器；
 * - 不允许 `figure`／`figcaption`：HTMLPurifier 只带 HTML 4.01 与 XHTML 定义，写进白名单会产生
 *   PHP 警告且元素照样被丢弃；
 * - `video`／`source` 要自己补元素定义：HTMLPurifier 的 HTML 4.01 定义里没有它们，
 *   而存量稿件 61454 带视频；
 * - 内联 `style` 放行 `text-align`／`font-weight`／`font-style`／`text-decoration`
 *   与 `font-family`／`font-size`／`color`／`background-color`：前四个是编辑器对齐与强调所需，
 *   后四个对应后台「字体」「字号」「文字颜色」「背景色」四个功能（2026-09-12 经确认放开）；
 *   其余样式（位置、边距、行高等）一律剥离，避免从 Word／网页带进来的排版噪音污染全站；
 * - 协议只放行 `http`／`https`／`mailto`，站内相对路径原样保留（存量图片大量使用相对路径）；
 * - 绝对外链自动补 `target="_blank"` 与 `rel="noopener noreferrer"`，站内相对链接不补。
 *
 * 缓存：定义缓存指向 `backend/storage/htmlpurifier`，不落进 vendor 目录（部署环境 vendor 可能只读，
 * 落进去会退化成每请求重建定义并写警告）。
 *
 * 白名单一旦变化必须同步提升 {@see self::DEFINITION_REV}，否则会命中旧的定义缓存。
 */
final class HtmlSanitizer
{
    /** 白名单版本号：改动白名单时 +1，用于让 HTMLPurifier 的定义缓存失效 */
    private const DEFINITION_REV = 3;

    /** 允许的标签与属性 */
    private const ALLOWED = 'p,br,strong,b,em,i,u,s,del,h2,h3,h4,ul,ol,li,blockquote,div,span,'
        . 'a[href|title|rel|target],img[src|alt|width|height],'
        . 'video[src|poster|controls|width|height],source[src|type],*[style]';

    private static ?\HTMLPurifier $purifier = null;

    /** 清洗一段正文，返回可安全入库与输出的 HTML */
    public static function clean(string $html): string
    {
        return self::purifier()->purify($html);
    }

    private static function purifier(): \HTMLPurifier
    {
        if (self::$purifier instanceof \HTMLPurifier) {
            return self::$purifier;
        }

        $auto = dirname(__DIR__, 2) . '/vendor/htmlpurifier/library/HTMLPurifier.auto.php';
        if (!is_file($auto)) {
            throw new \RuntimeException('正文清洗组件缺失：' . $auto);
        }
        require_once $auto;

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('Cache.SerializerPath', self::cacheDir());
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
        $config->set('CSS.AllowedProperties', [
            'text-align', 'font-weight', 'font-style', 'text-decoration',
            'font-family', 'font-size', 'color', 'background-color',
        ]);
        // 缺 alt 的图片默认会拿文件名当 alt，会把 art61454-1.jpg 这类文件名读出来，改成空 alt
        $config->set('Attr.DefaultImageAlt', '');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('HTML.TargetBlank', true);
        $config->set('HTML.Allowed', self::ALLOWED);

        // 自定义元素要挂在带版本号的定义上，才能与缓存配合
        $config->set('HTML.DefinitionID', 'hechi-article');
        $config->set('HTML.DefinitionRev', self::DEFINITION_REV);
        $definition = $config->maybeGetRawHTMLDefinition();
        if ($definition !== null) {
            $definition->addElement('video', 'Block', 'Optional: #PCDATA | source | img | br', 'Common', [
                'src'      => 'URI',
                'poster'   => 'URI',
                'controls' => 'Enum#controls,',
                'width'    => 'Length',
                'height'   => 'Length',
            ]);
            $definition->addElement('source', 'Block', 'Empty', 'Common', [
                'src'  => 'URI',
                'type' => 'Text',
            ]);
        }

        self::$purifier = new \HTMLPurifier($config);

        return self::$purifier;
    }

    private static function cacheDir(): string
    {
        $storage = (string) hechi_config('paths.storage', dirname(__DIR__, 2) . '/storage');
        $dir = rtrim($storage, '/') . '/htmlpurifier';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
