#!/usr/bin/env node
/**
 * 政协委员提案系统检查：委员名册导入 → 委员登录与首登改密 → 提交提案 →
 * 提案委收件/受理/退回 → 委员改稿重交 → 导出 Excel/Word → 权限与附件隔离 → 停用账号。
 *
 * 全程用临时 SQLite 库（migrate + seed + 建后台账号），不动开发库。
 *
 *   node tests/proposal-check.mjs
 *   node tests/proposal-check.mjs --keep      # 保留临时库与导出文件
 */

import { spawn, spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, readFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO = path.resolve(HERE, "..");

const ADMIN_USER = "checkproposal";
const ADMIN_PASSWORD = "check-proposal-2026";
const EDITOR_USER = "checkeditor2";
const EDITOR_PASSWORD = "check-editor2-2026";

const MEMBER_PASSWORD = "member-new-2026";
const MEMBER2_PASSWORD = "member2-new-2026";

let failures = 0;
let total = 0;

function check(name, ok, detail = "") {
  total += 1;
  if (!ok) failures += 1;
  console.log((ok ? "PASS  " : "FAIL  ") + name + (ok || !detail ? "" : "  — " + detail));
}

function parseArgs(argv) {
  const opts = { port: 8985, keep: false };
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
    clear() {
      jar.clear();
    }
  };
}

function makeClient(base) {
  const jar = createJar();
  const request = async (method, url, { form = null, multipart = null, binary = false } = {}) => {
    const headers = {};
    const cookie = jar.header();
    if (cookie) headers.Cookie = cookie;
    let body;
    if (multipart) {
      const fd = new FormData();
      for (const [key, value] of Object.entries(multipart.fields || {})) fd.append(key, value);
      for (const file of multipart.files || []) {
        const content = file.buffer || file.content;
        fd.append(file.field, new Blob([content], { type: file.type || "application/octet-stream" }), file.filename);
      }
      body = fd;
    } else if (form) {
      body = new URLSearchParams(form).toString();
      headers["Content-Type"] = "application/x-www-form-urlencoded";
    }
    const res = await fetch(base + url, { method, headers, body, redirect: "manual" });
    jar.absorb(res);
    if (binary) {
      const buffer = Buffer.from(await res.arrayBuffer());
      return { status: res.status, headers: res.headers, buffer };
    }
    const text = await res.text();
    return { status: res.status, headers: res.headers, text };
  };

  return {
    jar,
    request,
    get(url, opts) { return request("GET", url, opts); },
    post(url, form, opts) { return request("POST", url, { form, ...opts }); },
    upload(url, fields, files) { return request("POST", url, { multipart: { fields, files } }); }
  };
}

function csrfToken(html) {
  const m = /name="_token" value="([^"]+)"/.exec(html);
  return m ? m[1] : "";
}

/** 用 PHP 的 mbstring 把 UTF-8 文本转成 GBK，验证导入的编码自动识别 */
function toGbk(php, text, tmpRoot) {
  const utf8File = path.join(tmpRoot, "gbk-source.txt");
  const gbkFile = path.join(tmpRoot, "gbk-target.txt");
  writeFileSync(utf8File, text, "utf8");
  const result = spawnSync(
    php,
    ["-r", "file_put_contents($argv[2], mb_convert_encoding(file_get_contents($argv[1]), 'GB18030', 'UTF-8'));", utf8File, gbkFile],
    { encoding: "utf8" }
  );
  if (result.status !== 0) return null;
  return readFileSync(gbkFile);
}

/** 从导出的 Office 压缩包里取某个部件的内容（macOS 自带 unzip） */
function unzipPart(buffer, part, tmpRoot, name) {
  const file = path.join(tmpRoot, name);
  writeFileSync(file, buffer);
  const result = spawnSync("unzip", ["-p", file, part], { encoding: "utf8" });
  return result.status === 0 ? result.stdout : "";
}

async function main() {
  const opts = parseArgs(process.argv.slice(2));
  if (opts.help) {
    console.log("用法：node tests/proposal-check.mjs [--port 8985] [--keep]");
    return 0;
  }

  const php = resolvePhp();
  if (!php) {
    console.error("本机没有可用的 php，无法启动服务。请先 brew install php。");
    return 2;
  }

  const tmpRoot = mkdtempSync(path.join(tmpdir(), "hechi-proposal-check-"));
  const dbFile = path.join(tmpRoot, "proposal.sqlite");
  const env = {
    DB_DRIVER: "sqlite",
    DB_DATABASE: dbFile,
    APP_ENV: "local",
    APP_DEBUG: "1",
    PUBLISH_OUT: path.join(tmpRoot, "publish")
  };
  console.log("PHP：" + php + "　临时库：" + dbFile);

  const migrated = runPhp(php, "backend/bin/migrate.php", env);
  const seeded = runPhp(php, "backend/bin/seed.php", env);
  const adminUser = runPhp(php, "backend/bin/user.php", env, ["create", ADMIN_USER, ADMIN_PASSWORD, "提案委"]);
  const editorUser = runPhp(php, "backend/bin/user.php", env, ["create", EDITOR_USER, EDITOR_PASSWORD, "编辑账号", "editor"]);
  check(
    "建表 / 灌数 / 建后台账号三步成功",
    migrated.status === 0 && seeded.status === 0 && adminUser.status === 0 && editorUser.status === 0,
    (migrated.stderr || seeded.stderr || adminUser.stderr || editorUser.stderr || "").trim().split("\n")[0]
  );
  if (migrated.status !== 0 || seeded.status !== 0 || adminUser.status !== 0) return 1;

  check(
    "迁移 005 建出提案与委员表",
    migrated.stdout.includes("sys_member") && migrated.stdout.includes("cms_proposal"),
    migrated.stdout.slice(0, 80)
  );

  const server = spawn(php, ["-S", "127.0.0.1:" + opts.port, "-t", "backend/public", "backend/public/router.php"], {
    cwd: REPO,
    env: { ...process.env, ...env },
    stdio: ["ignore", "ignore", "pipe"]
  });
  const base = "http://127.0.0.1:" + opts.port;
  const admin = makeClient(base);
  const memberA = makeClient(base);
  const memberB = makeClient(base);
  const guest = makeClient(base);

  try {
    const ready = await waitForServer(base);
    check("服务已就绪", ready, base);
    if (!ready) return 1;

    // ---------- 一、门户与未登录拦截 ----------
    const portal = await guest.get("/member");
    check("门户首页可访问：登录框 + 登录指南",
      portal.status === 200
        && portal.text.includes('class="m-login-card"')
        && portal.text.includes('name="login_name"')
        && portal.text.includes("登录指南"),
      "状态 " + portal.status);
    check("门户页把整幅底纹当页面底板（自带文档，不套后台式外壳）",
      portal.text.includes('class="m-portal-bg"')
        && portal.text.includes("/assets/member/header-art.jpg")
        && portal.text.includes("/assets/member.css")
        && portal.text.includes('class="m-portal-title-main"')
        && portal.text.indexOf("m-head") === -1);

    for (const url of ["/member/proposals", "/member/proposal/new", "/member/password"]) {
      const res = await guest.get(url);
      check("未登录访问 " + url + " 被挡到登录页",
        res.status === 302 && (res.headers.get("location") || "") === "/member/login",
        "状态 " + res.status + " → " + res.headers.get("location"));
    }

    const noToken = await guest.post("/member/login", { login_name: "13800000001", password: "whatever" });
    check("缺 CSRF 令牌的登录被拒绝",
      noToken.status === 400 && noToken.text.includes("页面已过期"), "状态 " + noToken.status);

    const loginPage = await guest.get("/member/login");
    const guestToken = csrfToken(loginPage.text);
    const unknown = await guest.post("/member/login", { _token: guestToken, login_name: "13900009999", password: "whatever" });
    const unknownFlash = await guest.get("/member/login");
    check("登录名不存在时不泄露账号是否存在（提示与密码错误一致）",
      unknown.status === 302 && unknownFlash.text.includes("登录名或密码不正确"));

    // ---------- 二、后台登录与委员名册导入 ----------
    const adminLoginPage = await admin.get("/admin/login");
    const adminToken = csrfToken(adminLoginPage.text);
    const adminLogin = await admin.post("/admin/login", { _token: adminToken, username: ADMIN_USER, password: ADMIN_PASSWORD });
    check("后台登录成功", adminLogin.status === 302 && (adminLogin.headers.get("location") || "") === "/admin");

    const memberList = await admin.get("/admin/members");
    check("委员管理页可访问", memberList.status === 200 && memberList.text.includes("委员管理"), "状态 " + memberList.status);
    check("侧栏出现「提案管理」分组",
      memberList.text.includes("提案管理") && memberList.text.includes("提案收件"));

    const template = await admin.get("/admin/members/import/template.csv");
    check("导入模板可下载且表头固定",
      template.status === 200 && template.text.includes("姓名,手机号,界别,专委会,单位及职务,届次,备注"),
      "状态 " + template.status);

    const importPage = await admin.get("/admin/members/import");
    const importToken = csrfToken(importPage.text);
    check("导入页可访问并给出模板说明",
      importPage.status === 200
        && importPage.text.includes("委员名册导入")
        && importPage.text.includes("姓名")
        && importPage.text.includes("手机号"),
      "状态 " + importPage.status);

    const utf8Csv = "姓名,手机号,界别,专委会,单位及职务,届次,备注\n"
      + "张三,13800000001,中国共产党,提案委员会,河池市某某局副局长,五届,\n"
      + ",13800000009,经济界,,,五届,缺姓名\n"
      + "李四,13800000002,经济界,经济委员会,河池市某某公司总经理,五届,\n";
    const imported = await admin.upload("/admin/members/import", { _token: importToken }, [
      { field: "roster", filename: "roster.csv", content: Buffer.from(utf8Csv, "utf8"), type: "text/csv" }
    ]);
    const reportPage = await admin.get("/admin/members/import");
    check("导入 UTF-8 名册：成功 2 行、失败 1 行并给出原因",
      imported.status === 302
        && reportPage.text.includes("成功 <strong>2</strong>")
        && reportPage.text.includes("缺姓名") === false
        && reportPage.text.includes("姓名为空"),
      "状态 " + imported.status);

    const credentials = await admin.get("/admin/members/credentials.csv");
    check("导入后可下载一次性初始密码清单",
      credentials.status === 200 && credentials.text.includes("姓名,登录名,初始密码"),
      "状态 " + credentials.status);
    check("登录名取委员本人姓名（清单两列都是姓名）",
      /^张三,张三,/m.test(credentials.text) && /^李四,李四,/m.test(credentials.text));
    const passwordA = (/^张三,张三,([A-Za-z0-9]+)/m.exec(credentials.text) || [])[1] || "";
    const passwordB = (/^李四,李四,([A-Za-z0-9]+)/m.exec(credentials.text) || [])[1] || "";
    check("清单里两个账号都有密码", passwordA.length >= 8 && passwordB.length >= 8);

    const credentialsAgain = await admin.get("/admin/members/credentials.csv");
    const afterSecond = await admin.get("/admin/members/import");
    check("密码清单只给一次：第二次下载被挡回，页面也不再显示",
      credentialsAgain.status === 302
        && (credentialsAgain.headers.get("location") || "").includes("/admin/members/import")
        && afterSecond.status === 200
        && afterSecond.text.includes("已下载并从页面清除")
        && !afterSecond.text.includes(passwordA),
      "状态 " + credentialsAgain.status);

    const dupCsv = "姓名,手机号,界别,专委会,单位及职务,届次,备注\n王五,13800000001,经济界,,,五届,手机号重复\n";
    const dupToken = csrfToken((await admin.get("/admin/members/import")).text);
    await admin.upload("/admin/members/import", { _token: dupToken }, [
      { field: "roster", filename: "dup.csv", content: Buffer.from(dupCsv, "utf8"), type: "text/csv" }
    ]);
    const dupPage = await admin.get("/admin/members/import");
    check("手机号重复的行被挡下并说明原因",
      dupPage.text.includes("手机号重复") && dupPage.text.includes("第 2 行"));

    const dupNameCsv = "姓名,手机号,界别,专委会,单位及职务,届次,备注\n张三,13800000004,教育界,教科卫体委员会,河池市某某中学教师,五届,与前面重名\n";
    const dupNameToken = csrfToken((await admin.get("/admin/members/import")).text);
    await admin.upload("/admin/members/import", { _token: dupNameToken }, [
      { field: "roster", filename: "dup-name.csv", content: Buffer.from(dupNameCsv, "utf8"), type: "text/csv" }
    ]);
    const dupNameCredentials = await admin.get("/admin/members/credentials.csv");
    check("重名委员的登录名自动补序号（张三2）",
      dupNameCredentials.status === 200 && /^张三,张三2,/m.test(dupNameCredentials.text),
      "状态 " + dupNameCredentials.status);

    const gbkCsv = "姓名,手机号,界别,专委会,单位及职务,届次,备注\n周七,13800000003,教育界,教科卫体委员会,河池市某某中学教师,五届,\n";
    const gbkBuffer = toGbk(php, gbkCsv, tmpRoot);
    check("能把 UTF-8 样例转成 GBK 用于导入", gbkBuffer !== null && gbkBuffer.length > 0);
    if (gbkBuffer) {
      const gbkToken = csrfToken((await admin.get("/admin/members/import")).text);
      await admin.upload("/admin/members/import", { _token: gbkToken }, [
        { field: "roster", filename: "gbk.csv", content: gbkBuffer, type: "text/csv" }
      ]);
      const gbkPage = await admin.get("/admin/members/import");
      check("GBK 编码的名册同样能识别（周七入列）", gbkPage.text.includes("周七"));
    }

    const membersAfter = await admin.get("/admin/members");
    check("委员列表显示导入的账号与提案数",
      membersAfter.text.includes("张三") && membersAfter.text.includes("13800000001") && membersAfter.text.includes("提案数"));

    // 无提案权限的账号访问提案收件
    const editor = makeClient(base);
    const editorLoginPage = await editor.get("/admin/login");
    await editor.post("/admin/login", {
      _token: csrfToken(editorLoginPage.text),
      username: EDITOR_USER,
      password: EDITOR_PASSWORD
    });
    const editorProposals = await editor.get("/admin/proposals");
    check("没有 proposal.view 权限时提案收件返回 403",
      editorProposals.status === 403, "状态 " + editorProposals.status);

    // ---------- 三、委员首登、强制改密 ----------
    const memberLoginPage = await memberA.get("/member/login");
    const wrongPassword = await memberA.post("/member/login", {
      _token: csrfToken(memberLoginPage.text),
      login_name: "13800000001",
      password: "wrong-password"
    });
    const wrongFlash = await memberA.get("/member/login");
    check("委员密码错误时给出提示",
      wrongPassword.status === 302 && wrongFlash.text.includes("登录名或密码不正确"));

    const firstLogin = await memberA.post("/member/login", {
      _token: csrfToken((await memberA.get("/member/login")).text),
      login_name: "13800000001",
      password: passwordA
    });
    check("首次登录后跳转到改密页（首登强制改密）",
      firstLogin.status === 302 && (firstLogin.headers.get("location") || "") === "/member/password",
      "状态 " + firstLogin.status + " → " + firstLogin.headers.get("location"));

    const blocked = await memberA.get("/member/proposals");
    check("未改密前访问我的提案被挡回改密页",
      blocked.status === 302 && (blocked.headers.get("location") || "") === "/member/password");

    const passwordPage = await memberA.get("/member/password");
    const weak = await memberA.post("/member/password", {
      _token: csrfToken(passwordPage.text),
      current_password: passwordA,
      new_password: "short",
      confirm_password: "short"
    });
    const weakFlash = await memberA.get("/member/password");
    check("新密码太短被挡下", weak.status === 302 && weakFlash.text.includes("至少 8 位"));

    const mismatch = await memberA.post("/member/password", {
      _token: csrfToken((await memberA.get("/member/password")).text),
      current_password: passwordA,
      new_password: MEMBER_PASSWORD,
      confirm_password: MEMBER_PASSWORD + "x"
    });
    check("两次新密码不一致被挡下",
      mismatch.status === 302 && (await memberA.get("/member/password")).text.includes("两次输入的新密码不一致"));

    const changed = await memberA.post("/member/password", {
      _token: csrfToken((await memberA.get("/member/password")).text),
      current_password: passwordA,
      new_password: MEMBER_PASSWORD,
      confirm_password: MEMBER_PASSWORD
    });
    check("改密成功后进入我的提案",
      changed.status === 302 && (changed.headers.get("location") || "") === "/member/proposals");
    const listAfterChange = await memberA.get("/member/proposals");
    check("改密后可正常打开我的提案", listAfterChange.status === 200 && listAfterChange.text.includes("我的提案"));

    const byName = makeClient(base);
    const byNamePage = await byName.get("/member/login");
    const byNameRes = await byName.post("/member/login", {
      _token: csrfToken(byNamePage.text),
      login_name: "张三",
      password: MEMBER_PASSWORD
    });
    check("委员可用本人姓名登录",
      byNameRes.status === 302 && (byNameRes.headers.get("location") || "") === "/member/proposals",
      "状态 " + byNameRes.status + " → " + (byNameRes.headers.get("location") || ""));

    const byMobile = makeClient(base);
    const byMobilePage = await byMobile.get("/member/login");
    const byMobileRes = await byMobile.post("/member/login", {
      _token: csrfToken(byMobilePage.text),
      login_name: "13800000001",
      password: MEMBER_PASSWORD
    });
    check("登记的手机号仍可作备用登录标识",
      byMobileRes.status === 302 && (byMobileRes.headers.get("location") || "") === "/member/proposals",
      "状态 " + byMobileRes.status + " → " + (byMobileRes.headers.get("location") || ""));

    const dupNamePw = (/^张三,张三2,([A-Za-z0-9]+)/m.exec(dupNameCredentials.text) || [])[1] || "";
    const bySharedName = makeClient(base);
    const bySharedNamePage = await bySharedName.get("/member/login");
    const bySharedNameRes = await bySharedName.post("/member/login", {
      _token: csrfToken(bySharedNamePage.text),
      login_name: "张三",
      password: dupNamePw
    });
    check("重名委员用本人姓名＋自己密码登录（不猜账号）",
      bySharedNameRes.status === 302 && (bySharedNameRes.headers.get("location") || "") === "/member/password",
      "状态 " + bySharedNameRes.status + " → " + (bySharedNameRes.headers.get("location") || ""));

    const legacyCreate = runPhp(php, "backend/bin/member.php", env, ["create", "13800000009", "legacy-pass-2026", "测试委员"]);
    check("能用命令行造出「登录名≠姓名」的老形态账号", legacyCreate.status === 0, legacyCreate.stderr.trim().split("\n")[0]);
    const byLegacyName = makeClient(base);
    const byLegacyNamePage = await byLegacyName.get("/member/login");
    const byLegacyNameRes = await byLegacyName.post("/member/login", {
      _token: csrfToken(byLegacyNamePage.text),
      login_name: "测试委员",
      password: "legacy-pass-2026"
    });
    check("老账号（登录名是手机号）仍可用本人姓名登录",
      byLegacyNameRes.status === 302 && (byLegacyNameRes.headers.get("location") || "") === "/member/password",
      "状态 " + byLegacyNameRes.status + " → " + (byLegacyNameRes.headers.get("location") || ""));

    // ---------- 四、提交提案 ----------
    const formPage = await memberA.get("/member/proposal/new");
    check("填写提案页可访问且字段齐全",
      formPage.status === 200
        && formPage.text.includes('name="title"')
        && formPage.text.includes('name="problem_text"')
        && formPage.text.includes('name="analysis_text"')
        && formPage.text.includes('name="suggestion_text"')
        && formPage.text.includes('name="attachments[]"'),
      "状态 " + formPage.status);

    const invalid = await memberA.post("/member/proposal/create", {
      _token: csrfToken(formPage.text),
      proposer_type: "personal",
      proposer_name: "张三",
      sector: "中国共产党",
      committee: "提案委员会",
      contact_mobile: "13800000001",
      category: "经济建设",
      title: "",
      problem_text: "",
      analysis_text: "",
      suggestion_text: ""
    });
    check("必填项为空时留在表单页并给出提示",
      invalid.status === 400 && invalid.text.includes("请填写案由") && invalid.text.includes("请填写「建议」"),
      "状态 " + invalid.status);

    const longTitle = "案".repeat(51);
    const invalidTitle = await memberA.post("/member/proposal/create", {
      _token: csrfToken((await memberA.get("/member/proposal/new")).text),
      proposer_type: "collective",
      proposer_name: "张三",
      contact_mobile: "13800000001",
      category: "社会建设",
      title: longTitle,
      problem_text: "情况",
      suggestion_text: "建议"
    });
    check("案由超过 50 字被挡下，且集体提案要求填写集体名称",
      invalidTitle.status === 400
        && invalidTitle.text.includes("案由不能超过 50 个字")
        && invalidTitle.text.includes("集体提案请填写提出单位或界别名称"));

    const title = "关于完善城区老旧小区充电设施的建议";
    const created = await memberA.upload("/member/proposal/create", {
      _token: csrfToken((await memberA.get("/member/proposal/new")).text),
      proposer_type: "personal",
      proposer_name: "张三",
      sector: "中国共产党",
      committee: "提案委员会",
      contact_mobile: "13800000001",
      category: "经济建设",
      title,
      problem_text: "城区老旧小区电动自行车充电设施不足。",
      analysis_text: "既有线路与场地条件受限。",
      suggestion_text: "由住建部门牵头，分批加装集中充电棚。"
    }, [
      { field: "attachments[]", filename: "调研底稿.txt", content: Buffer.from("附件内容：调研底稿", "utf8"), type: "text/plain" }
    ]);
    const location = created.headers.get("location") || "";
    const proposalId = (/\/member\/proposal\/(\d+)/.exec(location) || [])[1] || "";
    check("提交成功后跳到提案详情",
      created.status === 302 && proposalId !== "", "状态 " + created.status + " → " + location);

    const detail = await memberA.get("/member/proposal/" + proposalId);
    check("详情页显示已提交与三段正文",
      detail.status === 200
        && detail.text.includes("已提交")
        && detail.text.includes("一、情况与问题")
        && detail.text.includes("调研底稿.txt"),
      "状态 " + detail.status);

    const memberListPage = await memberA.get("/member/proposals");
    check("我的提案列表出现该提案",
      memberListPage.status === 200 && memberListPage.text.includes(title) && memberListPage.text.includes("共 1 件"));

    // ---------- 五、后台收件、退回与受理 ----------
    const adminList = await admin.get("/admin/proposals");
    check("后台收件列表能看到提案与状态统计",
      adminList.status === 200
        && adminList.text.includes(title)
        && adminList.text.includes("已提交")
        && adminList.text.includes("提案收件"),
      "状态 " + adminList.status);

    const adminDetail = await admin.get("/admin/proposal/" + proposalId);
    check("后台详情页带出三段正文与委员账号",
      adminDetail.status === 200
        && adminDetail.text.includes("城区老旧小区电动自行车充电设施不足")
        && adminDetail.text.includes("13800000001"),
      "状态 " + adminDetail.status);

    const returnToken = csrfToken(adminDetail.text);
    const emptyReturn = await admin.post("/admin/proposal/" + proposalId + "/return", { _token: returnToken, returned_reason: "" });
    const afterEmptyReturn = await admin.get("/admin/proposal/" + proposalId);
    check("退回收件时不写意见被挡下",
      emptyReturn.status === 302 && afterEmptyReturn.text.includes("退回应写明意见"));

    const returnReason = "请补充拟选址点位与资金测算。";
    await admin.post("/admin/proposal/" + proposalId + "/return", {
      _token: csrfToken(afterEmptyReturn.text),
      returned_reason: returnReason
    });
    const afterReturn = await admin.get("/admin/proposal/" + proposalId);
    check("退回后状态为已退回并记录意见",
      afterReturn.text.includes("已退回") && afterReturn.text.includes(returnReason));

    const memberDetailAfterReturn = await memberA.get("/member/proposal/" + proposalId);
    check("委员端能看到退回意见与「修改并重新提交」入口",
      memberDetailAfterReturn.status === 200
        && memberDetailAfterReturn.text.includes(returnReason)
        && memberDetailAfterReturn.text.includes("修改并重新提交"));

    const submittedStatus = await memberA.get("/member/proposals?" + "status=returned");
    check("委员端可按状态筛选出被退回的提案",
      submittedStatus.status === 200 && submittedStatus.text.includes(title));

    const editPage = await memberA.get("/member/proposal/" + proposalId + "/edit");
    check("退回后的提案可以打编辑页并带出原值",
      editPage.status === 200 && editPage.text.includes("关于完善城区老旧小区充电设施的建议"));

    const resubmit = await memberA.post("/member/proposal/" + proposalId + "/submit", {
      _token: csrfToken(editPage.text),
      proposer_type: "personal",
      proposer_name: "张三",
      sector: "中国共产党",
      committee: "提案委员会",
      contact_mobile: "13800000001",
      category: "经济建设",
      title,
      problem_text: "城区老旧小区电动自行车充电设施不足，消防隐患突出。",
      analysis_text: "既有线路与场地条件受限。",
      suggestion_text: "由住建部门牵头，分批加装集中充电棚，资金来源由财政与物业共担。"
    });
    check("修改后重新提交成功", resubmit.status === 302 && (resubmit.headers.get("location") || "").includes("/member/proposal/" + proposalId));

    const detailAfterResubmit = await memberA.get("/member/proposal/" + proposalId);
    check("重交后状态回到已提交且办理记录有两条",
      detailAfterResubmit.text.includes("已提交")
        && detailAfterResubmit.text.includes("提交提案")
        && detailAfterResubmit.text.includes("修改后重新提交")
        && detailAfterResubmit.text.includes("退回补充"));

    const acceptToken = csrfToken((await admin.get("/admin/proposal/" + proposalId)).text);
    await admin.post("/admin/proposal/" + proposalId + "/accept", { _token: acceptToken, review_note: "予以立案。" });
    const afterAccept = await admin.get("/admin/proposal/" + proposalId);
    check("受理后状态为已受理并记录受理意见",
      afterAccept.text.includes("已受理") && afterAccept.text.includes("予以立案"));

    const memberAccepted = await memberA.get("/member/proposal/" + proposalId);
    check("委员端能看到受理意见", memberAccepted.status === 200 && memberAccepted.text.includes("予以立案"));

    // ---------- 六、越权与附件隔离 ----------
    const member2Login = await memberB.post("/member/login", {
      _token: csrfToken((await memberB.get("/member/login")).text),
      login_name: "13800000002",
      password: passwordB
    });
    check("第二个委员也能首登（跳改密）", member2Login.status === 302);
    await memberB.post("/member/password", {
      _token: csrfToken((await memberB.get("/member/password")).text),
      current_password: passwordB,
      new_password: MEMBER2_PASSWORD,
      confirm_password: MEMBER2_PASSWORD
    });
    const otherProposal = await memberB.get("/member/proposal/" + proposalId);
    check("委员访问他人提案返回 404", otherProposal.status === 404, "状态 " + otherProposal.status);

    const attachmentHref = (new RegExp('/member/proposal/' + proposalId + '/attachment/(\\d+)').exec(detailAfterResubmit.text) || [])[0] || "";
    check("详情页给出附件下载地址", attachmentHref !== "");
    const attachmentByOwner = await memberA.get(attachmentHref, { binary: true });
    check("本人可下载附件且内容一致",
      attachmentByOwner.status === 200 && attachmentByOwner.buffer.toString("utf8").includes("调研底稿"),
      "状态 " + attachmentByOwner.status);
    const attachmentByOther = await memberB.get(attachmentHref);
    check("他人访问该附件返回 404", attachmentByOther.status === 404);
    const attachmentByGuest = await guest.get(attachmentHref);
    check("未登录访问该附件被挡到登录页", attachmentByGuest.status === 302);

    // 附件不能从公开目录直接取到（落在 storage 下，不进 webroot）
    const probes = await admin.get("/admin/proposals");
    check("后台收件页可打开（供下面导出用）", probes.status === 200);

    // ---------- 七、导出 ----------
    const xlsx = await admin.get("/admin/proposals/export.xlsx", { binary: true });
    const sheet = xlsx.status === 200 ? unzipPart(xlsx.buffer, "xl/worksheets/sheet1.xml", tmpRoot, "export.xlsx") : "";
    const workbook = xlsx.status === 200 ? unzipPart(xlsx.buffer, "xl/workbook.xml", tmpRoot, "export.xlsx") : "";
    check("收件清单导出为可解析的 xlsx 且含案由与状态",
      xlsx.status === 200
        && workbook.includes("提案收件清单")
        && sheet.includes("提案号")
        && sheet.includes("关于完善城区老旧小区充电设施的建议")
        && sheet.includes("已受理"),
      "状态 " + xlsx.status);

    const word = await admin.get("/admin/proposal/" + proposalId + "/word", { binary: true });
    const documentXml = word.status === 200 ? unzipPart(word.buffer, "word/document.xml", tmpRoot, "proposal.docx") : "";
    check("单件 Word 提案表可解析且三段与意见齐全",
      word.status === 200
        && documentXml.includes("关于完善城区老旧小区充电设施的建议")
        && documentXml.includes("一、情况与问题")
        && documentXml.includes("三、建议")
        && documentXml.includes("予以立案"),
      "状态 " + word.status);
    check("Word 导出带政务排版设置（A4、宋体、固定行距 28 磅）",
      documentXml.includes('w:w="11906"') && documentXml.includes('w:eastAsia="宋体"') && documentXml.includes('w:line="560"'));

    const memberWord = await memberA.get("/member/proposal/" + proposalId + "/word", { binary: true });
    const memberDoc = memberWord.status === 200 ? unzipPart(memberWord.buffer, "word/document.xml", tmpRoot, "member.docx") : "";
    check("委员端可下载自己提案的 Word 版",
      memberWord.status === 200 && memberDoc.includes("关于完善城区老旧小区充电设施的建议"));

    // ---------- 八、停用账号后无法登录 ----------
    const rosterPage = await admin.get("/admin/members?keyword=13800000001");
    const statusToken = csrfToken(rosterPage.text);
    const memberIdMatch = /\/admin\/member\/(\d+)\/status/.exec(rosterPage.text);
    check("委员管理列表给出停用入口", memberIdMatch !== null);
    if (memberIdMatch) {
      const disable = await admin.post("/admin/member/" + memberIdMatch[1] + "/status", { _token: statusToken });
      check("停用委员成功", disable.status === 302);

      const disabled = makeClient(base);
      const disabledPage = await disabled.get("/member/login");
      await disabled.post("/member/login", {
        _token: csrfToken(disabledPage.text),
        login_name: "13800000001",
        password: MEMBER_PASSWORD
      });
      const disabledFlash = await disabled.get("/member/login");
      check("停用后登录被拒并说明原因", disabledFlash.text.includes("该账号已停用"));

      const sessionInvalid = await memberA.get("/member/proposals");
      check("已登录的会话在账号停用后立即失效",
        sessionInvalid.status === 302 && (sessionInvalid.headers.get("location") || "") === "/member/login");
    }

    console.log("");
    console.log(`共 ${total} 项检查，失败 ${failures} 项。`);
    return failures === 0 ? 0 : 1;
  } finally {
    server.kill();
    if (!opts.keep) {
      // 临时目录里的库与导出文件随进程退出保留在 tmpRoot，--keep 时打印出来便于排查
    } else {
      console.log("临时目录（--keep）：" + tmpRoot);
    }
  }
}

main().then((code) => {
  process.exit(code);
}).catch((error) => {
  console.error(error);
  process.exit(2);
});
