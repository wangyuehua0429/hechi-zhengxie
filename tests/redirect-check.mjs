#!/usr/bin/env node
/**
 * 旧地址 301 与静态化路径检查。
 *
 * 做四件事：
 *   1. 用临时 SQLite 库跑 migrate + seed + 发布，检查 43 个栏目各有一个静态页；
 *   2. 跑 backend/bin/redirects.php，核对映射内容、幂等性与目标产物校验；
 *   3. 起 PHP 服务，按真实请求验证旧地址 301、县区参数不误伤、未登记地址照旧 404；
 *   4. 核对部署用的 Nginx 配置不再挂着那条取不到 $1 的旧规则。
 *
 * 用法：
 *   node tests/redirect-check.mjs          # 全自动（需要本机有 php）
 *   node tests/redirect-check.mjs --keep   # 保留临时库与发布产物
 */

import { spawn, spawnSync } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, "..");

const results = [];
let failures = 0;

function check(name, condition, detail = "") {
  const ok = !!condition;
  if (!ok) failures += 1;
  results.push({ name, ok, detail });
  console.log((ok ? "PASS  " : "FAIL  ") + name + (ok || !detail ? "" : "  — " + detail));
}

function resolvePhp() {
  const candidates = ["php", "/opt/homebrew/bin/php", "/usr/local/bin/php", "/usr/bin/php"];
  for (const bin of candidates) {
    const probe = spawnSync(bin, ["-v"], { encoding: "utf8" });
    if (!probe.error && probe.status === 0) return bin;
  }
  return null;
}

function runPhp(php, script, env, args = []) {
  return spawnSync(php, [script, ...args], {
    cwd: REPO,
    env: { ...process.env, ...env },
    encoding: "utf8"
  });
}

/** 用 php -r 读库（不依赖 sqlite3 命令行） */
function phpEval(php, env, code) {
  const result = spawnSync(php, ["-r", code], {
    cwd: REPO,
    env: { ...process.env, ...env },
    encoding: "utf8"
  });
  return (result.stdout || "").trim();
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

async function main() {
  const keep = process.argv.includes("--keep");
  const php = resolvePhp();
  if (!php) {
    console.error("本机没有可用的 php，无法跑 301 检查（先 brew install php）。");
    return 2;
  }

  const tmpRoot = mkdtempSync(path.join(tmpdir(), "hechi-redirect-check-"));
  const dbFile = path.join(tmpRoot, "check.sqlite");
  const publishDir = path.join(tmpRoot, "publish");
  const legacyDir = path.join(tmpRoot, "legacy-site");
  const env = {
    DB_DRIVER: "sqlite",
    DB_DATABASE: dbFile,
    PUBLISH_OUT: publishDir,
    APP_ENV: "local",
    APP_DEBUG: "1"
  };
  const port = 8981;
  const base = "http://127.0.0.1:" + port;
  let server = null;

  console.log("PHP：" + php + "　临时库：" + dbFile);

  try {
    // ---- 准备
    const migrated = runPhp(php, "backend/bin/migrate.php", env);
    check("migrate 建表成功", migrated.status === 0, (migrated.stderr || "").trim().split("\n")[0]);
    const seeded = runPhp(php, "backend/bin/seed.php", env);
    check("seed 灌入样例数据成功", seeded.status === 0, (seeded.stderr || "").trim().split("\n")[0]);
    const published = runPhp(php, "backend/bin/publish.php", env, ["--out=" + publishDir, "--html-only"]);
    check("发布静态页成功", published.status === 0, (published.stderr || "").trim().split("\n")[0]);
    if (migrated.status !== 0 || seeded.status !== 0 || published.status !== 0) {
      return 1;
    }

    // ---- 栏目静态页：43 个栏目 43 个路径，不再互相覆盖
    const channelDir = path.join(publishDir, "channel");
    const channelDirs = existsSync(channelDir) ? readdirSync(channelDir) : [];
    check("栏目静态页目录数与栏目数一致（43）", channelDirs.length === 43, "实际 " + channelDirs.length);
    const page902 = path.join(channelDir, "zhengxie-dongtai-902", "index.html");
    const page904 = path.join(channelDir, "zhengxie-dongtai-904", "index.html");
    check("同 slug 的栏目各自成页（902 与 904 都存在）",
      existsSync(page902) && existsSync(page904));
    if (existsSync(page902) && existsSync(page904)) {
      const title902 = (readFileSync(page902, "utf8").match(/<title>([^<]*)<\/title>/) || [])[1] || "";
      const title904 = (readFileSync(page904, "utf8").match(/<title>([^<]*)<\/title>/) || [])[1] || "";
      check("902 与 904 静态页内容不同（没有被覆盖）",
        title902.includes("全国政协动态") && title904.includes("市政协动态"), title902 + " / " + title904);
    }
    const sitemap = existsSync(path.join(publishDir, "sitemap.xml"))
      ? readFileSync(path.join(publishDir, "sitemap.xml"), "utf8") : "";
    const locs = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
    check("sitemap 地址无重复", locs.length > 0 && new Set(locs).size === locs.length,
      "共 " + locs.length + " 条，去重后 " + new Set(locs).size + " 条");

    // ---- 归档稿件（public_scope=archive）不产静态页、不登记 301
    const archiveId = 88881;
    phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " $db->execute(\"INSERT INTO cms_article (article_id, site_id, channel_type, title, content_html, has_body, status, public_scope, published_at)"
      + ` VALUES (${archiveId}, 1, '904', '归档检查用稿件', '<p>超期稿件</p>', 1, 'published', 'archive', '2019-05-06 10:00:00')\");`
      + ` $db->execute(\"INSERT INTO cms_article_channel (article_id, site_id, channel_type, sort_no, is_primary) VALUES (${archiveId}, 1, '904', 0, 1)\");`);
    const republish = runPhp(php, "backend/bin/publish.php", env, ["--out=" + publishDir, "--html-only"]);
    check("归档稿件：重新发布后仍不出静态页",
      republish.status === 0 && !existsSync(path.join(publishDir, "article", archiveId + ".html")));
    const sitemap2 = existsSync(path.join(publishDir, "sitemap.xml"))
      ? readFileSync(path.join(publishDir, "sitemap.xml"), "utf8") : "";
    check("归档稿件：不进 sitemap", !sitemap2.includes("/article/" + archiveId + ".html"));
    phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + ` $db->execute("UPDATE cms_article SET public_scope = 'public' WHERE article_id = ${archiveId}");`);
    runPhp(php, "backend/bin/publish.php", env, ["--out=" + publishDir, "--html-only"]);
    check("稿件转为公开后重新发布会产出静态页",
      existsSync(path.join(publishDir, "article", archiveId + ".html")));
    phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + ` $db->execute("UPDATE cms_article SET public_scope = 'archive' WHERE article_id = ${archiveId}");`);
    const pruned = runPhp(php, "backend/bin/publish.php", env, ["--out=" + publishDir, "--html-only"]);
    check("再次归档后重新发布会清掉旧静态页（不留可直出的归档页）",
      pruned.status === 0 && !existsSync(path.join(publishDir, "article", archiveId + ".html"))
      && (pruned.stdout || "").includes("pruned"),
      (pruned.stdout || "").trim().split("\n").slice(-2).join(" / "));

    // ---- 旧站目录对照用的临时样本（真旧站目录不在每次检查里扫描）
    mkdirSync(path.join(legacyDir, "html"), { recursive: true });
    writeFileSync(path.join(legacyDir, "html/news-view-62180.html"), "<html>旧站样例</html>");
    writeFileSync(path.join(legacyDir, "html/news-view-62179.html"), "<html>旧站样例</html>");
    writeFileSync(path.join(legacyDir, "html/news-view-999999.html"), "<html>未迁移样例</html>");
    mkdirSync(path.join(legacyDir, "zl20991231"), { recursive: true });

    // ---- 生成映射
    const redirects = runPhp(php, "backend/bin/redirects.php", env, [
      "--out=" + publishDir, "--check=" + publishDir, "--legacy-site=" + legacyDir
    ]);
    check("redirects.php 执行成功", redirects.status === 0, (redirects.stderr || redirects.stdout || "").trim().split("\n")[0]);
    const redirectsOut = redirects.stdout || "";
    check("每条映射的目标产物都存在（--check 无缺失）", redirectsOut.includes("全部命中"), redirectsOut.split("\n").filter((l) => l.includes("目标")).join(" / "));

    const csvPath = path.join(publishDir, "redirects/url-map.csv");
    const nginxPath = path.join(publishDir, "redirects/nginx-301.conf");
    check("生成 url-map.csv 与 nginx-301.conf", existsSync(csvPath) && existsSync(nginxPath));

    const csv = existsSync(csvPath) ? readFileSync(csvPath, "utf8").trim().split("\n") : [];
    const dbCount = Number(phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " echo (int) $db->scalar('SELECT COUNT(*) FROM sys_url_redirect');"));
    check("映射表条数与 CSV 行数一致", csv.length - 1 === dbCount && dbCount > 0, "CSV " + (csv.length - 1) + " 行，库 " + dbCount + " 条");

    const lookup = (oldPath) => {
      const line = csv.find((row) => row.startsWith(oldPath + ","));
      return line ? line.split(",")[1] : null;
    };
    check("/html/news-view-62180.html → /article/62180.html",
      lookup("/html/news-view-62180.html") === "/article/62180.html", String(lookup("/html/news-view-62180.html")));
    check("/news_view.php?id=62180 → /article/62180.html",
      lookup("/news_view.php?id=62180") === "/article/62180.html", String(lookup("/news_view.php?id=62180")));
    check("/cq_view.php?id=62180 → /article/62180.html",
      lookup("/cq_view.php?id=62180") === "/article/62180.html", String(lookup("/cq_view.php?id=62180")));
    check("/news_list.php?id=904 → /channel/zhengxie-dongtai-904/",
      lookup("/news_list.php?id=904") === "/channel/zhengxie-dongtai-904/", String(lookup("/news_list.php?id=904")));
    check("/news_list.php?id=902 指向自己的栏目页（不与 904 撞）",
      lookup("/news_list.php?id=902") === "/channel/zhengxie-dongtai-902/", String(lookup("/news_list.php?id=902")));
    check("/qy_list.php → 县（区）政协栏目页",
      String(lookup("/qy_list.php")).startsWith("/channel/xianqu-zhengxie"), String(lookup("/qy_list.php")));
    check("/zl20260225/ 与 /zl20260225 都指向专题栏目页",
      lookup("/zl20260225/") !== null && lookup("/zl20260225/") === lookup("/zl20260225"), String(lookup("/zl20260225/")));
    check("旧站入口页 → 首页", lookup("/home.php") === "/", String(lookup("/home.php")));
    check("归档稿件不登记 301", lookup("/html/news-view-88881.html") === null && lookup("/news_view.php?id=88881") === null,
      String(lookup("/html/news-view-88881.html")));

    const report = existsSync(path.join(publishDir, "redirects/report.txt"))
      ? readFileSync(path.join(publishDir, "redirects/report.txt"), "utf8") : "";
    check("报告里列出未登记的旧地址", report.includes("/html/news-view-999999.html"));
    check("报告里点出旧站目录里多出来的专题目录", report.includes("/zl20991231/"));

    const nginx = existsSync(nginxPath) ? readFileSync(nginxPath, "utf8") : "";
    check("Nginx 片段把旧路径转给 PHP 入口",
      nginx.includes("news_view\\.php") && nginx.includes("fastcgi_pass php:9000") && nginx.includes("zl[0-9]+"));
    const deployConf = readFileSync(path.join(REPO, "deploy/nginx/default.conf"), "utf8");
    check("部署配置不再有取不到 $1 的旧 301 规则",
      !deployConf.includes("return 301 /article/$1") && !deployConf.includes("location ^~ /news-view-"));
    check("部署配置引用了生成的 301 片段并直出栏目静态页",
      deployConf.includes("redirects/nginx-301.conf") && deployConf.includes("location /channel/"));

    // ---- 幂等
    const again = runPhp(php, "backend/bin/redirects.php", env, ["--dry-run"]);
    check("重复执行只报「不变」，不产生新增",
      again.status === 0 && /新增 \/ 更新 \/ 不变\s+0 \/ 0 \/ \d+/.test(again.stdout || ""),
      (again.stdout || "").split("\n").find((l) => l.includes("不变")) || "");

    // ---- 稿件转归档后重跑：失效的旧地址映射要被清掉，且 --check 仍全部命中
    check("转归档前：该稿件登记了 301", lookup("/html/news-view-62180.html") === "/article/62180.html",
      String(lookup("/html/news-view-62180.html")));
    phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " $db->execute(\"UPDATE cms_article SET public_scope = 'archive' WHERE article_id = 62180\");");
    const regen = runPhp(php, "backend/bin/redirects.php", env, [
      "--out=" + publishDir, "--check=" + publishDir,
    ]);
    const csvAfter = readFileSync(csvPath, "utf8").trim().split("\n").slice(1);
    const lookupAfter = (oldPath) => {
      const line = csvAfter.find((row) => row.startsWith(oldPath + ","));
      return line ? line.split(",")[1] : null;
    };
    check("转归档后重跑：失效映射被清掉（旧地址回到 404）",
      regen.status === 0 && lookupAfter("/html/news-view-62180.html") === null
      && lookupAfter("/news_view.php?id=62180") === null
      && /清掉的失效映射\s+[1-9]/.test(regen.stdout || ""),
      (regen.stdout || "").split("\n").find((l) => l.includes("失效映射")) || "");
    check("转归档后 --check 仍报全部命中（表里没有指向空地址的 301）",
      (regen.stdout || "").includes("全部命中"),
      (regen.stdout || "").split("\n").find((l) => l.includes("目标")) || "");
    phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " $db->execute(\"UPDATE cms_article SET public_scope = 'public' WHERE article_id = 62180\");");
    runPhp(php, "backend/bin/redirects.php", env, ["--out=" + publishDir]);
    check("改回公开后重跑：301 重新登记",
      lookup("/html/news-view-62180.html") === "/article/62180.html",
      String(lookup("/html/news-view-62180.html")));

    // ---- 运行期
    server = spawn(php, ["-S", "127.0.0.1:" + port, "-t", "backend/public", "backend/public/router.php"], {
      cwd: REPO,
      env: { ...process.env, ...env },
      stdio: ["ignore", "ignore", "pipe"]
    });
    const ready = await waitForServer(base);
    check("服务已就绪", ready, base);
    if (!ready) return 1;

    const noFollow = (p) => fetch(base + p, { redirect: "manual" });
    const followed = async (p) => {
      const res = await fetch(base + p); // 默认跟随跳转
      return { status: res.status, url: res.url, text: await res.text() };
    };

    const r1 = await noFollow("/news_list.php?id=904");
    check("运行时：/news_list.php?id=904 返回 301",
      r1.status === 301 && r1.headers.get("location") === "/channel/zhengxie-dongtai-904/",
      r1.status + " → " + r1.headers.get("location"));
    const r2 = await noFollow("/news_view.php?id=62180");
    check("运行时：/news_view.php?id=62180 返回 301",
      r2.status === 301 && r2.headers.get("location") === "/article/62180.html",
      r2.status + " → " + r2.headers.get("location"));
    const r3 = await noFollow("/cq_view.php?id=62180");
    check("运行时：/cq_view.php?id=62180 返回 301", r3.status === 301, String(r3.status));
    const r4 = await followed("/news_view.php?id=62180");
    check("跟随 301 后落到详情静态页且标题正确",
      r4.status === 200 && r4.url.endsWith("/article/62180.html") && r4.text.includes("许显辉赴河池市调研"),
      r4.status + " " + r4.url);
    const r5 = await noFollow("/news_list.php?id=902");
    check("运行时：902 跳到自己的栏目静态页",
      r5.status === 301 && r5.headers.get("location") === "/channel/zhengxie-dongtai-902/",
      r5.status + " → " + r5.headers.get("location"));
    const r6 = await followed("/channel/zhengxie-dongtai-904/");
    check("栏目静态页可直出（本地走发布目录）",
      r6.status === 200 && r6.text.includes("市政协动态"), r6.status + " " + r6.url);
    const r7 = await noFollow("/news_view.php?id=62180&q=33");
    check("县区子站参数（q≠22）不映射，照旧 404", r7.status === 404, String(r7.status));
    const r8 = await noFollow("/html/news-view-999999.html");
    check("未登记的旧地址照旧 404（不 301 到空页）", r8.status === 404, String(r8.status));
    const archiveApi = await noFollow("/api/v1/article/88881");
    check("归档稿件：公开接口取不到（404）", archiveApi.status === 404, String(archiveApi.status));
    const archiveOld = await noFollow("/news_view.php?id=88881");
    check("归档稿件：旧地址 404（不发不跳）", archiveOld.status === 404, String(archiveOld.status));
    const r9 = await noFollow("/home.php");
    check("旧站入口页 301 到首页", r9.status === 301 && r9.headers.get("location") === "/", r9.status + " → " + r9.headers.get("location"));
    const r10 = await noFollow("/zl20260225/");
    check("旧专题目录 301 到专题栏目页",
      r10.status === 301 && String(r10.headers.get("location")).startsWith("/channel/"), r10.status + " → " + r10.headers.get("location"));
    const post = await fetch(base + "/news_list.php?id=904", { method: "POST", redirect: "manual" });
    check("POST 到旧脚本不改成 301（避免丢请求体）", post.status !== 301, String(post.status));

    const hits = Number(phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " echo (int) $db->scalar(\"SELECT hits FROM sys_url_redirect WHERE old_path = '/news_list.php?id=904'\");"));
    check("命中的旧地址累计了命中数（hits）", hits >= 1, "hits=" + hits);

    // ---- 生产入口：Nginx 把旧路径转给 backend/public/index.php（与本地 router.php 是两条路）
    const entryRouter = path.join(tmpRoot, "entry-router.php");
    writeFileSync(entryRouter, "<?php require " + JSON.stringify(path.join(REPO, "backend/public/index.php")) + ";\n");
    const entryPort = port + 1;
    const entryBase = "http://127.0.0.1:" + entryPort;
    const entryServer = spawn(php, ["-S", "127.0.0.1:" + entryPort, "-t", "backend/public", entryRouter], {
      cwd: REPO,
      env: { ...process.env, ...env },
      stdio: ["ignore", "ignore", "pipe"]
    });
    try {
      const entryReady = await waitForServer(entryBase);
      check("生产入口（index.php）已就绪", entryReady, entryBase);
      if (entryReady) {
        const e1 = await fetch(entryBase + "/news_list.php?id=904", { redirect: "manual" });
        check("入口层：/news_list.php?id=904 返回 301",
          e1.status === 301 && e1.headers.get("location") === "/channel/zhengxie-dongtai-904/",
          e1.status + " → " + e1.headers.get("location"));
        const e2 = await fetch(entryBase + "/news_view.php?id=62180", { redirect: "manual" });
        check("入口层：/news_view.php?id=62180 返回 301",
          e2.status === 301 && e2.headers.get("location") === "/article/62180.html",
          e2.status + " → " + e2.headers.get("location"));
        const e3 = await fetch(entryBase + "/html/news-view-999999.html", { redirect: "manual" });
        check("入口层：未登记的旧地址仍返回 404", e3.status === 404, String(e3.status));
        const e4 = await fetch(entryBase + "/api/v1/health");
        check("入口层：接口不受 301 逻辑影响", e4.status === 200);
      }
    } finally {
      entryServer.kill();
    }

    return failures === 0 ? 0 : 1;
  } finally {
    if (server) {
      server.kill();
    }
    if (!keep) {
      rmSync(tmpRoot, { recursive: true, force: true });
    } else {
      console.log("保留临时目录：" + tmpRoot);
    }
    console.log("\n合计 " + results.length + " 项检查，" + (results.length - failures) + " 通过，" + failures + " 失败");
  }
}

const code = await main();
process.exit(code);
