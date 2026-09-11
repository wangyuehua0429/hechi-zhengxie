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

    // ---- 栏目筛选是导航条（不是下拉），且顺序与前台一致
    const chipLabels = (html) => {
      const out = [];
      const re = /class="channel-chip[^"]*"[\s\S]{0,400}?>([^<]+)<\/a>/g;
      let hit;
      while ((hit = re.exec(html)) !== null) out.push(hit[1].trim());
      return out;
    };
    const listChips = chipLabels(list.text);
    check("稿件列表里的栏目是导航条而非下拉",
      list.text.includes('class="channel-nav"') && !/<select name="channel"/.test(list.text),
      "chip " + listChips.length + " 个");
    check("导航条栏目顺序与前台一致（前 5 个）",
      listChips.slice(1, 6).join(",") === "政协领导,全国政协动态,区（广西）政协动态,市政协动态,政协新闻",
      listChips.slice(0, 6).join("、"));
    check("导航条按一级栏目分组", list.text.includes("channel-group-title"));
    const activeChip = (list.text.match(/class="channel-chip active"[\s\S]{0,400}?>([^<]+)<\/a>/) || [])[1] || "";
    check("选中栏目的 chip 高亮在「全部栏目」上", activeChip === "全部栏目", activeChip);
    const filteredChips = chipLabels((await client.get("/admin/articles?channel=314")).text);
    check("按栏目筛选后仍在导航条上操作", filteredChips.length > 40);

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
      content_html: originalContent
    });
    check("恢复原稿成功", restored.status === 302);
    const publishedPublic = await client.get("/api/v1/article/" + SAMPLE_ID, { json: true });
    check("恢复为已发布后公开接口又能读到",
      publishedPublic.status === 200 && publishedPublic.body?.article?.title === originalTitle);

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
      real_name: "审核账号",
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
      reviewerScoped.text.includes("共 0 篇"),
      (reviewerScoped.text.match(/共 \d+ 篇/) || [])[0] || "无统计");

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
