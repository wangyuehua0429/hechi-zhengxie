#!/usr/bin/env php
<?php

/**
 * 正文清洗层检查：HtmlPurifier 白名单的行为验证。
 *
 *   php tests/sanitizer-check.php
 *
 * 不需要数据库与服务，直接跑类本身。退出码 0 为全部通过，1 为有用例失败，2 为脚本自身出错。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/backend/src/bootstrap.php';

use HechiZx\Content\HtmlSanitizer;

$failures = 0;
$total = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $total;
    $total += 1;
    if (!$ok) {
        $failures += 1;
    }
    fwrite(STDOUT, ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($ok || $detail === '' ? '' : '  —  ' . $detail) . "\n");
}

if (!class_exists(HtmlSanitizer::class)) {
    fwrite(STDERR, "清洗类尚未实现：" . HtmlSanitizer::class . "\n");
    exit(2);
}

/* ---------------------------------------------------------------- 载荷剥离 */

$payloads = [
    'script 标签'      => '<p>前</p><script>alert(1)</script><p>后</p>',
    'img onerror'      => '<img src="x" onerror="alert(1)">',
    'javascript 链接'  => '<a href="javascript:alert(1)">点我</a>',
    'svg onload'       => '<svg onload="alert(1)"><circle r="10"/></svg>',
    'data 协议图片'    => '<img src="data:image/png;base64,iVBORw0KGgo=">',
    'iframe 嵌入'      => '<iframe src="https://evil.example/"></iframe>',
    'style 块'         => '<style>body{display:none}</style><p>正文</p>',
    'onclick 属性'     => '<p onclick="alert(1)">点我</p>',
];

$dangerPattern = '/<script|onerror|onload|onclick|javascript:|<iframe|<style|data:image/i';

foreach ($payloads as $name => $html) {
    $clean = HtmlSanitizer::clean($html);
    check('剥离：' . $name, preg_match($dangerPattern, $clean) !== 1, $clean);
}

check(
    '剥离：script 内容不进正文，前后文字保留',
    str_contains(HtmlSanitizer::clean('<p>前</p><script>alert(1)</script><p>后</p>'), '前')
        && str_contains(HtmlSanitizer::clean('<p>前</p><script>alert(1)</script><p>后</p>'), '后')
        && !str_contains(HtmlSanitizer::clean('<p>前</p><script>alert(1)</script><p>后</p>'), 'alert')
);

/* ---------------------------------------------------------------- 白名单保留 */

$allowed = [
    'p'          => '<p>段落</p>',
    'br'         => '<p>上<br>下</p>',
    'strong／b'  => '<p><strong>粗</strong><b>粗</b></p>',
    'em／i'      => '<p><em>斜</em><i>斜</i></p>',
    'u／s／del'  => '<p><u>下划线</u><s>删除线</s><del>删除线</del></p>',
    'h2／h3／h4' => '<h2>二</h2><h3>三</h3><h4>四</h4>',
    'ul／ol／li' => '<ul><li>无序</li></ul><ol><li>有序</li></ol>',
    'blockquote' => '<blockquote>引用</blockquote>',
    'div'        => '<div><p>旧库的 div 结构</p></div>',
    'a href'     => '<a href="https://example.com">外链</a>',
    'img'        => '<img src="/uploads/a.jpg" alt="图" width="600" height="400">',
];

foreach ($allowed as $name => $html) {
    $clean = HtmlSanitizer::clean($html);
    $tag = preg_replace('/[^a-z0-9\/].*$/', '', $name);
    check('保留：' . $name, str_contains($clean, '<' . $tag), $clean);
}

/* ---------------------------------------------------------------- 属性细节 */

$align = HtmlSanitizer::clean('<p style="text-align:center;color:red;font-family:宋体;font-size:18px;position:fixed;margin-top:99px">排版</p>');
check(
    '排版样式：对齐、颜色、字体、字号保留，其它内联样式剥离',
    str_contains($align, 'text-align:center')
        && preg_match('/(?<![-\w])color\s*:/i', $align) === 1
        && stripos($align, 'font-family') !== false
        && stripos($align, 'font-size') !== false
        && !str_contains($align, 'position') && !str_contains($align, 'margin-top'),
    $align
);
$highlight = HtmlSanitizer::clean('<span style="background-color:#ffe08a">高亮</span>');
check(
    '排版样式：内联 span 上的背景色保留（编辑器给文字上色就是写 span）',
    str_contains($highlight, '<span') && stripos($highlight, 'background-color') !== false,
    $highlight
);

$relative = HtmlSanitizer::clean('<p><img src="images/channel/art61454-1.jpg" alt="旧稿"></p>');
check('相对路径：原样保留，不被绝对化', str_contains($relative, 'src="images/channel/art61454-1.jpg"'), $relative);

$external = HtmlSanitizer::clean('<a href="https://example.com">外链</a>');
check(
    '外链：自动补 target=_blank 与 rel=noopener noreferrer',
    str_contains($external, 'target="_blank"') && str_contains($external, 'noopener') && str_contains($external, 'noreferrer'),
    $external
);

$internal = HtmlSanitizer::clean('<a href="detail.html?id=1">站内</a>');
check('站内链接：不加 target', !str_contains($internal, 'target='), $internal);

$video = HtmlSanitizer::clean('<div><video src="/uploads/a.mp4" poster="/uploads/a.png" controls></video></div>');
check(
    'video：存量稿件 61454 的视频结构保留',
    str_contains($video, '<video') && str_contains($video, 'src="/uploads/a.mp4"') && str_contains($video, 'poster="/uploads/a.png"'),
    $video
);

$videoDanger = HtmlSanitizer::clean('<video src="javascript:alert(1)" onerror="alert(1)" controls></video>');
check('video：协议与事件仍被剥离', !preg_match('/javascript:|onerror/i', $videoDanger), $videoDanger);

/* ---------------------------------------------------------------- 已知限制与实际行为 */

$warnings = [];
set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
    $warnings[] = $msg;
    return true;
});
$figure = HtmlSanitizer::clean('<figure><img src="/uploads/a.jpg" alt="图"><figcaption>图说</figcaption></figure>');
restore_error_handler();

check('figure：不产生 PHP 警告（HTML 4.01 定义里没有这个元素）', $warnings === [], implode(' / ', array_slice($warnings, 0, 2)));
check('figure：标签被丢、文字保留', !str_contains($figure, '<figure') && str_contains($figure, '图说'), $figure);

/* ---------------------------------------------------------------- 幂等与缓存 */

$once = HtmlSanitizer::clean('<p style="text-align:center">居中</p><script>alert(1)</script>');
$twice = HtmlSanitizer::clean($once);
check('幂等：清洗结果再清洗不变', $once === $twice, $once . ' → ' . $twice);

$vendorCache = dirname(__DIR__) . '/backend/vendor/htmlpurifier/library/HTMLPurifier/DefinitionCache/Serializer';
$vendorCacheFiles = is_dir($vendorCache) ? glob($vendorCache . '/**/*.ser') ?: [] : [];
check(
    '缓存：定义缓存不写进 vendor 目录（应指向 backend/storage）',
    $vendorCacheFiles === [],
    '发现 ' . count($vendorCacheFiles) . ' 个缓存文件'
);

/* ---------------------------------------------------------------- 空值与纯文本 */

check('空串：返回空串', HtmlSanitizer::clean('') === '');
check('纯文本：不加标签', HtmlSanitizer::clean('一段没有标签的文字') === '一段没有标签的文字');

fwrite(STDOUT, "\n共 " . $total . " 项，" . ($failures === 0 ? '全部通过' : $failures . " 项失败") . "\n");
exit($failures === 0 ? 0 : 1);
