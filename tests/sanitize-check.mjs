#!/usr/bin/env node
/**
 * 正文清洗的端到端检查：保存 → 接口 → 静态页三条输出链路都不带载荷，且不误伤允许的标记。
 *
 * 全程用临时 SQLite 库（migrate + seed + 建一个测试账号），不动开发库。
 *
 *   node tests/sanitize-check.mjs
 *   node tests/sanitize-check.mjs --keep      # 保留临时库与发布产物
 */

import { spawn, spawnSync } from "node:child_process";
import { createHash } from "node:crypto";
import { existsSync, mkdtempSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, "..");

const USER = "sanitizeadmin";
const PASSWORD = "sanitize-admin-2026";
const SAMPLE_ID = "62180";

/** 五种载荷：与方案里列的一致 */
const PAYLOAD = [
  "<p>正常段落</p>",
  "<script>alert(1)</script>",
  "<img src=x onerror=alert(2)>",
  '<a href="javascript:alert(3)">危险链接</a>',
  '<svg onload=alert(4)></svg>',
  '<img src="data:image/png;base64,iVBORw0KGgo=">',
].join("");

/** 允许的标记：清洗后应原样保留 */
const GOOD_HTML = [
  '<div><p><strong>加粗</strong><em>斜体</em><u>下划线</u></p>',
  '<ul><li>列表项</li></ul>',
  '<p style="text-align:center">居中段落</p>',
  '<p><img src="images/channel/art62180-1.jpg" alt="相对路径图" width="600"></p>',
  '<p><a href="https://example.com">外链</a></p></div>',
].join("");

/**
 * 载荷特征。不能直接匹配 `<script`：静态页模板自身会输出同源外链脚本
 * （`<script src="/js/…"></script>`），那是页面骨架不是载荷——2026-09-19 就是这么误报的。
 * 这里匹配载荷自身的形态：内联脚本、事件属性、javascript: 协议、data: 图片；
 * 带 src 的外链脚本不算载荷（若它夹带脚本体，由 alert( 这一条兜住）。
 */
const DANGER = /<script(?![^>]*\ssrc=)|onerror|onload|onclick|javascript:|data:image|alert\(/i;

let failures = 0;
let total = 0;

function check(name, ok, detail = "") {
  total += 1;
  if (!ok) failures += 1;
  console.log((ok ? "PASS  " : "FAIL  ") + name + (ok || !detail ? "" : "  —  " + detail));
}

function parseArgs(argv) {
  const opts = { port: 8987, keep: false };
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
    get(url, opts) { return this.request("GET", url, opts); },
    post(url, form, opts) { return this.request("POST", url, { form, ...opts }); },
  };
}

function csrfToken(html) {
  const m = /name="_token" value="([^"]+)"/.exec(html);
  return m ? m[1] : "";
}

/** 从编辑页里取出正文 textarea 的值（表单字段名不变，仍是 content_html） */
function textareaContent(html) {
  const m = /name="content_html"[^>]*>([\s\S]*?)<\/textarea>/.exec(html);
  return m ? m[1] : "";
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (opts.help) {
    console.log("用法：node tests/sanitize-check.mjs [--port 8987] [--keep]");
    return 0;
  }

  const php = resolvePhp();
  if (!php) {
    console.error("本机没有可用的 php。请先 brew install php。");
    return 2;
  }

  const tmpRoot = mkdtempSync(path.join(tmpdir(), "hechi-sanitize-check-"));
  const dbFile = path.join(tmpRoot, "sanitize.sqlite");
  const publishDir = path.join(tmpRoot, "publish");
  const env = {
    DB_DRIVER: "sqlite",
    DB_DATABASE: dbFile,
    APP_ENV: "local",
    APP_DEBUG: "1",
    PUBLISH_OUT: publishDir,
  };
  console.log("PHP：" + php + "　临时库：" + dbFile);

  const migrated = runPhp(php, "backend/bin/migrate.php", env);
  const seeded = runPhp(php, "backend/bin/seed.php", env);
  const user = runPhp(php, "backend/bin/user.php", env, ["create", USER, PASSWORD, "清洗检查"]);
  check("建表 / 灌数 / 建账号三步成功",
    migrated.status === 0 && seeded.status === 0 && user.status === 0,
    (migrated.stderr || seeded.stderr || user.stderr || "").trim().split("\n")[0]);
  if (migrated.status !== 0 || seeded.status !== 0 || user.status !== 0) return 1;

  const server = spawn(php, ["-S", "127.0.0.1:" + opts.port, "-t", "backend/public", "backend/public/router.php"], {
    cwd: REPO,
    env: { ...process.env, ...env },
    stdio: ["ignore", "ignore", "pipe"],
  });
  const base = "http://127.0.0.1:" + opts.port;
  const client = makeClient(base);

  try {
    const ready = await waitForServer(base);
    check("服务已就绪", ready, base);
    if (!ready) return 1;

    const loginPage = await client.get("/admin/login");
    const logged = await client.post("/admin/login", {
      _token: csrfToken(loginPage.text), username: USER, password: PASSWORD,
    });
    check("后台登录成功", logged.status === 302, "状态 " + logged.status);

    /* ---------------- 纵深防御：CSP 与 nosniff ---------------- */

    const csp = loginPage.headers.get("content-security-policy") || "";
    check("响应头：后台页面带 X-Content-Type-Options: nosniff",
      (loginPage.headers.get("x-content-type-options") || "") === "nosniff",
      String(loginPage.headers.get("x-content-type-options")));
    check("响应头：后台页面带 Content-Security-Policy", csp !== "");
    check("响应头：脚本只允许同源与哈希，不放 'unsafe-inline'",
      csp.includes("script-src 'self'") && !/script-src[^;]*unsafe-inline/.test(csp), csp);
    check("响应头：object-src 'none' 且 base-uri 'none'",
      csp.includes("object-src 'none'") && csp.includes("base-uri 'none'"), csp);

    // 登录页的内联脚本靠哈希放行；改了脚本忘了改哈希，这条会红
    const inlineScript = /<script>([\s\S]*?)<\/script>/.exec(loginPage.text)?.[1] ?? "";
    const expected = "sha256-" + createHash("sha256").update(inlineScript, "utf8").digest("base64");
    check("响应头：登录页内联脚本的哈希与 CSP 一致（改脚本必须同步改哈希）",
      inlineScript !== "" && csp.includes("'" + expected + "'"),
      "期望 " + expected + "，实际 " + csp.replace(/^.*script-src([^;]*).*$/, "script-src$1"));

    /* ---------------- 入口清洗：保存时就把载荷拿掉 ---------------- */

    const edit = await client.get("/admin/article/" + SAMPLE_ID);
    const saved = await client.post("/admin/article/" + SAMPLE_ID, {
      _token: csrfToken(edit.text),
      title: "清洗检查：载荷",
      content_html: PAYLOAD,
    });
    check("保存含载荷的正文返回 302", saved.status === 302, "状态 " + saved.status);

    const afterSave = await client.get("/admin/article/" + SAMPLE_ID);
    const stored = textareaContent(afterSave.text);
    check("入口：保存后编辑页正文里没有载荷", !DANGER.test(stored), stored.slice(0, 160));
    check("入口：正常文字保留", stored.includes("正常段落"), stored.slice(0, 160));

    /* ---------------- 出口兜底：接口 ---------------- */

    const api = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
    const apiContent = String(api.body?.article?.content ?? "");
    check("出口：内容接口返回的正文没有载荷", api.status === 200 && !DANGER.test(apiContent), apiContent.slice(0, 160));
    check("出口：内容接口仍返回正常文字", apiContent.includes("正常段落"));

    /* ---------------- 出口兜底：静态页 ---------------- */

    const published = runPhp(php, "backend/bin/publish.php", env);
    const staticFile = path.join(publishDir, "article", SAMPLE_ID + ".html");
    const staticHtml = existsSync(staticFile) ? readFileSync(staticFile, "utf8") : "";
    check("发布器跑通并产出静态页", published.status === 0 && staticHtml !== "",
      (published.stderr || "").trim().split("\n")[0]);
    check("出口：静态页里没有载荷", staticHtml !== "" && !DANGER.test(staticHtml), staticHtml.slice(0, 160));
    check("出口：静态页仍含正常文字", staticHtml.includes("正常段落"));

    /* ---------------- 不误伤：允许的标记原样保留 ---------------- */

    const edit2 = await client.get("/admin/article/" + SAMPLE_ID);
    const savedGood = await client.post("/admin/article/" + SAMPLE_ID, {
      _token: csrfToken(edit2.text),
      title: "清洗检查：正常标记",
      content_html: GOOD_HTML,
    });
    check("保存正常标记的正文返回 302", savedGood.status === 302);

    const apiGood = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
    const good = String(apiGood.body?.article?.content ?? "");
    check("不误伤：div 保留", good.includes("<div>"), good.slice(0, 200));
    check("不误伤：strong／em／u 保留", good.includes("<strong>") && good.includes("<em>") && good.includes("<u>"));
    check("不误伤：列表保留", good.includes("<ul>") && good.includes("<li>"));
    check("不误伤：对齐的内联 style 保留", good.includes("text-align:center"), good.slice(0, 200));
    check("不误伤：图片与相对路径原样保留",
      good.includes('src="images/channel/art62180-1.jpg"') && good.includes('width="600"'), good.slice(0, 240));

    /* ---------------- 出口兜底：覆盖历史脏数据 ---------------- */

    const dirty = runPhp(php, "-r", env, [
      'require "backend/src/bootstrap.php";'
      + ' $db = new HechiZx\\Support\\Db((array) hechi_config()->get("db"));'
      + ' $db->execute("UPDATE cms_article SET content_html = :c WHERE article_id = :i",'
      + ' ["c" => "<p>历史脏数据</p><script>alert(9)</script><img src=x onerror=alert(8)>", "i" => ' + SAMPLE_ID + ']);'
      + ' echo "ok";',
    ]);
    check("把载荷直接写进库（模拟历史数据）", dirty.status === 0 && dirty.stdout.includes("ok"),
      (dirty.stderr || "").trim().split("\n")[0]);

    const apiDirty = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
    const dirtyContent = String(apiDirty.body?.article?.content ?? "");
    check("出口：库里已有的载荷在接口输出中被清洗", !DANGER.test(dirtyContent), dirtyContent.slice(0, 160));

    const published2 = runPhp(php, "backend/bin/publish.php", env);
    const staticHtml2 = existsSync(staticFile) ? readFileSync(staticFile, "utf8") : "";
    check("出口：库里已有的载荷在静态页输出中被清洗",
      published2.status === 0 && staticHtml2 !== "" && !DANGER.test(staticHtml2), staticHtml2.slice(0, 160));

    /* ---------------- 存量统计脚本（只读） ---------------- */

    const expectedCounts = runPhp(php, "-r", env, [
      'require "backend/src/bootstrap.php";'
      + ' $db = new HechiZx\\Support\\Db((array) hechi_config()->get("db"));'
      + ' $total = (int) $db->scalar("SELECT COUNT(*) FROM cms_article WHERE content_html <> \'\'");'
      + ' $bad = (int) $db->scalar("SELECT COUNT(*) FROM cms_article WHERE content_html LIKE \'%<script%\''
      + ' OR content_html LIKE \'%onerror%\' OR content_html LIKE \'%javascript:%\'");'
      + ' echo $total . "," . $bad;',
    ]);
    const [expectedTotal, expectedBad] = String(expectedCounts.stdout || "").trim().split(",");

    const snapshot = () => runPhp(php, "-r", env, [
      'require "backend/src/bootstrap.php";'
      + ' $db = new HechiZx\\Support\\Db((array) hechi_config()->get("db"));'
      + ' echo (string) $db->scalar("SELECT content_html FROM cms_article WHERE article_id = ' + SAMPLE_ID + '");',
    ]).stdout;

    const beforeScan = snapshot();
    const scan = runPhp(php, "backend/bin/scan-content.php", env);
    const afterScan = snapshot();

    check("存量统计：脚本可运行（退出码 0）", scan.status === 0,
      (scan.stderr || scan.stdout || "").trim().split("\n")[0]);
    check("存量统计：报出有正文的稿件数与库内一致",
      scan.stdout.includes("有正文稿件：" + expectedTotal + " 篇"),
      "期望 " + expectedTotal + "，输出：" + scan.stdout.split("\n").slice(0, 6).join(" / "));
    check("存量统计：报出含可疑标记的稿件数与库内一致",
      scan.stdout.includes("含可疑标记：" + expectedBad + " 篇"),
      "期望 " + expectedBad + "，输出：" + scan.stdout.split("\n").slice(0, 6).join(" / "));
    check("存量统计：列出可疑稿件的稿件号", scan.stdout.includes(SAMPLE_ID), scan.stdout.slice(0, 300));
    check("存量统计：给出清洗前后预览", scan.stdout.includes("清洗前") && scan.stdout.includes("清洗后"),
      scan.stdout.slice(0, 300));
    check("存量统计：只读，不改库", beforeScan === afterScan);
  } finally {
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
  .catch((err) => {
    console.error(err);
    process.exit(2);
  });
