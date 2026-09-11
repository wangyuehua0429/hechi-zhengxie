#!/usr/bin/env node
/**
 * 排序端到端检查：起一个临时库 + 临时 PHP 服务，全部通过 HTTP 走真实后台与接口。
 *
 * 覆盖四类排序：
 *   A 栏目顺序（后台栏目管理 ↑↓ → /api/v1/channels 数组顺序）
 *   B 首页导航顺序（导航栏目页 ↑↓ → /api/v1/home 的 nav 数组）
 *   C 头条轮换顺序（轮播页 ↑↓ → /api/v1/home 的 slides 数组）
 *   D 栏目内稿件顺序（稿件列表 ↑↓ → 栏目接口与首页模块同时变化）
 *   E 按栏目置顶（稿件编辑页勾选 → 栏目接口与首页模块同时排前、取消后按时间落回原位）
 *
 *   node tests/order-e2e.mjs [--port 8987] [--keep]
 */

import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, "..");
const USER = "e2eorder";
const PASSWORD = "e2e-order-2026";

let pass = 0;
let fail = 0;
const step = (name, ok, detail = "") => {
  if (ok) pass += 1; else fail += 1;
  console.log((ok ? "PASS  " : "FAIL  ") + name + (ok || !detail ? "" : "  — " + detail));
};

const args = process.argv.slice(2);
const port = Number(args[args.indexOf("--port") + 1]) || 8987;
const keep = args.includes("--keep");

function phpBin() {
  for (const bin of ["php", "/opt/homebrew/bin/php", "/usr/local/bin/php", "/usr/bin/php"]) {
    const probe = spawnSync(bin, ["-v"], { encoding: "utf8" });
    if (!probe.error && probe.status === 0) return bin;
  }
  return null;
}

const php = phpBin();
if (!php) { console.error("本机没有可用的 php"); process.exit(2); }

const tmpRoot = mkdtempSync(path.join(tmpdir(), "hechi-order-e2e-"));
const env = {
  DB_DRIVER: "sqlite",
  DB_DATABASE: path.join(tmpRoot, "order.sqlite"),
  APP_ENV: "local",
  APP_DEBUG: "1",
  PUBLISH_OUT: path.join(tmpRoot, "publish"),
};
const runPhp = (script, cliArgs = []) =>
  spawnSync(php, [script, ...cliArgs], { cwd: REPO, env: { ...process.env, ...env }, encoding: "utf8" });

for (const [name, res] of [
  ["migrate", runPhp("backend/bin/migrate.php")],
  ["seed", runPhp("backend/bin/seed.php")],
  ["user", runPhp("backend/bin/user.php", ["create", USER, PASSWORD, "排序检查"])],
]) {
  if (res.status !== 0) {
    console.error(`准备失败（${name}）：` + (res.stderr || res.stdout));
    process.exit(2);
  }
}

const server = spawn(php, ["-S", "127.0.0.1:" + port, "-t", "backend/public", "backend/public/router.php"], {
  cwd: REPO,
  env: { ...process.env, ...env },
  stdio: ["ignore", "ignore", "pipe"],
});
const base = "http://127.0.0.1:" + port;

const jar = new Map();
const cookieHeader = () => [...jar.entries()].map(([k, v]) => k + "=" + v).join("; ");
const remember = (res) => {
  for (const raw of res.headers.getSetCookie?.() || []) {
    const [pair] = raw.split(";");
    const i = pair.indexOf("=");
    if (i > 0) jar.set(pair.slice(0, i).trim(), pair.slice(i + 1).trim());
  }
};
const safeJson = (t) => { try { return JSON.parse(t); } catch { return null; } };

async function req(method, url, form = null, json = false) {
  const headers = {};
  const cookie = cookieHeader();
  if (cookie) headers.Cookie = cookie;
  let body;
  if (form) {
    body = new URLSearchParams(form).toString();
    headers["Content-Type"] = "application/x-www-form-urlencoded";
  }
  const res = await fetch(base + url, { method, headers, body, redirect: "manual" });
  remember(res);
  const text = await res.text();
  return { status: res.status, location: res.headers.get("location"), text, body: json ? safeJson(text) : null };
}
const csrf = (html) => (/name="_token" value="([^"]+)"/.exec(html) || [])[1] || "";
const api = async (url) => (await req("GET", url, null, true)).body;

async function waitReady() {
  for (let i = 0; i < 75; i += 1) {
    try { if ((await fetch(base + "/api/v1/health")).ok) return true; } catch { /* 还没起来 */ }
    await new Promise((r) => setTimeout(r, 200));
  }
  return false;
}

const orderOf = (html) =>
  [...html.matchAll(/class="title-link" href="\/admin\/channel\/(\d+)"/g)].map((m) => m[1]);
const navTitles = (html) =>
  [...html.matchAll(/name="title" value="([^"]*)"/g)].map((m) => m[1]);
const homeNav = async () => (await api("/api/v1/home"))?.home?.nav?.map((n) => n.title) || [];
const homeSlides = async () => (await api("/api/v1/home"))?.home?.slides?.map((s) => s.title) || [];
const channelIds = async (type, size = 6) =>
  ((await api(`/api/v1/channels/${type}?listSize=${size}`))?.channel?.list || []).map((i) => String(i.id));
const zxdtTop = async () =>
  ((await api("/api/v1/home"))?.home?.zxdt?.tabs?.[0]?.items || []).map((i) => String(i.id));
const apiChannelOrder = async () => ((await api("/api/v1/channels?withList=0"))?.channels || []).map((c) => String(c.type));

try {
  if (!(await waitReady())) { console.error("服务没起来"); process.exit(2); }

  const loginPage = await req("GET", "/admin/login");
  const logged = await req("POST", "/admin/login", { _token: csrf(loginPage.text), username: USER, password: PASSWORD });
  step("登录后台", logged.status === 302 && jar.has("PHPSESSID"));

  // ---------- A 栏目顺序 ----------
  console.log("\n— A 栏目顺序（/admin/channels ↑↓ → /api/v1/channels）");
  const channelsPage = await req("GET", "/admin/channels");
  const before = orderOf(channelsPage.text);
  const i = before.indexOf("905");
  const apiBefore = await apiChannelOrder();
  const moved = await req("POST", "/admin/channel/905/move", { _token: csrf(channelsPage.text), dir: "up" });
  const after = orderOf((await req("GET", "/admin/channels")).text);
  const apiAfter = await apiChannelOrder();
  step("后台：905 上移后与相邻栏目换位",
    moved.status === 302 && after[i - 1] === "905" && after[i] === before[i - 1],
    `${before.slice(0, 6).join(",")} → ${after.slice(0, 6).join(",")}`);
  step("接口：/api/v1/channels 顺序同步变化",
    apiAfter.indexOf("905") === apiBefore.indexOf("905") - 1,
    `数组中位置 ${apiBefore.indexOf("905")} → ${apiAfter.indexOf("905")}`);
  await req("POST", "/admin/channel/905/move", { _token: csrf((await req("GET", "/admin/channels")).text), dir: "down" });
  step("下移一位可还原栏目顺序", orderOf((await req("GET", "/admin/channels")).text).join(",") === before.join(","));

  // ---------- B 首页导航顺序 ----------
  console.log("\n— B 首页导航顺序（/admin/nav ↑↓ → /api/v1/home 的 nav）");
  const navPage = await req("GET", "/admin/nav");
  const navBefore = navTitles(navPage.text);
  const navApiBefore = await homeNav();
  await req("POST", "/admin/nav/2/move", { _token: csrf(navPage.text), dir: "up" });
  const navAfter = navTitles((await req("GET", "/admin/nav")).text);
  const navApiAfter = await homeNav();
  step("后台：第 3 项上移后与前一项互换",
    navAfter[1] === navBefore[2] && navAfter[2] === navBefore[1],
    `${navBefore.slice(1, 3).join("/")} → ${navAfter.slice(1, 3).join("/")}`);
  step("接口：/api/v1/home 的 nav 顺序同步（首页与内页顶栏同源）",
    navApiAfter[1] === navApiBefore[2] && navApiAfter[2] === navApiBefore[1],
    `${navApiBefore.slice(0, 3).join("→")} / ${navApiAfter.slice(0, 3).join("→")}`);
  await req("POST", "/admin/nav/1/move", { _token: csrf((await req("GET", "/admin/nav")).text), dir: "down" });
  step("可还原导航顺序", (await homeNav()).join(",") === navApiBefore.join(","));

  // ---------- C 头条轮换顺序 ----------
  console.log("\n— C 头条轮换顺序（/admin/slides ↑↓ → /api/v1/home 的 slides）");
  const slidesPage = await req("GET", "/admin/slides");
  const slideTitlesBefore = navTitles(slidesPage.text);
  const slideApiBefore = await homeSlides();
  const slideIds = [...slidesPage.text.matchAll(/\/admin\/slides\/(\d+)\/status/g)].map((m) => m[1]);
  await req("POST", `/admin/slides/${slideIds[1]}/move`, { _token: csrf(slidesPage.text), dir: "up" });
  const slideTitlesAfter = navTitles((await req("GET", "/admin/slides")).text);
  const slideApiAfter = await homeSlides();
  step("后台：第 2 条上移后与前一条互换",
    slideTitlesAfter[0] === slideTitlesBefore[1] && slideTitlesAfter[1] === slideTitlesBefore[0],
    `${slideTitlesBefore.slice(0, 2).join(" / ")} → ${slideTitlesAfter.slice(0, 2).join(" / ")}`);
  step("接口：首页轮播顺序同步", slideApiAfter[0] === slideApiBefore[1] && slideApiAfter[1] === slideApiBefore[0]);
  await req("POST", `/admin/slides/${slideIds[1]}/move`, { _token: csrf((await req("GET", "/admin/slides")).text), dir: "down" });
  step("可还原轮播顺序", (await homeSlides()).join(",") === slideApiBefore.join(","));

  // ---------- D 栏目内稿件顺序 ----------
  console.log("\n— D 栏目内稿件顺序（/admin/articles ↑↓ → 栏目接口与首页模块）");
  const listBefore = await channelIds("904", 6);
  const editPage = await req("GET", "/admin/articles?channel=904");
  step("稿件列表在按栏目筛选时提供 ↑↓ 按钮",
    /\/admin\/article\/\d+\/order/.test(editPage.text) && editPage.text.includes("调整该栏目内的顺序"));
  await req("POST", `/admin/article/${listBefore[1]}/order`, { _token: csrf(editPage.text), dir: "up" });
  const listAfter = await channelIds("904", 6);
  step("上移后该稿在栏目列表里排到最前",
    listAfter[0] === listBefore[1] && listAfter[1] === listBefore[0],
    `${listBefore.slice(0, 3).join(",")} → ${listAfter.slice(0, 3).join(",")}`);
  const zxdtAfterMove = await zxdtTop();
  step("首页「政协动态·市政协动态」同步排前", zxdtAfterMove[0] === listBefore[1],
    `首页首条 ${zxdtAfterMove[0] || "无"}`);
  await req("POST", `/admin/article/${listBefore[1]}/order`, { _token: csrf((await req("GET", "/admin/articles?channel=904")).text), dir: "down" });
  step("下移一位可还原栏目内顺序", (await channelIds("904", 6)).join(",") === listBefore.join(","));

  // ---------- E 按栏目置顶 ----------
  console.log("\n— E 按栏目置顶（稿件编辑页勾选 → 两处同时排前）");
  const beforeTop = await channelIds("904", 6);
  const target = beforeTop[2];
  const targetPage = await req("GET", "/admin/article/" + target);
  const targetTitle = (/name="title" value="([^"]*)"/.exec(targetPage.text) || [])[1] || "";
  const saved = await req("POST", "/admin/article/" + target, {
    _token: csrf(targetPage.text),
    title: targetTitle,
    content_html: "<p>排序端到端检查正文。</p>",
    is_top: "1",
  });
  const afterTop = await channelIds("904", 6);
  const zxdtTopAfter = await zxdtTop();
  step("勾选「在本栏目置顶」可以保存", saved.status === 302);
  step("置顶后栏目列表排最前", afterTop[0] === target, `首条 ${afterTop[0]}，置顶 ${target}`);
  step("置顶后首页模块排最前", zxdtTopAfter[0] === target, `首页首条 ${zxdtTopAfter[0] || "无"}`);

  const unTopPage = await req("GET", "/admin/article/" + target);
  await req("POST", "/admin/article/" + target, {
    _token: csrf(unTopPage.text),
    title: targetTitle,
    content_html: "<p>排序端到端检查正文。</p>",
  });
  const afterUnTop = await channelIds("904", 6);
  step("取消置顶后不再排最前", afterUnTop[0] !== target, `首条 ${afterUnTop[0]}`);
  step("取消置顶后回落到原来的位置（按发布时间）",
    afterUnTop.indexOf(target) === beforeTop.indexOf(target),
    `置顶前第 ${beforeTop.indexOf(target) + 1} 位 → 取消后第 ${afterUnTop.indexOf(target) + 1} 位`);
} finally {
  server.kill("SIGTERM");
  console.log(`\n合计 ${pass + fail} 项：${pass} 通过，${fail} 失败`);
  if (keep) console.log("临时库保留在：" + tmpRoot);
  else rmSync(tmpRoot, { recursive: true, force: true });
}
process.exit(fail === 0 ? 0 : 1);
