<?php

/**
 * 静态页模板（正式版，2026-09-17 B1）：与前端内页同一套外壳与样式。
 *
 * 站头、主导航、面包屑、侧栏（最新新闻／图片新闻）与页脚的结构对齐
 * `frontend/home/detail.html` 与 `frontend/home/js/shell.js` 的渲染结果，CSS 直接复用
 * `/css/style.css`、`/css/inner.css`；产物落在发布目录（`/article/…`、`/channel/…`）下，
 * 所以图片、样式、脚本一律用站点根开头的绝对地址。
 *
 * 与前端内页的两处有意差异：① 侧栏条数固定（最新新闻 10 条 + 图片新闻 4 张），
 * 不做 JS 的按高度配平；② 数据在服务端渲染，页面不依赖 /api/v1。
 *
 * @var string $title
 * @var string $description
 * @var string $heading
 * @var string $bodyHtml
 * @var string $canonical
 * @var string $siteName
 * @var string $domain
 * @var string $generatedAt
 * @var string $kind                   article | channel | home
 * @var list<array<string,mixed>> $nav
 * @var array<string,mixed>      $meta
 * @var list<array<string,mixed>> $latest
 * @var list<array<string,mixed>> $thumbs
 * @var list<array<string,mixed>> $crumb
 * @var array<string,string>     $articleMeta
 * @var string $editor
 * @var list<array<string,mixed>> $attachments
 */

declare(strict_types=1);

$esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

$kind = (string) ($kind ?? 'article');
$navItems = array_values(array_filter(
    (array) ($nav ?? []),
    static fn (mixed $item): bool => is_array($item) && empty($item['hidden']) && (string) ($item['title'] ?? '') !== ''
));
$homeItem = $navItems[0] ?? ['title' => '首页', 'url' => '/'];
$navRest = array_slice($navItems, 1);
$navMid = (int) ceil(count($navRest) / 2);

$metaInfo = (array) ($meta ?? []);
$crumbItems = array_values(array_filter((array) ($crumb ?? []), 'is_array'));
$latestItems = array_values(array_filter((array) ($latest ?? []), 'is_array'));
$thumbItems = array_values(array_filter((array) ($thumbs ?? []), 'is_array'));
$articleMeta = (array) ($articleMeta ?? []);
$attachmentItems = array_values(array_filter((array) ($attachments ?? []), 'is_array'));
$editor = (string) ($editor ?? '');
$today = date('Y年n月j日');
$metaText = static fn (string $key): string => (string) ($metaInfo[$key] ?? '');

/** 站头导航单个链接：站内地址当前页打开，站外地址新标签打开 */
$navLink = static function (array $item) use ($esc): string {
    $url = (string) ($item['url'] ?? '/');
    $external = preg_match('~^https?://~i', $url) === 1;
    return '<a href="' . $esc($url) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>'
        . $esc((string) ($item['title'] ?? '')) . '</a>';
};
?>
<!DOCTYPE html>
<html lang="zh-CN" class="mast-folded">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $esc($title) ?></title>
  <meta name="description" content="<?= $esc($description) ?>">
  <link rel="canonical" href="<?= $esc('https://' . $domain . $canonical) ?>">
  <link rel="icon" href="/favicon.ico">
  <link rel="stylesheet" href="/css/style.css">
  <link rel="stylesheet" href="/css/inner.css">
  <script src="/js/motion-toggle.js"></script>
</head>
<body data-font="default">
  <a class="skip-link" href="#main">跳到主要内容</a>

  <div class="topbar">
    <div class="container topbar-inner">
      <div class="topbar-left">
        <span class="topbar-date" id="topbarDate"><?= $esc($today) ?></span>
        <span class="topbar-org"><?= $esc($metaText('owner') !== '' ? $metaText('owner') : $siteName) ?></span>
      </div>
      <div class="topbar-right">
        <div class="font-tools" role="group" aria-label="字号调整">
          <span>适老化</span>
          <button type="button" data-font="small" aria-label="缩小字号">A-</button>
          <button type="button" data-font="default" aria-label="标准字号" class="active">默认</button>
          <button type="button" data-font="large" aria-label="放大字号">A+</button>
        </div>
        <button type="button" class="contrast-btn" id="contrastBtn" aria-label="切换高对比度" aria-pressed="false">无障碍</button>
        <button type="button" class="motion-btn" id="motionBtn" aria-label="暂停页面自动动效" aria-pressed="false">暂停动效</button>
      </div>
    </div>
  </div>

  <header class="site-header">
    <div class="container masthead">
      <a href="/" aria-label="<?= $esc($siteName) ?>首页">
        <div class="masthead-layers" aria-hidden="false">
          <img class="mast-bg active" src="/images/head_bg1.jpg" alt="" fetchpriority="high">
          <img class="mast-bg" src="/images/head_bg2.jpg" alt="" fetchpriority="low" decoding="async">
          <img class="mast-bg" src="/images/head_bg3.jpg" alt="" fetchpriority="low" decoding="async">
          <img class="mast-logo" src="/images/head_logo.png" alt="<?= $esc($siteName) ?>" decoding="async">
          <img class="mast-slogan" src="/images/head_slogan.png" alt="政治协商 民主监督 参政议政">
        </div>
      </a>
    </div>
    <div class="container header-inner">
      <a class="brand" href="/" aria-label="<?= $esc($siteName) ?>首页">
        <span class="brand-badge" aria-hidden="true">政&nbsp;协</span>
        <span class="brand-text">
          <strong><?= $esc($siteName) ?></strong>
          <small><?= $esc($metaText('owner')) ?></small>
        </span>
      </a>
      <button type="button" class="nav-toggle" id="navToggle" aria-label="打开菜单" aria-expanded="false" aria-controls="siteNav">
        <span></span><span></span><span></span>
      </button>
    </div>
    <nav class="site-nav" id="siteNav" aria-label="主导航">
      <div class="container nav-wrap">
        <a class="nav-home" id="navHome" href="<?= $esc((string) ($homeItem['url'] ?? '/')) ?>" aria-label="返回首页">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/>
            <path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
          </svg>
          <span><?= $esc((string) ($homeItem['title'] ?? '首页')) ?></span>
        </a>
        <div class="nav-rows" id="navRows">
          <div class="nav-row"><?= implode('', array_map($navLink, array_slice($navRest, 0, $navMid))) ?></div>
          <div class="nav-row"><?= implode('', array_map($navLink, array_slice($navRest, $navMid))) ?></div>
        </div>
      </div>
    </nav>
  </header>

  <main id="main">
    <?php if ($crumbItems !== []): ?>
      <nav class="crumb-bar" aria-label="当前位置">
        <div class="container">
          <ol class="crumb" id="crumb">
            <?php foreach ($crumbItems as $index => $item): ?>
              <?php
              $label = (string) ($item['label'] ?? '');
              $url = (string) ($item['url'] ?? '');
              $isCurrent = $index === count($crumbItems) - 1;
              ?>
              <?php if ($isCurrent || $url === ''): ?>
                <li<?= $isCurrent ? ' class="is-current" aria-current="page"' : '' ?>><?= $esc($label) ?></li>
              <?php else: ?>
                <li><a href="<?= $esc($url) ?>"><?= $esc($label) ?></a></li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ol>
        </div>
      </nav>
    <?php endif; ?>

    <div class="container inner-layout">
      <div class="inner-main">
        <article class="article-panel" id="articlePanel">
          <header class="article-head">
            <h1 class="article-title"><?= $esc($heading) ?></h1>
            <?php if (($articleMeta['date'] ?? '') !== '' || ($articleMeta['source'] ?? '') !== '' || ($articleMeta['author'] ?? '') !== ''): ?>
              <div class="article-meta">
                <?php if (($articleMeta['date'] ?? '') !== ''): ?>
                  <span>发布时间：<b><?= $esc((string) $articleMeta['date']) ?></b></span>
                <?php endif; ?>
                <?php if (($articleMeta['source'] ?? '') !== ''): ?>
                  <span>来源：<b><?= $esc((string) $articleMeta['source']) ?></b></span>
                <?php endif; ?>
                <?php if (($articleMeta['author'] ?? '') !== ''): ?>
                  <span>作者：<b><?= $esc((string) $articleMeta['author']) ?></b></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </header>

          <?php if ($kind === 'article'): ?>
            <div class="article-toolbar" id="articleToolbar">
              <div class="tool-group" role="group" aria-label="正文字号">
                <span>正文字号</span>
                <button type="button" data-size="down" aria-label="缩小正文字号">A-</button>
                <button type="button" data-size="reset" aria-label="恢复默认正文字号">默认</button>
                <button type="button" data-size="up" aria-label="放大正文字号">A+</button>
              </div>
              <div class="tool-group">
                <button type="button" id="printBtn">打印本页</button>
                <button type="button" id="copyLinkBtn">复制链接</button>
              </div>
            </div>
          <?php endif; ?>

          <div class="article-body" id="articleBody"><?= (string) $bodyHtml ?></div>

          <?php if ($attachmentItems !== []): ?>
            <section class="article-attach" aria-label="附件下载">
              <h2>附件下载</h2>
              <ul>
                <?php foreach ($attachmentItems as $file): ?>
                  <li><a href="<?= $esc((string) ($file['url'] ?? '')) ?>"><?= $esc((string) ($file['name'] ?? '')) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endif; ?>

          <?php if ($kind === 'article' && $editor !== ''): ?>
            <p class="article-sign">责任编辑：<?= $esc($editor) ?></p>
          <?php endif; ?>
        </article>
      </div>

      <aside class="inner-side is-sticky" id="innerSide">
        <section class="side-card">
          <div class="side-head"><h3>最新新闻</h3></div>
          <div class="side-body">
            <ul class="side-list side-list--plain" id="latestList">
              <?php if ($latestItems === []): ?>
                <li class="empty-state">暂无新闻。</li>
              <?php else: ?>
                <?php foreach ($latestItems as $item): ?>
                  <li><a href="<?= $esc((string) ($item['url'] ?? '')) ?>" title="<?= $esc((string) ($item['title'] ?? '')) ?>"><?= $esc((string) ($item['title'] ?? '')) ?></a></li>
                <?php endforeach; ?>
              <?php endif; ?>
            </ul>
          </div>
        </section>
        <?php if ($thumbItems !== []): ?>
          <section class="side-card">
            <div class="side-head"><h3>图片新闻</h3></div>
            <div class="side-thumbs" id="thumbGrid">
              <?php foreach ($thumbItems as $item): ?>
                <a href="<?= $esc((string) ($item['url'] ?? '')) ?>" title="<?= $esc((string) ($item['title'] ?? '')) ?>">
                  <img src="<?= $esc((string) ($item['img'] ?? '')) ?>" alt="<?= $esc((string) ($item['title'] ?? '')) ?>" loading="lazy">
                  <span><?= $esc((string) ($item['title'] ?? '')) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>
      </aside>
    </div>
  </main>

  <footer class="site-footer">
    <div class="container footer-inner" id="footerBody">
      <div class="footer-mark">
        <a href="https://bszs.conac.cn/sitename?method=searchIndex" target="_blank" rel="noopener" title="党政机关网站标识">
          <img src="/images/gov-badge.png" alt="党政机关网站标识" width="67" height="80">
        </a>
      </div>
      <div class="footer-text">
        <p>版权所有：<?= $esc($metaText('owner')) ?></p>
        <p class="footer-copy">
          <a href="https://<?= $esc($metaText('domain')) ?>" target="_blank" rel="noopener"><?= $esc($metaText('copyright')) ?></a>
        </p>
        <p class="footer-contact">投稿邮箱：<a href="mailto:<?= $esc($metaText('contactEmail')) ?>"><?= $esc($metaText('contactEmail')) ?></a><span class="footer-sep"></span>联系电话：<?= $esc($metaText('contactPhone')) ?></p>
        <div class="footer-icp">
          <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener"><?= $esc($metaText('icp')) ?></a>
          <span class="footer-police"><img src="/images/ghs.png" alt="公安备案徽标"><?= $esc($metaText('police')) ?></span>
        </div>
        <p>开发维护：河池市融媒体中心&nbsp;&nbsp;河池市数媒创新科技发展有限公司</p>
        <p class="footer-note">建议使用 Chrome / Edge 等现代浏览器访问，分辨率 1280×768 及以上</p>
      </div>
      <div class="footer-qr">
        <img src="/images/wechat-qr.jpg" alt="河池政协微信公众号二维码" width="120" height="120">
        <p class="footer-qr-text">扫码关注<br>河池政协微信公众号</p>
      </div>
    </div>
  </footer>

  <script>
  /* 静态页自带的交互：与 frontend/home/js/shell.js 的 bindInteractions 同款行为，
     只覆盖不依赖接口的部分（菜单、字号、高对比度、打印、复制链接、正文字号）。 */
  (function () {
    var toggle = document.getElementById("navToggle"), nav = document.getElementById("siteNav");
    if (toggle && nav) {
      toggle.addEventListener("click", function () {
        var open = nav.classList.toggle("open");
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
      });
    }
    document.querySelectorAll(".font-tools button").forEach(function (b) {
      b.addEventListener("click", function () {
        document.body.setAttribute("data-font", b.dataset.font);
        document.querySelectorAll(".font-tools button").forEach(function (x) {
          x.classList.toggle("active", x === b);
        });
      });
    });
    var cb = document.getElementById("contrastBtn");
    if (cb) {
      cb.addEventListener("click", function () {
        document.body.classList.toggle("high-contrast");
        cb.setAttribute("aria-pressed", document.body.classList.contains("high-contrast") ? "true" : "false");
      });
    }
    var topbar = document.querySelector(".topbar"), lastY = window.scrollY;
    window.addEventListener("scroll", function () {
      if (!topbar) return;
      var y = window.scrollY;
      if (y > lastY && y > 80) topbar.classList.add("is-hidden");
      else if (y < lastY) topbar.classList.remove("is-hidden");
      lastY = y;
    }, { passive: true });
    var printBtn = document.getElementById("printBtn");
    if (printBtn) printBtn.addEventListener("click", function () { window.print(); });
    var copyBtn = document.getElementById("copyLinkBtn");
    if (copyBtn) {
      copyBtn.addEventListener("click", function () {
        var done = function () {
          copyBtn.textContent = "已复制";
          setTimeout(function () { copyBtn.textContent = "复制链接"; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(location.href).then(done, done);
        else done();
      });
    }
    var body = document.querySelector(".article-body"), scale = 1;
    document.querySelectorAll("[data-size]").forEach(function (b) {
      b.addEventListener("click", function () {
        var v = b.dataset.size;
        scale = v === "up" ? Math.min(1.4, scale + 0.1) : (v === "down" ? Math.max(0.8, scale - 0.1) : 1);
        if (body) body.style.setProperty("--body-scale", String(scale));
      });
    });
  })();
  </script>
</body>
</html>
