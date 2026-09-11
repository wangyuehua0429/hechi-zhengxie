#!/usr/bin/env node
/**
 * 后台管理界面检查：登录 → 稿件列表/编辑/保存 → 栏目编辑 → 一键发布 → 退出。
 *
 * 全程用临时 SQLite 库（migrate + seed + 建一个测试账号），不动开发库。
 *
 *   node tests/admin-check.mjs
 *   node tests/admin-check.mjs --keep      # 保留临时库与发布产物
 */

import { spawn, spawnSync } from "node:child_process";
import { existsSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, "..");

const USER = "checkadmin";
const PASSWORD = "check-admin-2026";
const SAMPLE_ID = "62180";

let failures = 0;
let total = 0;

function check(name, ok, detail = "") {
  total += 1;
  if (!ok) failures += 1;
  console.log((ok ? "PASS  " : "FAIL  ") + name + (ok || !detail ? "" : "  — " + detail));
}

function parseArgs(argv) {
  const opts = { port: 8981, keep: false };
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

/** 极简 cookie 容器：够用即可，不做域/路径匹配 */
function createJar() {
  const jar = new Map();
  return {
    header() {
      return [...jar.entries()].map(([k, v]) => k + "=" + v).join("; ");
    },
    absorb(res) {
      const cookies = typeof res.headers.getSetCookie === "function" ? res.headers.getSetCookie() : [];
      for (const raw of cookies) {
        const [pair] = raw.split(";");
        const index = pair.indexOf("=");
        if (index > 0) jar.set(pair.slice(0, index).trim(), pair.slice(index + 1).trim());
      }
    },
    clear() {
      jar.clear();
    }
  };
}

function makeClient(base) {
  const jar = createJar();
  return {
    jar,
    async request(method, url, { form = null, json = false } = {}) {
      const headers = {};
      const cookie = jar.header();
      if (cookie) headers.Cookie = cookie;
      let body;
      if (form) {
        body = new URLSearchParams(form).toString();
        headers["Content-Type"] = "application/x-www-form-urlencoded";
      }
      const res = await fetch(base + url, { method, headers, body, redirect: "manual" });
      jar.absorb(res);
      const text = await res.text();
      if (json) {
        try {
          return { status: res.status, headers: res.headers, body: JSON.parse(text), text };
        } catch (e) {
          return { status: res.status, headers: res.headers, body: null, text };
        }
      }
      return { status: res.status, headers: res.headers, text };
    },
    get(url, opts) {
      return this.request("GET", url, opts);
    },
    post(url, form, opts) {
      return this.request("POST", url, { form, ...opts });
    }
  };
}

function csrfToken(html) {
  const m = /name="_token" value="([^"]+)"/.exec(html);
  return m ? m[1] : "";
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (opts.help) {
    console.log("用法：node tests/admin-check.mjs [--port 8981] [--keep]");
    return 0;
  }

  const php = resolvePhp();
  if (!php) {
    console.error("本机没有可用的 php，无法启动后台。请先 brew install php。");
    return 2;
  }

  const tmpRoot = mkdtempSync(path.join(tmpdir(), "hechi-admin-check-"));
  const dbFile = path.join(tmpRoot, "admin.sqlite");
  const publishDir = path.join(tmpRoot, "publish");
  const env = {
    DB_DRIVER: "sqlite",
    DB_DATABASE: dbFile,
    APP_ENV: "local",
    APP_DEBUG: "1",
    PUBLISH_OUT: publishDir
  };
  console.log("PHP：" + php + "　临时库：" + dbFile);

  const migrated = runPhp(php, "backend/bin/migrate.php", env);
  const seeded = runPhp(php, "backend/bin/seed.php", env);
  const user = runPhp(php, "backend/bin/user.php", env, ["create", USER, PASSWORD, "检查账号"]);
  check("建表 / 灌数 / 建账号三步成功",
    migrated.status === 0 && seeded.status === 0 && user.status === 0,
    (migrated.stderr || seeded.stderr || user.stderr || "").trim().split("\n")[0]);
  if (migrated.status !== 0 || seeded.status !== 0 || user.status !== 0) return 1;

  const lists = runPhp(php, "backend/bin/user.php", env, ["list"]);
  check("命令行能列出后台账号", lists.status === 0 && lists.stdout.includes(USER));

  const server = spawn(php, ["-S", "127.0.0.1:" + opts.port, "-t", "backend/public", "backend/public/router.php"], {
    cwd: REPO,
    env: { ...process.env, ...env },
    stdio: ["ignore", "ignore", "pipe"]
  });
  const base = "http://127.0.0.1:" + opts.port;
  const client = makeClient(base);

  try {
    const ready = await waitForServer(base);
    check("后台服务已就绪", ready, base);
    if (!ready) return 1;

    // ---- 未登录访问会被挡到登录页
    const dashboardGuest = await client.get("/admin");
    check("未登录访问 /admin 跳转登录页",
      dashboardGuest.status === 302 && (dashboardGuest.headers.get("location") || "").includes("/admin/login"),
      "状态 " + dashboardGuest.status);
    const articlesGuest = await client.get("/admin/articles");
    check("未登录访问 /admin/articles 也跳转登录页", articlesGuest.status === 302);

    // ---- 登录页与登录
    const loginPage = await client.get("/admin/login");
    const token = csrfToken(loginPage.text);
    check("登录页可访问且带 CSRF 令牌", loginPage.status === 200 && token !== "", "token=" + token.slice(0, 8));
    check("登录页渲染站点名与表单", loginPage.text.includes("内容管理后台") && loginPage.text.includes('name="password"'));

    const wrong = await client.post("/admin/login", { _token: token, username: USER, password: "wrong-password" });
    check("密码错误时留在登录页并提示", wrong.status === 200 && wrong.text.includes("账号或密码不正确"));

    const noToken = await client.post("/admin/login", { username: USER, password: PASSWORD });
    check("缺少 CSRF 令牌的登录被拒绝", noToken.status === 200 && noToken.text.includes("页面已过期"));

    const ok = await client.post("/admin/login", { _token: token, username: USER, password: PASSWORD });
    check("登录成功并跳转 /admin",
      ok.status === 302 && (ok.headers.get("location") || "") === "/admin",
      "状态 " + ok.status + " location=" + ok.headers.get("location"));
    check("登录后拿到会话 Cookie", client.jar.header().includes("PHPSESSID"));

    // ---- 概览
    const dashboard = await client.get("/admin");
    check("概览页显示稿件总数与登录人", dashboard.status === 200 && dashboard.text.includes("稿件总数") && dashboard.text.includes("检查账号"));
    check("概览页有发布按钮", dashboard.text.includes('action="/admin/publish"'));

    // ---- 稿件列表
    const list = await client.get("/admin/articles");
    check("稿件列表可访问并列出数据", list.status === 200 && list.text.includes("<table") && list.text.includes("/admin/article/"));
    const filtered = await client.get("/admin/articles?channel=904&keyword=" + encodeURIComponent("政协"));
    check("稿件列表支持栏目 + 关键词筛选", filtered.status === 200 && filtered.text.includes("共 "));

    // ---- 稿件编辑
    const edit = await client.get("/admin/article/" + SAMPLE_ID);
    const editToken = csrfToken(edit.text);
    check("稿件编辑页可打开", edit.status === 200 && edit.text.includes("编辑稿件") && editToken !== "");
    const originalTitle = (/name="title" value="([^"]*)"/.exec(edit.text) || [])[1] || "";
    const originalSummary = (/name="summary" rows="3">([\s\S]*?)<\/textarea>/.exec(edit.text) || [])[1] || "";
    check("编辑页带出原标题", originalTitle !== "", originalTitle);

    const tokens = await client.get("/admin/article/" + SAMPLE_ID);
    const saveToken = csrfToken(tokens.text);
    const newTitle = originalTitle + "（后台检查）";
    const saved = await client.post("/admin/article/" + SAMPLE_ID, {
      _token: saveToken,
      title: newTitle,
      subtitle: "",
      source: "广西政协报",
      author: "黄 骏",
      editor: "刁海音",
      published_date: "2026-04-23",
      published_time: "11:17",
      status: "draft",
      summary: originalSummary,
      content_html: "<p>后台检查写入的正文。</p>"
    });
    check("保存稿件后跳回编辑页", saved.status === 302 && (saved.headers.get("location") || "").includes("/admin/article/" + SAMPLE_ID));

    const apiAfterSave = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
    check("保存结果已进数据库（接口能读到新标题与新正文）",
      apiAfterSave.body?.article?.title === newTitle && apiAfterSave.body.article.content.includes("后台检查写入的正文"),
      JSON.stringify(apiAfterSave.body?.article?.title));

    const badSave = await client.post("/admin/article/" + SAMPLE_ID, { _token: "invalid", title: "x" });
    check("令牌错误的保存被拒绝（400）", badSave.status === 400 && badSave.text.includes("提交被拒绝"), "状态 " + badSave.status);

    const emptyTitle = await client.get("/admin/article/" + SAMPLE_ID);
    const restoreToken = csrfToken(emptyTitle.text);
    const restored = await client.post("/admin/article/" + SAMPLE_ID, {
      _token: restoreToken,
      title: originalTitle,
      subtitle: "",
      source: "广西政协报",
      author: "黄 骏",
      editor: "刁海音",
      published_date: "2026-04-23",
      published_time: "11:17",
      status: "published",
      summary: originalSummary,
      content_html: (await client.get("/api/v1/article/" + SAMPLE_ID, { json: true })).body?.article?.content || ""
    });
    check("恢复原稿成功", restored.status === 302);

    // ---- 栏目管理
    const channels = await client.get("/admin/channels");
    check("栏目列表可访问并列出 43 个栏目",
      channels.status === 200 && (channels.text.match(/\/admin\/channel\//g) || []).length >= 43,
      "匹配 " + (channels.text.match(/\/admin\/channel\//g) || []).length + " 处");

    const channelEdit = await client.get("/admin/channel/904");
    const channelToken = csrfToken(channelEdit.text);
    check("栏目编辑页可打开且带出栏目名", channelEdit.status === 200 && channelEdit.text.includes("市政协动态") && channelToken !== "");

    const channelSaved = await client.post("/admin/channel/904", {
      _token: channelToken,
      name: "政协动态",
      inner_name: "市政协动态",
      intro: "聚焦市政协重要会议、调研视察与履职活动，及时反映全市政协工作进展。",
      layout: "list",
      sort_no: "0",
      status: "published"
    });
    check("保存栏目后跳回栏目编辑页", channelSaved.status === 302);

    // ---- 一键发布
    const pubToken = csrfToken((await client.get("/admin")).text);
    const published = await client.post("/admin/publish", { _token: pubToken });
    check("一键发布执行后跳回概览", published.status === 302 && (published.headers.get("location") || "") === "/admin");

    const dashboardAfter = await client.get("/admin");
    check("发布后概览提示发布结果", dashboardAfter.text.includes("发布完成"));

    check("发布产物落盘（首页 / 栏目数据 / 详情静态页 / sitemap）",
      existsSync(path.join(publishDir, "index.html")) &&
      existsSync(path.join(publishDir, "data/channel.json")) &&
      existsSync(path.join(publishDir, "article/" + SAMPLE_ID + ".html")) &&
      existsSync(path.join(publishDir, "sitemap.xml")));

    if (existsSync(path.join(publishDir, "article/" + SAMPLE_ID + ".html"))) {
      const html = readFileSync(path.join(publishDir, "article/" + SAMPLE_ID + ".html"), "utf8");
      check("详情静态页含该稿件标题", html.includes(originalTitle), originalTitle);
    }

    // ---- 退出
    const logoutToken = csrfToken((await client.get("/admin")).text);
    const logout = await client.post("/admin/logout", { _token: logoutToken });
    check("退出后跳转登录页", logout.status === 302 && (logout.headers.get("location") || "").includes("/admin/login"));
    const afterLogout = await client.get("/admin");
    check("退出后访问 /admin 再次被拦", afterLogout.status === 302);
  } finally {
    server.kill("SIGTERM");
    if (opts.keep) {
      console.log("临时目录保留在：" + tmpRoot);
    } else {
      rmSync(tmpRoot, { recursive: true, force: true });
    }
  }

  console.log("\n合计 " + total + " 项检查，" + (total - failures) + " 通过，" + failures + " 失败");
  return failures === 0 ? 0 : 1;
}

main()
  .then((code) => process.exit(code))
  .catch((e) => {
    console.error("检查脚本自身出错：" + ((e && e.stack) || e));
    process.exit(2);
  });
