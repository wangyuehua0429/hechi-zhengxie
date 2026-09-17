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
const EDITOR_USER = "checkeditor";
const EDITOR_PASSWORD = "check-editor-2026";
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
    async request(method, url, { form = null, json = false, multipart = null } = {}) {
      const headers = {};
      const cookie = jar.header();
      if (cookie) headers.Cookie = cookie;
      let body;
      if (multipart) {
        const fd = new FormData();
        for (const [key, value] of Object.entries(multipart.fields || {})) {
          fd.append(key, value);
        }
        for (const file of multipart.files || []) {
          fd.append(file.field, new Blob([file.content], { type: file.type || "application/octet-stream" }), file.filename);
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
    },
    upload(url, fields, files) {
      return this.request("POST", url, { multipart: { fields, files } });
    }
  };
}

function csrfToken(html) {
  const m = /name="_token" value="([^"]+)"/.exec(html);
  return m ? m[1] : "";
}

/** 页面里的表单值都是转义过的，回填 POST 时要还原 */
function unescapeHtml(value) {
  return String(value == null ? "" : value)
    .replace(/&lt;/g, "<").replace(/&gt;/g, ">")
    .replace(/&quot;/g, '"').replace(/&#039;/g, "'")
    .replace(/&amp;/g, "&");
}

/** 从编辑页取出某列表单值（已反转义） */
function formValue(html, name) {
  const m = new RegExp('name="' + name + '" value="([^"]*)"').exec(html);
  return m ? unescapeHtml(m[1]) : "";
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
  const editorUser = runPhp(php, "backend/bin/user.php", env, ["create", EDITOR_USER, EDITOR_PASSWORD, "编辑账号", "editor"]);
  check("建表 / 灌数 / 建账号三步成功",
    migrated.status === 0 && seeded.status === 0 && user.status === 0 && editorUser.status === 0,
    (migrated.stderr || seeded.stderr || user.stderr || editorUser.stderr || "").trim().split("\n")[0]);
  if (migrated.status !== 0 || seeded.status !== 0 || user.status !== 0) return 1;

  check("账号创建支持指定角色（栏目编辑）",
    editorUser.status === 0 && editorUser.stdout.includes("editor"),
    (editorUser.stdout || editorUser.stderr || "").trim());

  const lists = runPhp(php, "backend/bin/user.php", env, ["list"]);
  check("命令行能列出后台账号", lists.status === 0 && lists.stdout.includes(USER));
  check("账号列表显示所属角色", lists.status === 0 && lists.stdout.includes("editor"));

  // ---- 时区：php.ini 默认是 UTC，应用必须自己设成 Asia/Shanghai，
  //      否则后台「上次发布」显示 10:23 而实际是 18:23
  const timezone = runPhp(php, "-r", env, ["require 'backend/src/bootstrap.php'; echo date_default_timezone_get();"]);
  check("应用时区按配置生效（不是 php.ini 的 UTC）",
    timezone.stdout.trim() === "Asia/Shanghai", timezone.stdout.trim());

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
    check("概览页不再重复显示面包屑（只有 H1 的「概览」）",
      !dashboard.text.includes('class="breadcrumb"'));
    // 2026-09-17：红色语义收窄到「需要处理」——待审／退回单独一行，其余状态进折叠区
    check("概览统计卡分主次：待办标红、0 值弱化",
      dashboard.text.includes("stat stat--todo") && dashboard.text.includes("stat--zero"));
    check("顶栏姓名与角色名相同时只显示一次",
      (dashboard.text.match(/检查账号/g) || []).length === 1,
      "出现 " + (dashboard.text.match(/检查账号/g) || []).length + " 次");
    check("侧栏一级菜单带内联 SVG 图标（不引图标库）",
      (dashboard.text.match(/class="ico"/g) || []).length >= 5);

    // ---- 自检页：侧栏「接口状态」不再直接弹一串 JSON
    const healthPage = await client.get("/admin/health");
    check("侧栏「接口状态」指向后台自检页（不再直开接口）",
      dashboard.text.includes('href="/admin/health"')
        && !dashboard.text.includes('href="/api/v1/health"'));
    check("自检页把探活结果读成人能看的样子",
      healthPage.status === 200
        && healthPage.text.includes("接口正常")
        && healthPage.text.includes("探活结果")
        && healthPage.text.includes("运行环境")
        && healthPage.text.includes("重新检查"),
      "状态 " + healthPage.status);
    check("自检页保留原始 JSON 入口，并列出数据库与目录写权限",
      healthPage.text.includes('href="/api/v1/health"')
        && healthPage.text.includes("数据库")
        && healthPage.text.includes("发布目录")
        && /可写|只读/.test(healthPage.text));

    // ---- 设计令牌：品牌红与站点前台一致
    const cssText = readFileSync(path.join(REPO, "backend/public/assets/admin.css"), "utf8");
    check("后台品牌色统一到前台红 #a51d22",
      /--brand:\s*#a51d22/.test(cssText) && !/--brand:\s*#b01e23/.test(cssText));
    check("令牌齐全（间距／圆角／字号／投影）",
      ["--space-4", "--radius-lg", "--text-sm", "--shadow-float"].every((token) => cssText.includes(token + ":")));

    // ---- 稿件列表
    const list = await client.get("/admin/articles");
    check("稿件列表可访问并列出数据", list.status === 200 && list.text.includes("<table") && list.text.includes("/admin/article/"));
    const filtered = await client.get("/admin/articles?channel=904&keyword=" + encodeURIComponent("政协"));
    check("稿件列表支持栏目 + 关键词筛选", filtered.status === 200 && filtered.text.includes("共 "));

    // ---- 预览／复制链接：预览打开前台正式详情页，复制链接给对外静态地址；归档稿两个都置灰
    const rowsOf = (html) => (html.match(/<tr>[\s\S]*?<\/tr>/g) || []);
    const rowFor = (html, id) => rowsOf(html).find((row) => row.includes("/admin/article/" + id + '">')) || "";
    const publishedList = await client.get("/admin/articles?status=published");
    const sampleId = (publishedList.text.match(/\/admin\/article\/(\d+)">/) || [])[1] || "";
    check("列表的「预览」打开前台详情页、「复制链接」给对外静态地址",
      sampleId !== ""
        && rowFor(publishedList.text, sampleId).includes('href="/detail.html?id=' + sampleId + '"')
        && rowFor(publishedList.text, sampleId).includes('data-copy-link="/article/' + sampleId + '.html"'),
      "样例 #" + sampleId);

    // 归档稿（public_scope=archive）超出公开年限、只留后台，不产静态页且接口取不到
    runPhp(php, "-r", env, [
      'require "backend/src/bootstrap.php"; $db = new HechiZx\\Support\\Db((array) hechi_config("db"));'
        + ' $db->execute("UPDATE cms_article SET public_scope = :s WHERE article_id = :id", ["s" => "archive", "id" => ' + SAMPLE_ID + ']);',
    ]);
    const archivedList = await client.get("/admin/articles?status=published&keyword=" + encodeURIComponent("许显辉"));
    const archivedRow = rowFor(archivedList.text, SAMPLE_ID);
    check("归档稿的「预览」「复制链接」置灰并说明原因",
      archivedRow.includes("is-disabled")
        && !archivedRow.includes('href="/detail.html?id=' + SAMPLE_ID + '"')
        && /归档稿不对外发布/.test(archivedRow),
      archivedRow ? "该行已置灰" : "没找到该行");
    runPhp(php, "-r", env, [
      'require "backend/src/bootstrap.php"; $db = new HechiZx\\Support\\Db((array) hechi_config("db"));'
        + ' $db->execute("UPDATE cms_article SET public_scope = :s WHERE article_id = :id", ["s" => "public", "id" => ' + SAMPLE_ID + ']);',
    ]);

    // ---- 栏目筛选是导航条（不是下拉）：默认只列一级项，选中哪一组就展开哪一组
    const chipLabels = (html) => {
      const out = [];
      // chip 里现在带计数 <span class="chip-num">，先把计数去掉再取名字，
      // 否则 `>([^<]+)</a>` 这种写法只会抓到计数那个文本节点
      const re = /<a class="channel-chip[^"]*"[^>]*>([\s\S]*?)<\/a>/g;
      let hit;
      while ((hit = re.exec(html)) !== null) {
        out.push(hit[1].replace(/<span class="chip-num">[\s\S]*?<\/span>/g, "").replace(/<[^>]+>/g, "").trim());
      }
      return out;
    };
    const listChips = chipLabels(list.text);
    check("稿件列表里的栏目是导航条而非下拉",
      list.text.includes('class="channel-nav"') && !/<select name="channel"/.test(list.text),
      "chip " + listChips.length + " 个");
    check("栏目条默认只列一级项（不在首屏铺开 43 个 chip）",
      listChips.length === 26 && (list.text.match(/channel-chip--group/g) || []).length === 5,
      "chip " + listChips.length + " 个");
    check("默认不展开任何子栏目",
      !list.text.includes('class="channel-group"'));
    check("一级项顺序与前台导航一致（前 5 个）",
      listChips.slice(1, 6).join(",") === "政协领导,政协动态,政协会议,规章制度,政协提案",
      listChips.slice(0, 6).join("、"));
    const activeChip = (list.text.match(/class="channel-chip active"[\s\S]{0,400}?>([^<]+)<\/a>/) || [])[1] || "";
    check("选中栏目的 chip 高亮在「全部栏目」上", activeChip === "全部栏目", activeChip);
    const filteredChips = chipLabels((await client.get("/admin/articles?channel=314")).text);
    check("按栏目筛选后仍在导航条上操作",
      filteredChips.length === 26 && filteredChips.includes("图片新闻"),
      "chip " + filteredChips.length + " 个");

    // ---- 一级栏目点下去筛整组（栏目号 904 同时是组内「市政协动态」的号，必须能区分）
    const groupPage = await client.get("/admin/articles?group=904");
    const groupChips = chipLabels(groupPage.text);
    const totalOf = (html) => {
      const hit = html.match(/共 <strong>(\d+)<\/strong> 篇稿件/);
      return hit ? Number(hit[1]) : -1;
    };
    const groupTotal = totalOf(groupPage.text);
    const childTotals = [];
    for (const type of ["902", "903", "904", "905", "906"]) {
      childTotals.push(totalOf((await client.get("/admin/articles?channel=" + type)).text));
    }
    check("点一级栏目会展开该组的子栏目",
      groupPage.text.includes('class="channel-group"') &&
      groupChips.includes("全国政协动态") && groupChips.includes("县区政协工作动态"),
      groupChips.length + " 个 chip");
    check("按一级筛选时摘要写明「整个栏目」",
      groupPage.text.includes("栏目：政协动态（整个栏目）"));
    check("整组筛选条数 = 组内各子栏目之和",
      groupTotal > 0 && groupTotal === childTotals.reduce((a, b) => a + b, 0),
      "整组 " + groupTotal + "，子栏目 " + childTotals.join("+"));
    check("点子栏目只筛它自己，不连带整组",
      childTotals[1] > 0 && childTotals[1] < groupTotal,
      "903=" + childTotals[1] + "，整组=" + groupTotal);

    // ---- 母栏目是开关：展开状态下再点一次收起子栏目
    check("子栏目用浅红底与母栏目连成一组",
      /\.channel-chip--child\s*\{[^}]*var\(--brand-soft\)/s.test(cssText));
    const openParentHref = (groupPage.text.match(/<a class="[^"]*channel-chip--group[^"]*"[^>]*href="([^"]+)"/) || [])[1] || "";
    check("展开状态下母栏目的链接指回收起状态",
      openParentHref !== "" && !openParentHref.includes("group=904"),
      openParentHref);
    const collapsed = await client.get(openParentHref);
    const collapsedTotal = totalOf(collapsed.text);
    check("再点一次母栏目会收起子栏目",
      !collapsed.text.includes('class="channel-group"') &&
      collapsedTotal === totalOf(list.text) && collapsedTotal !== groupTotal,
      "收起后 " + collapsedTotal + " 篇，整组 " + groupTotal + " 篇");

    // ---- 空状态：图标 + 出路，不是一行干文字
    const emptyPage = await client.get("/admin/articles?keyword=" + encodeURIComponent("zzz不可能匹配的标题zzz"));
    check("筛选无结果时给出图标与下一步",
      emptyPage.text.includes('class="empty-icon"') &&
      emptyPage.text.includes("empty-hint") &&
      emptyPage.text.includes("清除全部筛选"));

    // ---- 稿库导航条（不是状态下拉）
    check("稿件列表用稿库导航条而不是状态下拉",
      list.text.includes('class="vault-nav"') && !/<select name="status"/.test(list.text));
    check("稿库导航条六个库齐全且带计数",
      ["全部", "草稿", "待审", "退回", "已发布", "已撤回", "回收站"]
        .every((label) => new RegExp(">" + label + '<span class="vault-num">').test(list.text)),
      (list.text.match(/vault-num">\d+/g) || []).slice(0, 7).join(" "));
    check("概览页统计按稿库口径展示",
      (() => {
        const dash = dashboard.text;
        return dash.includes("草稿") && dash.includes("待审") && dash.includes("已撤回") &&
          /\/admin\/articles\?status=published/.test(dash);
      })());

    // ---- 稿件编辑
    const edit = await client.get("/admin/article/" + SAMPLE_ID);
    check("稿件编辑页可打开", edit.status === 200 && edit.text.includes("编辑稿件") && csrfToken(edit.text) !== "");
    const originalTitle = (/name="title" value="([^"]*)"/.exec(edit.text) || [])[1] || "";
    const originalSummary = (/name="summary" rows="3">([\s\S]*?)<\/textarea>/.exec(edit.text) || [])[1] || "";
    const originalContent = (/name="content_html" rows="18" class="mono">([\s\S]*?)<\/textarea>/.exec(edit.text) || [])[1] || "";
    // 2026-09-14 起编辑页把正文题区收进「原标题」三列，正文里不再重复；
    // 回填保存时要把这三列一起带上，否则等于用户把原标题删了。
    const originalOrig = {
      orig_kicker: formValue(edit.text, "orig_kicker"),
      orig_title: formValue(edit.text, "orig_title"),
      orig_subtitle: formValue(edit.text, "orig_subtitle"),
    };
    check("编辑页带出原标题与正文", originalTitle !== "" && originalContent.length > 100,
      originalTitle + " / 正文 " + originalContent.length + " 字符");

    const newTitle = originalTitle + "（后台检查）";
    const saved = await client.post("/admin/article/" + SAMPLE_ID, {
      _token: csrfToken(edit.text),
      title: newTitle,
      subtitle: "",
      source: "广西政协报",
      author: "黄 骏",
      editor: "刁海音",
      published_date: "2026-04-23",
      published_time: "11:17",
      summary: originalSummary,
      content_html: "<p>后台检查写入的正文。</p>"
    });
    check("保存稿件后跳回编辑页", saved.status === 302 && (saved.headers.get("location") || "").includes("/admin/article/" + SAMPLE_ID));

    // ---- 表单不再改状态：撤回走稿库流转
    const editedPage = await client.get("/admin/article/" + SAMPLE_ID);
    check("编辑页用状态徽标 + 流转按钮，不再有状态下拉",
      editedPage.text.includes("flow-bar") && !/<select name="status"/.test(editedPage.text));

    const withdrawn = await client.post("/admin/article/" + SAMPLE_ID + "/flow", {
      _token: csrfToken(editedPage.text),
      action: "withdraw",
      note: "检查脚本撤回"
    });
    check("撤回后跳回编辑页", withdrawn.status === 302 && (withdrawn.headers.get("location") || "").includes("/admin/article/" + SAMPLE_ID));

    const withdrawnPublic = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
    check("已撤回稿件不对外可见（公开接口 404）",
      withdrawnPublic.status === 404 && withdrawnPublic.body?.error?.code === "not_found",
      "状态 " + withdrawnPublic.status);

    const withdrawnInAdmin = await client.get("/admin/articles?status=withdrawn&keyword=" + encodeURIComponent("后台检查"));
    check("已撤回稿件进「已撤回」稿库并可检索",
      withdrawnInAdmin.status === 200 && withdrawnInAdmin.text.includes("后台检查"));
    check("稿库只列本库内容：已撤回库里查不到标题相同但未撤回的稿件",
      (await client.get("/admin/articles?status=draft&keyword=" + encodeURIComponent("后台检查"))).text.includes("没有符合条件"));

    const republished = await client.post("/admin/article/" + SAMPLE_ID + "/flow", {
      _token: csrfToken((await client.get("/admin/article/" + SAMPLE_ID)).text),
      action: "republish"
    });
    check("从已撤回重新发布", republished.status === 302);
    check("重新发布后公开接口恢复可见",
      (await client.get("/api/v1/article/" + SAMPLE_ID, { json: true })).status === 200);

    const badSave = await client.post("/admin/article/" + SAMPLE_ID, { _token: "invalid", title: "x" });
    check("令牌错误的保存被拒绝（400）", badSave.status === 400 && badSave.text.includes("提交被拒绝"), "状态 " + badSave.status);

    const restorePage = await client.get("/admin/article/" + SAMPLE_ID);
    const restored = await client.post("/admin/article/" + SAMPLE_ID, {
      _token: csrfToken(restorePage.text),
      title: originalTitle,
      subtitle: "",
      source: "广西政协报",
      author: "黄 骏",
      editor: "刁海音",
      published_date: "2026-04-23",
      published_time: "11:17",
      summary: originalSummary,
      // 编辑页里的 textarea 值是转义过的，回填时要还原成真 HTML（否则会把 &lt;div&gt; 存进库）
      content_html: unescapeHtml(originalContent),
      ...originalOrig
    });
    check("恢复原稿成功", restored.status === 302);
    const publishedPublic = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
    check("恢复为已发布后公开接口又能读到",
      publishedPublic.status === 200 && publishedPublic.body?.article?.title === originalTitle);

    // ---- 「在本栏目置顶」：2026-09-14 起编辑页不再设置排序，置顶统一在首页管理／列表里点
    const listBeforeTop = (await client.get("/api/v1/channels/904?listSize=5", { json: true })).body?.channel?.list || [];
    const topTargetId = String(listBeforeTop[1]?.id || "");
    check("取到用于置顶检查的第二篇稿件", topTargetId !== "", "904 前三条 " + listBeforeTop.slice(0, 3).map((i) => i.id).join(","));
    if (topTargetId !== "") {
      const topEdit = await client.get("/admin/article/" + topTargetId);
      check("编辑页不再有「在本栏目置顶」复选框（排序不在提交环节设置）",
        !/name="is_top"/.test(topEdit.text));
      const topSaved = await client.post("/admin/article/" + topTargetId + "/top", {
        _token: csrfToken(topEdit.text),
        value: "1",
        back: "/admin/articles"
      });
      check("首页管理/列表里的「置顶」按钮可以保存", topSaved.status === 302);
      const listAfterTop = (await client.get("/api/v1/channels/904?listSize=5", { json: true })).body?.channel?.list || [];
      check("置顶后该稿排在栏目列表最前",
        String(listAfterTop[0]?.id || "") === topTargetId,
        "首条 " + (listAfterTop[0]?.id || "无") + "，置顶 " + topTargetId);
      const homeAfterTop = await client.get("/api/v1/home", { json: true });
      check("置顶后该稿同时排在首页「政协动态·市政协动态」最前",
        String(homeAfterTop.body?.home?.zxdt?.tabs?.[0]?.items?.[0]?.id || "") === topTargetId,
        "首页首条 " + (homeAfterTop.body?.home?.zxdt?.tabs?.[0]?.items?.[0]?.id || "无"));

      const unTopPage = await client.get("/admin/article/" + topTargetId);
      const unTopSaved = await client.post("/admin/article/" + topTargetId + "/top", {
        _token: csrfToken(unTopPage.text),
        value: "0",
        back: "/admin/articles"
      });
      check("取消置顶接口可用，且编辑页始终没有置顶复选框",
        unTopSaved.status === 302
          && !/name="is_top"/.test((await client.get("/admin/article/" + topTargetId)).text),
        "状态 " + unTopSaved.status);
      const listAfterUnTop = (await client.get("/api/v1/channels/904?listSize=5", { json: true })).body?.channel?.list || [];
      const posBefore = listBeforeTop.findIndex((i) => String(i.id) === topTargetId);
      const posAfter = listAfterUnTop.findIndex((i) => String(i.id) === topTargetId);
      check("取消置顶后按发布时间落回原位，不会顶到最前",
        posAfter === posBefore && posAfter !== 0,
        "置顶前第 " + (posBefore + 1) + " 位 → 取消后第 " + (posAfter + 1) + " 位");
    }

    // ---- 原标题：正文题区收进三列、编辑器正文不重复、保存幂等（2026-09-14）
    {
      const origPage = await client.get("/admin/article/" + SAMPLE_ID);
      const taMatch = /<textarea[^>]*name="content_html"[^>]*>([\s\S]*?)<\/textarea>/.exec(origPage.text);
      const taHtml = taMatch ? unescapeHtml(taMatch[1]) : "";
      const kicker = formValue(origPage.text, "orig_kicker");
      const mainTitle = formValue(origPage.text, "orig_title");
      check("编辑页把正文题区回填进「原标题」输入框",
        kicker.includes("许显辉赴河池市调研时提出") && mainTitle.includes("生态与资源协同发力"),
        JSON.stringify({ kicker, mainTitle }));
      check("编辑页正文里不再重复题区行", taHtml !== "" && !taHtml.includes("许显辉赴河池市调研时提出"),
        taHtml.slice(0, 80));

      const pageTitle = (/name="title" value="([^"]*)"/.exec(origPage.text) || [])[1] || "";
      const post = async () => client.post("/admin/article/" + SAMPLE_ID, {
        _token: csrfToken((await client.get("/admin/article/" + SAMPLE_ID)).text),
        title: unescapeHtml(pageTitle),
        content_html: taHtml,
        orig_kicker: kicker,
        orig_title: mainTitle,
      });
      // 作者／责任编辑已不在编辑页维护（表单里没有这两个框），保存时请求也就不会带这两列；
      // 详情页仍按库里的内容显示「作者」「责任编辑」，所以保存不能把它们清成空值。
      const signBefore = (await client.get("/api/v1/article/" + SAMPLE_ID, { json: true })).body?.article || {};
      await post();
      await post();   // 连续保存两次，正文不应出现两份题区
      const after = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
      const body = String(after.body?.article?.content ?? "");
      check("表单不带作者／责任编辑时，保存不会清掉库里的署名",
        String(signBefore.author ?? "") !== "" && String(signBefore.editor ?? "") !== ""
          && String(after.body?.article?.author ?? "") === String(signBefore.author)
          && String(after.body?.article?.editor ?? "") === String(signBefore.editor),
        JSON.stringify({
          before: signBefore.author + " / " + signBefore.editor,
          after: after.body?.article?.author + " / " + after.body?.article?.editor,
        }));
      const indent = "\u3000\u3000";
      // 前台出口会把行首缩进裁掉交给 CSS（text-indent: 2em），所以接口这里只看题区在最前
      check("保存后正文题区仍排在最前（缩进由前台样式给）",
        body.startsWith("<p><strong>" + kicker + "</strong></p>")
          && body.includes("<p><strong>" + mainTitle + "</strong></p>"),
        body.slice(0, 120));
      check("重复保存不会叠加题区",
        body.split(kicker).length - 1 === 1 && body.split(mainTitle).length - 1 === 1,
        "引题出现 " + (body.split(kicker).length - 1) + " 次");

      // 库里存的那份要保留「首行空两格」，且缩进写在 <strong> 里（编辑器外侧空白会被吃掉）
      const stored = runPhp(php, "-r", env, [
        'require "backend/src/bootstrap.php"; $db = new HechiZx\\Support\\Db((array) hechi_config("db"));'
          + ' echo (string) $db->scalar("SELECT content_html FROM cms_article WHERE article_id = ' + SAMPLE_ID + '");',
      ]);
      const storedHtml = String(stored.stdout || "");
      check("库里题区行首行空两格，且缩进写在 <strong> 内",
        storedHtml.startsWith("<p><strong>" + indent + kicker + "</strong></p>")
          && storedHtml.includes("<p><strong>" + indent + mainTitle + "</strong></p>"),
        storedHtml.slice(0, 120));

      // 回归（P1）：正文有三行题区、但三列只填了两列时，空着的那列要从正文补回，保存不能丢行
      runPhp(php, "-r", env, [
        'require "backend/src/bootstrap.php"; $db = new HechiZx\\Support\\Db((array) hechi_config("db"));'
          + ' $db->execute("UPDATE cms_article SET content_html = :c, orig_kicker = :k, orig_title = :t, orig_subtitle = :s WHERE article_id = :id",'
          + ' ["c" => "<div><strong>引题新增</strong></div><div><strong>主标题新增</strong></div>'
          + '<div><strong>副题新增</strong></div><div>正文。</div>",'
          + ' "k" => "引题新增", "t" => "主标题新增", "s" => "", "id" => ' + SAMPLE_ID + ']);',
      ]);
      const backPage = await client.get("/admin/article/" + SAMPLE_ID);
      check("残缺状态：空着的副题会从正文题区补回",
        formValue(backPage.text, "orig_subtitle") === "副题新增",
        formValue(backPage.text, "orig_subtitle"));
      const backTa = unescapeHtml((/<textarea[^>]*name="content_html"[^>]*>([\s\S]*?)<\/textarea>/.exec(backPage.text) || [])[1] || "");
      await client.post("/admin/article/" + SAMPLE_ID, {
        _token: csrfToken(backPage.text),
        title: formValue(backPage.text, "title"),
        content_html: backTa,
        orig_kicker: formValue(backPage.text, "orig_kicker"),
        orig_title: formValue(backPage.text, "orig_title"),
        orig_subtitle: formValue(backPage.text, "orig_subtitle"),
      });
      const kept = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
      const keptBody = String(kept.body?.article?.content ?? "");
      check("残缺状态保存后三行题区都还在（不会丢副题）",
        keptBody.includes("引题新增") && keptBody.includes("主标题新增") && keptBody.includes("副题新增"),
        keptBody.slice(0, 140));
    }

    // ---- 新建稿件 → 附件 → 插图 → 删除
    const newForm = await client.get("/admin/article/new");
    check("新建稿件表单可打开", newForm.status === 200 && newForm.text.includes("新建稿件"));
    check("新建稿件给「保存并发布」与「保存为草稿」两个按钮",
      /name="status" value="published"[^>]*>\s*保存并发布/.test(newForm.text) &&
      /name="status" value="draft"[^>]*>\s*保存为草稿/.test(newForm.text));
    check("新建页的栏目选择是导航条而非下拉",
      newForm.text.includes('class="channel-nav"') && !/<select name="channel_type"/.test(newForm.text));
    const newForm314 = await client.get("/admin/article/new?channel=314");
    check("导航条选栏目后带进表单隐藏域",
      /name="channel_type" value="314"/.test(newForm314.text) &&
      (newForm314.text.match(/class="channel-chip active"[\s\S]{0,400}?>([^<]+)<\/a>/) || [])[1] === "图片新闻",
      "active=" + ((newForm314.text.match(/class="channel-chip active"[\s\S]{0,400}?>([^<]+)<\/a>/) || [])[1] || "无"));

    const created = await client.post("/admin/article/create", {
      _token: csrfToken(newForm.text),
      channel_type: "904",
      title: "后台检查用临时稿件",
      subtitle: "",
      source: "检查脚本",
      author: "",
      editor: "",
      published_date: "2026-09-11",
      published_time: "09:30",
      status: "published",
      summary: "自动化检查创建，用完即删。",
      // 故意只写纯文本、用空行分段：验证后台会自动转成 <p>
      content_html: "这是检查脚本写入的第一段。\n\n这是第二段。"
    });
    const createdId = (/(\/admin\/article\/(\d+))$/.exec(created.headers.get("location") || "") || [])[2] || "";
    check("新建稿件成功并跳到编辑页", created.status === 302 && createdId !== "", "id=" + createdId);

    if (createdId) {
      const createdPublic = await client.get("/api/v1/article/" + createdId, { json: true });
      check("新建的已发布稿件立刻能被公开接口读到",
        createdPublic.status === 200 && createdPublic.body?.article?.title === "后台检查用临时稿件");
      check("纯文本正文自动分段（空行转成 <p>）",
        (createdPublic.body?.article?.content || "").includes("<p>这是检查脚本写入的第一段。</p>") &&
        (createdPublic.body?.article?.content || "").includes("<p>这是第二段。</p>"),
        JSON.stringify(createdPublic.body?.article?.content || "").slice(0, 120));

      const createdEdit = await client.get("/admin/article/" + createdId);
      const uploadToken = csrfToken(createdEdit.text);
      const uploaded = await client.upload(
        "/admin/article/" + createdId + "/attachment",
        { _token: uploadToken },
        [{ field: "file", filename: "检查附件.txt", type: "text/plain", content: "后台附件上传检查" }]
      );
      check("上传附件后跳回编辑页", uploaded.status === 302);

      const editAfterUpload = await client.get("/admin/article/" + createdId);
      check("编辑页列出刚上传的附件", editAfterUpload.text.includes("检查附件.txt"));
      const fileUrl = (/href="(\/uploads\/[^"]+\.txt)"/.exec(editAfterUpload.text) || [])[1] || "";
      check("上传的文件可直接访问", fileUrl !== "" && (await client.get(fileUrl)).status === 200, fileUrl);

      const imageUploaded = await client.upload(
        "/admin/article/" + createdId + "/image",
        { _token: csrfToken(editAfterUpload.text) },
        [{
          field: "image",
          filename: "check.png",
          type: "image/png",
          content: Buffer.from(
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
            "base64"
          )
        }]
      );
      check("上传正文图片后跳回编辑页", imageUploaded.status === 302);
      const afterImage = await client.get("/api/v1/article/" + createdId, { json: true });
      check("正文里出现刚插入的图片，且登记为图集图片",
        (afterImage.body?.article?.content || "").includes("<img") && (afterImage.body?.article?.images || []).length === 1,
        "images=" + JSON.stringify(afterImage.body?.article?.images || []));

      const deletePage = await client.get("/admin/article/" + createdId + "/delete");
      check("已发布稿件直接删除会被拦下并提示先撤回",
        deletePage.status === 200 && deletePage.text.includes("请先撤回"));

      const beforeWithdraw = await client.get("/admin/article/" + createdId);
      const withdrawForDelete = await client.post("/admin/article/" + createdId + "/flow", {
        _token: csrfToken(beforeWithdraw.text),
        action: "withdraw",
        note: "检查删除流程"
      });
      check("撤回后方可删除", withdrawForDelete.status === 302);

      const trashConfirm = await client.get("/admin/article/" + createdId + "/delete");
      check("回收站确认页可打开", trashConfirm.status === 200 && trashConfirm.text.includes("移入回收站"));
      const deleted = await client.post("/admin/article/" + createdId + "/delete", {
        _token: csrfToken(trashConfirm.text),
        confirm: "delete"
      });
      check("移入回收站后跳到回收站稿库",
        deleted.status === 302 && (deleted.headers.get("location") || "") === "/admin/articles?status=deleted");
      check("回收站稿件前台读不到", (await client.get("/api/v1/article/" + createdId, { json: true })).status === 404);
      if (fileUrl !== "") {
        check("移入回收站保留附件文件（可恢复）", (await client.get(fileUrl)).status === 200, fileUrl);
      }

      const trashList = await client.get("/admin/articles?status=deleted");
      check("回收站列表能看到刚移入的稿件", trashList.text.includes("后台检查用临时稿件"));

      const trashEdit = await client.get("/admin/article/" + createdId);
      check("回收站稿件页给出恢复入口", trashEdit.text.includes('value="restore"'));
      const restoredFromTrash = await client.post("/admin/article/" + createdId + "/flow", {
        _token: csrfToken(trashEdit.text),
        action: "restore"
      });
      check("从回收站恢复成功", restoredFromTrash.status === 302);
      const afterRestore = await client.get("/admin/article/" + createdId);
      check("恢复后回到删除前的稿库（已撤回）",
        afterRestore.text.includes("已撤回") && afterRestore.text.includes('value="republish"'),
        (afterRestore.text.match(/tag tag-(\w+)/) || [])[1] || "无状态标签");
    }

    // ---- 栏目管理
    // ---- 稿库流转全链路
    const flowForm = await client.get("/admin/article/new");
    const flowCreated = await client.post("/admin/article/create", {
      _token: csrfToken(flowForm.text),
      channel_type: "904",
      title: "稿库流转检查稿",
      subtitle: "",
      source: "检查脚本",
      author: "",
      editor: "",
      published_date: "2026-09-11",
      published_time: "10:00",
      status: "draft",
      summary: "",
      content_html: "<p>流转检查正文。</p>"
    });
    const flowId = (/(\/admin\/article\/(\d+))$/.exec(flowCreated.headers.get("location") || "") || [])[2] || "";
    check("新建为草稿成功", flowCreated.status === 302 && flowId !== "", "id=" + flowId);

    if (flowId) {
      const draftEdit = await client.get("/admin/article/" + flowId);
      check("草稿库稿件给出「提交审核」入口", draftEdit.text.includes('value="submit"'));
      check("草稿稿件前台读不到", (await client.get("/api/v1/article/" + flowId, { json: true })).status === 404);

      const submitted = await client.post("/admin/article/" + flowId + "/flow", {
        _token: csrfToken(draftEdit.text),
        action: "submit"
      });
      check("提交审核成功", submitted.status === 302);
      const pendingEdit = await client.get("/admin/article/" + flowId);
      check("稿件进入待审库并给出通过／退回入口",
        pendingEdit.text.includes("待审") && pendingEdit.text.includes('value="reject"') && pendingEdit.text.includes('value="approve"'));

      await client.post("/admin/article/" + flowId + "/flow", {
        _token: csrfToken(pendingEdit.text),
        action: "reject",
        note: ""
      });
      const rejectFail = await client.get("/admin/article/" + flowId);
      check("退回不填意见会被拒绝", rejectFail.text.includes("请填写退回意见"));

      await client.post("/admin/article/" + flowId + "/flow", {
        _token: csrfToken(rejectFail.text),
        action: "reject",
        note: "请补充来源"
      });
      const rejectedEdit = await client.get("/admin/article/" + flowId);
      check("退回后进退回库并留下退回意见",
        rejectedEdit.text.includes("退回意见") && rejectedEdit.text.includes("请补充来源"));

      await client.post("/admin/article/" + flowId + "/flow", {
        _token: csrfToken(rejectedEdit.text),
        action: "submit"
      });
      const approveEdit = await client.get("/admin/article/" + flowId);
      const approved = await client.post("/admin/article/" + flowId + "/flow", {
        _token: csrfToken(approveEdit.text),
        action: "approve"
      });
      check("审核通过后跳回编辑页", approved.status === 302);
      check("审核通过后前台可见",
        (await client.get("/api/v1/article/" + flowId, { json: true })).status === 200);
    }

    // ---- 权限与数据范围：栏目编辑账号
    const editorClient = makeClient(base);
    const editorLoginPage = await editorClient.get("/admin/login");
    const editorLoggedIn = await editorClient.post("/admin/login", {
      _token: csrfToken(editorLoginPage.text),
      username: EDITOR_USER,
      password: EDITOR_PASSWORD
    });
    check("栏目编辑账号可登录后台", editorLoggedIn.status === 302);
    const editorDash = await editorClient.get("/admin");
    check("顶栏显示当前账号的角色名", editorDash.text.includes("栏目编辑"));
    check("栏目编辑的概览不提供一键发布按钮", !editorDash.text.includes('action="/admin/publish"'));
    check("栏目编辑访问栏目管理被判 403", (await editorClient.get("/admin/channels")).status === 403);
    check("栏目编辑调一键发布接口也被判 403",
      (await editorClient.post("/admin/publish", { _token: csrfToken(editorDash.text) })).status === 403);

    const editorNewForm = await editorClient.get("/admin/article/new");
    const editorCreated = await editorClient.post("/admin/article/create", {
      _token: csrfToken(editorNewForm.text),
      channel_type: "904",
      title: "编辑越权发布检查稿",
      subtitle: "",
      source: "",
      author: "",
      editor: "",
      published_date: "2026-09-11",
      published_time: "10:30",
      status: "published",
      summary: "",
      content_html: "<p>越权检查正文。</p>"
    });
    const editorCreatedId = (/(\/admin\/article\/(\d+))$/.exec(editorCreated.headers.get("location") || "") || [])[2] || "";
    const editorCreatedPage = editorCreatedId ? await editorClient.get("/admin/article/" + editorCreatedId) : null;
    check("栏目编辑点「保存并发布」会被降级为草稿",
      editorCreatedPage !== null && editorCreatedPage.text.includes("tag-draft") && editorCreatedPage.text.includes("没有发布权限"),
      "id=" + editorCreatedId);
    check("降级后的稿件前台读不到",
      editorCreatedId !== "" && (await client.get("/api/v1/article/" + editorCreatedId, { json: true })).status === 404);
    check("栏目编辑访问用户管理被判 403", (await editorClient.get("/admin/users")).status === 403);

    // ---- 写库的时间戳必须是本地时间：这条脚本写的稿件刚更新过，跟当前本地时间比
    const lastUpdatedAt = runPhp(php, "-r", env, [
      "echo (new PDO('sqlite:'.getenv('DB_DATABASE')))->query('SELECT updated_at FROM cms_article ORDER BY article_id DESC LIMIT 1')->fetchColumn();"
    ]).stdout.trim();
    const localNow = runPhp(php, "-r", env, [
      "require 'backend/src/bootstrap.php'; echo date('Y-m-d H:i:s');"
    ]).stdout.trim();
    const stampMs = (text) => new Date(text.replace(" ", "T") + "+08:00").getTime();
    check("稿件时间戳写的是本地时间（与当前相差 2 分钟内）",
      lastUpdatedAt !== "" && localNow !== "" && Math.abs(stampMs(localNow) - stampMs(lastUpdatedAt)) < 120000,
      "库=" + lastUpdatedAt + " 本地=" + localNow);

    // ---- 用户与角色管理（管理员）
    const usersPage = await client.get("/admin/users");
    check("用户列表可访问并列出账号与角色",
      usersPage.status === 200 && usersPage.text.includes("用户管理") &&
      usersPage.text.includes(USER) && usersPage.text.includes("栏目编辑"));

    const rolesPage = await client.get("/admin/roles");
    const roleIds = [...rolesPage.text.matchAll(/\/admin\/role\/(\d+)"/g)].map((m) => m[1]);
    check("角色列表列出四个内置角色", rolesPage.status === 200 && roleIds.length >= 4, "ids=" + roleIds.join(","));
    const reviewerRoleId = roleIds[1] || "2";

    const userNewForm = await client.get("/admin/user/new");
    check("新建账号页可打开并列出角色勾选项",
      userNewForm.status === 200 && userNewForm.text.includes("新建账号") && userNewForm.text.includes("roles[]"));
    const createdUser = await client.post("/admin/user/create", {
      _token: csrfToken(userNewForm.text),
      username: "checkreviewer",
      password: "check-review-2026",
      // 故意让姓名与角色名相同，用来验证顶栏不会显示成「审核 审核」
      real_name: "审核",
      dept: "办公室",
      mobile: "13800000000",
      email: "reviewer@example.com",
      remark: "检查脚本创建",
      status: "enabled",
      "roles[]": reviewerRoleId
    });
    const createdUserId = (/(\/admin\/user\/(\d+))$/.exec(createdUser.headers.get("location") || "") || [])[2] || "";
    check("新建账号成功并跳到编辑页", createdUser.status === 302 && createdUserId !== "", "id=" + createdUserId);
    const createdUserPage = await client.get("/admin/user/" + createdUserId);
    check("新账号带上了所选角色与部门",
      createdUserPage.text.includes("审核") && createdUserPage.text.includes("办公室"));

    const reviewerClient = makeClient(base);
    const reviewerLoginPage = await reviewerClient.get("/admin/login");
    const reviewerOk = await reviewerClient.post("/admin/login", {
      _token: csrfToken(reviewerLoginPage.text),
      username: "checkreviewer",
      password: "check-review-2026"
    });
    check("新建的审核账号可登录", reviewerOk.status === 302);
    const reviewerDash = await reviewerClient.get("/admin");
    check("审核账号顶栏显示角色名", reviewerDash.text.includes("审核"));
    check("姓名与角色名相同时顶栏不重复显示",
      (reviewerDash.text.match(/审核/g) || []).length === 1,
      "「审核」出现 " + (reviewerDash.text.match(/审核/g) || []).length + " 次");
    check("审核账号访问用户管理被判 403", (await reviewerClient.get("/admin/users")).status === 403);
    check("无 article.edit 的账号看不到「新建稿件」按钮",
      !(await reviewerClient.get("/admin/articles")).text.includes('href="/admin/article/new'));

    const roleEdit = await client.get("/admin/role/" + reviewerRoleId);
    check("角色编辑页列出权限码与栏目范围",
      roleEdit.status === 200 && roleEdit.text.includes("article.review") && roleEdit.text.includes("栏目范围"));
    const savedRole = await client.post("/admin/role/" + reviewerRoleId, {
      _token: csrfToken(roleEdit.text),
      name: "审核",
      description: "待审稿件的通过或退回",
      remark: "责任人：内容把关",
      "perms[]": "article.review",
      "channels[]": "904"
    });
    check("保存角色后跳回角色页", savedRole.status === 302);
    check("角色列表显示该角色已限定 1 个栏目",
      (await client.get("/admin/roles")).text.includes("1 个栏目"));
    const reviewerScoped = await reviewerClient.get("/admin/articles?channel=314");
    check("数据范围生效：限定栏目的账号查不到范围外稿件",
      /共 <strong>0<\/strong> 篇稿件/.test(reviewerScoped.text),
      (reviewerScoped.text.match(/共 <strong>\d+<\/strong> 篇稿件/) || [])[0] || "无统计");

    const logsPage = await client.get("/admin/logs");
    check("操作日志页可访问并记录稿库流转与账号改动",
      logsPage.status === 200 && logsPage.text.includes("提交审核") && logsPage.text.includes("新建账号"));

    // ---- 栏目管理
    const channels = await client.get("/admin/channels");
    check("栏目列表可访问并列出 43 个栏目",
      channels.status === 200 && (channels.text.match(/\/admin\/channel\//g) || []).length >= 43,
      "匹配 " + (channels.text.match(/\/admin\/channel\//g) || []).length + " 处");
    check("栏目列表按前台顺序排列（首行是 202 政协领导）",
      channels.text.includes("顺序与前台导航一致") &&
      (channels.text.match(/\/admin\/channel\/(\d+)/) || [])[1] === "202",
      "首行栏目号 " + ((channels.text.match(/\/admin\/channel\/(\d+)/) || [])[1] || "无"));

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

    // ---- 稿件 / 栏目管理界面重构（2026-09-11）：筛选面板、排序、每页条数、批量操作、栏目分组与上移下移
    const listPage = await client.get("/admin/articles");
    check("稿件列表把稿库、栏目与筛选条件收进一块筛选面板",
      listPage.text.includes('class="filter-panel"') &&
      listPage.text.includes('class="vault-nav"') &&
      listPage.text.includes('class="channel-nav"') &&
      /<select name="size"/.test(listPage.text) &&
      /<select name="sort"/.test(listPage.text));
    check("稿件列表有面包屑与页码跳转",
      listPage.text.includes('class="breadcrumb"') && listPage.text.includes('class="pager-jump"'));

    const firstTitleId = (html) =>
      (html.match(/class="title-link" href="\/admin\/article\/(\d+)"/) || [])[1] || "";
    const ascList = await client.get("/admin/articles?sort=article_id:asc");
    const descList = await client.get("/admin/articles?sort=article_id:desc");
    check("列表可按稿件号排序（升序、降序结果不同）",
      firstTitleId(ascList.text) !== "" && firstTitleId(descList.text) !== "" &&
      Number(firstTitleId(ascList.text)) < Number(firstTitleId(descList.text)),
      "升序首条 " + firstTitleId(ascList.text) + "，降序首条 " + firstTitleId(descList.text));

    const bigList = await client.get("/admin/articles?size=50");
    check("每页条数可选（50 条时一页列出 50 篇）",
      (bigList.text.match(/class="title-link" href="\/admin\/article\//g) || []).length === 50 &&
      bigList.text.includes('<option value="50" selected>'),
      "实际 " + (bigList.text.match(/class="title-link" href="\/admin\/article\//g) || []).length + " 行");

    check("列表提供批量操作条与可勾选行",
      listPage.text.includes('id="bulk-form"') && /name="ids\[\]"/.test(listPage.text) &&
      listPage.text.includes("data-bulk-action") && listPage.text.includes('form="bulk-form"'));
    check("批量动作里没有「移入回收站」（这项保留单篇确认）",
      !/<option value="delete"/.test(listPage.text));
    check("列表页引用了渐进增强脚本",
      listPage.text.includes("/assets/admin.js") && (await client.get("/assets/admin.js")).status === 200);

    // ---- UI 评审修复（2026-09-17）：移动端溢出、行内动作过载、批量口径、无障碍
    const adminJs = await client.get("/assets/admin.js");
    check("概览页「最近稿件」表套了横向滚动容器（窄屏不再撑破视口）",
      /<div class="table-scroll">\s*<table class="grid">/.test(dashboard.text),
      "窄屏 390 下整页原可横向拖动 127px");
    check("操作日志／角色／用户的表格同样套了横向滚动容器",
      /<div class="table-scroll">\s*<table class="grid">/.test((await client.get("/admin/logs")).text) &&
      /<div class="table-scroll">\s*<table class="grid">/.test((await client.get("/admin/roles")).text) &&
      /<div class="table-scroll">\s*<table class="grid">/.test((await client.get("/admin/users")).text));
    check("概览页待办优先：待审／退回单独一行、其余状态收进折叠加一行摘要",
      dashboard.text.includes('class="stat stat--todo') &&
      dashboard.text.includes('class="stats-more"') &&
      /等待处理|暂无待处理/.test(dashboard.text) &&
      dashboard.text.includes("按稿库看全部状态"));
    check("概览页「发布全站」的技术说明默认折叠",
      dashboard.text.includes("publish-advanced") &&
      dashboard.text.includes("平时发稿不用点这里") &&
      !/文件生成位置/.test(dashboard.text));
    check("列表行内动作收进「更多」，每行只留一个主动作",
      listPage.text.includes('class="row-more"') &&
      listPage.text.includes(">更多<") &&
      (listPage.text.match(/class="row-more"/g) || []).length > 1);
    check("没有对外页面的稿件用真 disabled 按钮，并把原因写给读屏",
      /<button type="button" class="btn btn-sm btn-ghost is-disabled" disabled title="[^"]*">\s*预览<span class="visually-hidden">/.test(archivedList.text),
      "归档稿行");
    check("批量条写的是真实上限（100 篇／跨页保留），并带站内提示容器",
      /单次最多处理 100 篇/.test(listPage.text) &&
      listPage.text.includes('class="js-only"') &&
      listPage.text.includes("data-bulk-error") &&
      listPage.text.includes("data-bulk-scope"));
    // 篮子键由服务端按归一化后的筛选算：翻页、URL 里参数的顺序与空值都不能改变它，
    // 否则「翻页不会丢掉已选」在常见的「点一次筛选再翻页」路径上就会静默失效。
    const bulkKey = (html) => (html.match(/data-bulk-key="([^"]*)"/) || [])[1] || "";
    const keyBase = bulkKey(listPage.text);
    const keyPage2 = bulkKey((await client.get("/admin/articles?page=2")).text);
    const keyChannel = bulkKey((await client.get("/admin/articles?channel=914")).text);
    const keyChannelPage2 = bulkKey((await client.get("/admin/articles?page=2&channel=914")).text);
    // 模拟「点一次筛选、字段一个不改」：地址栏里空值与默认值都写全了
    const keyFilledDefaults = bulkKey((await client.get(
      "/admin/articles?channel=&group=&status=&keyword=&sort=published_at%3Adesc&size=20"
    )).text);
    check("跨页篮子的键只跟筛选有关（翻页／参数顺序／空值都不影响）",
      keyBase !== "" && keyBase === keyPage2 && keyChannel === keyChannelPage2 &&
      keyBase !== keyChannel && keyBase === keyFilledDefaults,
      "base=" + keyBase + " p2=" + keyPage2 + " channel=" + keyChannel + " defaults=" + keyFilledDefaults);
    check("深色模式：主题引导脚本在样式表之前、顶栏带切换开关",
      dashboard.text.indexOf("/assets/theme.js") < dashboard.text.indexOf("/assets/admin.css") &&
      dashboard.text.includes("data-theme-toggle") &&
      (await client.get("/assets/theme.js")).status === 200 &&
      loginPage.text.includes("/assets/theme.js"));
    check("深色模式令牌与系统偏好两条路径都在样式表里",
      /prefers-color-scheme: dark/.test(cssText) &&
      /:root\[data-theme="dark"\]/.test(cssText) &&
      /--brand-ink/.test(cssText));
    // 规范第 5 节把这个列为坑位：两份深色令牌必须逐值一致，这里直接比对。
    const darkTokenMaps = (() => {
      const collect = (block) => {
        const map = {};
        (block.match(/--[a-z0-9-]+:\s*[^;]+;/g) || []).forEach((decl) => {
          const at = decl.indexOf(":");
          map[decl.slice(0, at).trim()] = decl.slice(at + 1).replace(/;.*$/, "").trim();
        });
        return map;
      };
      const media = /:root:not\(\[data-theme="light"\]\) \{([\s\S]*?)\n  \}/.exec(cssText);
      const explicit = /:root\[data-theme="dark"\] \{([\s\S]*?)\n\}/.exec(cssText);
      return { media: media ? collect(media[1]) : null, explicit: explicit ? collect(explicit[1]) : null };
    })();
    const sameTokens = darkTokenMaps.media && darkTokenMaps.explicit &&
      JSON.stringify(darkTokenMaps.media) === JSON.stringify(darkTokenMaps.explicit);
    check("深色令牌两份逐值一致（媒体查询那份 vs data-theme 那份）",
      sameTokens,
      sameTokens ? "" : "两份不一致，规范第5节要求同步");
    check("栏目管理的稿件数走右对齐数字列",
      (await client.get("/admin/channels")).text.includes('class="nowrap num"') &&
      /td\.num, th\.num \{ text-align: right/.test(cssText));
    check("批量自检不再用原生弹窗（改走页面内提示）",
      !/window\.alert\s*\(/.test(adminJs.text) && adminJs.text.includes("data-bulk-error"));
    check("主区可聚焦：跳转链接能把焦点带进内容区",
      dashboard.text.includes('id="main" tabindex="-1"'));
    check("未按栏目筛选时也解释「↑ ↓」什么时候可用",
      listPage.text.includes("调整该栏目内的顺序"));

    // 新建一篇草稿，用来验证「行内删除入口指向确认页」与批量流转
    const bulkFormPage = await client.get("/admin/article/new");
    const bulkCreated = await client.post("/admin/article/create", {
      _token: csrfToken(bulkFormPage.text),
      channel_type: "904",
      title: "批量操作检查稿",
      subtitle: "",
      source: "检查脚本",
      author: "",
      editor: "",
      published_date: "2026-09-11",
      published_time: "11:00",
      status: "draft",
      summary: "",
      content_html: "<p>批量操作检查正文。</p>"
    });
    const bulkId = (/(\/admin\/article\/(\d+))$/.exec(bulkCreated.headers.get("location") || "") || [])[2] || "";
    check("批量检查用草稿创建成功", bulkCreated.status === 302 && bulkId !== "", "id=" + bulkId);

    const draftList = await client.get("/admin/articles?status=draft&keyword=" + encodeURIComponent("批量操作检查稿"));
    check("列表行内「移入回收站」指向确认页，不再直接提交表单",
      draftList.text.includes('href="/admin/article/' + bulkId + '/delete"'));

    const bulkSubmitted = await client.post("/admin/articles/bulk", {
      _token: csrfToken(draftList.text),
      back: "/admin/articles?status=draft",
      action: "submit",
      "ids[]": bulkId
    });
    check("批量提交审核后回到原筛选列表",
      bulkSubmitted.status === 302 && (bulkSubmitted.headers.get("location") || "") === "/admin/articles?status=draft");
    const afterBulkSubmit = await client.get("/admin/article/" + bulkId);
    check("批量提交后稿件进入待审",
      afterBulkSubmit.text.includes('value="approve"') && afterBulkSubmit.text.includes("待审"));

    const bulkApproved = await client.post("/admin/articles/bulk", {
      _token: csrfToken(afterBulkSubmit.text),
      back: "/admin/articles",
      action: "approve",
      "ids[]": bulkId
    });
    check("批量审核通过成功", bulkApproved.status === 302);
    check("批量审核通过后前台可见",
      (await client.get("/api/v1/article/" + bulkId, { json: true })).status === 200);

    const bulkEmpty = await client.post("/admin/articles/bulk", {
      _token: csrfToken((await client.get("/admin/articles")).text),
      back: "/admin/articles",
      action: "submit"
    });
    check("批量操作不勾选稿件会被挡下并提示",
      bulkEmpty.status === 302 &&
      (await client.get("/admin/articles")).text.includes("没有选中任何稿件"));

    const bulkBadAction = await client.post("/admin/articles/bulk", {
      _token: csrfToken((await client.get("/admin/articles")).text),
      back: "/admin/articles",
      action: "purge",
      "ids[]": bulkId
    });
    check("批量接口只认白名单里的动作",
      bulkBadAction.status === 302 &&
      (await client.get("/admin/articles")).text.includes("请先选择要执行的批量操作"));

    // ---- 编辑页结构：分区卡片 + 右侧栏 + 正文预览
    const editPage = await client.get("/admin/article/62246");
    check("编辑页只剩写作窗一张卡（基本信息、发布设置已并入／删除）",
      editPage.text.includes("摘要与正文") && editPage.text.includes("writing-paper")
        && !editPage.text.includes("基本信息") && !editPage.text.includes("发布设置"));
    check("编辑页右侧栏放稿库流转、稿件信息与附件",
      editPage.text.includes('class="edit-side"') && editPage.text.includes("稿库流转") &&
      editPage.text.includes("稿件信息") && editPage.text.includes("正文插图"));
    check("编辑页有正文预览与字数统计钩子",
      editPage.text.includes("data-preview-toggle") && editPage.text.includes("data-content-count") &&
      editPage.text.includes("data-preview-frame"));

    // ---- 栏目管理：分组、检索、上移下移
    const channelsGrouped = await client.get("/admin/channels");
    check("栏目列表按一级栏目分组展示",
      channelsGrouped.text.includes('class="channel-block"') &&
      channelsGrouped.text.includes("group-head") &&
      channelsGrouped.text.includes("组内第 1 个"));
    check("栏目列表给出总数与上下线统计",
      channelsGrouped.text.includes("栏目总数") && channelsGrouped.text.includes("已下线") &&
      channelsGrouped.text.includes("栏目稿件数合计"));

    const channelOrder = (html) =>
      [...html.matchAll(/class="title-link" href="\/admin\/channel\/(\d+)"/g)].map((m) => m[1]);
    const orderBefore = channelOrder(channelsGrouped.text);
    const moveIndex = orderBefore.indexOf("905");
    check("栏目列表能定位到要移动的栏目（905 在中间）",
      moveIndex > 0 && moveIndex < orderBefore.length - 1, "顺序 " + orderBefore.slice(0, 8).join(","));
    if (moveIndex > 0 && moveIndex < orderBefore.length - 1) {
      const moved = await client.post("/admin/channel/905/move", {
        _token: csrfToken(channelsGrouped.text),
        dir: "up"
      });
      check("栏目上移接口执行后回到列表", moved.status === 302);
      const orderAfterMove = channelOrder((await client.get("/admin/channels")).text);
      check("上移后与相邻栏目交换了位置",
        orderAfterMove[moveIndex - 1] === "905" && orderAfterMove[moveIndex] === orderBefore[moveIndex - 1],
        "移动前 " + orderBefore.slice(0, 6).join(",") + " → 移动后 " + orderAfterMove.slice(0, 6).join(","));
      const restored = await client.post("/admin/channel/905/move", {
        _token: csrfToken((await client.get("/admin/channels")).text),
        dir: "down"
      });
      check("再下移一位可还原顺序",
        restored.status === 302 &&
        channelOrder((await client.get("/admin/channels")).text).join(",") === orderBefore.join(","));
    }

    const searched = await client.get("/admin/channels?q=" + encodeURIComponent("市政协动态"));
    check("栏目管理支持按名称检索",
      searched.text.includes("市政协动态") && !searched.text.includes("县区政协工作动态") &&
      searched.text.includes("匹配到 1 个栏目"),
      "命中 " + (searched.text.match(/class="title-link" href="\/admin\/channel\//g) || []).length + " 个栏目");

    // ---- 栏目新建与删除（2026-09-12）
    const newChannelPage = await client.get("/admin/channel/new");
    check("栏目列表与新建页都有入口",
      channels.text.includes('href="/admin/channel/new"') && newChannelPage.status === 200 &&
      newChannelPage.text.includes("新建栏目") && newChannelPage.text.includes('name="parent_type"'));
    check("新建页给出一级栏目归属下拉与版式选项",
      (newChannelPage.text.match(/<option value="\d+"[^>]*>[\s\S]*?（栏目号 \d+/g) || []).length >= 15 &&
      newChannelPage.text.includes('value="county"'),
      "归属选项 " + (newChannelPage.text.match(/<option value="\d+"[^>]*>[\s\S]*?（栏目号 \d+/g) || []).length + " 个");
    check("栏目编辑（无 channel.manage）访问新建页被判 403",
      (await editorClient.get("/admin/channel/new")).status === 403);

    const newType = "9901";
    const createdChannel = await client.post("/admin/channel/create", {
      _token: csrfToken(newChannelPage.text),
      parent_type: "904",
      type_code: newType,
      inner_name: "临时检查栏目",
      slug: "lin-shi-jian-cha",
      layout: "list",
      status: "published",
      intro: "检查用栏目，跑完就删。"
    });
    check("新建栏目成功后跳到该栏目的编辑页", createdChannel.status === 302 &&
      (createdChannel.headers.get("location") || "").endsWith("/admin/channel/" + newType),
      createdChannel.status + " → " + createdChannel.headers.get("location"));
    const afterCreate = await client.get("/admin/channels");
    check("新建的栏目出现在列表里，且沿用父栏目的一级名",
      afterCreate.text.includes("临时检查栏目") &&
      /href="\/admin\/channel\/9901"/.test(afterCreate.text) &&
      afterCreate.text.includes("栏目总数") &&
      afterCreate.text.includes(">44<"),
      "列表里栏目数统计 " + ((afterCreate.text.match(/<span class="stat-num">(\d+)<\/span><span class="stat-label">栏目总数/) || [])[1] || "?"));
    const afterCreateApi = await client.get("/api/v1/channels?listSize=1");
    check("新建的已上线栏目立刻出现在内容接口里",
      afterCreateApi.text.includes('"type":"' + newType + '"') || afterCreateApi.text.includes('"type": "' + newType + '"'));

    const duplicate = await client.post("/admin/channel/create", {
      _token: csrfToken(newChannelPage.text),
      parent_type: "",
      type_code: newType,
      name: "重复栏目号",
      inner_name: "重复栏目号",
      layout: "list",
      status: "published",
      intro: ""
    });
    check("栏目号重复被挡下并给出提示",
      duplicate.status === 200 && duplicate.text.includes("已经被占用") &&
      ((await client.get("/admin/channels")).text.match(/<span class="stat-num">(\d+)<\/span><span class="stat-label">栏目总数/) || [])[1] === "44",
      "栏目总数 " + (((await client.get("/admin/channels")).text.match(/<span class="stat-num">(\d+)<\/span><span class="stat-label">栏目总数/) || [])[1] || "?"));
    const badType = await client.post("/admin/channel/create", {
      _token: csrfToken(newChannelPage.text),
      parent_type: "",
      type_code: "非法 号",
      name: "非法栏目号",
      inner_name: "非法栏目号",
      layout: "list",
      status: "published",
      intro: ""
    });
    check("栏目号格式不合法被挡下", badType.status === 200 && badType.text.includes("只能用"));
    const underChild = await client.post("/admin/channel/create", {
      _token: csrfToken(newChannelPage.text),
      parent_type: "902",
      type_code: "9902",
      inner_name: "三级栏目",
      layout: "list",
      status: "published",
      intro: ""
    });
    check("挂在子栏目下被挡下（只做两级）",
      underChild.status === 200 && underChild.text.includes("只能挂在一级栏目下"));

    const blockedDelete = await client.get("/admin/channel/904/delete");
    check("删除有稿件的栏目会列出阻止理由",
      blockedDelete.status === 200 && blockedDelete.text.includes("暂不能删除") &&
      blockedDelete.text.includes("篇稿件挂在这个栏目"));
    const blockedDeletePost = await client.post("/admin/channel/904/delete", {
      _token: csrfToken(blockedDelete.text),
      confirm: "delete"
    });
    check("即使勾选确认，有稿件的栏目仍删不掉",
      blockedDeletePost.status === 302 &&
      (await client.get("/admin/channel/904/delete")).text.includes("暂不能删除"));

    // 先把新栏目写进 301 映射，验证删除时会一并清理
    runPhp(php, "backend/bin/redirects.php", env);
    const mappingBefore = runPhp(php, "-r", env, [
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " echo (int) $db->scalar(\"SELECT COUNT(*) FROM sys_url_redirect WHERE old_path = '/news_list.php?id=" + newType + "'\");"
    ]);
    check("新建栏目会进入旧地址 301 映射（该栏目号曾用过才需要，这里验证清理逻辑）",
      (mappingBefore.stdout || "").trim() === "1", (mappingBefore.stdout || "").trim());

    const noConfirm = await client.post("/admin/channel/" + newType + "/delete", {
      _token: csrfToken(blockedDelete.text)
    });
    check("删除栏目必须先勾选确认",
      noConfirm.status === 302 &&
      (await client.get("/admin/channels")).text.includes("请先勾选确认"));
    const deletedChannel = await client.post("/admin/channel/" + newType + "/delete", {
      _token: csrfToken((await client.get("/admin/channel/" + newType + "/delete")).text),
      confirm: "delete"
    });
    check("确认后栏目被删除并回到列表", deletedChannel.status === 302 &&
      (deletedChannel.headers.get("location") || "").endsWith("/admin/channels"));
    const afterDelete = await client.get("/admin/channels");
    check("删除后列表里不再有该栏目",
      !/href="\/admin\/channel\/9901"/.test(afterDelete.text) &&
      ((afterDelete.text.match(/<span class="stat-num">(\d+)<\/span><span class="stat-label">栏目总数/) || [])[1] === "43"),
      "栏目总数 " + ((afterDelete.text.match(/<span class="stat-num">(\d+)<\/span><span class="stat-label">栏目总数/) || [])[1] || "?"));
    const mappingAfter = runPhp(php, "-r", env, [
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " echo (int) $db->scalar(\"SELECT COUNT(*) FROM sys_url_redirect WHERE old_path LIKE '%id=" + newType + "'\");"
    ]);
    check("删除栏目时清掉它的 301 映射记录", (mappingAfter.stdout || "").trim() === "0", (mappingAfter.stdout || "").trim());
    const afterDeleteApi = await client.get("/api/v1/channels?listSize=1");
    check("删除后内容接口里也不再有该栏目", !afterDeleteApi.text.includes(newType));

    // ---- 批量操作也要过权限位：栏目编辑只该看到「提交审核」
    const editorListPage = await editorClient.get("/admin/articles");
    check("栏目编辑的批量动作只列出他有权限的那几个",
      /<option value="submit"/.test(editorListPage.text) &&
      !/<option value="approve"/.test(editorListPage.text) &&
      !/<option value="withdraw"/.test(editorListPage.text) &&
      !/<option value="republish"/.test(editorListPage.text) &&
      !/<option value="restore"/.test(editorListPage.text));
    const editorBulkDenied = await editorClient.post("/admin/articles/bulk", {
      _token: csrfToken(editorListPage.text),
      back: "/admin/articles",
      action: "withdraw",
      note: "越权检查",
      "ids[]": bulkId
    });
    check("越权批量撤回被判无权限",
      editorBulkDenied.status === 302 &&
      (await editorClient.get("/admin/articles")).text.includes("没有「撤回」权限"));

    // ---- 首页四大类：导航栏目 / 头条轮换 / 其他栏目 / 站内横幅
    const navRows = (html) => {
      const rows = [];
      const re = /name="title" value="([^"]*)"[\s\S]*?name="url" value="([^"]*)"/g;
      let hit;
      while ((hit = re.exec(html)) !== null) rows.push({ title: hit[1], url: hit[2] });
      return rows;
    };

    const navPage = await client.get("/admin/nav");
    const navBefore = navRows(navPage.text);
    check("侧栏一级项是「概览／首页管理／稿件管理／用户管理／操作日志」",
      // 菜单项现在带内联图标，summary 里图标在前、名字在 span 里
      /<summary>[\s\S]{0,600}?首页管理<\/span><\/summary>/.test(navPage.text) &&
      navPage.text.includes(">稿件管理<") &&
      navPage.text.includes(">用户管理<") &&
      navPage.text.includes(">操作日志<") &&
      !navPage.text.includes(">用户与角色<"));
    // 长页面回到顶部：外壳里有入口（默认 hidden，脚本滚过一屏才让它出现）
    check("后台外壳带「回到顶部」入口且默认隐藏",
      /<button[^>]*class="to-top"[^>]*data-to-top[^>]*hidden>/.test(navPage.text) &&
      navPage.text.includes("回到顶部"),
      (navPage.text.match(/<button[^>]*data-to-top[^>]*>/) || [""])[0]);
    check("「首页管理」是默认展开的树形分组，子项都在里面",
      /<details class="sidenav-group[^"]*" open>/.test(navPage.text) &&
      navPage.text.includes('href="/admin/nav"') &&
      navPage.text.includes('href="/admin/notice"') &&
      navPage.text.includes('href="/admin/slides"') &&
      navPage.text.includes('href="/admin/sections"') &&
      navPage.text.includes('href="/admin/banners"'));
    check("当前页在树里高亮，面包屑带上「首页管理」这一层",
      /href="\/admin\/nav" class="active" aria-current="page"/.test(navPage.text) &&
      /<nav class="breadcrumb"[\s\S]*?<a href="\/admin\/nav">首页管理<\/a>[\s\S]*?<span class="crumb-current">导航栏目<\/span>[\s\S]*?<\/nav>/
        .test(navPage.text));
    const listCrumb = ((await client.get("/admin/articles")).text.match(/<nav class="breadcrumb"[\s\S]*?<\/nav>/) || [""])[0];
    check("首页管理以外的页面，面包屑仍从「概览」起",
      /<a href="\/admin">概览<\/a>/.test(listCrumb) && listCrumb.includes("稿件管理"),
      listCrumb.replace(/\s+/g, " ").slice(0, 120));
    const logsActive = (await client.get("/admin/logs")).text;
    check("操作日志页在侧栏高亮自己",
      /href="\/admin\/logs" class="active" aria-current="page"/.test(logsActive));
    check("导航栏目页列出 18 个首页导航项", navPage.status === 200 && navBefore.length === 18,
      "实际 " + navBefore.length + " 项");
    check("导航栏目页给出每个入口指向的栏目与稿件数",
      navPage.text.includes("指向栏目") && navPage.text.includes("篇）"));

    const navMoved = await client.post("/admin/nav/1/move", { _token: csrfToken(navPage.text), dir: "up" });
    const navAfterMove = await client.get("/admin/nav");
    const navMovedRows = navRows(navAfterMove.text);
    check("导航项可以上移（前两项互换）",
      navMoved.status === 302 && navMovedRows[0]?.title === navBefore[1]?.title && navMovedRows[1]?.title === navBefore[0]?.title,
      navBefore.slice(0, 2).map((r) => r.title).join("/") + " → " + navMovedRows.slice(0, 2).map((r) => r.title).join("/"));
    await client.post("/admin/nav/0/move", { _token: csrfToken(navAfterMove.text), dir: "down" });
    check("导航顺序可以还原",
      navRows((await client.get("/admin/nav")).text)[0]?.title === navBefore[0]?.title);

    const hideRow = navBefore[1];
    const hidden = await client.post("/admin/nav/1", {
      _token: csrfToken((await client.get("/admin/nav")).text),
      title: hideRow.title,
      url: hideRow.url,
      hidden: "1"
    });
    const hiddenPage = await client.get("/admin/nav");
    check("导航项可以隐藏（条目保留、列表里标出）",
      hidden.status === 302 && /name="hidden" value="1" checked/.test(hiddenPage.text) &&
      navRows(hiddenPage.text).length === 18);
    await client.post("/admin/nav/1", {
      _token: csrfToken(hiddenPage.text),
      title: hideRow.title,
      url: hideRow.url
    });
    check("取消隐藏后恢复显示",
      !/name="hidden" value="1" checked/.test((await client.get("/admin/nav")).text));

    // ---- 滚动公告：首页搜索框左侧那条滚动要闻（2026-09-14 补的维护入口）
    const marqueeOf = async () => (await client.get("/api/v1/home", { json: true })).body?.home?.meta?.marquee || "";
    const marqueeField = (html) => ((html.match(/name="marquee"[^>]*>([\s\S]*?)<\/textarea>/) || [])[1] || "").trim();
    const noticePage = await client.get("/admin/notice");
    const marqueeBefore = marqueeField(noticePage.text);
    check("滚动公告页可访问并带出当前公告",
      noticePage.status === 200 && marqueeBefore !== "" && marqueeBefore === (await marqueeOf()),
      "当前 " + marqueeBefore.length + " 字");
    check("滚动公告挂在「首页管理」树里，面包屑也带上这一层",
      /href="\/admin\/notice" class="active" aria-current="page"/.test(noticePage.text) &&
      /<nav class="breadcrumb"[\s\S]*?<a href="\/admin\/nav">首页管理<\/a>[\s\S]*?<span class="crumb-current">滚动公告<\/span>[\s\S]*?<\/nav>/
        .test(noticePage.text));
    check("滚动公告页给出前台效果预览与字数",
      noticePage.text.includes("前台效果") && noticePage.text.includes("notice-preview") &&
      noticePage.text.includes("最多 500 字"));

    const noticeSaved = await client.post("/admin/notice", {
      _token: csrfToken(noticePage.text),
      marquee: "检查脚本写入的滚动公告"
    });
    check("保存公告后前台接口立刻更新",
      noticeSaved.status === 302 && (await marqueeOf()) === "检查脚本写入的滚动公告",
      "接口读到：" + (await marqueeOf()));
    check("保存后回到公告页能看到新文字",
      marqueeField((await client.get("/admin/notice")).text) === "检查脚本写入的滚动公告");

    const noticeSpaced = await client.post("/admin/notice", {
      _token: csrfToken((await client.get("/admin/notice")).text),
      marquee: "第一行\n\n   第二行"
    });
    check("公告里的换行与连续空格被收成单个空格",
      noticeSpaced.status === 302 && (await marqueeOf()) === "第一行 第二行",
      "接口读到：" + (await marqueeOf()));

    const noticeTooLong = await client.post("/admin/notice", {
      _token: csrfToken((await client.get("/admin/notice")).text),
      marquee: "长".repeat(501)
    });
    check("超过 500 字的公告被挡下并提示，原值不变",
      noticeTooLong.status === 302 &&
      (await client.get("/admin/notice")).text.includes("公告最多 500 个字") &&
      (await marqueeOf()) === "第一行 第二行",
      "接口读到长度 " + (await marqueeOf()).length);
    check("公告保存缺 CSRF 令牌被拒绝",
      (await client.post("/admin/notice", { marquee: "无令牌写入" })).status === 400 &&
      (await marqueeOf()) === "第一行 第二行");

    // 停用：前台整条滚条（含图标）不显示，搜索框保留并居中（前台按 meta.marqueeHidden 判断）
    const marqueeOff = await client.post("/admin/notice", {
      _token: csrfToken((await client.get("/admin/notice")).text),
      marquee: "第一行 第二行",
      hidden: "1"
    });
    const marqueeMetaOff = (await client.get("/api/v1/home", { json: true })).body?.home?.meta || {};
    check("勾选停用后接口带上 marqueeHidden，公告文字仍保留",
      marqueeOff.status === 302 && marqueeMetaOff.marqueeHidden === true && marqueeMetaOff.marquee === "第一行 第二行",
      JSON.stringify({ hidden: marqueeMetaOff.marqueeHidden, marquee: marqueeMetaOff.marquee }));
    const marqueePageOff = await client.get("/admin/notice");
    check("停用状态在公告页回显（勾选框 + 已停用标签 + 预览说明）",
      /name="hidden" value="1" checked/.test(marqueePageOff.text) &&
      marqueePageOff.text.includes("已停用") &&
      marqueePageOff.text.includes("只剩搜索框"));

    const noticeRestored = await client.post("/admin/notice", {
      _token: csrfToken((await client.get("/admin/notice")).text),
      marquee: marqueeBefore
    });
    check("取消停用并还原成检查前的文字（marqueeHidden 字段去掉）",
      noticeRestored.status === 302 && (await marqueeOf()) === marqueeBefore &&
      !("marqueeHidden" in ((await client.get("/api/v1/home", { json: true })).body?.home?.meta || {})) &&
      !/name="hidden" value="1" checked/.test((await client.get("/admin/notice")).text));

    const slidesPage = await client.get("/admin/slides");
    const slideTitles = (html) => [...html.matchAll(/name="title" value="([^"]*)"/g)].map((m) => m[1]);
    check("头条轮换页可访问并列出初始 6 条", slidesPage.status === 200 && slidesPage.text.includes("共 6 条"));
    check("头条轮换页同时提供「从稿件里选」与「手工新增」两个入口",
      slidesPage.text.includes("从已发布稿件里选") && slidesPage.text.includes("手工新增外链条目"));

    const pickPage = await client.get("/admin/slides?q=" + encodeURIComponent("政协"));
    check("能检索已发布稿件并加入轮播", pickPage.text.includes("加入轮播"));
    check("备选稿件的标题带前台预览链接",
      /<a class="pick-title" href="\/detail\.html\?id=\d+" target="_blank" rel="noopener"/.test(pickPage.text),
      (pickPage.text.match(/class="pick-title"[^>]*/) || [""])[0]);
    const addedFromArticle = await client.post("/admin/slides/create", {
      _token: csrfToken(pickPage.text),
      article_id: "62246"
    });
    check("从稿件加入轮播成功",
      addedFromArticle.status === 302 && (await client.get("/admin/slides")).text.includes("共 7 条"));

    const afterAddSlide = await client.get("/admin/slides");
    const addedManual = await client.post("/admin/slides/create", {
      _token: csrfToken(afterAddSlide.text),
      title: "检查用外链条目",
      link_url: "https://example.com/check",
      summary: "检查脚本新增"
    });
    check("手工新增外链条目成功",
      addedManual.status === 302 && slidesPage.text !== "" &&
      (await client.get("/admin/slides")).text.includes("检查用外链条目"));

    const slideList = await client.get("/admin/slides");
    const slideTitlesBefore = slideTitles(slideList.text);
    const secondSlideId = ([...slideList.text.matchAll(/\/admin\/slides\/(\d+)\/status/g)].map((m) => m[1]))[1];
    const slideMoved = await client.post("/admin/slides/" + secondSlideId + "/move", {
      _token: csrfToken(slideList.text),
      dir: "up"
    });
    const slideTitlesAfter = slideTitles((await client.get("/admin/slides")).text);
    check("轮播条目可以上移（前两条互换）",
      slideMoved.status === 302 && slideTitlesAfter[0] === slideTitlesBefore[1] && slideTitlesAfter[1] === slideTitlesBefore[0],
      slideTitlesBefore.slice(0, 2).join("/") + " → " + slideTitlesAfter.slice(0, 2).join("/"));

    const firstSlideId = ([...slideList.text.matchAll(/\/admin\/slides\/(\d+)\/status/g)].map((m) => m[1]))[0];
    const slideOff = await client.post("/admin/slides/" + firstSlideId + "/status", {
      _token: csrfToken((await client.get("/admin/slides")).text)
    });
    check("轮播条目可以下线",
      slideOff.status === 302 && /tag-offline">已下线/.test((await client.get("/admin/slides")).text));
    // 「序」只数已上线的条目（即前台轮播位次），已下线的显示「—」不占号
    const slideSeqRows = (html) => [...html.matchAll(
      /<tr(?: class="[^"]*")?>\s*<td class="nowrap muted">([\s\S]*?)<\/td>[\s\S]*?<span class="tag tag-(?:published|offline)">(已上线|已下线)<\/span>/g
    )].map((m) => ({ seq: m[1].replace(/<[^>]+>/g, "").trim(), status: m[2] }));
    const seqRows = slideSeqRows((await client.get("/admin/slides")).text);
    const seqOnline = seqRows.filter((r) => r.status === "已上线").map((r) => r.seq);
    const seqOffline = seqRows.filter((r) => r.status === "已下线").map((r) => r.seq);
    check("已下线的条目不占「序」，编号只数已上线",
      seqOffline.length > 0 && seqOffline.every((s) => s === "—") &&
      seqOnline.join(",") === seqOnline.map((_, i) => i + 1).join(","),
      "上线：" + seqOnline.join("/") + "；下线：" + seqOffline.join("/"));
    const slideOn = await client.post("/admin/slides/" + firstSlideId + "/status", {
      _token: csrfToken((await client.get("/admin/slides")).text)
    });
    check("轮播条目可以重新上线",
      slideOn.status === 302 && !/tag-offline">已下线/.test((await client.get("/admin/slides")).text));

    const manualSlidePage = await client.get("/admin/slides");
    const manualId = ([...manualSlidePage.text.matchAll(/\/admin\/slides\/(\d+)\/status/g)].map((m) => m[1])).pop();
    const slideDeleted = await client.post("/admin/slides/" + manualId + "/delete", {
      _token: csrfToken(manualSlidePage.text)
    });
    // 删除后总数回到 7（初始 6 + 从稿件加入的 1）；被删标题仍会出现在本次的提示条里，所以比总数
    check("轮播条目可以删除",
      slideDeleted.status === 302 && (await client.get("/admin/slides")).text.includes("共 7 条"));

    // 首屏预览：拖动排序。页面带着当前顺序，脚本拖完把整串交给 /admin/slides/order
    const stripPage = await client.get("/admin/slides");
    const stripIds = [...stripPage.text.matchAll(/data-slide-id="(\d+)"/g)].map((m) => m[1]);
    check("首屏预览的每条都带排序、预览与删除用的数据钩子",
      stripIds.length > 0 && stripPage.text.includes("data-slide-strip") &&
      stripPage.text.includes('action="/admin/slides/order"') &&
      stripPage.text.includes('class="slide-chip-media"') &&
      (stripPage.text.match(/data-confirm="确认从首屏轮播里删除/g) || []).length === stripIds.length,
      "预览 " + stripIds.length + " 条");

    const flippedIds = stripIds.slice().reverse();
    const reordered = await client.post("/admin/slides/order", {
      _token: csrfToken(stripPage.text),
      order: flippedIds.join(",")
    });
    const afterOrderPage = await client.get("/admin/slides");
    const afterIds = [...afterOrderPage.text.matchAll(/data-slide-id="(\d+)"/g)].map((m) => m[1]);
    check("拖动排序按新顺序保存",
      reordered.status === 302 && afterIds.join(",") === flippedIds.join(","),
      stripIds.join(",") + " → " + afterIds.join(","));
    check("拖动排序后回到页面并给出成功提示",
      afterOrderPage.text.includes("已按拖动后的顺序保存"));
    check("顺序与当前上线条目对不上时整单拒绝",
      (await client.post("/admin/slides/order", {
        _token: csrfToken(afterOrderPage.text),
        order: "999999"
      })).status === 302 &&
      [...(await client.get("/admin/slides")).text.matchAll(/data-slide-id="(\d+)"/g)].map((m) => m[1]).join(",") ===
        flippedIds.join(","));

    const sectionsPage = await client.get("/admin/sections");
    check("其他栏目页列出 13 个首页模块",
      sectionsPage.status === 200 && (sectionsPage.text.match(/admin\/section\//g) || []).length === 13,
      "实际 " + (sectionsPage.text.match(/admin\/section\//g) || []).length + " 个");
    check("其他栏目页给出绑定栏目与首页当前显示的稿件",
      sectionsPage.text.includes("绑定：") && sectionsPage.text.includes("首页当前显示"));
    // 稿件表格的循环变量曾把模块配置覆盖掉，表单里三个框会渲染成空值（保存即丢配置）
    check("其他栏目页的模块表单带出当前配置，不被稿件表格覆盖",
      sectionsPage.text.includes('name="label" value="公告通知"') &&
      /name="page_size" min="1" max="30" value="4"/.test(sectionsPage.text) &&
      sectionsPage.text.includes('name="more_url" value="https://www.gxhczx.gov.cn/news_list.php?id=302"'),
      "标题/条数/更多链接三个框的值");
    check("其他栏目页列出未进首页导航的栏目",
      sectionsPage.text.includes("未进首页导航的栏目") && sectionsPage.text.includes("/admin/articles?channel="));
    // 顶部栏目索引：按一级栏目分组，模块绑定的跳到卡片，没进导航的跳到页面下方清单；
    // 页内锚点必须都落在本页元素上
    const indexChipTags = [...sectionsPage.text.matchAll(
      /<a class="channel-chip[^"]*" href="([^"]+)" title="([^"]*)">([\s\S]*?)<\/a>/g
    )];
    const indexChips = indexChipTags.map((m) => m[1]);
    const indexAnchors = indexChips.filter((h) => h.startsWith("#"));
    const pageIds = new Set([...sectionsPage.text.matchAll(/ id="([^"]+)"/g)].map((m) => m[1]));
    const dangling = indexAnchors.filter((h) => !pageIds.has(h.slice(1)));
    check("其他栏目页顶部列出全部 43 个栏目，页内锚点都能跳到对应位置",
      indexChips.length === 43 && indexAnchors.length >= 30 && dangling.length === 0,
      "索引 " + indexChips.length + " 个（页内 " + indexAnchors.length + "，落空 " + dangling.length + "）");

    // 分组口径：有子栏目的 5 个一级栏目各一个组块，其余 20 个独立栏目合成一行；
    // 被首页模块使用的栏目带红点标记，悬停提示写明是哪个模块
    const indexBlocks = [...sectionsPage.text.matchAll(/<div class="index-group-title"[^>]*>([^<]*)</g)]
      .map((m) => m[1].trim());
    const markedChips = indexChipTags.filter((m) => m[0].includes("channel-chip--module"));
    check("栏目索引按一级栏目分组，并标出被首页模块使用的栏目",
      indexBlocks.length === 5 &&
      ["政协动态", "政协会议", "政协提案", "党派团体", "政协艺苑"].every((t) => indexBlocks.includes(t)) &&
      sectionsPage.text.includes("独立栏目") &&
      markedChips.length === 24 &&
      markedChips.every((m) => m[2].includes("喂给")),
      "组块 " + indexBlocks.join("／") + "；标记 " + markedChips.length + " 个");
    check("每个模块卡片都能跳回顶部索引",
      (sectionsPage.text.match(/href="#channel-index"/g) || []).length === 13,
      "实际 " + (sectionsPage.text.match(/href="#channel-index"/g) || []).length + " 处");
    // 栏目号不再印在页面上：chip、一级栏目组头、页底清单都收进悬停提示
    check("栏目编号不直接显示，改成悬停提示",
      indexChipTags.length === 43 &&
      indexChipTags.every((m) => /^栏目 [a-z0-9]+/i.test(m[2]) && !/<span/.test(m[3])) &&
      (sectionsPage.text.match(/<div class="index-group-title" title="一级栏目 [a-z0-9]+">/gi) || []).length === 5 &&
      !/<span class="muted">\d+ · \d+ 篇<\/span>/.test(sectionsPage.text),
      "例：" + indexChipTags.slice(0, 2).map((m) => m[2]).join(" ｜ "));

    const resized = await client.post("/admin/section/notice", {
      _token: csrfToken(sectionsPage.text),
      label: "公告通知",
      kind: "channels",
      scope: "302",
      page_size: "2",
      status: "published",
      more_url: ""
    });
    check("首页模块可以改绑定与显示条数",
      resized.status === 302 && /公告通知[\s\S]{0,800}?首页显示 2 条/.test((await client.get("/admin/sections")).text));

    const badScope = await client.post("/admin/section/notice", {
      _token: csrfToken((await client.get("/admin/sections")).text),
      label: "公告通知",
      kind: "channels",
      scope: "999999",
      page_size: "4",
      status: "published"
    });
    check("绑定不存在的栏目会被挡下",
      badScope.status === 302 && (await client.get("/admin/sections")).text.includes("这些栏目号不存在"));
    await client.post("/admin/section/notice", {
      _token: csrfToken((await client.get("/admin/sections")).text),
      label: "公告通知",
      kind: "channels",
      scope: "302",
      page_size: "4",
      status: "published",
      more_url: "https://www.gxhczx.gov.cn/news_list.php?id=302"
    });
    // 模块下线：前台接口里这一键要消失（此前会被快照样例兜底填回来，等于下线不生效）
    const noticeOff = await client.post("/admin/section/notice", {
      _token: csrfToken((await client.get("/admin/sections")).text),
      label: "公告通知",
      kind: "channels",
      scope: "302",
      page_size: "4",
      status: "offline",
      more_url: "https://www.gxhczx.gov.cn/news_list.php?id=302"
    });
    const homeWhileOff = (await client.get("/api/v1/home", { json: true })).body?.home || {};
    check("模块下线后首页接口不再返回该模块（不回退快照）",
      noticeOff.status === 302 && !("notice" in homeWhileOff) && "sxNews" in homeWhileOff,
      "notice 在：" + ("notice" in homeWhileOff));
    await client.post("/admin/section/notice", {
      _token: csrfToken((await client.get("/admin/sections")).text),
      label: "公告通知",
      kind: "channels",
      scope: "302",
      page_size: "4",
      status: "published",
      more_url: "https://www.gxhczx.gov.cn/news_list.php?id=302"
    });
    check("模块重新上线后接口里又有该模块",
      "notice" in ((await client.get("/api/v1/home", { json: true })).body?.home || {}));

    // 头条轮换全部下线：接口给空数组（前台据此收起轮播），不回退快照里的 6 条样例
    const slideRowsBefore = await client.get("/admin/slides");
    const slideItems = [...slideRowsBefore.text.matchAll(/\/admin\/slides\/(\d+)\/status/g)].map((m) => m[1]);
    for (const sid of slideItems) {
      await client.post("/admin/slides/" + sid + "/status", {
        _token: csrfToken((await client.get("/admin/slides")).text),
        status: "offline"
      });
    }
    const slidesOff = (await client.get("/api/v1/home", { json: true })).body?.home?.slides;
    check("轮播全部下线时接口返回空数组，不退快照",
      Array.isArray(slidesOff) && slidesOff.length === 0,
      "slides=" + JSON.stringify(slidesOff));
    for (const sid of slideItems) {
      await client.post("/admin/slides/" + sid + "/status", {
        _token: csrfToken((await client.get("/admin/slides")).text),
        status: "published"
      });
    }
    check("轮播重新上线后接口里又有条目",
      (((await client.get("/api/v1/home", { json: true })).body?.home?.slides) || []).length === slideItems.length);

    check("首页模块改动可以还原",
      /公告通知[\s\S]{0,800}?首页显示 4 条/.test((await client.get("/admin/sections")).text));

    // ---- 其他栏目页把稿件按头条轮换那样的表格列出来，并可直接排序／置顶
    const sectionsWithRows = await client.get("/admin/sections");
    check("其他栏目页把模块稿件列成表格（与头条轮换同样的形式）",
      /class="[^"]*section-table[^"]*"/.test(sectionsWithRows.text) &&
      /\/admin\/article\/\d+\/order/.test(sectionsWithRows.text) &&
      /\/admin\/article\/\d+\/top/.test(sectionsWithRows.text) &&
      sectionsWithRows.text.includes("已发布"));
    check("模块表格标出置顶状态与未入库的快照条目",
      sectionsWithRows.text.includes("来自改版前的快照") || sectionsWithRows.text.includes("已置顶"));

    const noticeIds = (await client.get("/api/v1/channels/302?listSize=4", { json: true })).body?.channel?.list
      ?.map((i) => String(i.id)) || [];
    check("取到公告通知模块的前几条稿件", noticeIds.length >= 3, noticeIds.join(","));
    if (noticeIds.length >= 3) {
      const movedInModule = await client.post("/admin/article/" + noticeIds[1] + "/order", {
        _token: csrfToken(sectionsWithRows.text),
        channel: "302",
        back: "/admin/sections",
        dir: "up"
      });
      const noticeAfter = (await client.get("/api/v1/channels/302?listSize=4", { json: true })).body?.channel?.list
        ?.map((i) => String(i.id)) || [];
      check("模块页的上移按钮生效并跳回其他栏目页",
        movedInModule.status === 302 &&
        (movedInModule.headers.get("location") || "") === "/admin/sections" &&
        noticeAfter[0] === noticeIds[1],
        noticeIds.slice(0, 3).join(",") + " → " + noticeAfter.slice(0, 3).join(","));

      const topInModule = await client.post("/admin/article/" + noticeIds[1] + "/top", {
        _token: csrfToken((await client.get("/admin/sections")).text),
        channel: "302",
        back: "/admin/sections",
        value: "1"
      });
      const noticeTop = (await client.get("/api/v1/channels/302?listSize=3", { json: true })).body?.channel?.list
        ?.map((i) => String(i.id)) || [];
      check("模块页的置顶按钮生效（栏目列表与首页模块同时排前）",
        topInModule.status === 302 && noticeTop[0] === noticeIds[1],
        "首条 " + (noticeTop[0] || "无"));
      const homeNotice = (await client.get("/api/v1/home", { json: true })).body?.home?.notice || [];
      check("其他栏目页置顶后，首页对应模块首条同步", String(homeNotice[0]?.id || "") === noticeIds[1]);

      await client.post("/admin/article/" + noticeIds[1] + "/top", {
        _token: csrfToken((await client.get("/admin/sections")).text),
        channel: "302",
        back: "/admin/sections",
        value: "0"
      });
      const noticeRestored = (await client.get("/api/v1/channels/302?listSize=4", { json: true })).body?.channel?.list
        ?.map((i) => String(i.id)) || [];
      check("取消置顶后回到原顺序（按发布时间）", noticeRestored.join(",") === noticeIds.join(","),
        noticeRestored.join(","));

      // ---- 徽标：下拉预设 + 自定义输入 + 保存后状态列显示、可改可删
      check("模块表格里有徽标的下拉预设与自定义输入",
        sectionsWithRows.text.includes('name="badge_preset"') &&
        sectionsWithRows.text.includes('data-badge-custom') &&
        sectionsWithRows.text.includes("自定义…") &&
        sectionsWithRows.text.includes(">最新<") &&
        sectionsWithRows.text.includes('data-badge-form'));

      const badgeTarget = noticeIds[1];
      const badgeSave = (fields) => client.post("/admin/article/" + badgeTarget + "/flags", {
        _token: csrfToken(sectionsWithRows.text),
        channel: "302",
        back: "/admin/sections",
        highlight: "0",
        ...fields
      });
      const badgePage = async () => (await client.get("/admin/sections")).text;
      const homeBadge = async () => ((await client.get("/api/v1/home", { json: true })).body?.home?.notice || [])
        .find((i) => String(i.id) === badgeTarget)?.badge || "";

      const presetSaved = await badgeSave({ badge_preset: "最新", badge: "" });
      const presetPage = await badgePage();
      check("存预设徽标后状态列显示徽标、前台也拿到同一文字",
        presetSaved.status === 302 &&
        presetPage.includes("徽标：最新") &&
        (await homeBadge()) === "最新",
        "status=" + presetSaved.status +
        " 页面含徽标=" + presetPage.includes("徽标：最新") +
        " 前台=" + JSON.stringify(await homeBadge()) +
        " flash=" + JSON.stringify((/class="flash[^"]*"[^>]*>([\s\S]{0,120})/.exec(presetPage) || [])[1] || ""));

      const customSaved = await badgeSave({ badge_preset: "__custom__", badge: "独家解读" });
      const customPage = await badgePage();
      check("改成自定义徽标后显示也跟着变",
        customSaved.status === 302 &&
        customPage.includes("徽标：独家解读") &&
        (await homeBadge()) === "独家解读",
        "status=" + customSaved.status +
        " 页面含徽标=" + customPage.includes("徽标：独家解读") +
        " 前台=" + JSON.stringify(await homeBadge()));

      const tooLong = await badgeSave({ badge_preset: "__custom__", badge: "七个字太长的徽标" });
      check("超过 6 个字的徽标被挡下，原值不变",
        tooLong.status === 302 &&
        (await badgePage()).includes("徽标最多 6 个字") &&
        (await homeBadge()) === "独家解读");

      const badgeDeleted = await badgeSave({ badge_preset: "", badge: "" });
      check("删除徽标后状态列与前台都不再显示",
        badgeDeleted.status === 302 &&
        !(await badgePage()).includes("徽标：独家解读") &&
        (await homeBadge()) === "");
    }

    const bannersPage = await client.get("/admin/banners");
    check("站内横幅页列出 7 个固定槽位",
      bannersPage.status === 200 && (bannersPage.text.match(/槽位 <code>/g) || []).length === 7);
    check("横幅页给出每个位置的位置说明",
      bannersPage.text.includes("首屏专题条幅 · 左") &&
      bannersPage.text.includes("三列模块上方（乡村振兴委员行，通栏）"));
    check("横幅页每个位置给出比例与出图建议",
      (bannersPage.text.match(/出图建议：/g) || []).length === 7 &&
      bannersPage.text.includes("355:76") &&
      bannersPage.text.includes("建议 1960×350"));
    check("横幅页给出通用出图说明",
      bannersPage.text.includes("显示尺寸的 2 倍") && bannersPage.text.includes("300 KB"));
    check("横幅页的图片框带选后即时预览的钩子",
      (bannersPage.text.match(/data-preview-file="/g) || []).length === 7 &&
      (bannersPage.text.match(/data-preview-box/g) || []).length === 7 &&
      (bannersPage.text.match(/data-preview-pending/g) || []).length === 7 &&
      bannersPage.text.includes('data-preview-file="#banner-preview-hero-1"'));

    // 图片一律 ≤ 2 MB（2026-09-12 定的口径），附件与视频仍是 32 MB
    const oversizedImage = Buffer.alloc(2 * 1024 * 1024 + 4096, 7);
    const oversizedBanner = await client.upload(
      "/admin/banner/hero-1",
      { _token: csrfToken(bannersPage.text), image_url: "", link_url: "", title: "", status: "published" },
      [{ field: "image", filename: "oversized.jpg", type: "image/jpeg", content: oversizedImage }]
    );
    check("横幅上传超过 2 MB 的图片被挡下，且提示能看懂",
      oversizedBanner.status === 302 && (await client.get("/admin/banners")).text.includes("2 MB"));
    const oversizedInline = await client.upload(
      "/admin/article/" + createdId + "/image",
      { _token: csrfToken((await client.get("/admin/article/" + createdId)).text) },
      [{ field: "image", filename: "oversized.jpg", type: "image/jpeg", content: oversizedImage }]
    );
    check("正文插图超过 2 MB 同样被挡下",
      oversizedInline.status === 302 && (await client.get("/admin/article/" + createdId)).text.includes("2 MB"));

    const bannerOff = await client.post("/admin/banner/body-1", {
      _token: csrfToken(bannersPage.text),
      image_url: "images/chatu.gif",
      link_url: "",
      title: "政治协商",
      status: "offline"
    });
    check("横幅可以下线",
      bannerOff.status === 302 && /tag-offline">已下线/.test((await client.get("/admin/banners")).text));
    const bannerOn = await client.post("/admin/banner/body-1", {
      _token: csrfToken((await client.get("/admin/banners")).text),
      image_url: "images/chatu.gif",
      link_url: "",
      title: "政治协商",
      status: "published"
    });
    check("横幅可以重新上线",
      bannerOn.status === 302 && !/tag-offline">已下线/.test((await client.get("/admin/banners")).text));

    // 首页管理直接同步前台，凡是会改前台的提交都要带确认文案（由 admin.js 弹一次确认）
    const confirmSlidesPage = await client.get("/admin/slides");
    const countOf = (text, needle) => (text.match(new RegExp(needle, "g")) || []).length;
    check("首页管理的提交都带确认文案",
      countOf(navPage.text, 'data-confirm="保存导航') >= 1 &&
      countOf(confirmSlidesPage.text, 'data-confirm="确认从首屏轮播里删除') >= 1 &&
      countOf(confirmSlidesPage.text, 'data-confirm="下线「|data-confirm="上线「') >= 1 &&
      countOf(confirmSlidesPage.text, 'data-confirm="保存「') >= 1 &&
      countOf(sectionsPage.text, 'data-confirm="保存模块') >= 1 &&
      countOf(sectionsPage.text, 'data-confirm="置顶「|data-confirm="取消置顶「') >= 1 &&
      countOf(bannersPage.text, 'data-confirm="保存「') === 7,
      "导航 " + countOf(navPage.text, "data-confirm") +
      " 处 / 轮播 " + countOf(confirmSlidesPage.text, "data-confirm") +
      " 处 / 模块 " + countOf(sectionsPage.text, "data-confirm") +
      " 处 / 横幅 " + countOf(bannersPage.text, "data-confirm") + " 处");

    // 上移／下移也是点了就换前台顺序，同样要带确认
    const formsMatching = (text, re) => [...text.matchAll(re)].map((m) => m[0]);
    const moveForms = {
      nav: formsMatching(navPage.text, /<form[^>]*\/admin\/nav\/\d+\/move"[^>]*>/g),
      slides: formsMatching(confirmSlidesPage.text, /<form[^>]*\/admin\/slides\/\d+\/move"[^>]*>/g),
      sections: formsMatching(sectionsPage.text, /<form[^>]*\/admin\/article\/\d+\/order"[^>]*>/g)
    };
    const allMoveConfirmed = (forms) =>
      forms.length > 0 && forms.every((tag) => tag.includes("data-confirm"));
    check("上移／下移的按钮也都会先确认",
      allMoveConfirmed(moveForms.nav) &&
      allMoveConfirmed(moveForms.slides) &&
      allMoveConfirmed(moveForms.sections),
      "导航 " + moveForms.nav.length + " 处 / 轮播 " + moveForms.slides.length +
      " 处 / 模块 " + moveForms.sections.length + " 处");

    const homeLogs = await client.get("/admin/logs");
    check("首页四类的改动都写进操作日志",
      homeLogs.text.includes("调整导航顺序") && homeLogs.text.includes("新增头条轮换") &&
      homeLogs.text.includes("修改首页模块") && homeLogs.text.includes("修改站内横幅"));

    // ---- 无 home.manage 的账号看不到也进不去这四个页面
    const reviewerHome = await reviewerClient.get("/admin");
    check("没有 home.manage 的账号侧栏不出现首页四类入口",
      !reviewerHome.text.includes("/admin/nav") && !reviewerHome.text.includes("/admin/banners"));
    check("没有 home.manage 的账号访问首页四类页面被判 403",
      (await reviewerClient.get("/admin/nav")).status === 403 &&
      (await reviewerClient.get("/admin/slides")).status === 403 &&
      (await reviewerClient.get("/admin/sections")).status === 403 &&
      (await reviewerClient.get("/admin/banners")).status === 403);

    // ---- 一键发布
    const pubToken = csrfToken((await client.get("/admin")).text);
    const published = await client.post("/admin/publish", { _token: pubToken });
    check("一键发布执行后跳回概览", published.status === 302 && (published.headers.get("location") || "") === "/admin");

    const dashboardAfter = await client.get("/admin");
    check("发布后概览提示发布结果", dashboardAfter.text.includes("发布完成"));

    // ---- 「上次发布」以操作日志为准，且时间用本地时区
    const publishLogTime = runPhp(php, "-r", env, [
      "echo (new PDO('sqlite:'.getenv('DB_DATABASE')))->query(\"SELECT created_at FROM sys_operation_log WHERE action='publish.all' ORDER BY log_id DESC LIMIT 1\")->fetchColumn();"
    ]).stdout.trim();
    const shownPublishTime = (/上次发布：([0-9-]{10} [0-9:]{8})/.exec(dashboardAfter.text) || [])[1] || "";
    check("概览「上次发布」与操作日志时间一致",
      shownPublishTime !== "" && shownPublishTime === publishLogTime,
      "面板=" + shownPublishTime + " 日志=" + publishLogTime);
    check("发布记录带发布人与产物数量",
      /上次发布：[0-9-]{10} [0-9:]{8}（[^）]+）/.test(dashboardAfter.text) && dashboardAfter.text.includes("个页面"));
    check("发布说明改成面对使用者的表述",
      dashboardAfter.text.includes("平时发稿不用点这里") && !dashboardAfter.text.includes("输出到 <code>"));

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
