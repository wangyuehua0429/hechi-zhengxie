#!/usr/bin/env node
/**
 * 前端页面回归检查（无头 Chromium）
 *
 * 背景：2026-09-11 提交 85918c2 把链接映射换成精简索引后，全部栏目页打不开、
 * 详情页标题多出 undefined，当天未被察觉（只能靠人工发现）。本脚本把当时
 * 的手工检查固化成一条可重复执行的命令，改前端后跑一遍即可。
 *
 * 用法：
 *   node tests/check-pages.mjs                     # 自起静态服务器（默认 8973 端口）
 *   node tests/check-pages.mjs --via-api           # 起 PHP 服务（站点+接口同源，需要 php），验证前端走接口
 *   node tests/check-pages.mjs --only 详情         # 只跑名字含“详情”的用例
 *   node tests/check-pages.mjs --url http://127.0.0.1:8899   # 检查已在跑的站点，不另起服务
 *   node tests/check-pages.mjs --headed            # 显示浏览器窗口，便于肉眼对照
 *   node tests/check-pages.mjs --browser chrome    # 指定浏览器（chrome/chromium/msedge）
 *
 * 依赖：Node 18+ 与 playwright（本机为全局 `npm install -g @playwright/cli`，
 * 脚本会依次尝试本地 node_modules、全局 npm root 下的 playwright）。
 * 浏览器优先用 playwright 自带的 Chromium；若本机缓存里的版本与驱动不匹配，
 * 自动回退到系统已安装的 Google Chrome（channel=chrome）。
 * 静态服务用 python3 -m http.server，与仓库 README 的本地预览方式一致。
 */

import { spawn, spawnSync, execSync } from "node:child_process";
import { existsSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { createRequire } from "node:module";
import { fileURLToPath } from "node:url";

const require = createRequire(import.meta.url);
const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, "..");
const SITE_DIR = path.join(REPO, "frontend", "home");

const DESKTOP = { width: 1440, height: 900 };
const MOBILE = { width: 390, height: 844 };

// ---------------------------------------------------------------- 用例定义

const CASES = [
  {
    name: "首页（桌面 1440）",
    page: "index.html",
    viewport: DESKTOP,
    ready: "#navRows a",
    look: {
      selectors: {
        "#navRows a": 17,
        "#heroCarousel .carousel-slide": 1,
        "#leadersBody .leader-chair": 1,
        "#zxdtList li": 1,
        "#imageMarquee img": 1,
        // 站内横幅 7 个固定位（首屏 2 个专题条幅 + 正文 5 处）
        "[data-banner]": 7
      }
    }
  },
  {
    name: "首页（手机 390）",
    page: "index.html",
    viewport: MOBILE,
    ready: "#navRows a",
    look: {
      selectors: {
        "#navToggle": 1,
        "#heroCarousel .carousel-slide": 1,
        "#zxdtList li": 1
      }
    }
  },
  {
    name: "栏目页·列表（市政协动态 904）",
    page: "channel.html?id=904",
    viewport: DESKTOP,
    ready: "#listWrap .article-rows li",
    look: {
      selectors: {
        "#listWrap .article-rows li": 10,
        "#latestList li": 1,
        "#thumbGrid img": 1,
        "#crumb li": 2
      },
      textIncludes: ["市政协动态"],
      textExcludes: ["数据加载失败", "未找到该栏目"]
    }
  },
  {
    name: "栏目页·分页跳第 2 页（904）",
    page: "channel.html?id=904",
    viewport: DESKTOP,
    ready: "#listWrap .article-rows li",
    kind: "pager"
  },
  {
    name: "栏目页·政协领导（202）",
    page: "channel.html?id=202",
    viewport: DESKTOP,
    ready: "#listWrap .leader-card",
    look: {
      selectors: {
        "#listWrap .leader-card": 9,
        "#listWrap .leader-group": 2,
        "#crumb li": 2
      },
      textIncludes: ["政协领导"],
      textExcludes: ["数据加载失败", "未找到该栏目"]
    }
  },
  {
    name: "栏目页·图片新闻（314）",
    page: "channel.html?id=314",
    viewport: DESKTOP,
    ready: "#listWrap .gallery-card",
    look: {
      selectors: {
        "#listWrap .gallery-card": 10,
        "#latestList li": 1
      },
      textExcludes: ["数据加载失败", "未找到该栏目"]
    }
  },
  {
    name: "栏目页·政协视频（316）",
    page: "channel.html?id=316",
    viewport: DESKTOP,
    ready: "#listWrap .video-card",
    look: {
      selectors: { "#listWrap .video-card": 1 },
      textExcludes: ["数据加载失败", "未找到该栏目"]
    }
  },
  {
    name: "栏目页·专题（topic）",
    page: "channel.html?id=topic",
    viewport: DESKTOP,
    ready: "#listWrap .topic-card",
    look: {
      selectors: { "#listWrap .topic-card": 1 },
      textExcludes: ["数据加载失败", "未找到该栏目"]
    }
  },
  {
    name: "栏目页·县（区）政协（qy）",
    page: "channel.html?id=qy",
    viewport: DESKTOP,
    ready: "#listWrap .article-rows li",
    look: {
      selectors: {
        "#countyLinks .county-link": 5,
        "#listWrap .article-rows li": 10
      },
      textExcludes: ["数据加载失败", "未找到该栏目"]
    }
  },
  {
    name: "栏目页·委员直通车（interactive）",
    page: "channel.html?id=interactive",
    viewport: DESKTOP,
    ready: "#listWrap .interactive-box",
    look: {
      selectors: { "#listWrap .interactive-box": 1 },
      textExcludes: ["数据加载失败", "未找到该栏目"]
    }
  },
  {
    name: "栏目页·未知栏目 id",
    page: "channel.html?id=999999",
    viewport: DESKTOP,
    ready: "#listWrap .empty-state",
    look: {
      textIncludes: ["未找到栏目"],
      textExcludes: ["数据加载失败"]
    }
  },
  {
    name: "详情页·常规（62180，含正文图片）",
    page: "detail.html?id=62180",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    look: {
      selectors: {
        "#articleMeta span": 2,
        "#crumb li": 2
      },
      // 2026-09-14 起：正文里已有图时不再重复出图集条，图集只服务纯图集型稿件
      hidden: ["#articleGallery"],
      textIncludes: ["市政协动态"],
      textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"]
    }
  },
  {
    // 预期基于阶段 A 快照数据：静态目标与脚本自建临时库都成立，
    // 指向开发库时不成立（40029 在开发库是归档稿，接口 404）。
    name: "详情页·附件下载（40029）",
    page: "detail.html?id=40029",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    seededData: true,
    look: {
      visible: ["#articleAttach"],
      textIncludes: ["附件下载"],
      textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"]
    }
  },
  {
    // 同上：62212 在阶段 A 快照里没有正文，开发库里已有正文
    name: "详情页·列表有正文未内置（62212）",
    page: "detail.html?id=62212",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    seededData: true,
    look: {
      textIncludes: ["该篇暂无正文"],
      textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"]
    }
  },
  {
    // 2026-09-14 详情页排版修复的回归点：段距、首行缩进、引题、图集去重。
    // 归一化发生在后端出口（接口与发布器），所以只在接口可用时断言；
    // 接口不可用时前端回退的是阶段 A 原型快照，那份数据不经过出口，不在本用例口径内。
    name: "详情页·正文排版（62180）",
    page: "detail.html?id=62180",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    apiData: true,
    // 前台详情页专有的断言（侧栏目数量、正文字号档位、两栏底边对齐、元信息口径）
    frontendDetail: true,
    // 引题属于正文内容：62180 的正文第一行就是「许显辉赴河池市调研时提出」
    expectLeadLine: "许显辉赴河池市调研时提出",
    kind: "detail-layout"
  },
  {
    // 长文页：右栏高于视口时不再粘住，「图片新闻」仍能看到
    name: "详情页·长文右栏不粘（2522）",
    page: "detail.html?id=2522",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    kind: "sticky-check",
    // 正文 6599px 的长稿：侧栏应配到上限附近（15 条 + 3 行 6 张）
    expectSideMax: true
  },
  {
    name: "详情页·回到顶部（62180）",
    page: "detail.html?id=62180",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    kind: "back-top"
  },
  {
    // 题区两行（引题＋主标题）、引题与网页标题恰好相同：两行都要留在正文（61598 快照与开发库都有）
    name: "详情页·两行题区保留（61598）",
    page: "detail.html?id=61598",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    kind: "title-zone",
    expectLeadLine: "市政协党组（扩大）会议暨五届60次主席会议召开",
    look: {
      textIncludes: ["提升履职效能 奋力书写河池政协新答卷"],
      textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"]
    }
  },
  {
    // 用户点名的例子：正文写的是「引题＋主标题」，引题与网页标题相同也要保留（63904 只在开发库有）
    name: "详情页·引题与标题相同仍保留（63904）",
    page: "detail.html?id=63904",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    kind: "title-zone",
    devOnly: true,
    expectLeadLine: "黄恩率队到都安开展专题调研",
    look: {
      textIncludes: ["聚焦常态化帮扶 筑牢防返贫底线"],
      textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"]
    }
  },
  {
    // 单行题区且与标题一字不差：正文顶部不再重复网页标题（154 只在开发库有）
    name: "详情页·单行重复标题去重（154）",
    page: "detail.html?id=154",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    kind: "title-zone",
    devOnly: true,
    leadLineExcludes: "中国人民政治协商会议章程",
    look: { textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"] }
  },
  {
    name: "详情页·打印样式（62180）",
    page: "detail.html?id=62180",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    kind: "print"
  },
  {
    name: "详情页·未知稿件 id",
    page: "detail.html?id=999999",
    viewport: DESKTOP,
    ready: "#articleBody .empty-state",
    allowApi404: true,
    look: {
      textIncludes: ["未找到该篇信息"],
      textExcludes: ["数据加载失败"]
    }
  },
  {
    name: "详情页·草稿不对外可见（接口模式）",
    page: "detail.html?id=62245",
    viewport: DESKTOP,
    ready: "#articleBody .empty-state",
    onlyApi: true,
    allowApi404: true,
    look: {
      textIncludes: ["未找到该篇信息"],
      textExcludes: ["数据加载失败"]
    }
  },
  {
    name: "详情页（手机 390）",
    page: "detail.html?id=62180",
    viewport: MOBILE,
    ready: "#articleBody > *",
    look: {
      selectors: { "#articleMeta span": 2 },
      textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"]
    }
  },
  {
    name: "刷新回顶·首页",
    page: "index.html",
    viewport: DESKTOP,
    ready: "#zxdtList li",
    kind: "reload-top"
  },
  {
    name: "刷新回顶·栏目页",
    page: "channel.html?id=904",
    viewport: DESKTOP,
    ready: "#listWrap .article-rows li",
    kind: "reload-top"
  },
  {
    name: "刷新回顶·详情页",
    page: "detail.html?id=62180",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    kind: "reload-top"
  }
];

/**
 * 发布产物（Nginx 直出的静态详情页）用例：只在 `--publish` 时跑。
 * 目标目录是临时发布目录，站内 /uploads 资源不在其中，所以放行破图与 404，
 * 只断言样式与归一化结果——这正是「线上直出页」的那套渲染。
 */
const PUBLISH_CASES = [
  {
    name: "静态页·正文排版（62180）",
    page: "article/62180.html",
    viewport: DESKTOP,
    ready: ".article-body > *",
    waitUntil: "domcontentloaded",
    allowBrokenImages: true,
    allowHttpErrors: true,
    kind: "detail-layout",
    look: {
      selectors: { ".article-body": 1, "h1": 1 },
      textIncludes: ["许显辉赴河池市调研时提出"],
      textExcludes: ["信息加载中"]
    }
  },
  {
    name: "静态页·资源地址与图集（49643）",
    page: "article/49643.html",
    viewport: MOBILE,
    ready: ".article-body > *",
    waitUntil: "domcontentloaded",
    allowBrokenImages: true,
    allowHttpErrors: true,
    kind: "static-media"
  }
];

// ---------------------------------------------------------------- 参数与环境

function parseArgs(argv) {
  const opts = { url: "", port: 8973, only: "", headed: false, timeout: 20000, keep: false, browser: "", viaApi: false, publish: false };
  for (let i = 0; i < argv.length; i += 1) {
    const a = argv[i];
    if (a === "--url") opts.url = String(argv[++i] || "").replace(/\/+$/, "");
    else if (a === "--port") opts.port = Number(argv[++i]) || opts.port;
    else if (a === "--only") opts.only = String(argv[++i] || "");
    else if (a === "--timeout") opts.timeout = Number(argv[++i]) || opts.timeout;
    else if (a === "--headed") opts.headed = true;
    else if (a === "--keep") opts.keep = true;
    else if (a === "--via-api") opts.viaApi = true;
    else if (a === "--publish") opts.publish = true;
    else if (a === "--browser") opts.browser = String(argv[++i] || "");
    else if (a === "--help" || a === "-h") opts.help = true;
  }
  return opts;
}

function resolvePhp() {
  for (const bin of ["php", "/opt/homebrew/bin/php", "/usr/local/bin/php", "/usr/bin/php"]) {
    const probe = spawnSync(bin, ["-v"], { encoding: "utf8" });
    if (!probe.error && probe.status === 0) return bin;
  }
  return null;
}

/**
 * 接口模式：临时 SQLite 库 + PHP 内置服务器，一个进程同时提供站点静态文件与 /api/v1。
 * 顺便把一篇已发布稿件改成草稿，用来验证"草稿不对外可见"。
 */
async function startPhpServer(port) {
  const php = resolvePhp();
  if (!php) {
    throw new Error("未找到 php：请先 brew install php，或用 --url 指向已启动的服务");
  }
  const tmpRoot = mkdtempSync(path.join(tmpdir(), "hechi-pages-api-"));
  const env = {
    DB_DRIVER: "sqlite",
    DB_DATABASE: path.join(tmpRoot, "pages.sqlite"),
    APP_ENV: "local",
    APP_DEBUG: "1",
    PUBLISH_OUT: path.join(tmpRoot, "publish")
  };
  const migrate = spawnSync(php, ["backend/bin/migrate.php"], { cwd: REPO, env: { ...process.env, ...env }, encoding: "utf8" });
  const seed = spawnSync(php, ["backend/bin/seed.php"], { cwd: REPO, env: { ...process.env, ...env }, encoding: "utf8" });
  if (migrate.status !== 0 || seed.status !== 0) {
    throw new Error("准备临时库失败：" + (migrate.stderr || seed.stderr || "").trim().split("\n")[0]);
  }
  spawnSync(php, [
    "-r",
    "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " $db->execute(\"UPDATE cms_article SET status = 'draft' WHERE article_id = 62245\");"
  ], { cwd: REPO, env: { ...process.env, ...env }, encoding: "utf8" });

  const child = spawn(php, ["-S", "127.0.0.1:" + port, "-t", "backend/public", "backend/public/router.php"], {
    cwd: REPO,
    env: { ...process.env, ...env },
    stdio: ["ignore", "ignore", "pipe"]
  });
  const base = "http://127.0.0.1:" + port;
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(base + "/api/v1/health");
      if (res.ok) return { child, base, tmpRoot };
    } catch (e) { /* 还没起来 */ }
    await new Promise((r) => setTimeout(r, 200));
  }
  child.kill("SIGTERM");
  throw new Error("PHP 服务 15 秒内未就绪：" + base);
}

// 自带 Chromium 与系统 Chrome 双通道：带版本号的自带浏览器可能尚未下载，
// 此时直接回退到本机 Chrome，避免为跑一条回归检查去下载上百 MB 的浏览器。
async function launchBrowser(chromium, opts) {
  const headless = !opts.headed;
  const tries = opts.browser
    ? [{ channel: opts.browser }]
    : [{}, { channel: "chrome" }, { channel: "msedge" }];
  const errors = [];
  for (const extra of tries) {
    try {
      const browser = await chromium.launch(Object.assign({ headless }, extra));
      return { browser, label: extra.channel || "chromium（playwright 自带）" };
    } catch (e) {
      errors.push((extra.channel || "chromium") + "：" + String((e && e.message) || e).split("\n")[0]);
    }
  }
  throw new Error("浏览器启动失败：\n" + errors.join("\n"));
}

function loadPlaywright() {
  const fails = [];
  for (const spec of ["playwright", "playwright-core"]) {
    try {
      return require(spec);
    } catch (e) {
      fails.push(spec);
    }
  }
  const roots = [];
  try {
    const out = execSync("npm root -g", { encoding: "utf8", stdio: ["ignore", "pipe", "ignore"] }).trim();
    if (out) roots.push(out);
  } catch (e) { /* npm 不可用则跳过 */ }
  roots.push("/opt/homebrew/lib/node_modules", "/usr/local/lib/node_modules");
  const rels = [
    "@playwright/cli/node_modules/playwright",
    "@playwright/test/node_modules/playwright",
    "playwright"
  ];
  for (const root of roots) {
    for (const rel of rels) {
      const dir = path.join(root, rel);
      if (!existsSync(dir)) continue;
      try {
        return require(dir);
      } catch (e) {
        fails.push(dir);
      }
    }
  }
  throw new Error(
    "未找到 playwright（试过：" + fails.join("、") + "）。\n" +
    "请先安装：npm install -g @playwright/cli"
  );
}

async function startStaticServer(dir, port) {
  const child = spawn(
    "python3",
    ["-m", "http.server", String(port), "--bind", "127.0.0.1", "--directory", dir],
    { stdio: ["ignore", "ignore", "pipe"] }
  );
  let stderr = "";
  child.stderr.on("data", (d) => { stderr += String(d); });
  const base = "http://127.0.0.1:" + port;
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) {
    if (child.exitCode !== null) {
      throw new Error("静态服务器启动失败：" + stderr.trim());
    }
    try {
      const res = await fetch(base + "/index.html");
      if (res.ok) return { child, base };
    } catch (e) { /* 还没起来，继续等 */ }
    await new Promise((r) => setTimeout(r, 150));
  }
  child.kill("SIGTERM");
  throw new Error("静态服务器 15 秒内未就绪：" + stderr.trim());
}

// ---------------------------------------------------------------- 单例检查

function countMatches(list, pattern) {
  return list.filter((s) => s.indexOf(pattern) !== -1);
}

async function scrollThrough(page) {
  await page.evaluate(async () => {
    const step = Math.max(200, Math.floor(window.innerHeight * 0.8));
    for (let y = 0; y < document.documentElement.scrollHeight; y += step) {
      window.scrollTo(0, y);
      await new Promise((r) => setTimeout(r, 60));
    }
    window.scrollTo(0, document.documentElement.scrollHeight);
    await new Promise((r) => setTimeout(r, 200));
    window.scrollTo(0, 0);
    await new Promise((r) => setTimeout(r, 100));
  });
}

async function inspect(page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const badHref = [];
    document.querySelectorAll("[href], [src]").forEach((n) => {
      ["href", "src"].forEach((attr) => {
        const v = n.getAttribute(attr);
        if (v && v.indexOf("undefined") !== -1) badHref.push(attr + "=" + v);
      });
    });
    const imgs = Array.from(document.images);
    return {
      title: document.title,
      text: document.body.innerText || "",
      overflowX: doc.scrollWidth - doc.clientWidth,
      imgTotal: imgs.length,
      imgBroken: imgs.filter((i) => i.complete && i.naturalWidth === 0).map((i) => i.src),
      imgPending: imgs.filter((i) => !i.complete).length,
      badHref,
      scrollY: Math.round(window.scrollY)
    };
  });
}

async function runCase(browser, base, c, opts) {
  const failures = [];
  const notes = [];
  const context = await browser.newContext({
    viewport: c.viewport,
    deviceScaleFactor: 1,
    reducedMotion: "no-preference"
  });
  const page = await context.newPage();
  const consoleErrors = [];
  const consoleWarnings = [];
  const pageErrors = [];
  const httpErrors = [];
  const isLocal = (u) => u.startsWith(base);

  page.on("console", (m) => {
    const t = m.type();
    if (t === "error") consoleErrors.push(m.text());
    else if (t === "warning") consoleWarnings.push(m.text());
  });
  page.on("pageerror", (e) => pageErrors.push(String((e && e.message) || e)));
  page.on("requestfailed", (r) => {
    if (isLocal(r.url())) httpErrors.push((r.failure() && r.failure().errorText) + " " + r.url());
  });
  page.on("response", (r) => {
    if (r.status() >= 400 && isLocal(r.url())) httpErrors.push("HTTP " + r.status() + " " + r.url());
  });

  const url = new URL(c.page, base + "/").href;
  try {
    // 发布产物里的图片指向旧站（慢，单请求约 10s），静态页用例只等文档加载完
    const resp = await page.goto(url, { waitUntil: c.waitUntil || "load", timeout: opts.timeout });
    if (!resp || !resp.ok()) failures.push("页面返回 HTTP " + (resp ? resp.status() : "无响应") + "：" + url);
    await page.waitForSelector(c.ready, { state: "attached", timeout: opts.timeout });
    await page.waitForTimeout(250);
    if (c.kind !== "reload-top") await scrollThrough(page);

    // 接口模式：确认页面取数真的走了 /api/v1，而不是悄悄回退到静态快照
    if (opts.viaApi) {
      const mode = await page.evaluate(() => (window.SITE_DATA ? window.SITE_DATA.mode() : "无 SITE_DATA"));
      if (mode !== "api") failures.push("数据源不是接口（实际 " + mode + "）");
      else notes.push("数据源 api");
    }

    const info = await inspect(page);

    // 1) 文本层：回归的两个典型症状
    if (info.title.indexOf("undefined") !== -1) failures.push("标题含 undefined：" + info.title);
    if (info.text.indexOf("undefined") !== -1) failures.push("页面正文含 undefined");
    if (info.text.indexOf("数据加载失败") !== -1) failures.push("页面出现「数据加载失败」");
    if (info.text.indexOf("信息加载中") !== -1) failures.push("详情页标题仍是占位「信息加载中」");
    if (info.badHref.length) failures.push("有 " + info.badHref.length + " 处链接/图片地址含 undefined：" + info.badHref.slice(0, 3).join("，")); 

    // 2) 横向溢出
    if (info.overflowX > 1) failures.push("横向溢出 " + info.overflowX + "px");

    // 3) 破图（已加载完成但尺寸为 0；未触发的懒加载不计）
    if (info.imgBroken.length && !c.allowBrokenImages) {
      failures.push("破图 " + info.imgBroken.length + " 张：" + info.imgBroken.slice(0, 3).join("，"));
    }
    notes.push("图片 " + (info.imgTotal - info.imgBroken.length) + "/" + info.imgTotal + " 张已加载" +
      (info.imgPending ? "（" + info.imgPending + " 张懒加载未触发）" : ""));

    // 4) 控制台与请求。两类 404 属预期，不计失败：
    //    · 静态托管（GitHub Pages 等）下 /api/v1/health 必然 404，前端据此回退静态快照；
    //    · 用例声明 allowApi404 时，接口对"这篇不存在/未发布"返回 404，前端就是要显示"未找到"。
    const allowProbe404 = !opts.viaApi;
    const consoleErrorsReal = (c.allowApi404 || allowProbe404)
      ? consoleErrors.filter((t) => !/status of 404/.test(t))
      : consoleErrors;
    const httpErrorsReal = httpErrors.filter((t) => {
      if (/\/api\/v1\/health/.test(t) && allowProbe404) return false;
      if (c.allowApi404 && /\/api\/v1\/article\//.test(t)) return false;
      return true;
    });
    if (pageErrors.length) failures.push("JS 异常 " + pageErrors.length + " 条：" + pageErrors.slice(0, 2).join(" | "));
    if (consoleErrorsReal.length) failures.push("控制台报错 " + consoleErrorsReal.length + " 条：" + consoleErrorsReal.slice(0, 2).join(" | "));
    if (httpErrorsReal.length && !c.allowHttpErrors) {
      failures.push("请求失败 " + httpErrorsReal.length + " 条：" + httpErrorsReal.slice(0, 3).join(" | "));
    }
    if (consoleWarnings.length) notes.push("控制台警告 " + consoleWarnings.length + " 条");

    // 5) 版式用例：关键容器数量、必须可见的区块、必须出现的文案
    const look = c.look || {};
    for (const [sel, min] of Object.entries(look.selectors || {})) {
      const n = await page.locator(sel).count();
      if (n < min) failures.push("容器 " + sel + " 只有 " + n + " 个，少于预期 " + min);
    }
    for (const sel of look.visible || []) {
      if (!(await page.locator(sel).first().isVisible())) failures.push("区块 " + sel + " 不可见");
    }
    for (const sel of look.hidden || []) {
      const node = page.locator(sel).first();
      if ((await node.count()) && (await node.isVisible())) failures.push("区块 " + sel + " 不该显示");
    }
    for (const s of look.textIncludes || []) {
      if (info.text.indexOf(s) === -1) failures.push("缺少文案「" + s + "」");
    }
    for (const s of look.textExcludes || []) {
      if (info.text.indexOf(s) !== -1) failures.push("出现不该有的文案「" + s + "」");
    }

    // 6) 分页：点第 2 页后列表仍有内容，且页码文案同步
    if (c.kind === "pager") {
      const btn = page.locator('#pager button[data-page="2"]').first();
      if (!(await btn.count())) {
        failures.push("分页条没有第 2 页按钮");
      } else {
        await btn.click();
        await page.waitForTimeout(600);
        const rows = await page.locator("#listWrap .article-rows li").count();
        const countText = await page.locator("#listCount").innerText();
        if (rows < 1) failures.push("翻到第 2 页后列表为空");
        if (countText.indexOf("第 2") === -1) failures.push("翻页后页码文案未同步：" + countText.trim());
        notes.push("第 2 页 " + rows + " 行");
      }
    }

    // 7) 手动刷新回顶（366d7a4 / c9096ee 的回归点）
    if (c.kind === "reload-top") {
      await page.evaluate(() => window.scrollTo(0, 1200));
      await page.waitForTimeout(200);
      const before = await page.evaluate(() => Math.round(window.scrollY));
      await page.reload({ waitUntil: "load", timeout: opts.timeout });
      await page.waitForSelector(c.ready, { state: "attached", timeout: opts.timeout });
      await page.waitForTimeout(400);
      const after = await page.evaluate(() => Math.round(window.scrollY));
      if (before < 500) failures.push("刷新前没滚动到位（scrollY=" + before + "），用例未生效");
      if (after > 50) failures.push("刷新后没有回到顶部（scrollY=" + after + "）");
      notes.push("刷新 " + before + " → " + after);
      const afterText = await page.evaluate(() => document.body.innerText || "");
      if (afterText.indexOf("数据加载失败") !== -1) failures.push("刷新后出现「数据加载失败」");
    }

    // 8) 详情页正文排版（2026-09-14 修复的回归点）
    if (c.kind === "detail-layout") {
      const m = await page.evaluate(() => {
        const body = document.getElementById("articleBody") || document.querySelector(".article-body");
        const blocks = Array.from(body.children).filter((n) => n.nodeType === 1);
        const font = parseFloat(getComputedStyle(body).fontSize);
        const out = {
          font,
          fontFamily: getComputedStyle(body).fontFamily,
          lineHeight: parseFloat(getComputedStyle(body).lineHeight),
          empty: 0,
          leadSpace: 0,
          margins: [],
          indent: null,
          bodyImgs: body.querySelectorAll("img").length,
          galleryHidden: true,
          leadLine: "",
          headExtraLine: false
        };
        const visible = (s) => String(s || "").replace(/[\s\u3000\u00a0]/g, "");
        blocks.forEach((el) => {
          const text = el.textContent || "";
          if (visible(text) === "" && !el.querySelector("img,video,table")) out.empty += 1;
          if (visible(text) !== "" && /^[\s\u3000\u00a0]/.test(text)) out.leadSpace += 1;
          const mb = parseFloat(getComputedStyle(el).marginBottom);
          if (mb > 0) out.margins.push(mb);
        });
        // 首个可见字符距版心的距离：应约等于 2 字（text-indent: 2em）
        const target = blocks.find((el) => visible(el.textContent || "").length > 20);
        if (target) {
          const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
          let node = walker.nextNode();
          while (node && visible(node.nodeValue) === "") node = walker.nextNode();
          if (node) {
            const idx = String(node.nodeValue).search(/[^\s\u3000\u00a0]/);
            if (idx >= 0) {
              const range = document.createRange();
              range.setStart(node, idx);
              range.setEnd(node, idx + 1);
              const box = target.getBoundingClientRect();
              const cs = getComputedStyle(target);
              const contentLeft = box.left + parseFloat(cs.paddingLeft) + parseFloat(cs.borderLeftWidth);
              out.indent = Math.round(range.getBoundingClientRect().left - contentLeft);
            }
          }
        }
        const gallery = document.getElementById("articleGallery");
        if (gallery) out.galleryHidden = gallery.hidden || out.bodyImgs === 0;
        // 顶部只出一行标题：引题属于正文，不能在 h1 上方再占一行
        const sub = document.getElementById("articleSub");
        out.headExtraLine = !!sub && !sub.hidden && visible(sub.textContent) !== "";
        out.leadLine = blocks.length ? visible(blocks[0].textContent || "").slice(0, 40) : "";
        return out;
      });

      if (m.empty > 0) failures.push("正文仍有 " + m.empty + " 个空段（应为 0）");
      if (m.leadSpace > 0) failures.push("正文还有 " + m.leadSpace + " 段自带首部空格（与 2em 缩进叠加）");
      // 正文版式参照全国政协网稿件页：宋体族 / 行高 1.625（16px → 26px）
      if (!/宋体|SimSun|Songti/i.test(m.fontFamily)) {
        failures.push("正文字体不是宋体族：" + String(m.fontFamily).slice(0, 30));
      }
      const lhEm = m.lineHeight / m.font;
      if (Math.abs(lhEm - 1.625) > 0.12) failures.push("正文行高 " + lhEm.toFixed(2) + " 字（参照页为 1.625）");
      const maxMargin = m.margins.length ? Math.max(...m.margins) : 0;
      const minMargin = m.margins.length ? Math.min(...m.margins) : 0;
      // 参照页段距 = 一行（16px 字号下 26px = 1.625em）
      if (maxMargin > m.font * 2.0) failures.push("段间距过大：" + Math.round(maxMargin) + "px（正文 " + Math.round(m.font) + "px）");
      if (m.margins.length && minMargin < m.font * 1.3) failures.push("段间距过小：" + Math.round(minMargin) + "px");
      if (m.indent !== null) {
        const em = m.indent / m.font;
        if (em < 1.4 || em > 2.6) failures.push("首行缩进 " + em.toFixed(1) + " 字（应为 2 字）");
        notes.push("首行缩进 " + em.toFixed(1) + " 字");
      }
      if (!m.galleryHidden) failures.push("正文已有图，图集条仍重复渲染");
      if (m.headExtraLine) failures.push("标题上方多出一行引题（顶部应只有一行标题）");
      if (c.expectLeadLine && m.leadLine.indexOf(c.expectLeadLine) !== 0) {
        failures.push("正文首行不是引题「" + c.expectLeadLine + "」，实际「" + m.leadLine + "」");
      }
      notes.push("正文首行「" + m.leadLine.slice(0, 14) + "…」");
      notes.push("正文 " + Math.round(m.font) + "px/" + lhEm.toFixed(2) + "，段间距 " + Math.round(minMargin) + "–" + Math.round(maxMargin) + "px");

      // 侧栏数量：详情页口径是「短正文固定 10 条 + 2 行 4 张」，长正文最多 15 条 + 3 行 6 张
      if (c.frontendDetail) {
      const sideCounts = await page.evaluate(() => ({
        latest: document.querySelectorAll("#latestList li").length,
        thumbs: document.querySelectorAll("#thumbGrid img").length,
      }));
      if (sideCounts.latest < 10 || sideCounts.latest > 15) {
        failures.push("侧栏最新新闻 " + sideCounts.latest + " 条（应在 10–15 之间）");
      }
      if (sideCounts.thumbs < 4 || sideCounts.thumbs > 6) {
        failures.push("侧栏图片新闻 " + sideCounts.thumbs + " 张（应在 4–6 之间）");
      }
      notes.push("侧栏 " + sideCounts.latest + " 条 / " + sideCounts.thumbs + " 张");

      // 「正文字号」档位走 CSS 变量：点 A+ 变大，且不覆盖 16px 基准与适老化系数
      await page.locator('#articleToolbar button[data-size="up"]').click();
      await page.waitForTimeout(120);
      const bigger = await page.evaluate(() => parseFloat(getComputedStyle(document.getElementById("articleBody")).fontSize));
      if (!(bigger > m.font + 0.5)) failures.push("点 A+ 后正文字号没有变大（" + m.font + " → " + bigger + "）");
      await page.locator('#articleToolbar button[data-size="reset"]').click();
      // 改字号会让正文高度变化，侧栏配平有 120ms 防抖：等它跑完再看底边对齐
      await page.waitForTimeout(450);

      // 短稿：正文卡片撑到与侧栏同高（内容下方留白），两栏底边对齐；正文更长时不撑
      const align = await page.evaluate(() => {
        const main = document.querySelector(".inner-main");
        const side = document.getElementById("innerSide");
        const panel = document.querySelector(".inner-main > *:last-child");
        const prev = panel ? panel.style.minHeight : "";
        if (panel) panel.style.minHeight = "";
        const naturalMain = Math.round(main.getBoundingClientRect().height);
        if (panel) panel.style.minHeight = prev;
        return {
          naturalMain,
          sideHeight: Math.round(side.getBoundingClientRect().height),
          mainBottom: Math.round(main.getBoundingClientRect().bottom),
          sideBottom: Math.round(side.getBoundingClientRect().bottom),
          stretched: prev !== "",
        };
      });
      if (align.naturalMain < align.sideHeight - 2) {
        if (!align.stretched || Math.abs(align.mainBottom - align.sideBottom) > 2) {
          failures.push("短稿没和侧栏底边对齐：正文 " + align.mainBottom + " / 侧栏 " + align.sideBottom);
        }
      } else if (align.stretched) {
        failures.push("正文并不比侧栏短，却被撑高了");
      }
      notes.push("底边：" + (align.stretched ? "已撑高对齐 " + align.mainBottom + "px" : "按内容高度"));

      // 元信息口径：发布时间只到年月日；不显示点击（阅读）数据
      const metaText = await page.evaluate(() => (document.getElementById("articleMeta") || {}).innerText || "");
      if (/阅读/.test(metaText)) failures.push("详情页元信息仍显示阅读数据");
      if (!/发布时间：\d{4}-\d{2}-\d{2}/.test(metaText)) {
        failures.push("发布时间不是年月日：「" + metaText.slice(0, 40) + "」");
      } else {
        notes.push("元信息：" + metaText.replace(/\s+/g, " ").slice(0, 40));
      }
      } // frontendDetail
    }

    // 9) 正文题区与标题重复：顶部只出一行标题，题区按行留在正文里
    if (c.kind === "title-zone") {
      const m = await page.evaluate(() => {
        const body = document.getElementById("articleBody");
        const sub = document.getElementById("articleSub");
        const visible = (s) => String(s || "").replace(/[\s\u3000\u00a0]/g, "");
        const blocks = Array.from(body.children).filter((el) => visible(el.textContent) !== "" || el.querySelector("img"));
        return {
          headExtraLine: !!sub && !sub.hidden && visible(sub.textContent) !== "",
          leadLine: blocks.length ? String(blocks[0].textContent || "").replace(/[\s\u3000\u00a0]/g, "") : "",
          lineCount: blocks.length
        };
      });
      if (m.headExtraLine) failures.push("标题上方多出一行引题（顶部应只有一行标题）");
      if (c.expectLeadLine && m.leadLine.indexOf(c.expectLeadLine) !== 0) {
        failures.push("正文首行应为「" + c.expectLeadLine + "」，实际「" + m.leadLine.slice(0, 30) + "」");
      }
      if (c.leadLineExcludes && m.leadLine.indexOf(c.leadLineExcludes) === 0) {
        failures.push("单行题区与标题重复时不应再出现在正文首行，实际「" + m.leadLine.slice(0, 30) + "」");
      }
      notes.push("正文首行「" + m.leadLine.slice(0, 16) + "…」，共 " + m.lineCount + " 块");
    }

    // 10) 长文页右栏不粘：侧栏高于视口时应取消 position: sticky
    if (c.kind === "sticky-check") {
      const m = await page.evaluate(() => {
        const side = document.getElementById("innerSide");
        return {
          height: Math.round(side.getBoundingClientRect().height),
          sticky: side.classList.contains("is-sticky"),
          viewport: window.innerHeight,
          latest: document.querySelectorAll("#latestList li").length,
          thumbs: document.querySelectorAll("#thumbGrid img").length,
        };
      });
      if (m.height >= m.viewport - 90 && m.sticky) {
        failures.push("侧栏高 " + m.height + "px 仍启用粘性，底部内容在视口外看不到");
      }
      // 详情页侧栏口径：10–15 条、4–6 张；长文应顶到上限附近
      if (m.latest < 10 || m.latest > 15) failures.push("侧栏最新新闻 " + m.latest + " 条（应在 10–15 之间）");
      if (m.thumbs < 4 || m.thumbs > 6) failures.push("侧栏图片新闻 " + m.thumbs + " 张（应在 4–6 之间）");
      if (c.expectSideMax && (m.latest < 13 || m.thumbs < 6)) {
        failures.push("长文的侧栏没顶到上限：" + m.latest + " 条 / " + m.thumbs + " 张");
      }
      notes.push("侧栏 " + m.height + "px、新闻 " + m.latest + " 条 / 图 " + m.thumbs + " 张"
        + (m.sticky ? "（粘性保留）" : "（粘性取消）"));
    }

    // 10) 回到顶部：滚动后出现，点击回到顶部
    if (c.kind === "back-top") {
      await page.evaluate(() => window.scrollTo(0, window.innerHeight * 2));
      await page.waitForTimeout(300);
      const shown = await page.locator(".to-top").isVisible();
      if (!shown) failures.push("滚动后「回到顶部」按钮未出现");
      else {
        await page.locator(".to-top").click();
        await page.waitForTimeout(700);
        const y = await page.evaluate(() => Math.round(window.scrollY));
        if (y > 50) failures.push("点击回到顶部后仍在 " + y + "px");
        else notes.push("回到顶部可用");
      }
    }

    // 11) 打印样式：站头、导航、侧栏、工具栏、页脚都不应打出来
    if (c.kind === "print") {
      await page.emulateMedia({ media: "print" });
      await page.waitForTimeout(200);
      const stillVisible = await page.evaluate(() => {
        return [".topbar", ".site-header", ".crumb-bar", ".inner-side", ".article-toolbar", ".site-footer"]
          .filter((sel) => {
            const el = document.querySelector(sel);
            return !!el && getComputedStyle(el).display !== "none";
          });
      });
      await page.emulateMedia({ media: "screen" });
      if (stillVisible.length) failures.push("打印态仍会打出：" + stillVisible.join("、"));
      else notes.push("打印态已隐藏站头/侧栏/工具栏");
    }

    // 12) 静态发布页：旧站地址必须已改写（本地有文件的走站内，其余强制 https）
    if (c.kind === "static-media") {
      const m = await page.evaluate(() => {
        const html = document.body.innerHTML;
        const video = document.querySelector(".article-body video");
        const body = document.querySelector(".article-body");
        const de = document.documentElement;
        return {
          plainHttp: (html.match(/http:\/\/(?:www\.)?gxhczx\.gov\.cn/gi) || []).length,
          videoWidth: video ? Math.round(video.getBoundingClientRect().width) : 0,
          bodyWidth: body ? Math.round(body.getBoundingClientRect().width) : 0,
          overflowX: de.scrollWidth - de.clientWidth
        };
      });
      if (m.plainHttp > 0) failures.push("静态页仍有 " + m.plainHttp + " 处 http 旧站地址");
      if (m.videoWidth > m.bodyWidth + 1) failures.push("静态页视频 " + m.videoWidth + "px 超出正文 " + m.bodyWidth + "px");
      if (m.overflowX > 1) failures.push("静态页横向溢出 " + m.overflowX + "px");
      // 静态页与前台同口径：发布时间只到年月日
      const staticMeta = await page.evaluate(() => (document.querySelector(".meta") || {}).textContent || "");
      if (/\d{2}:\d{2}/.test(staticMeta) || !/发布时间：\d{4}-\d{2}-\d{2}/.test(staticMeta)) {
        failures.push("静态页发布时间口径不对：「" + staticMeta.slice(0, 40) + "」");
      }
      notes.push("视频 " + m.videoWidth + "/" + m.bodyWidth + "px");
    }
  } catch (e) {
    failures.push("执行异常：" + String((e && e.message) || e).split("\n")[0]);
  } finally {
    await context.close().catch(() => {});
  }

  return { name: c.name, failures, notes };
}

// ---------------------------------------------------------------- 主流程

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (opts.help) {
    console.log("用法：node tests/check-pages.mjs [--url <base>] [--port 8973] [--only <关键词>] [--via-api] [--publish] [--browser chrome] [--headed] [--keep]");
    return 0;
  }

  const cases = opts.only
    ? CASES.filter((c) => c.name.indexOf(opts.only) !== -1)
    : CASES;
  const publishCases = opts.only
    ? PUBLISH_CASES.filter((c) => c.name.indexOf(opts.only) !== -1)
    : PUBLISH_CASES;
  if (!cases.length && !(opts.publish && publishCases.length)) {
    console.log("没有匹配 --only " + opts.only + " 的用例");
    return 1;
  }

  const { chromium } = loadPlaywright();
  let server = null;
  let base = opts.url;
  let phpServer = null;
  if (opts.viaApi && !base) {
    phpServer = await startPhpServer(opts.port);
    server = { child: phpServer.child };
    base = phpServer.base;
    console.log("站点与接口（同源）：" + base + " → frontend/home + /api/v1");
  } else if (!base) {
    server = await startStaticServer(SITE_DIR, opts.port);
    base = server.base;
    console.log("静态服务器：" + base + " → " + path.relative(REPO, SITE_DIR));
  } else {
    console.log("检查目标：" + base);
  }

  // 同一篇稿件的预期会随数据源变化（40029 在快照里公开、在开发库是归档；62212 在快照里无正文、
  // 在开发库已有正文），所以用例分三类，按目标实际是什么来选：
  //   · 通用：任何目标都跑；
  //   · seededData：预期基于阶段 A 快照数据（静态目标与脚本自建临时库都成立），指向开发库时不跑；
  //   · onlyApi：依赖脚本对临时库的改动（例如把 62245 改成草稿），只在自建临时库时跑。
  let apiAvailable = opts.viaApi;
  if (!apiAvailable) {
    try {
      const res = await fetch(base + "/api/v1/health");
      apiAvailable = res.ok;
    } catch (e) {
      apiAvailable = false;
    }
  }
  const liveDb = apiAvailable && !opts.viaApi;
  const selected = cases.filter((c) => {
    if (c.onlyApi) return opts.viaApi && !opts.url;
    if (c.seededData) return !liveDb;
    if (c.apiData) return apiAvailable;
    if (c.devOnly) return liveDb;
    return true;
  });
  console.log("数据源：" + (apiAvailable ? "接口可用（/api/v1/health 正常）" : "接口不可用（走静态快照）"));

  const { browser, label } = await launchBrowser(chromium, opts);
  console.log("浏览器：" + label);
  const started = Date.now();
  const results = [];
  let publishServer = null;
  let publishRoot = null;
  try {
    // Nginx 直出的静态详情页：临时发布一次，用静态服务跑同一套排版断言
    if (opts.publish) {
      const php = resolvePhp();
      if (!php) throw new Error("--publish 需要 php");
      publishRoot = mkdtempSync(path.join(tmpdir(), "hechi-publish-"));
      const run = spawnSync(php, ["backend/bin/publish.php", "--out=" + publishRoot], { cwd: REPO, encoding: "utf8" });
      if (run.status !== 0) {
        throw new Error("发布失败：" + String(run.stderr || run.stdout || "").trim().split("\n").slice(-1)[0]);
      }
      publishServer = await startStaticServer(publishRoot, opts.port + 1);
      console.log("发布产物：" + publishRoot + " → " + publishServer.base);
    }
    for (const c of selected) {
      const r = await runCase(browser, base, c, opts);
      results.push(r);
      const tag = r.failures.length ? "FAIL" : "PASS";
      console.log(tag + "  " + r.name + (r.notes.length ? "  — " + r.notes.join("；") : ""));
      r.failures.forEach((f) => console.log("      · " + f));
    }
    if (publishServer) {
      for (const c of publishCases) {
        const r = await runCase(browser, publishServer.base, c, opts);
        results.push(r);
        const tag = r.failures.length ? "FAIL" : "PASS";
        console.log(tag + "  " + r.name + (r.notes.length ? "  — " + r.notes.join("；") : ""));
        r.failures.forEach((f) => console.log("      · " + f));
      }
    }
  } finally {
    await browser.close().catch(() => {});
    if (server && !opts.keep) server.child.kill("SIGTERM");
    if (phpServer && !opts.keep) rmSync(phpServer.tmpRoot, { recursive: true, force: true });
    if (publishServer && !opts.keep) publishServer.child.kill("SIGTERM");
    if (publishRoot && !opts.keep) rmSync(publishRoot, { recursive: true, force: true });
  }

  const failed = results.filter((r) => r.failures.length);
  console.log("\n合计 " + results.length + " 个用例，" + (results.length - failed.length) + " 通过，" +
    failed.length + " 失败，用时 " + ((Date.now() - started) / 1000).toFixed(1) + "s");
  if (failed.length) {
    console.log("失败用例：" + failed.map((r) => r.name).join("、"));
    return 1;
  }
  return 0;
}

main()
  .then((code) => process.exit(code))
  .catch((e) => {
    console.error("检查脚本自身出错：" + ((e && e.stack) || e));
    process.exit(2);
  });
