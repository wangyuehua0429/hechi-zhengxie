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
    .article-body img { max-width: 100%; height: auto; }
    footer { margin-top: 40px; border-top: 1px solid #e5e5e5; padding-top: 12px; color: #999; font-size: 13px; }
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
