#!/usr/bin/env php
<?php

/**
 * 正文展示归一化检查：BodyNormalizer 的去空段、裁缩进、拍平嵌套、资源改写与引题抽取。
 *
 *   php tests/normalizer-check.php
 *
 * 不需要数据库与服务，直接跑类本身。退出码 0 为全部通过，1 为有用例失败，2 为脚本自身出错。
 */

declare(strict_types=1);

require dirname(__DIR__) . '/backend/src/bootstrap.php';

use HechiZx\Content\BodyNormalizer;
use HechiZx\Content\AuthorSignature;
use HechiZx\Content\HtmlSanitizer;

$failures = 0;
$total = 0;
$skipped = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $total;
    $total += 1;
    if (!$ok) {
        $failures += 1;
    }
    fwrite(STDOUT, ($ok ? 'PASS  ' : 'FAIL  ') . $name . ($ok || $detail === '' ? '' : '  —  ' . $detail) . "\n");
}

/** 环境不具备（例如上传目录只读）时跳过，不当失败 */
function skip(string $name, string $reason): void
{
    global $skipped;
    $skipped += 1;
    fwrite(STDOUT, 'SKIP  ' . $name . '  —  ' . $reason . "\n");
}

/** 走一遍真实出口顺序：先清洗，再归一化 */
function normalize(string $html, string $title = '', string $author = ''): string
{
    return BodyNormalizer::normalize(HtmlSanitizer::clean($html), $title, $author);
}

/* ---------------------------------------------------------------- 空段 */

$empty = normalize('<div><strong>标题行</strong></div><div><br></div><div>　　正文第一段。</div><p>&nbsp;</p>');
check(
    '空段：<div><br></div> 与只含 &nbsp; 的空段被删除',
    !str_contains($empty, '<br>')
        && substr_count($empty, '<div') === 2
        && !str_contains($empty, '<p>'),
    $empty
);

$keepImg = normalize('<div><br></div><p><img src="/uploads/a.jpg" alt=""></p>');
check(
    '空段：含图片的块不算空段，不被删除',
    str_contains($keepImg, '<img'),
    $keepImg
);

/* ---------------------------------------------------------------- 首部缩进 */

$indent = normalize('<div>　　正文第一段。</div><div>&emsp;&emsp;正文第二段。</div><div>&nbsp;&nbsp;正文第三段。</div><div><br>正文第四段。</div>');
$leadsTrimmed = substr_count($indent, '正文');
check(
    '缩进：首部的全角空格、&emsp;、&nbsp; 与 <br> 都被裁掉（缩进交给样式）',
    $leadsTrimmed === 4
        && !str_contains($indent, "\u{3000}")
        && !str_contains($indent, "\u{00a0}")
        && !str_contains($indent, '<br>'),
    $indent
);

$innerSpace = normalize('<div>韦&emsp;平（壮族） 韦茂明（壮族）</div>');
check(
    '缩进：正文中间的对齐空格（人名中的 &emsp;）保留',
    str_contains($innerSpace, "\u{2003}"),
    $innerSpace
);

/* ---------------------------------------------------------------- 嵌套结构 */

$nested = normalize('<div><div>内层第一段。</div><div>内层第二段。</div></div>');
check(
    '嵌套：div 套 div 被拍平成兄弟节点',
    !str_contains($nested, '<div><div') && substr_count($nested, '<div>') === 2,
    $nested
);

$inline = normalize('<strong> <div>　　民革（7人）</div></strong>');
check(
    '嵌套：行内标签里的块级元素被提升为块，首部缩进一并裁掉',
    str_contains($inline, '<div>') && str_contains($inline, '民革（7人）')
        && !str_contains($inline, "\u{3000}"),
    $inline
);

/* ---------------------------------------------------------------- 资源地址 */

$missing = normalize('<p><img src="http://gxhczx.gov.cn/uploadfiles/20230726/20230726115207496.jpg" alt=""></p>');
check(
    '资源：本地没有文件的旧站图片保留旧站地址，但强制 https（避免混合内容被拦）',
    str_contains($missing, 'src="https://www.gxhczx.gov.cn/uploadfiles/20230726/20230726115207496.jpg"'),
    $missing
);

// 本地有文件的分支：在 uploads/legacy 下建一个一次性文件，跑完删掉
$uploads = (string) hechi_config('paths.uploads', dirname(__DIR__) . '/backend/public/uploads');
$fixtureDir = rtrim($uploads, '/') . '/legacy/uploadfiles/_selfcheck';
$fixtureName = 'selfcheck-' . bin2hex(random_bytes(4)) . '.jpg';
$fixturePath = $fixtureDir . '/' . $fixtureName;
if (!is_dir($fixtureDir)) {
    @mkdir($fixtureDir, 0775, true);
}
$madeFixture = is_dir($fixtureDir) && file_put_contents($fixturePath, 'x') !== false;

if (!$madeFixture) {
    skip('资源：本地有文件的旧站图片改写成站内路径', '无法在 uploads/legacy 建测试文件：' . $fixtureDir);
} else {
    $local = normalize('<p><img src="http://gxhczx.gov.cn/uploadfiles/_selfcheck/' . $fixtureName . '" alt=""></p>');
    check(
        '资源：本地有文件的旧站图片改写成站内路径',
        str_contains($local, 'src="/uploads/legacy/uploadfiles/_selfcheck/' . $fixtureName . '"'),
        $local
    );
    @unlink($fixturePath);
}

$video = normalize('<div><video src="http://gxhczx.gov.cn/uploadfiles/20231121/a.mp4" poster="http://gxhczx.gov.cn/uploadfiles/20231121/a.png" controls></video></div>');
check(
    '资源：video 的 src 与 poster 一起改写',
    str_contains($video, 'src="https://www.gxhczx.gov.cn/uploadfiles/20231121/a.mp4"')
        && str_contains($video, 'poster="https://www.gxhczx.gov.cn/uploadfiles/20231121/a.png"'),
    $video
);

$relative = normalize('<p><img src="images/channel/art62180-1.jpg" alt="相对路径"></p>');
check('资源：站内相对路径原样不动', str_contains($relative, 'src="images/channel/art62180-1.jpg"'), $relative);

/* ---------------------------------------------------------------- 正文题区与标题重复 */

$same = normalize('<div><strong>中国人民政治协商会议章程</strong></div><div>　　第一条 内容。</div>', '中国人民政治协商会议章程');
check(
    '题区：只有一行题且与标题一字不差时去掉（正文不再重复网页标题）',
    !str_contains($same, '<strong>') && str_contains($same, '第一条 内容。'),
    $same
);

$twoLines = normalize('<div><strong>黄恩率队到都安开展专题调研</strong></div><div><strong>聚焦常态化帮扶 筑牢防返贫底线</strong></div><div>　　正文。</div>', '黄恩率队到都安开展专题调研');
check(
    '题区：两行题区（引题＋主标题）整段保留，即使引题与标题相同',
    str_starts_with($twoLines, '<div><strong>黄恩率队到都安开展专题调研</strong></div>')
        && str_contains($twoLines, '聚焦常态化帮扶 筑牢防返贫底线'),
    $twoLines
);

$expanded = normalize('<div><strong>许显辉赴河池市调研时提出</strong></div><div><strong>生态与资源协同发力</strong></div><div>　　正文。</div>', '许显辉赴河池市调研');
check(
    '题区：引题行与标题不同时同样保留在正文第一行',
    str_starts_with($expanded, '<div><strong>许显辉赴河池市调研时提出</strong></div>')
        && str_contains($expanded, '生态与资源协同发力'),
    $expanded
);

$dash = normalize('<div><strong>——河池市政协挖掘优秀传统文化工作侧记</strong></div><div>正文。</div>', '河池市政协挖掘优秀传统文化工作侧记');
check('题区：单行题区带前置破折号时按标题重复去掉', !str_contains($dash, '——') && str_contains($dash, '正文。'), $dash);

$unrelated = normalize('<div><strong>中国共产党（22人）</strong></div><div>　　韦平（壮族）</div>', '政协河池市第五届委员会委员名单');
check(
    '题区：单行加粗且与标题不同（名单分组行）原样保留',
    str_contains($unrelated, '中国共产党（22人）') && str_contains($unrelated, '韦平（壮族）'),
    $unrelated
);

$boldBody = normalize('<p>普通首段，没有加粗。</p>', '某标题');
check('题区：正文没有题区时不动', $boldBody === '<p>普通首段，没有加粗。</p>', $boldBody);

$imageFirst = normalize('<p><img src="/uploads/a.jpg" alt=""></p>', '某标题');
check('题区：开头是图片时不算题区', str_contains($imageFirst, '<img'), $imageFirst);

$threeLines = normalize('<div><strong>标题行</strong></div><div><strong>副题一</strong></div><div><strong>副题二</strong></div><div>正文。</div>', '标题行');
check(
    '题区：三行题区按上限整体保留',
    str_contains($threeLines, '标题行') && str_contains($threeLines, '副题一') && str_contains($threeLines, '副题二'),
    $threeLines
);

/* ---------------------------------------------------------------- 图集出口 */

/* ---------------------------------------------------------------- 题区拆分（后台编辑页用） */

$split = BodyNormalizer::splitTitleZone('<div>　　<strong>引题行</strong></div><div><strong>主标题行</strong></div><div><br></div><div>　　正文第一段。</div>');
check(
    '题区拆分：两行题区被取出，正文里不再包含它们',
    $split['lines'] === ['引题行', '主标题行']
        && !str_contains($split['rest'], '引题行')
        && str_contains($split['rest'], '正文第一段。'),
    json_encode($split, JSON_UNESCAPED_UNICODE)
);

$splitNone = BodyNormalizer::splitTitleZone('<p>正文第一段。</p><p><strong>文中的加粗句</strong></p>');
check(
    '题区拆分：正文不以加粗行开头时不动',
    $splitNone['lines'] === [] && str_contains($splitNone['rest'], '正文第一段。'),
    json_encode($splitNone, JSON_UNESCAPED_UNICODE)
);

$splitCap = BodyNormalizer::splitTitleZone('<div><strong>一</strong></div><div><strong>二</strong></div><div><strong>三</strong></div><div><strong>四</strong></div><div>正文。</div>');
check(
    '题区拆分：最多取 3 行（引题＋主标题＋副题），第四行留在正文',
    $splitCap['lines'] === ['一', '二', '三'] && str_contains($splitCap['rest'], '<strong>四</strong>'),
    json_encode($splitCap, JSON_UNESCAPED_UNICODE)
);

check('题区拆分：空正文返回空', BodyNormalizer::splitTitleZone('') === ['lines' => [], 'rest' => '']);

$images = BodyNormalizer::normalizeImages([
    'http://gxhczx.gov.cn/uploadfiles/20231121/a.png',
    'http://gxhczx.gov.cn/uploadfiles/20231121/b.mp4',
    '/uploads/legacy/uploadfiles/x/c.jpg',
]);
check(
    '图集：剔除视频文件，保留图片并改写旧站地址',
    $images === [
        'https://www.gxhczx.gov.cn/uploadfiles/20231121/a.png',
        '/uploads/legacy/uploadfiles/x/c.jpg',
    ],
    json_encode($images, JSON_UNESCAPED_UNICODE)
);

/* ---------------------------------------------------------------- 兜底与幂等 */

check('空串：返回空正文', BodyNormalizer::normalize('') === '');
check('兜底：HTML 解析失败时原样返回', BodyNormalizer::normalize('<<>><div') !== '');

$once = normalize('<div><br></div><div>　　第一段。</div><div><strong>标题重复行</strong></div>', '标题重复行');
$twice = BodyNormalizer::normalize($once, '标题重复行');
check('幂等：归一化结果再跑一次内容不变', $twice === $once, $once . ' → ' . $twice);

/* ---------------------------------------------------------------- 结构不被破坏 */

$preserved = normalize('<p><strong>粗</strong><em>斜</em><a href="https://example.com">外链</a></p><ul><li>列表项</li></ul><blockquote>引用</blockquote>');
check(
    '保留：加粗、斜体、外链、列表、引用在归一化后都还在',
    str_contains($preserved, '<strong>粗</strong>')
        && str_contains($preserved, '<em>斜</em>')
        && str_contains($preserved, 'https://example.com')
        && str_contains($preserved, '<ul><li>列表项</li></ul>')
        && str_contains($preserved, '<blockquote>引用</blockquote>'),
    $preserved
);

/* ---------------------------------------------------------------- 末尾署名归口作者栏 */

// 旧站正文结尾普遍带「（黄荞丹 覃可论）」「口黄正华」这类署名，与标题下的作者栏重复
$signParen = normalize('<p>正文第一段。</p><div>（黄荞丹 覃可论）</div>', '', '黄荞丹 覃可论');
check(
    '末尾署名：与作者栏一致的括号署名被删（顺带清掉空块）',
    !str_contains($signParen, '黄荞丹') && $signParen === '<p>正文第一段。</p>',
    $signParen
);

$signMarker = normalize('<div>正文第一段。口黄正华<br> （文章刊登于广西政协报 2023年7月18日 3版）</div>', '', '黄正华');
check(
    '末尾署名：方框署名被删，后面的出处说明保留',
    !str_contains($signMarker, '口黄正华') && str_contains($signMarker, '文章刊登于广西政协报'),
    $signMarker
);

$signLabel = normalize('<div>正文第一段。(作者：本报首席记者 罗昌亮)</div>', '', '罗昌亮');
check('末尾署名：带「作者：」标签的署名被删', !str_contains($signLabel, '罗昌亮'), $signLabel);

$signKeepMismatch = normalize('<div>正文第一段。（李八）</div>', '', '钱七');
check('末尾署名：与作者栏不一致的署名不删', str_contains($signKeepMismatch, '（李八）'), $signKeepMismatch);

$signKeepRole = normalize('<div>正文第一段。口潘秋琳<br>（作者系河池市政协副主席）</div>', '', '潘秋琳');
check(
    '末尾署名：署名删掉、职务说明行保留',
    !str_contains($signKeepRole, '口潘秋琳') && str_contains($signKeepRole, '作者系河池市政协副主席'),
    $signKeepRole
);

$signKeepWire = normalize('<div>正文第一段。（新华社北京10月15日电 记者赵超 徐扬）</div>', '', '赵超 徐扬');
check('末尾署名：通讯社电头保留', str_contains($signKeepWire, '新华社北京10月15日电'), $signKeepWire);

// 只在末尾 160 个可见字符内找署名：正文中间（离末尾更远）的同名括号不动
$filler = str_repeat('这是一段较长的正文。', 20);
$signMid = normalize('<div>正文（黄正华）里提到的人名不动。' . $filler . '</div><div>结尾一段。</div>', '', '黄正华');
check('末尾署名：正文中间（窗口外）的同名括号不动', str_contains($signMid, '（黄正华）'), mb_substr($signMid, 0, 40));

$signEmptyAuthor = normalize('<div>正文第一段。（黄正华）</div>', '', '');
check('末尾署名：作者栏为空时不猜不删', str_contains($signEmptyAuthor, '（黄正华）'), $signEmptyAuthor);

check(
    '末尾署名：作者栏为空时可从「口姓名」「（作者：X）」取名',
    AuthorSignature::extractName('<div>正文一段。口潘剑</div>') === '潘剑'
        && AuthorSignature::extractName('<div>正文一段。(作者：本报评论员)</div>') === '本报评论员'
        && AuthorSignature::extractName('<div>正文一段。（作者系河池市政协副主席）</div>') === '',
    '潘剑 / 本报评论员 / 空'
);

fwrite(STDOUT, "\n共 " . $total . " 项，" . ($failures === 0 ? '全部通过' : $failures . " 项失败")
    . ($skipped > 0 ? "（另有 " . $skipped . " 项因环境跳过）" : '') . "\n");
exit($failures === 0 ? 0 : 1);
