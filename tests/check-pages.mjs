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

import { spawn, execSync } from "node:child_process";
import { existsSync } from "node:fs";
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
        "#imageMarquee img": 1
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
        "#crumb li": 2,
        "#articleGallery": 0,
        "#articleGallery .gallery-strip img, #articleGallery img": 2
      },
      visible: ["#articleGallery"],
      textIncludes: ["市政协动态"],
      textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"]
    }
  },
  {
    name: "详情页·附件下载（40029）",
    page: "detail.html?id=40029",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    look: {
      visible: ["#articleAttach"],
      textIncludes: ["附件下载"],
      textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"]
    }
  },
  {
    name: "详情页·列表有正文未内置（62212）",
    page: "detail.html?id=62212",
    viewport: DESKTOP,
    ready: "#articleBody > *",
    look: {
      textIncludes: ["正文未随原型内置"],
      textExcludes: ["数据加载失败", "未找到该篇信息", "信息加载中"]
    }
  },
  {
    name: "详情页·未知稿件 id",
    page: "detail.html?id=999999",
    viewport: DESKTOP,
    ready: "#articleBody .empty-state",
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

// ---------------------------------------------------------------- 参数与环境

function parseArgs(argv) {
  const opts = { url: "", port: 8973, only: "", headed: false, timeout: 20000, keep: false, browser: "" };
  for (let i = 0; i < argv.length; i += 1) {
    const a = argv[i];
    if (a === "--url") opts.url = String(argv[++i] || "").replace(/\/+$/, "");
    else if (a === "--port") opts.port = Number(argv[++i]) || opts.port;
    else if (a === "--only") opts.only = String(argv[++i] || "");
    else if (a === "--timeout") opts.timeout = Number(argv[++i]) || opts.timeout;
    else if (a === "--headed") opts.headed = true;
    else if (a === "--keep") opts.keep = true;
    else if (a === "--browser") opts.browser = String(argv[++i] || "");
    else if (a === "--help" || a === "-h") opts.help = true;
  }
  return opts;
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
    const resp = await page.goto(url, { waitUntil: "load", timeout: opts.timeout });
    if (!resp || !resp.ok()) failures.push("页面返回 HTTP " + (resp ? resp.status() : "无响应") + "：" + url);
    await page.waitForSelector(c.ready, { state: "attached", timeout: opts.timeout });
    await page.waitForTimeout(250);
    if (c.kind !== "reload-top") await scrollThrough(page);

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
    if (info.imgBroken.length) {
      failures.push("破图 " + info.imgBroken.length + " 张：" + info.imgBroken.slice(0, 3).join("，"));
    }
    notes.push("图片 " + (info.imgTotal - info.imgBroken.length) + "/" + info.imgTotal + " 张已加载" +
      (info.imgPending ? "（" + info.imgPending + " 张懒加载未触发）" : ""));

    // 4) 控制台与请求
    if (pageErrors.length) failures.push("JS 异常 " + pageErrors.length + " 条：" + pageErrors.slice(0, 2).join(" | "));
    if (consoleErrors.length) failures.push("控制台报错 " + consoleErrors.length + " 条：" + consoleErrors.slice(0, 2).join(" | "));
    if (httpErrors.length) failures.push("请求失败 " + httpErrors.length + " 条：" + httpErrors.slice(0, 3).join(" | "));
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
    console.log("用法：node tests/check-pages.mjs [--url <base>] [--port 8973] [--only <关键词>] [--browser chrome] [--headed] [--keep]");
    return 0;
  }

  const cases = opts.only
    ? CASES.filter((c) => c.name.indexOf(opts.only) !== -1)
    : CASES;
  if (!cases.length) {
    console.log("没有匹配 --only " + opts.only + " 的用例");
    return 1;
  }

  const { chromium } = loadPlaywright();
  let server = null;
  let base = opts.url;
  if (!base) {
    server = await startStaticServer(SITE_DIR, opts.port);
    base = server.base;
    console.log("静态服务器：" + base + " → " + path.relative(REPO, SITE_DIR));
  } else {
    console.log("检查目标：" + base);
  }

  const { browser, label } = await launchBrowser(chromium, opts);
  console.log("浏览器：" + label);
  const started = Date.now();
  const results = [];
  try {
    for (const c of cases) {
      const r = await runCase(browser, base, c, opts);
      results.push(r);
      const tag = r.failures.length ? "FAIL" : "PASS";
      console.log(tag + "  " + r.name + (r.notes.length ? "  — " + r.notes.join("；") : ""));
      r.failures.forEach((f) => console.log("      · " + f));
    }
  } finally {
    await browser.close().catch(() => {});
    if (server && !opts.keep) server.child.kill("SIGTERM");
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
