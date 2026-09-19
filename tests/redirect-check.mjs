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
import http from "node:http";
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
    APP_DEBUG: "1",
    // 本脚本主体按年限口径跑（ENFORCE_PUBLIC_SCOPE=1），覆盖「归档稿不出页、不登记 301、
    // 接口取不到」这些过滤分支；放开口径（当前默认）另有一组断言，见下面的 openEnv。
    ENFORCE_PUBLIC_SCOPE: "1"
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

    // ---- 县（区）政协页的县区站外链：旧站子域名全部不可访问，url 留空后按纯文本渲染
    // （2026-09-17：lc/hj/nd/te/da/dh.gxhczx.gov.cn 实测均不可访问）
    const qyPage = path.join(channelDir, "xianqu-zhengxie", "index.html");
    const qyHtml = existsSync(qyPage) ? readFileSync(qyPage, "utf8") : "";
    check("县（区）政协页不再输出已失效的县区子站外链",
      qyHtml !== "" && !/href="https?:\/\/(?:lc|hj|nd|te|da|dh)\.gxhczx\.gov\.cn/i.test(qyHtml)
        && /<span class="county-link is-plain">罗城<\/span>/.test(qyHtml),
      qyHtml === "" ? "没有生成 " + qyPage : "");

    // ---- 一页式栏目的左栏兄弟项：库里的原型期地址 detail.html?id= 要换成静态详情页地址
    // （2026-09-17：相对地址在 /channel/<目录名>/ 下会解析成 .../detail.html，必然 404）
    const siblingId = phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " $rows = $db->select(\"SELECT article_id FROM cms_article WHERE site_id = 1 AND status = 'published'"
      + " AND public_scope = 'public' AND has_body = 1 ORDER BY article_id ASC LIMIT 1\");"
      + " echo $rows[0]['article_id'] ?? '';");
    const gaikuangPage = path.join(channelDir, "zhengxie-gaikuang", "index.html");
    if (siblingId === "") {
      check("一页式栏目的 detail.html?id= 改指静态详情页", false, "样例库里没有可用稿件");
    } else {
      phpEval(php, env,
        "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
        + " $siblings = json_encode(["
        + ` ['type' => '999', 'name' => '政协简介', 'url' => 'detail.html?id=${siblingId}'],`
        // 稿件号撞上栏目号（202 是 zhengxie-gaikuang 自己的栏目号）：url 形态说它是稿件页，
        // 就必须链到详情页，不能被 type 映射抢走
        + ` ['type' => '202', 'name' => '撞栏目号', 'url' => 'detail.html?id=${siblingId}'],`
        // 目标稿件不产静态页：不能把 detail.html?id= 这种相对地址留在页面上（必然 404）
        + " ['type' => '999', 'name' => '未公开稿件', 'url' => 'detail.html?id=99999998']"
        + "], JSON_UNESCAPED_UNICODE);"
        + " $db->execute(\"UPDATE sys_channel SET siblings_json = :s WHERE site_id = 1 AND slug = 'zhengxie-gaikuang'\", ['s' => $siblings]);");
      const republished = runPhp(php, "backend/bin/publish.php", env, ["--out=" + publishDir, "--html-only"]);
      const gaikuangHtml = existsSync(gaikuangPage) ? readFileSync(gaikuangPage, "utf8") : "";
      check("一页式栏目的 detail.html?id= 改指 /article/<稿件号>.html",
        republished.status === 0
          && (gaikuangHtml.match(new RegExp('href="/article/' + siblingId + '\\.html"', "g")) || []).length === 2,
        gaikuangHtml === "" ? "没有生成 " + gaikuangPage : "");
      check("兄弟项的 type 撞上栏目号时仍走详情页，不被栏目映射抢走",
        gaikuangHtml.includes('>撞栏目号</a>'), "");
      check("目标稿件不产静态页的兄弟项退成纯文本（不留 detail.html?id= 死链）",
        gaikuangHtml.includes('class="channel-btn is-plain">未公开稿件</span>')
          && !gaikuangHtml.includes("detail.html?id="),
        gaikuangHtml === "" ? "没有生成 " + gaikuangPage : "");
    }

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

    // ---- 放开口径（ENFORCE_PUBLIC_SCOPE=0，当前默认口径）：归档稿照常出页、进 sitemap、登记 301
    // 输出到独立目录，避免扰动上面那套年限口径的产物与映射表
    const openEnv = { ...env, ENFORCE_PUBLIC_SCOPE: "0" };
    const openDir = path.join(tmpRoot, "publish-open");
    const openPublish = runPhp(php, "backend/bin/publish.php", openEnv, ["--out=" + openDir, "--html-only"]);
    check("放开口径：归档稿产出静态页",
      openPublish.status === 0 && existsSync(path.join(openDir, "article", archiveId + ".html")),
      (openPublish.stderr || "").trim().split("\n")[0]);
    const openSitemap = existsSync(path.join(openDir, "sitemap.xml"))
      ? readFileSync(path.join(openDir, "sitemap.xml"), "utf8") : "";
    check("放开口径：归档稿进 sitemap", openSitemap.includes("/article/" + archiveId + ".html"));
    // 栏目页一页 20 条，这条 2019 年的稿件排在末页，翻页文件一起看
    const openChannelDir = path.join(openDir, "channel", "zhengxie-dongtai-904");
    const openChannelHtml = existsSync(openChannelDir)
      ? readdirSync(openChannelDir).filter((f) => f.endsWith(".html"))
        .map((f) => readFileSync(path.join(openChannelDir, f), "utf8")).join("\n")
      : "";
    check("放开口径：归档稿出现在所属栏目页列表里",
      openChannelHtml.includes('href="/article/' + archiveId + '.html"'));
    const openRedirects = runPhp(php, "backend/bin/redirects.php", openEnv, ["--out=" + openDir, "--check=" + openDir]);
    const openCsvPath = path.join(openDir, "redirects/url-map.csv");
    const openCsv = existsSync(openCsvPath) ? readFileSync(openCsvPath, "utf8") : "";
    check("放开口径：归档稿登记旧地址 301",
      openRedirects.status === 0
      && openCsv.includes("/html/news-view-" + archiveId + ".html,/article/" + archiveId + ".html")
      && openCsv.includes("/news_view.php?id=" + archiveId + ",/article/" + archiveId + ".html"),
      (openRedirects.stdout || "").trim().split("\n").slice(-2).join(" / "));
    // 映射表是库内的，跑完按年限口径重建一次，后面的断言（含运行期 404）仍按原口径走
    runPhp(php, "backend/bin/redirects.php", env, ["--out=" + publishDir]);

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
      deployConf.includes("redirects/nginx-301.conf") && deployConf.includes("location ^~ /channel/"));
    // 2026-09-17：末尾那条按扩展名的正则 location 会盖过没有 ^~ 的前缀 location，
    // /uploads/x.jpg、/assets/admin.css 会被截走 → 404（本地 router.php 测不出来）。
    check("部署配置的前缀 location 都带 ^~（避免被末尾正则 location 截胡）",
      ["^~ /api/", "^~ /admin", "^~ /member", "^~ /assets/", "^~ /uploads/", "^~ /uploadfiles/",
        "^~ /data/", "^~ /article/", "^~ /channel/"]
        .every((prefix) => deployConf.includes("location " + prefix)));
    check("部署配置把旧站 /uploadfiles/ 指到 legacy 上传目录",
      /location \^~ \/uploadfiles\/ \{[^}]*alias \/var\/www\/backend\/public\/uploads\/legacy\/uploadfiles\/;/.test(deployConf));

    // 前缀 location 计数兜底：以后新增不带 ^~ 的前缀 location 时这里会红（正则 location 不受影响）
    const barePrefixLocations = [...deployConf.matchAll(/^\s*location\s+(\/[^\s{]*)\s*\{/gm)]
      .map((m) => m[1]);
    check("没有遗漏 ^~ 的前缀 location", barePrefixLocations.length === 0, barePrefixLocations.join(" "));

    const routerPhp = readFileSync(path.join(REPO, "backend/public/router.php"), "utf8");
    check("本地路由同样接住 /uploadfiles/（与 Nginx 同口径）",
      routerPhp.includes("str_starts_with($path, '/uploadfiles/')")
        && routerPhp.includes("uploads/legacy/uploadfiles"));

    // ---- CSP 与静态页脚本形态相容（2026-09-17 独立评审：/article/ 的 script-src 'self'
    // 曾把 page.php 的内联交互脚本整段拦掉，线上按钮全失效，而本地 router.php 不带 CSP 测不出来）
    const cspValues = [...deployConf.matchAll(/Content-Security-Policy "([^"]*)"/g)].map((m) => m[1]);
    check("静态页 CSP 同时挂在 /article/ 与 /channel/，且逐字一致",
      cspValues.length === 2 && cspValues[0] === cspValues[1],
      cspValues.length + " 处：" + cspValues.map((c, i) => i + "=" + c.slice(0, 40)).join(" | "));
    check("静态页 CSP 的 script-src 是 'self' 且没有 unsafe-inline",
      cspValues.length > 0 && /script-src 'self';/.test(cspValues[0]) && !/script-src[^;]*unsafe-inline/.test(cspValues[0]),
      cspValues[0] || "");
    // 只要 CSP 不允许内联脚本，发布产物里就必须一个内联 <script> 都没有（脚本一律 /js/*.js 外链）
    const publishedPages = [
      path.join(publishDir, "article", "62180.html"),
      path.join(channelDir, "zhengxie-dongtai-904", "index.html"),
      path.join(channelDir, "zhengxie-gaikuang", "index.html")
    ];
    const inlineScripts = publishedPages
      .filter((file) => existsSync(file))
      .flatMap((file) => [...readFileSync(file, "utf8").matchAll(/<script(?![^>]*\ssrc=)[^>]*>/gi)].map(() => path.basename(file)));
    check("发布产物里没有内联 <script>（否则会被 CSP 整段拦掉）",
      inlineScripts.length === 0, inlineScripts.join(" "));

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
    // ---- 运行期：/uploadfiles/ 兼容路由（真发请求，不看源码字符串）
    // 旧站图片是逐目录拷进来的，测试不能假设某个真实文件在库里，所以自己放一个一次性夹具再删掉。
    const legacyFixtureDir = path.join(REPO, "backend/public/uploads/legacy/uploadfiles/_selfcheck");
    const legacyFixtureName = "probe-" + Date.now() + ".jpg";
    mkdirSync(legacyFixtureDir, { recursive: true });
    writeFileSync(path.join(legacyFixtureDir, legacyFixtureName), Buffer.from([0xff, 0xd8, 0xff, 0xd9]));
    try {
      const hit = await fetch(base + "/uploadfiles/_selfcheck/" + legacyFixtureName);
      check("运行期：/uploadfiles/ 兼容路由直出 legacy 文件",
        hit.status === 200 && (hit.headers.get("content-type") || "").startsWith("image/jpeg"),
        hit.status + " " + hit.headers.get("content-type"));
      const miss = await fetch(base + "/uploadfiles/_selfcheck/not-copied-" + legacyFixtureName);
      check("运行期：缺图的 /uploadfiles/ 直接 404（不查 301 映射）", miss.status === 404, String(miss.status));
      // 穿越用例必须绕开 fetch：WHATWG URL 与浏览器都会先折叠 `..` 与 `%2e%2e`，
      // 那样请求根本没带越界路径到服务端。node:http 的 path 参数是原样发出的。
      const rawGet = (rawPath) => new Promise((resolve, reject) => {
        const req = http.request({ host: "127.0.0.1", port, path: rawPath, method: "GET" }, (res) => {
          res.resume();
          resolve(res.statusCode);
        });
        req.on("error", reject);
        req.end();
      });
      const trav1 = await rawGet("/uploadfiles/../../backend/config/config.php");
      const trav2 = await rawGet("/uploadfiles/%2e%2e/%2e%2e/backend/public/index.php");
      check("运行期：/uploadfiles/ 拒绝目录穿越（明文与编码两种写法）",
        trav1 === 404 && trav2 === 404, trav1 + " / " + trav2);
    } finally {
      rmSync(path.join(legacyFixtureDir, legacyFixtureName), { force: true });
      try { rmSync(legacyFixtureDir, { recursive: true, force: true }); } catch (e) { /* 目录非空（他人在用）就留着 */ }
    }
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
