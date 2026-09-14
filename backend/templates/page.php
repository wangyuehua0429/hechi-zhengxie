<?php

/**
 * 静态页模板（最小可读版）：发布器把 $page 传进来渲染。
 * 正式模板在阶段 C 内用 frontend/home 的结构替换本文件，发布器调用方式不变。
 *
 * @var string $title
 * @var string $description
 * @var string $heading
 * @var string $bodyHtml
 * @var string $canonical
 * @var string $siteName
 * @var string $generatedAt
 */

declare(strict_types=1);

$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $esc($title) ?></title>
  <meta name="description" content="<?= $esc($description) ?>">
  <link rel="canonical" href="<?= $esc($canonical) ?>">
  <style>
    body { margin: 0; font: 16px/1.8 "Microsoft YaHei", "PingFang SC", sans-serif; color: #333; }
    .wrap { max-width: 1080px; margin: 0 auto; padding: 24px 16px; }
    header { border-bottom: 2px solid #b01e23; padding-bottom: 12px; margin-bottom: 24px; }
    header a { color: #b01e23; text-decoration: none; font-size: 22px; font-weight: 700; }
    h1 { font-size: 26px; line-height: 1.5; }
    .meta, time { color: #888; font-size: 14px; }
    .article-rows { list-style: none; padding: 0; }
    .article-rows li { display: flex; justify-content: space-between; gap: 16px; border-bottom: 1px dashed #e5e5e5; padding: 10px 0; }
    .article-rows a { color: #222; text-decoration: none; }
    .article-rows a:hover { color: #b01e23; }
    /* 正文样式：与 frontend/home/css/inner.css 的详情页口径一致，参照全国政协网稿件页
       （宋体／16px／行高 1.625（26px）／首行缩进 2 字／段距 1.625em／图片 ≤700px／左对齐）；
       段距由样式统一给（正文已归一化掉库里的空段），列表符号补回来。 */
    .article-body {
      font-family: "宋体", SimSun, "Songti SC", "Songti TC", serif;
      font-size: 16px;
      line-height: 1.625;
      color: #121212;
      overflow-wrap: anywhere;
    }
    .article-body p,
    .article-body div { margin: 0 0 1.625em; text-indent: 2em; }
    .article-body div div { margin-bottom: 0; }
    .article-body > :last-child { margin-bottom: 0; }
    .article-body img {
      display: block;
      box-sizing: border-box;
      max-width: min(100%, 700px);
      height: auto;
      margin: 20px auto;
      text-indent: 0;
      border: 1px solid rgba(0, 0, 0, 0.08);
      border-radius: 3px;
    }
    /* 图片加载失败时留一层浅底，配合 alt 文案，不出现刺眼的纯白块 */
    .article-body img { background: #f7f8fa; }
    /* 视频要显式给宽度：元数据没加载出来时 video 的固有尺寸是 0，只写 max-width 会塌成一条线 */
    .article-body video,
    .article-body iframe {
      display: block;
      box-sizing: border-box;
      width: 100%;
      max-width: min(100%, 700px);
      height: auto;
      margin: 20px auto;
      text-indent: 0;
      background: #000;
      border: 1px solid rgba(0, 0, 0, 0.08);
      border-radius: 3px;
    }
    .article-body video:not([width]) { aspect-ratio: 16 / 9; }
    .article-body ul,
    .article-body ol { margin: 0 0 0.9em; padding-left: 2em; }
    .article-body ul { list-style: disc; }
    .article-body ol { list-style: decimal; }
    .article-body li { margin-bottom: 0.35em; text-indent: 0; }
    .article-body h2,
    .article-body h3,
    .article-body h4 { margin: 1.2em 0 0.5em; text-indent: 0; line-height: 1.5; }
    .article-body h2 { font-size: 20px; }
    .article-body h3 { font-size: 18px; }
    .article-body h4 { font-size: 17px; }
    .article-body blockquote {
      margin: 0.9em 0;
      padding: 2px 0 2px 14px;
      color: #666;
      text-indent: 0;
      border-left: 3px solid #e5e5e5;
    }
    .article-body table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 15px; }
    .article-body th { background: #fbfcfd; }
    .article-body td,
    .article-body th { border: 1px solid #e5e5e5; padding: 8px 10px; }
    footer { margin-top: 40px; border-top: 1px solid #e5e5e5; padding-top: 12px; color: #999; font-size: 13px; }

    @media print {
      @page { margin: 16mm; }
      header,
      footer { display: none; }
      .wrap { max-width: none; padding: 0; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <header><a href="/"><?= $esc($siteName) ?></a></header>
    <main>
      <h1><?= $esc($heading) ?></h1>
      <?= $bodyHtml ?>
    </main>
    <footer>
      <p>中国人民政治协商会议河池市委员会　本页由静态化发布器生成于 <?= $esc($generatedAt) ?></p>
    </footer>
  </div>
</body>
</html>
