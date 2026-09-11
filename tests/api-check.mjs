#!/usr/bin/env node
/**
 * 接口对拍检查：把 backend 的内容接口与阶段 A 的静态快照（frontend/home/data）逐项比对。
 *
 * 做三件事：
 *   1. 用临时 SQLite 库跑 migrate + seed（不动开发库）
 *   2. 起 PHP 内置服务器，逐个打接口，断言字段与快照一致、错误码正确
 *   3. 跑一遍静态化发布，检查产物结构
 *
 * 用法：
 *   node tests/api-check.mjs                 # 全自动（需要本机有 php）
 *   node tests/api-check.mjs --url http://127.0.0.1:8080   # 只检查已在跑的服务
 *   node tests/api-check.mjs --keep          # 保留临时库与发布产物，便于排查
 */

import { spawn, spawnSync } from "node:child_process";
import { existsSync, mkdtempSync, readFileSync, statSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, "..");
const SNAPSHOTS = path.join(REPO, "frontend", "home", "data");

const results = [];
let failures = 0;

function check(name, condition, detail = "") {
  const ok = !!condition;
  if (!ok) failures += 1;
  results.push({ name, ok, detail });
  console.log((ok ? "PASS  " : "FAIL  ") + name + (ok || !detail ? "" : "  — " + detail));
}

function json(file) {
  return JSON.parse(readFileSync(file, "utf8"));
}

function parseArgs(argv) {
  const opts = { url: "", port: 8978, keep: false, driver: "sqlite" };
  for (let i = 0; i < argv.length; i += 1) {
    if (argv[i] === "--url") opts.url = String(argv[++i] || "").replace(/\/+$/, "");
    else if (argv[i] === "--port") opts.port = Number(argv[++i]) || opts.port;
    else if (argv[i] === "--driver") opts.driver = String(argv[++i] || "sqlite");
    else if (argv[i] === "--keep") opts.keep = true;
    else if (argv[i] === "--help" || argv[i] === "-h") opts.help = true;
  }
  return opts;
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

async function fetchJson(base, pathName) {
  const res = await fetch(base + pathName);
  const text = await res.text();
  let body = null;
  try {
    body = JSON.parse(text);
  } catch (e) {
    body = { raw: text.slice(0, 200) };
  }
  return { status: res.status, body };
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

function sameIds(a, b) {
  return a.length === b.length && a.every((value, index) => String(value) === String(b[index]));
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (opts.help) {
    console.log("用法：node tests/api-check.mjs [--url <base>] [--port 8978] [--driver sqlite] [--keep]");
    return 0;
  }

  const snapshotChannels = json(path.join(SNAPSHOTS, "channel.json")).channels;
  const snapshotArticles = json(path.join(SNAPSHOTS, "article.json")).articles;
  const snapshotHome = json(path.join(SNAPSHOTS, "home.json"));
  const channel904 = snapshotChannels.find((c) => String(c.type) === "904");
  const article62180 = snapshotArticles.find((a) => String(a.id) === "62180");
  const article40029 = snapshotArticles.find((a) => String(a.id) === "40029");

  let server = null;
  let base = opts.url;
  let tmpRoot = null;
  const php = resolvePhp();

  if (!base) {
    if (!php) {
      console.error("本机没有可用的 php，无法自起后端。请先 brew install php，或用 --url 指向已启动的服务。");
      return 2;
    }
    tmpRoot = mkdtempSync(path.join(tmpdir(), "hechi-api-check-"));
    const dbFile = path.join(tmpRoot, "check.sqlite");
    const env = {
      DB_DRIVER: opts.driver,
      DB_DATABASE: dbFile,
      APP_ENV: "local",
      APP_DEBUG: "1"
    };

    console.log("PHP：" + php + "　临时库：" + dbFile);

    const migrated = runPhp(php, "backend/bin/migrate.php", env);
    check("migrate 建表成功", migrated.status === 0, (migrated.stderr || migrated.stdout || "").trim().split("\n")[0]);
    if (migrated.status === 0) {
      console.log((migrated.stdout || "").trim().split("\n").map((l) => "      " + l).join("\n"));
    }

    const seeded = runPhp(php, "backend/bin/seed.php", env);
    check("seed 灌入样例数据成功", seeded.status === 0, (seeded.stderr || seeded.stdout || "").trim().split("\n")[0]);
    if (seeded.status === 0) {
      console.log((seeded.stdout || "").trim().split("\n").map((l) => "      " + l).join("\n"));
    }

    if (migrated.status !== 0 || seeded.status !== 0) {
      return 1;
    }

    server = spawn(php, ["-S", "127.0.0.1:" + opts.port, "-t", "backend/public", "backend/public/router.php"], {
      cwd: REPO,
      env: { ...process.env, ...env },
      stdio: ["ignore", "ignore", "pipe"]
    });
    base = "http://127.0.0.1:" + opts.port;
    const ready = await waitForServer(base);
    check("接口服务已就绪（/api/v1/health）", ready, base);
    if (!ready) return 1;
  } else {
    console.log("检查目标：" + base);
  }

  try {
    // ---- 探活
    const health = await fetchJson(base, "/api/v1/health");
    check("health 返回 200 且 status=ok", health.status === 200 && health.body?.status === "ok", JSON.stringify(health.body).slice(0, 120));
    check("health 报出数据库驱动", !!health.body?.driver, JSON.stringify(health.body));

    // ---- 栏目索引
    const channels = await fetchJson(base, "/api/v1/channels?listSize=100");
    const apiChannels = channels.body?.channels || [];
    check("栏目数量与快照一致（" + snapshotChannels.length + "）", apiChannels.length === snapshotChannels.length, "实际 " + apiChannels.length);
    const snapshotTypes = snapshotChannels.map((c) => String(c.type)).sort();
    const apiTypes = apiChannels.map((c) => String(c.type)).sort();
    check("栏目号集合与快照一致", JSON.stringify(snapshotTypes) === JSON.stringify(apiTypes));
    check("栏目顺序与快照一致（即前台主导航顺序）",
      apiChannels.map((c) => String(c.type)).join(",") === snapshotChannels.map((c) => String(c.type)).join(","),
      "API 前 5：" + apiChannels.slice(0, 5).map((c) => c.type).join("、") +
        "；快照前 5：" + snapshotChannels.slice(0, 5).map((c) => c.type).join("、"));

    const listMismatch = [];
    for (const snap of snapshotChannels) {
      const api = apiChannels.find((c) => String(c.type) === String(snap.type));
      if (!api) continue;
      const snapIds = (snap.list || []).map((i) => String(i.id));
      const apiIds = (api.list || []).map((i) => String(i.id));
      if (!sameIds(apiIds, snapIds)) listMismatch.push(snap.type);
    }
    check("各栏目的列表项顺序与快照一致", listMismatch.length === 0, "不一致栏目：" + listMismatch.join("、"));

    // ---- 单栏目
    const ch = await fetchJson(base, "/api/v1/channels/904?listSize=24");
    const chBody = ch.body?.channel;
    check("channels/904 返回 200", ch.status === 200 && !!chBody, "状态 " + ch.status);
    check("channels/904 栏目元信息与快照一致",
      chBody && chBody.name === channel904.name && chBody.inner === channel904.inner &&
      chBody.layout === channel904.layout && String(chBody.total) === String(channel904.total),
      JSON.stringify({ name: chBody?.name, inner: chBody?.inner, layout: chBody?.layout, total: chBody?.total }));
    check("channels/904 列表项 id 与快照一致",
      chBody && sameIds((chBody.list || []).map((i) => String(i.id)), (channel904.list || []).map((i) => String(i.id))));
    const firstItem = (chBody?.list || [])[0] || {};
    const snapFirst = (channel904.list || [])[0] || {};
    check("列表项字段与快照一致（date/datetime/views/hasBody）",
      firstItem.date === snapFirst.date && firstItem.datetime === snapFirst.datetime &&
      String(firstItem.views) === String(snapFirst.views) && firstItem.hasBody === snapFirst.hasBody,
      JSON.stringify(firstItem).slice(0, 160));

    // ---- 分页
    const page = await fetchJson(base, "/api/v1/articles?channel=904&page=1&size=20");
    check("articles 分页返回结构完整", page.status === 200 && Array.isArray(page.body?.articles) &&
      typeof page.body.total === "number" && typeof page.body.pages === "number", JSON.stringify(page.body).slice(0, 120));
    check("articles 第 1 页内容与快照前 20 条一致",
      page.body?.articles && sameIds(page.body.articles.map((i) => String(i.id)), (channel904.list || []).slice(0, 20).map((i) => String(i.id))));
    check("articles 的 size 上限被夹到 100",
      (await fetchJson(base, "/api/v1/articles?size=999")).body?.size === 100);

    // ---- 详情
    const article = await fetchJson(base, "/api/v1/article/62180");
    const aBody = article.body?.article;
    check("article/62180 返回 200", article.status === 200 && !!aBody, "状态 " + article.status);
    check("详情字段与快照一致（标题/日期/来源/编辑）",
      aBody && aBody.title === article62180.title && aBody.date === article62180.date &&
      aBody.dateText === article62180.dateText && aBody.source === article62180.source &&
      aBody.editor === article62180.editor,
      JSON.stringify({ title: aBody?.title, date: aBody?.date, source: aBody?.source }).slice(0, 160));
    check("详情正文非空且含图片路径",
      !!aBody && aBody.content.length > 100 &&
      sameIds(aBody.images || [], article62180.images || []), "正文 " + (aBody?.content?.length || 0) + " 字");
    check("详情所属栏目名正确", aBody?.channelName === article62180.channelName, aBody?.channelName);

    // ---- 附件
    const attachments = await fetchJson(base, "/api/v1/attachments?article=40029");
    check("附件接口返回与快照一致的条数与文件名",
      (attachments.body?.attachments || []).length === (article40029.attachments || []).length &&
      (attachments.body?.attachments || []).every((file, index) => file.name === article40029.attachments[index].name),
      JSON.stringify(attachments.body).slice(0, 160));
    check("article/{id}/attachments 与查询参数写法结果一致",
      JSON.stringify((await fetchJson(base, "/api/v1/article/40029/attachments")).body) === JSON.stringify(attachments.body));

    // ---- 检索
    const search = await fetchJson(base, "/api/v1/search?q=" + encodeURIComponent("政协") + "&size=5");
    check("search 命中结果", search.status === 200 && (search.body?.total || 0) > 0, JSON.stringify(search.body).slice(0, 120));
    check("search 未传 q 时返回 400 bad_request",
      (await fetchJson(base, "/api/v1/search")).status === 400);

    // ---- 首页
    const home = await fetchJson(base, "/api/v1/home");
    const homeKeys = Object.keys(home.body?.home || {});
    check("home 返回 21 个顶层模块", homeKeys.length === Object.keys(snapshotHome).length,
      "实际 " + homeKeys.length + " 个：" + homeKeys.slice(0, 6).join("、"));
    check("home.meta 与快照一致",
      JSON.stringify(home.body?.home?.meta) === JSON.stringify(snapshotHome.meta));
    check("home.nav 条数与快照一致",
      (home.body?.home?.nav || []).length === snapshotHome.nav.length);

    // ---- 错误处理
    const missingChannel = await fetchJson(base, "/api/v1/channels/999999");
    check("未知栏目返回 404 not_found",
      missingChannel.status === 404 && missingChannel.body?.error?.code === "not_found",
      JSON.stringify(missingChannel.body).slice(0, 120));
    const missingArticle = await fetchJson(base, "/api/v1/article/999999");
    check("未知稿件返回 404 not_found",
      missingArticle.status === 404 && missingArticle.body?.error?.code === "not_found");
    const badPath = await fetchJson(base, "/api/v1/nope");
    check("未知路径返回 404 not_found", badPath.status === 404 && badPath.body?.error?.code === "not_found");

    // ---- 静态化发布（只跑数据快照，快；HTML 发布在独立用例里验结构）
    if (!opts.url && php) {
      const outDir = path.join(tmpRoot, "publish");
      const env = { DB_DRIVER: opts.driver, DB_DATABASE: path.join(tmpRoot, "check.sqlite"), APP_ENV: "local", APP_DEBUG: "1" };
      const published = runPhp(php, "backend/bin/publish.php", env, ["--out=" + outDir, "--data-only"]);
      check("publish --data-only 执行成功", published.status === 0, (published.stderr || "").trim().split("\n")[0]);

      const outChannel = path.join(outDir, "data/channel.json");
      const outHome = path.join(outDir, "data/home.json");
      const outIndex = path.join(outDir, "data/channel-index.json");
      check("发布产物齐全（home/channel/channel-index）",
        existsSync(outChannel) && existsSync(outHome) && existsSync(outIndex));

      if (existsSync(outChannel)) {
        const publishedChannels = json(outChannel).channels;
        check("发布的 channel.json 与库中栏目一致（" + snapshotChannels.length + " 个）",
          publishedChannels.length === snapshotChannels.length, "实际 " + publishedChannels.length);
        const published904 = publishedChannels.find((c) => String(c.type) === "904");
        check("发布的 904 栏目列表与快照一致",
          published904 && sameIds((published904.list || []).map((i) => String(i.id)),
            (channel904.list || []).map((i) => String(i.id))));
      }
      if (existsSync(outHome)) {
        check("发布的 home.json 顶层模块数与快照一致",
          Object.keys(json(outHome)).length === Object.keys(snapshotHome).length);
      }
      if (existsSync(outIndex)) {
        const index = json(outIndex).channels;
        check("发布的精简索引覆盖全部栏目且带稿件 id",
          index.length === snapshotChannels.length &&
          index.some((c) => String(c.type) === "904" && (c.ids || []).length > 0));
      }

      const htmlOut = path.join(tmpRoot, "publish-html");
      const htmlPub = runPhp(php, "backend/bin/publish.php", env, ["--out=" + htmlOut, "--html-only"]);
      check("publish --html-only 执行成功", htmlPub.status === 0, (htmlPub.stderr || "").trim().split("\n")[0]);
      const articleHtml = path.join(htmlOut, "article/62180.html");
      const sitemap = path.join(htmlOut, "sitemap.xml");
      check("静态页与 sitemap 已生成", existsSync(articleHtml) && existsSync(sitemap));
      if (existsSync(articleHtml)) {
        const html = readFileSync(articleHtml, "utf8");
        check("详情静态页含标题与正文",
          html.includes(article62180.title) && html.includes("article-body"), "体积 " + statSync(articleHtml).size + " 字节");
      }
      if (existsSync(sitemap)) {
        const xml = readFileSync(sitemap, "utf8");
        check("sitemap 收录首页与详情页",
          xml.includes("<loc>https://") && xml.includes("/article/62180.html"));
      }
    }
  } finally {
    if (server) server.kill("SIGTERM");
    if (tmpRoot && !opts.keep) {
      rmSync(tmpRoot, { recursive: true, force: true });
    } else if (tmpRoot) {
      console.log("临时目录保留在：" + tmpRoot);
    }
  }

  const passed = results.length - failures;
  console.log("\n合计 " + results.length + " 项检查，" + passed + " 通过，" + failures + " 失败");
  return failures === 0 ? 0 : 1;
}

main()
  .then((code) => process.exit(code))
  .catch((e) => {
    console.error("检查脚本自身出错：" + ((e && e.stack) || e));
    process.exit(2);
  });
