#!/usr/bin/env node
/**
 * 正文富文本编辑器（SunEditor 3.3.3）的端到端检查：
 * 编辑页挂载与渐进增强、取值同步、图片上传接口、粘贴图片自动上传、站内地址还原。
 *
 * 全程用临时 SQLite 库（migrate + seed + 建一个测试账号），不动开发库。
 *
 *   node tests/editor-check.mjs
 *   node tests/editor-check.mjs --keep
 */

import { spawn, spawnSync } from "node:child_process";
import { createRequire } from "node:module";
import { existsSync, mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const require = createRequire(import.meta.url);
const { chromium } = require(
  "/Users/wangyuehua/.npm-global/lib/node_modules/@playwright/cli/node_modules/playwright"
);

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, "..");

const USER = "editorcheck";
const PASSWORD = "editor-check-2026";
const SAMPLE_ID = "62180";

/** 1×1 透明 PNG，用于上传与粘贴用例 */
const PNG = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
  "base64"
);
const PNG_DATA_URI = "data:image/png;base64," + PNG.toString("base64");

let failures = 0;
let total = 0;

function check(name, ok, detail = "") {
  total += 1;
  if (!ok) failures += 1;
  console.log((ok ? "PASS  " : "FAIL  ") + name + (ok || !detail ? "" : "  —  " + detail));
}

function parseArgs(argv) {
  const opts = { port: 8991, keep: false };
  for (let i = 0; i < argv.length; i += 1) {
    if (argv[i] === "--port") opts.port = Number(argv[++i]) || opts.port;
    else if (argv[i] === "--keep") opts.keep = true;
    else if (argv[i] === "--help" || argv[i] === "-h") opts.help = true;
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

function runPhp(php, script, env, args = []) {
  return spawnSync(php, [script, ...args], { cwd: REPO, env: { ...process.env, ...env }, encoding: "utf8" });
}

async function waitForServer(base, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      const res = await fetch(base + "/api/v1/health");
      if (res.ok) return true;
    } catch (e) { /* 还没起来 */ }
    await new Promise((r) => setTimeout(r, 200));
  }
  return false;
}

function createJar() {
  const jar = new Map();
  return {
    header() { return [...jar.entries()].map(([k, v]) => k + "=" + v).join("; "); },
    absorb(res) {
      const cookies = typeof res.headers.getSetCookie === "function" ? res.headers.getSetCookie() : [];
      for (const raw of cookies) {
        const [pair] = raw.split(";");
        const index = pair.indexOf("=");
        if (index > 0) jar.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim());
      }
    },
  };
}

function makeClient(base) {
  const jar = createJar();
  return {
    jar,
    async request(method, url, { form = null, json = false, multipart = null, headers: extra = {} } = {}) {
      const headers = { ...extra };
      const cookie = jar.header();
      if (cookie) headers.Cookie = cookie;
      let body;
      if (multipart) {
        const fd = new FormData();
        for (const [key, value] of Object.entries(multipart.fields || {})) fd.append(key, value);
        for (const file of multipart.files || []) {
          fd.append(file.field, new Blob([file.content], { type: file.type }), file.filename);
        }
        body = fd;
      } else if (form) {
        body = new URLSearchParams(form).toString();
        headers["Content-Type"] = "application/x-www-form-urlencoded";
      }
      const res = await fetch(base + url, { method, headers, body, redirect: "manual" });
      jar.absorb(res);
      const text = await res.text();
      if (json) {
        try { return { status: res.status, headers: res.headers, body: JSON.parse(text), text }; }
        catch (e) { return { status: res.status, headers: res.headers, body: null, text }; }
      }
      return { status: res.status, headers: res.headers, text };
    },
    get(url, opts) { return this.request("GET", url, opts); },
    post(url, form, opts) { return this.request("POST", url, { form, ...opts }); },
    upload(url, fields, files, opts) { return this.request("POST", url, { multipart: { fields, files }, ...opts }); },
  };
}

function csrfToken(html) {
  const m = /name="_token" value="([^"]+)"/.exec(html);
  return m ? m[1] : "";
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (opts.help) {
    console.log("用法：node tests/editor-check.mjs [--port 8991] [--keep]");
    return 0;
  }

  const php = resolvePhp();
  if (!php) {
    console.error("本机没有可用的 php。请先 brew install php。");
    return 2;
  }

  const tmpRoot = mkdtempSync(path.join(tmpdir(), "hechi-editor-check-"));
  const env = {
    DB_DRIVER: "sqlite",
    DB_DATABASE: path.join(tmpRoot, "editor.sqlite"),
    APP_ENV: "local",
    APP_DEBUG: "1",
    PUBLISH_OUT: path.join(tmpRoot, "publish"),
  };
  console.log("PHP：" + php + "　临时库：" + env.DB_DATABASE);

  const migrated = runPhp(php, "backend/bin/migrate.php", env);
  const seeded = runPhp(php, "backend/bin/seed.php", env);
  const user = runPhp(php, "backend/bin/user.php", env, ["create", USER, PASSWORD, "编辑器检查"]);
  check("建表 / 灌数 / 建账号三步成功",
    migrated.status === 0 && seeded.status === 0 && user.status === 0,
    (migrated.stderr || seeded.stderr || user.stderr || "").trim().split("\n")[0]);
  if (migrated.status !== 0 || seeded.status !== 0 || user.status !== 0) return 1;

  const server = spawn(php, ["-S", "127.0.0.1:" + opts.port, "-t", "backend/public", "backend/public/router.php"], {
    cwd: REPO, env: { ...process.env, ...env }, stdio: ["ignore", "ignore", "pipe"],
  });
  const base = "http://127.0.0.1:" + opts.port;
  const client = makeClient(base);
  const browser = await chromium.launch();
  const context = await browser.newContext({ permissions: ["clipboard-read", "clipboard-write"] });

  try {
    const ready = await waitForServer(base);
    check("服务已就绪", ready, base);
    if (!ready) return 1;

    const loginPage = await client.get("/admin/login");
    const logged = await client.post("/admin/login", {
      _token: csrfToken(loginPage.text), username: USER, password: PASSWORD,
    });
    check("后台登录成功", logged.status === 302);

    const editorHtml = (await client.get("/admin/article/" + SAMPLE_ID)).text;
    const listHtml = (await client.get("/admin/articles")).text;
    const newHtml = (await client.get("/admin/article/new")).text;

    /* ---------------- 资源与渐进增强 ---------------- */

    check("编辑页：保留正文 textarea（提交字段不变）", /name="content_html"[^>]*>/.test(editorHtml));
    check("编辑页：有编辑器挂载点", editorHtml.includes("data-editor-mount"));
    // 纸张式写作区：大标题 + 原标题三列 + 来源 + 正文
    check("编辑页：写作区把标题、原标题、来源、正文收在一张纸里",
      editorHtml.includes("writing-paper")
        && /class="writing-title"[\s\S]*?maxlength="64"/.test(editorHtml)
        && editorHtml.includes('name="orig_title"')
        && editorHtml.includes('name="source"')
        && editorHtml.includes("data-title-count"),
      "写作区结构不完整");
    // 作者框 2026-09-17 加回写作纸（编辑要能自己填）；责任编辑仍不在编辑页维护，
    // 详情页按库里的署名显示，保存时不带这一列就不会被清空。
    check("编辑页：作者框在写作区、责任编辑仍不在编辑页维护",
      /name="author"/.test(editorHtml) && !/name="editor"/.test(editorHtml),
      "作者／责任编辑字段与预期不符");
    check("编辑页：标题、原标题、来源都已移出基本信息卡",
      !/基本信息[\s\S]{0,900}name="(title|orig_title|source)"/.test(editorHtml),
      "基本信息卡里还有这些字段");
    check("编辑页：引入编辑器资源（仅本页）",
      editorHtml.includes("/assets/editor/suneditor.min.js") && editorHtml.includes("/assets/editor/admin-editor.js"),
      "head 里没找到编辑器资源");
    check("新建页：也引入编辑器资源", newHtml.includes("/assets/editor/suneditor.min.js"));
    check("列表页：不引入编辑器资源（其它页零影响）",
      !listHtml.includes("/assets/editor/") && listHtml.includes("/assets/admin.js"));

    const js = await client.get("/assets/editor/suneditor.min.js");
    const css = await client.get("/assets/editor/suneditor.min.css");
    const lang = await client.get("/assets/editor/lang/zh_cn.js");
    check("静态资源可访问且体积正常",
      js.status === 200 && js.text.length > 500000 && css.status === 200 && lang.status === 200,
      "js=" + js.status + "/" + js.text.length + " css=" + css.status + " lang=" + lang.status);
    // 静态资源的 nosniff 由 nginx 配置（deploy/nginx/default.conf），PHP 内置服务器不带头，这里不断言

    /* ---------------- 浏览器：挂载、同步、兜底 ---------------- */

    const pageErrors = [];
    const cspViolations = [];
    const page = await context.newPage();
    page.on("pageerror", (e) => pageErrors.push(String(e.message)));
    page.on("console", (m) => {
      if (/Content Security Policy|Refused to/i.test(m.text())) cspViolations.push(m.text());
    });

    const login = async (p) => {
      await p.goto(base + "/admin/login");
      await p.fill('input[name="username"]', USER);
      await p.fill('input[name="password"]', PASSWORD);
      await Promise.all([p.waitForNavigation(), p.click("#login-submit")]);
    };

    await login(page);
    await page.goto(base + "/admin/article/" + SAMPLE_ID);
    const mountedOk = await page.waitForSelector('[data-editor-mount][data-editor-ready="1"]', { state: 'attached', timeout: 15000 })
      .then(() => true).catch(() => false);

    const mounted = mountedOk ? await page.evaluate(() => {
      const ta = document.querySelector('textarea[name="content_html"]');
      const container = document.querySelector(".se-container");
      const editable = document.querySelector('.se-container [contenteditable="true"]');
      return {
        textareaHidden: ta.hidden,
        mountVisible: !!container && !container.hidden && container.offsetWidth > 0,
        editable: !!editable,
        hasContent: editable ? editable.innerText.trim().length > 20 : false,
        textareaValue: ta.value.slice(0, 30),
      };
    }) : { editable: false, mountVisible: false, textareaHidden: false, hasContent: false };
    check("浏览器：编辑器挂载成功", mounted.editable && mounted.mountVisible, JSON.stringify(mounted));
    check("浏览器：挂载后 textarea 隐藏（内容仍随表单提交）", mounted.textareaHidden, JSON.stringify(mounted));
    check("浏览器：编辑器里带出原文", mounted.hasContent, JSON.stringify(mounted));
    check("浏览器：加载编辑器没有 JS 异常", pageErrors.length === 0, pageErrors.slice(0, 2).join(" | "));
    check("浏览器：编辑器不受后台 CSP 拦截", cspViolations.length === 0, cspViolations.slice(0, 2).join(" | "));

    const toolbar = await page.evaluate(() => Array.prototype.map.call(
      document.querySelectorAll(".se-toolbar button, .se-toolbar .se-btn-module button"),
      (b) => (b.getAttribute("title") || b.getAttribute("aria-label") || "").trim()
    ).filter(Boolean));
    check("浏览器：工具栏是我们配置的那套（含列表、对齐、图片、视频）",
      toolbar.some((t) => t.includes("列表")) && toolbar.some((t) => t.includes("对齐") || t.includes("居中"))
        && toolbar.some((t) => t.includes("图片")) && toolbar.some((t) => t.includes("视频")),
      toolbar.join("、"));
    const styleControls = await page.evaluate(() => ({
      // 字号在 v3 里是输入框控件，不带 title
      sizeInput: !!document.querySelector(".se-toolbar input, .se-toolbar select"),
      colorButtons: Array.prototype.filter.call(
        document.querySelectorAll(".se-toolbar button"),
        (b) => ((b.getAttribute("title") || "") + (b.getAttribute("aria-label") || "")).includes("颜色")
      ).length,
    }));
    check("浏览器：工具栏含字体与颜色（字体下拉、字号输入、文字颜色、背景色）",
      toolbar.some((t) => t.includes("字体")) && styleControls.sizeInput && styleControls.colorButtons >= 2,
      toolbar.join("、") + " | " + JSON.stringify(styleControls));

    if (mountedOk) {
      // 在编辑器里输入 → 提交 → 服务端应存下编辑器内容
      const editable = await page.$('.se-container [contenteditable="true"]');
      await editable.click();
      await page.keyboard.press("ControlOrMeta+A");
      await page.keyboard.type("编辑器写入的正文。");
      // SunEditor 的 onChange 有防抖，等它把内容同步回 textarea
      await page.waitForTimeout(1500);
      const syncedValue = await page.evaluate(() => document.querySelector('textarea[name="content_html"]').value);
      check("浏览器：编辑器改动同步回 textarea", syncedValue.includes("编辑器写入的正文"), syncedValue.slice(0, 80));

      // 源码 / 富文本切换
      await page.click("[data-editor-toggle]");
      const sourceState = await page.evaluate(() => ({
        textareaVisible: !document.querySelector('textarea[name="content_html"]').hidden,
        containerHidden: document.querySelector(".se-container").hidden,
        toggleText: document.querySelector("[data-editor-toggle]").textContent.trim(),
        // 标题与原标题／来源不在编辑器容器里，切到源码时不能跟着工具栏一起消失
        titleVisible: !!document.querySelector(".writing-title-line")?.offsetParent,
        origVisible: !!document.querySelector(".writing-orig")?.offsetParent,
      }));
      check("浏览器：可切到源码（textarea 恢复显示）",
        sourceState.textareaVisible && sourceState.containerHidden, JSON.stringify(sourceState));
      check("浏览器：切到源码后标题与原标题／来源仍在页面上",
        sourceState.titleVisible && sourceState.origVisible, JSON.stringify(sourceState));

      await page.click("[data-editor-toggle]");
      const richState = await page.evaluate(() => ({
        textareaHidden: document.querySelector('textarea[name="content_html"]').hidden,
        containerVisible: !document.querySelector(".se-container").hidden,
        titleVisible: !!document.querySelector(".writing-title-line")?.offsetParent,
        origVisible: !!document.querySelector(".writing-orig")?.offsetParent,
      }));
      check("浏览器：可切回富文本", richState.textareaHidden && richState.containerVisible
        && richState.titleVisible && richState.origVisible, JSON.stringify(richState));

      // 素材条在稿件表单里，但附件控件必须归属外置的 #writing-media-form：
      // 写成嵌套 <form> 时浏览器会丢弃内层表单、并把外层稿件表单提前闭合，保存按钮会失效
      const mediaForm = await page.evaluate(() => {
        const helper = document.getElementById("writing-media-form");
        const file = document.querySelector('.writing-media-form input[type="file"]');
        const bar = document.querySelector("[data-editor-media]");
        return {
          barInsideArticleForm: !!(bar && bar.closest("#article-form")),
          helperInArticleForm: !!(helper && helper.closest("#article-form")),
          fileOwner: file && file.form ? file.form.id : "",
        };
      });
      check("浏览器：素材条的附件控件归属外置表单（不会带动稿件表单一起提交）",
        mediaForm.barInsideArticleForm && !mediaForm.helperInArticleForm && mediaForm.fileOwner === "writing-media-form",
        JSON.stringify(mediaForm));

      await Promise.all([page.waitForNavigation(), page.click('button[type="submit"].btn-primary')]);
      const apiAfterType = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
      check("浏览器：提交后服务端存的是编辑器内容",
        String(apiAfterType.body?.article?.content ?? "").includes("编辑器写入的正文"),
        String(apiAfterType.body?.article?.content ?? "").slice(0, 80));
    } else {
      check("浏览器：编辑器改动同步回 textarea", false, "编辑器未挂载");
      check("浏览器：提交后服务端存的是编辑器内容", false, "编辑器未挂载");
    }

    // 禁用 JS 时退回 textarea
    const noJs = await browser.newContext({ javaScriptEnabled: false });
    const noJsPage = await noJs.newPage();
    // 新上下文没有会话，先按普通表单提交登录（登录页不依赖脚本）
    await noJsPage.goto(base + "/admin/login");
    await noJsPage.fill('input[name="username"]', USER);
    await noJsPage.fill('input[name="password"]', PASSWORD);
    await Promise.all([noJsPage.waitForNavigation(), noJsPage.click("#login-submit")]);
    await noJsPage.goto(base + "/admin/article/" + SAMPLE_ID);
    const fallback = await noJsPage.evaluate(() => {
      const ta = document.querySelector('textarea[name="content_html"]');
      return {
        textareaVisible: !!ta && !ta.hidden && ta.offsetWidth > 0,
        hasContainer: !!document.querySelector(".se-container"),
      };
    });
    check("无脚本：textarea 仍可见可用、不出现编辑器容器（渐进增强兜底）",
      fallback.textareaVisible && !fallback.hasContainer,
      JSON.stringify(fallback));
    await noJs.close();

    /* ---------------- 图片上传接口（已有稿件） ---------------- */

    const uploadToken = csrfToken((await client.get("/admin/article/" + SAMPLE_ID)).text);
    const uploaded = await client.upload(
      "/admin/media/image",
      { _token: uploadToken, article: SAMPLE_ID },
      [{ field: "file-0", filename: "upload-check.png", type: "image/png", content: PNG }],
      { json: true }
    );
    const uploadedItem = uploaded.body?.result?.[0];
    check("上传接口：返回 200 与 SunEditor 契约 {result:[{url,name,size}]}",
      uploaded.status === 200 && !!uploadedItem && typeof uploadedItem.url === "string"
        && typeof uploadedItem.name === "string" && typeof uploadedItem.size === "number",
      uploaded.status + " " + uploaded.text.slice(0, 120));
    check("上传接口：图片落到 /uploads/ 下",
      !!uploadedItem && new RegExp("^/uploads/" + SAMPLE_ID + "/").test(uploadedItem.url), String(uploadedItem?.url));
    check("上传接口：登记进图集",
      String((await client.get("/api/v1/article/" + SAMPLE_ID, { json: true })).body?.article?.images?.join(",") ?? "")
        .includes(String(uploadedItem?.url ?? "")),
      "图集里没有新图");
    check("上传接口：文件真的可访问", (await client.get(String(uploadedItem?.url ?? "/nope"))).status === 200);

    const noTokenUpload = await client.upload(
      "/admin/media/image",
      {},
      [{ field: "file-0", filename: "x.png", type: "image/png", content: PNG }],
      { json: true }
    );
    check("上传接口：缺 CSRF 令牌被拒", noTokenUpload.status === 400, "状态 " + noTokenUpload.status);

    /* ---------------- 视频上传接口 ---------------- */

    const videoUpload = await client.upload(
      "/admin/media/video",
      { _token: uploadToken, article: SAMPLE_ID },
      [{ field: "file-0", filename: "clip.mp4", type: "video/mp4", content: Buffer.from("00000018667479706d703432", "hex") }],
      { json: true }
    );
    const videoItem = videoUpload.body?.result?.[0];
    check("视频上传：返回 200 与同样的契约",
      videoUpload.status === 200 && !!videoItem && /\.mp4$/.test(String(videoItem.url)),
      videoUpload.status + " " + videoUpload.text.slice(0, 120));
    check("视频上传：文件可访问", (await client.get(String(videoItem?.url ?? "/nope"))).status === 200);

    const videoToken = csrfToken((await client.get("/admin/article/" + SAMPLE_ID)).text);
    await client.post("/admin/article/" + SAMPLE_ID, {
      _token: videoToken,
      title: "视频保留检查",
      content_html: '<p>带视频</p><video src="' + (videoItem?.url ?? "") + '" controls></video>',
    });
    const withVideo = String((await client.get("/api/v1/article/" + SAMPLE_ID, { json: true })).body?.article?.content ?? "");
    check("视频：正文里的 <video> 保存后保留", /<video[^>]+src="\/uploads\//.test(withVideo), withVideo.slice(0, 160));

    /* ---------------- 新建页：上传落在 pending、保存时认领 ---------------- */

    const newToken = csrfToken((await client.get("/admin/article/new")).text);
    const pendingUpload = await client.upload(
      "/admin/media/image",
      { _token: newToken },
      [{ field: "file-0", filename: "new-page.png", type: "image/png", content: PNG }],
      { json: true }
    );
    const pendingItem = pendingUpload.body?.result?.[0];
    check("新建页：没有稿件号也能上传（先落在 pending）",
      pendingUpload.status === 200 && /^\/uploads\/pending\//.test(String(pendingItem?.url)),
      pendingUpload.status + " " + String(pendingItem?.url));
    check("新建页：pending 文件可访问", (await client.get(String(pendingItem?.url ?? "/nope"))).status === 200);

    // 浏览器：新建页直接插图（粘贴 base64 自动上传）→ 填标题 → 保存 → 素材被认领到稿件目录
    await page.goto(base + "/admin/article/new");
    const newReady = await page.waitForSelector('[data-editor-mount][data-editor-ready="1"]', { state: "attached", timeout: 15000 })
      .then(() => true).catch(() => false);
    check("新建页：编辑器挂载成功", newReady);
    if (newReady) {
      const writingUi = await page.evaluate(() => ({
        paper: !!document.querySelector(".writing-paper"),
        titleInside: !!document.querySelector(".writing-paper input[name='title']"),
        origInside: !!document.querySelector(".writing-paper input[name='orig_title']"),
        authorInside: !!document.querySelector(".writing-paper input[name='author']"),
        noEditorInput: !document.querySelector(".writing-paper input[name='editor']"),
        countText: (document.querySelector("[data-title-count]") || {}).textContent || "",
        placeholder: (document.querySelector(".se-placeholder") || {}).textContent || "",
      }));
      check("新建页：写作区渲染正常（标题、原标题、标题字数）",
        writingUi.paper && writingUi.titleInside && writingUi.origInside && /\/64$/.test(writingUi.countText),
        JSON.stringify(writingUi));
      check("新建页：作者框在写作区、责任编辑不在编辑页维护",
        writingUi.authorInside && writingUi.noEditorInput, JSON.stringify(writingUi));
      check("新建页：正文占位符是「从这里开始写正文」",
        writingUi.placeholder.includes("从这里开始写正文"), JSON.stringify(writingUi));
      const writingOrder = await page.evaluate(() => Array.prototype.map.call(
        document.querySelectorAll(
          ".writing-paper .writing-title-line, .writing-paper .writing-orig,"
            + " .writing-paper .writing-meta, .writing-paper .writing-hint,"
            + " .writing-paper .se-toolbar, .writing-paper .se-wrapper,"
            + " .writing-paper .writing-media"
        ),
        (n) => n.className.split(" ")[0]
      ));
      // 2026-09-17：工具栏从字段上方挪到正文框正上方；素材条（附件）落在正文框下方
      check("新建页：写作窗顺序为 标题 → 原标题 → 来源与作者 → 正文提示 → 工具栏 → 正文 → 附件",
        JSON.stringify(writingOrder)
          === JSON.stringify([
            "writing-title-line", "writing-orig", "writing-meta", "writing-hint",
            "se-toolbar", "se-wrapper", "writing-media"
          ]),
        JSON.stringify(writingOrder));

      /* 版面细节：每个输入框都有可见标签（不靠 placeholder 当标签）、
         卡片带步骤号、栏目卡右上角显示当前选中的栏目。 */
      const paperUi = await page.evaluate(() => ({
        labels: Array.prototype.map.call(
          document.querySelectorAll(".writing-paper .writing-field-label"),
          (n) => n.textContent.trim()
        ),
        titleLabel: (document.querySelector(".writing-title-label") || {}).textContent || "",
        steps: document.querySelectorAll(".card .step-no").length,
        pick: (document.querySelector(".pick-current") || {}).textContent || "",
      }));
      check("新建页：标题、原标题三列、来源与作者都有可见标签",
        paperUi.titleLabel.trim() === "网页标题" && paperUi.labels.join("/") === "引题/主标题/副题/来源/作者",
        JSON.stringify(paperUi));
      check("新建页：两张卡片带步骤号，栏目卡显示当前选择",
        paperUi.steps === 2 && paperUi.pick.includes("当前："), JSON.stringify(paperUi));

      // 标题写到 56 字时字数提醒变色（上限 64，超了前台会截断）
      await page.fill('input[name="title"]', "检".repeat(56));
      const countClasses = await page.evaluate(() => document.querySelector("[data-title-count]").className);
      check("新建页：标题字数接近上限时给出提醒色", /is-near/.test(countClasses), countClasses);
      await page.fill('input[name="title"]', "");

      await page.fill('input[name="title"]', "新建页插图检查");
      await page.evaluate(async (dataUri) => {
        const html = '<p>新建页正文</p><p><img src="' + dataUri + '" alt="新建页粘贴图"></p>';
        await navigator.clipboard.write([new ClipboardItem({
          "text/html": new Blob([html], { type: "text/html" }),
          "text/plain": new Blob(["新建页正文"], { type: "text/plain" }),
        })]);
      }, PNG_DATA_URI);
      await page.evaluate(() => {
        document.querySelector('.se-container [contenteditable="true"]').focus();
      });
      await page.keyboard.press("ControlOrMeta+V");
      await page.waitForTimeout(2500);
      const newContent = await page.evaluate(() => window.AdminEditor.getContent());
      check("新建页：粘贴的图片自动上传（base64 不再进正文）",
        /uploads\/pending\//.test(newContent) && !/data:image/.test(newContent), newContent.slice(0, 200));

      await Promise.all([
        page.waitForNavigation(),
        page.click('button[type="submit"].btn-primary'),
      ]);
      const createdId = (/\/admin\/article\/(\d+)/.exec(page.url()) || [])[1] || "";
      check("新建页：保存成功并跳到新稿件", createdId !== "", page.url());
      if (createdId !== "") {
        const created = String((await client.get("/api/v1/article/" + createdId, { json: true })).body?.article?.content ?? "");
        check("新建页：正文里的图片被认领到 /uploads/<新稿件号>/",
          new RegExp("/uploads/" + createdId + "/").test(created) && !/uploads\/pending\//.test(created),
          created.slice(0, 200));
        const adopted = (/src="(\/uploads\/[^"]+)"/.exec(created) || [])[1] || "";
        check("新建页：认领后的图片可访问", adopted !== "" && (await client.get(adopted)).status === 200, adopted);
        const createdApi = await client.get("/api/v1/article/" + createdId, { json: true });
        check("新建页：图片登记进新稿件的图集",
          String(createdApi.body?.article?.images?.join(",") ?? "").includes(adopted), adopted);
      }
    } else {
      check("新建页：保存成功并跳到新稿件", false, "编辑器未挂载");
      check("新建页：正文里的图片被认领到 /uploads/<新稿件号>/", false, "编辑器未挂载");
      check("新建页：认领后的图片可访问", false, "编辑器未挂载");
      check("新建页：图片登记进新稿件的图集", false, "编辑器未挂载");
    }

    /* ---------------- 浏览器：粘贴 base64 图片自动上传 ---------------- */

    await page.goto(base + "/admin/article/" + SAMPLE_ID);
    const pasteReady = await page.waitForSelector('[data-editor-mount][data-editor-ready="1"]', { state: 'attached', timeout: 15000 })
      .then(() => true).catch(() => false);
    if (pasteReady) {
      await page.evaluate(async (dataUri) => {
      const html = '<p>带图粘贴</p><p><img src="' + dataUri + '" alt="粘贴图"></p>';
      await navigator.clipboard.write([new ClipboardItem({
        "text/html": new Blob([html], { type: "text/html" }),
        "text/plain": new Blob(["带图粘贴"], { type: "text/plain" }),
      })]);
      }, PNG_DATA_URI);
      await page.evaluate(() => {
        const el = document.querySelector('.se-container [contenteditable="true"]');
        el.focus();
      });
      await page.keyboard.press("ControlOrMeta+V");
      await page.waitForTimeout(2500);
      const pastedHtml = await page.evaluate(() => (window.AdminEditor ? window.AdminEditor.getContent() : ""));
      check("粘贴图片：base64 被替换成上传后的地址",
        /uploads\//.test(pastedHtml) && !/data:image/.test(pastedHtml), pastedHtml.slice(0, 200));
    } else {
      check("粘贴图片：base64 被替换成上传后的地址", false, "编辑器未挂载");
    }

    /* ---------------- 站内地址还原 ---------------- */

    // 字体与颜色：白名单放行 font-family／font-size／color／background-color，保存后必须还在
    const summaryToken = csrfToken((await client.get("/admin/article/" + SAMPLE_ID)).text);
    await client.post("/admin/article/" + SAMPLE_ID, {
      _token: summaryToken,
      title: "摘要取第一段检查",
      content_html: "<p>这是第一段，应当成为摘要。</p><p>这是第二段，不该进摘要。</p>",
    });
    const summary = String((await client.get("/api/v1/article/" + SAMPLE_ID, { json: true })).body?.article?.summary ?? "");
    check("摘要：不随正文自动生成（只在首页轮换头条里维护，稿件里保持原值）",
      !summary.includes("这是第一段") && !summary.includes("应当成为摘要"),
      summary);

    // 原标题三项：加粗三行拼在正文最前，且不改网页标题
    const origToken = csrfToken((await client.get("/admin/article/" + SAMPLE_ID)).text);
    await client.post("/admin/article/" + SAMPLE_ID, {
      _token: origToken,
      title: "原标题检查",
      content_html: "<p>正文第一段。</p>",
      orig_kicker: "引题层",
      orig_title: "主标题层",
      orig_subtitle: "副题层",
    });
    const withOrig = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
    const origBody = String(withOrig.body?.article?.content ?? "");
    // 前台出口会把行首缩进裁掉交给 CSS（text-indent: 2em），接口这里只看题区顺序
    const indent = "\u3000\u3000";
    check("原标题：三项按加粗三行拼在正文最前，网页标题不受影响",
      origBody.startsWith('<p><strong>引题层</strong></p><p><strong>主标题层</strong></p>')
        && origBody.includes('<p><strong>副题层</strong></p>') && origBody.includes("正文第一段")
        && withOrig.body?.article?.title === "原标题检查",
      origBody.slice(0, 160));
    // 库里那份要保留「首行空两格」，且缩进写在 <strong> 内（编辑器会把 strong 外侧的行首空白吃掉）
    const storedOrig = runPhp(php, "-r", env, [
      'require "backend/src/bootstrap.php"; $db = new HechiZx\\Support\\Db((array) hechi_config("db"));'
        + ' echo (string) $db->scalar("SELECT content_html FROM cms_article WHERE article_id = ' + SAMPLE_ID + '");',
    ]);
    const storedOrigHtml = String(storedOrig.stdout || "");
    check("原标题：库里三行都带首行空两格，且缩进写在 <strong> 内",
      storedOrigHtml.startsWith('<p><strong>' + indent + '引题层</strong></p><p><strong>' + indent + '主标题层</strong></p>')
        && storedOrigHtml.includes('<p><strong>' + indent + '副题层</strong></p>'),
      storedOrigHtml.slice(0, 160));

    // 首页管理三件套（置顶／高亮／徽标同层）：高亮与徽标写 `cms_article_channel`，随模块输出给前台
    const flagsToken = csrfToken((await client.get("/admin/article/" + SAMPLE_ID)).text);
    const flagsSaved = await client.post("/admin/article/" + SAMPLE_ID + "/flags", {
      _token: flagsToken, channel: "904", back: "/admin/sections?section=zxdt", highlight: "1", badge: "最新",
    });
    check("首页管理：高亮与徽标可保存", flagsSaved.status === 302, "状态 " + flagsSaved.status);

    const homeBody = (await client.get("/api/v1/home", { json: true })).body?.home ?? {};
    const homeItems = [];
    for (const tab of (homeBody.zxdt?.tabs ?? [])) {
      for (const item of (tab.items ?? [])) homeItems.push(item);
    }
    const mine = homeItems.find((x) => String(x.id) === SAMPLE_ID);
    check("首页管理：首页模块输出带高亮与徽标",
      !!mine && Number(mine.is_highlight) === 1 && mine.badge === "最新",
      JSON.stringify(mine ?? homeItems[0] ?? null));

    const flagsMissing = await client.post("/admin/article/999999/flags", {
      _token: flagsToken, channel: "904", back: "/admin/sections?section=zxdt", highlight: "1", badge: "x",
    });
    check("首页管理：不存在的稿件被拒绝", flagsMissing.status === 302, "状态 " + flagsMissing.status);

    // 预览与复制链接：预览打开前台正式详情页（/detail.html?id=），复制链接给对外静态地址
    const listHtml2 = (await client.get("/admin/articles")).text;
    const sectionsHtml = (await client.get("/admin/sections?section=zxdt")).text;
    check("稿件列表：已发布稿件带预览与复制链接",
      /href="\/detail\.html\?id=\d+"/.test(listHtml2)
        && /data-copy-link="\/article\/\d+\.html"/.test(listHtml2), "列表页没找到");
    check("首页管理：模块稿件带预览与复制链接",
      /data-copy-link="\/article\/\d+\.html"/.test(sectionsHtml)
        && /href="\/detail\.html\?id=\d+"/.test(sectionsHtml), "其他栏目页没找到");

    const styleToken = csrfToken((await client.get("/admin/article/" + SAMPLE_ID)).text);
    await client.post("/admin/article/" + SAMPLE_ID, {
      _token: styleToken,
      title: "字体颜色检查",
      content_html: '<p><span style="color:#c00000;background-color:#ffe08a;font-family:宋体;font-size:18px">彩色文字</span></p>',
    });
    const styled = String((await client.get("/api/v1/article/" + SAMPLE_ID, { json: true })).body?.article?.content ?? "");
    check("字体与颜色：保存后正文里仍然保留",
      /color/i.test(styled) && /font-family/i.test(styled) && /font-size/i.test(styled) && /background-color/i.test(styled),
      styled.slice(0, 200));

    const restoreToken = csrfToken((await client.get("/admin/article/" + SAMPLE_ID)).text);
    const absolutes = [
      '<p><img src="' + base + '/images/channel/x.jpg" alt="本机地址"></p>',
      '<p><img src="' + base + '/admin/images/channel/z.jpg" alt="后台路径解析出来的地址"></p>',
      '<p><img src="http://www.gxhczx.gov.cn/uploads/2026/09/y.jpg" alt="站点域名"></p>',
      '<p><img src="https://third.example.com/pic.jpg" alt="外站"></p>',
    ].join("");
    await client.post("/admin/article/" + SAMPLE_ID, {
      _token: restoreToken, title: "站内地址还原检查", content_html: absolutes,
    });
    const restored = String((await client.get("/api/v1/article/" + SAMPLE_ID, { json: true })).body?.article?.content ?? "");
    check("站内地址：本机绝对地址还原成根相对路径", restored.includes('src="/images/channel/x.jpg"'), restored.slice(0, 200));
    check("站内地址：被解析出 /admin 前缀的地址还原", restored.includes('src="/images/channel/z.jpg"'), restored.slice(0, 240));
    check("站内地址：站点域名的绝对地址还原", restored.includes('src="/uploads/2026/09/y.jpg"'), restored.slice(0, 240));
    check("站内地址：外站图片保持原样", restored.includes("https://third.example.com/pic.jpg"), restored.slice(0, 260));
  } finally {
    await browser.close();
    server.kill();
    if (!opts.keep) {
      rmSync(tmpRoot, { recursive: true, force: true });
    } else {
      console.log("临时目录保留在：" + tmpRoot);
    }
  }

  console.log("\n共 " + total + " 项，" + (failures === 0 ? "全部通过" : failures + " 项失败"));
  return failures === 0 ? 0 : 1;
}

main()
  .then((code) => process.exit(code))
  .catch((err) => { console.error(err); process.exit(2); });
