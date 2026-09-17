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
      for (const [key, value] of Object.entries(multipart.fields || {})) {
        // 多值字段（units[]、co_name[] 这类）要重复 append，不能拼成 "a,b"
        if (Array.isArray(value)) value.forEach((item) => fd.append(key, item));
        else fd.append(key, value);
      }
      for (const file of multipart.files || []) {
        const content = file.buffer || file.content;
        fd.append(file.field, new Blob([content], { type: file.type || "application/octet-stream" }), file.filename);
      }
      body = fd;
    } else if (form) {
      const params = new URLSearchParams();
      for (const [key, value] of Object.entries(form)) {
        if (Array.isArray(value)) value.forEach((item) => params.append(key, item));
        else params.append(key, value);
      }
      body = params.toString();
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
    check("导入模板可下载且表头固定为四列",
      template.status === 200 && template.text.includes("姓名,界别,职务,联系电话"),
      "状态 " + template.status);

    const importPage = await admin.get("/admin/members/import");
    const importToken = csrfToken(importPage.text);
    check("导入页可访问并给出模板说明",
      importPage.status === 200
        && importPage.text.includes("委员名册导入")
        && importPage.text.includes("姓名")
        && importPage.text.includes("职务")
        && importPage.text.includes("联系电话"),
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

    const dupCsv = "姓名,手机号,界别,专委会,单位及职务,届次,备注\n王五,13800000001,经济界,,河池市某某公司职员,五届,手机号重复\n";
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

    // ---------- 二之一之二、名册导出件的原样导入 ----------
    // 真实名册的样子：第 1 行是合并标题、表头写成「姓 名／现 任 职 务」、界别是小标题行、整列没有手机号
    const noNameCsv = "序号,现任职务\n1,某局副局长\n";
    const noNameToken = csrfToken((await admin.get("/admin/members/import")).text);
    await admin.upload("/admin/members/import", { _token: noNameToken }, [
      { field: "roster", filename: "no-name.csv", content: Buffer.from(noNameCsv, "utf8"), type: "text/csv" }
    ]);
    const noNamePage = await admin.get("/admin/members/import");
    check("表头里没有「姓名」列的名册被拒收并说明原因",
      noNamePage.text.includes("表头对不上"),
      "状态 " + noNamePage.status);

    const rawRosterCsv = "\uFEFF政协第五届河池市委员会委员提名人选名册\n（现有291名）\n"
      + "序号,姓 名,现 任 职 务\n"
      + "一、中国共产党（共1人）\n"
      + "1,韦    平,政协河池市委员会机关党组副书记、副秘书长、办公室副主任\n"
      + "二、民革（共1人）\n"
      + "2,朱    琳,政协河池市委员会提案委员会主任\n";
    const rawRosterToken = csrfToken((await admin.get("/admin/members/import")).text);
    await admin.upload("/admin/members/import", { _token: rawRosterToken }, [
      { field: "roster", filename: "roster.csv", content: Buffer.from(rawRosterCsv, "utf8"), type: "text/csv" }
    ]);
    const rawRosterReport = await admin.get("/admin/members/import");
    check("名册导出件能导入：表头在第 3 行也认得、成功 2 行、界别小标题不算失败",
      rawRosterReport.text.includes("成功 <strong>2</strong>")
        && rawRosterReport.text.includes("失败 <strong>0</strong>")
        && rawRosterReport.text.includes("共处理 4 行"),
      (rawRosterReport.text.match(/成功 <strong>\d+<\/strong>/) || [""])[0]);

    const rawRosterCredentials = await admin.get("/admin/members/credentials.csv");
    check("姓名里的排版空格被去掉，登录名就是姓名本身（韦平／朱琳）",
      rawRosterCredentials.status === 200
        && /^韦平,韦平,/m.test(rawRosterCredentials.text)
        && /^朱琳,朱琳,/m.test(rawRosterCredentials.text),
      "状态 " + rawRosterCredentials.status);

    const orgProbe = spawnSync(php, ["-r",
      "require 'backend/src/bootstrap.php';"
      + "$db = new \\HechiZx\\Support\\Db((array) hechi_config('db'));"
      + "echo (string) $db->scalar('SELECT org_title FROM sys_member WHERE name = :n', ['n' => '韦平']);"
    ], { cwd: REPO, env: { ...process.env, ...env }, encoding: "utf8" });
    check("「现 任 职 务」整列落进「单位及职务」",
      (orgProbe.stdout || "").includes("政协河池市委员会机关党组副书记"),
      (orgProbe.stderr || "").trim().split("\n")[0] || (orgProbe.stdout || "（空）"));

    // ---------- 新模板四列：姓名／界别／职务／联系电话，姓名与职务必填 ----------
    const newTemplateCsv = "姓名,界别,职务,联系电话\n"
      + "孙八,社会科学界,河池市某某研究所研究员,13800000005\n"
      + "钱九,农业界,,13800000006\n";
    const newTemplateToken = csrfToken((await admin.get("/admin/members/import")).text);
    await admin.upload("/admin/members/import", { _token: newTemplateToken }, [
      { field: "roster", filename: "new-template.csv", content: Buffer.from(newTemplateCsv, "utf8"), type: "text/csv" }
    ]);
    const newTemplatePage = await admin.get("/admin/members/import");
    check("新模板能导入：成功 1 行，职务留空的那行被挡下并说明原因",
      newTemplatePage.text.includes("成功 <strong>1</strong>")
        && newTemplatePage.text.includes("职务为空")
        && newTemplatePage.text.includes("钱九"),
      (newTemplatePage.text.match(/成功 <strong>\d+<\/strong>/) || [""])[0]);

    const newMemberRow = await admin.get("/admin/members?keyword=" + encodeURIComponent("孙八"));
    check("新模板的界别与联系电话进了名册列表",
      newMemberRow.text.includes("社会科学界") && newMemberRow.text.includes("13800000005"),
      "状态 " + newMemberRow.status);

    const orgProbeTwo = spawnSync(php, ["-r",
      "require 'backend/src/bootstrap.php';"
      + "$db = new \\HechiZx\\Support\\Db((array) hechi_config('db'));"
      + "echo (string) $db->scalar('SELECT org_title FROM sys_member WHERE name = :n', ['n' => '孙八']);"
    ], { cwd: REPO, env: { ...process.env, ...env }, encoding: "utf8" });
    check("新模板的「职务」列落进单位及职务",
      (orgProbeTwo.stdout || "").includes("河池市某某研究所研究员"),
      (orgProbeTwo.stderr || "").trim().split("\n")[0] || (orgProbeTwo.stdout || "（空）"));

    const noOrgCsv = "姓名,界别,联系电话\n孙九,经济界,13800000007\n";
    const noOrgToken = csrfToken((await admin.get("/admin/members/import")).text);
    await admin.upload("/admin/members/import", { _token: noOrgToken }, [
      { field: "roster", filename: "no-org.csv", content: Buffer.from(noOrgCsv, "utf8"), type: "text/csv" }
    ]);
    const noOrgPage = await admin.get("/admin/members/import");
    check("缺「职务」列的名册同样被拒收", noOrgPage.text.includes("表头对不上"));

    // ---------- 二之二、委员管理：手工新建账号（一条或多条） ----------
    // 多值字段（name[] 这类）要逐个 append：这里自己拼请求体，不依赖测试客户端对数组的处理
    const postRows = async (client, url, fields) => {
      const params = new URLSearchParams();
      Object.entries(fields).forEach(([key, value]) => {
        (Array.isArray(value) ? value : [value]).forEach((item) => params.append(key, item));
      });
      const res = await fetch(base + url, {
        method: "POST",
        headers: { Cookie: client.jar.header(), "Content-Type": "application/x-www-form-urlencoded" },
        body: params.toString(),
        redirect: "manual"
      });
      client.jar.absorb(res);
      return { status: res.status, headers: res.headers, text: await res.text() };
    };

    const manualFormPage = await admin.get("/admin/members");
    check("委员管理页给出手工新建账号表单（姓名／界别／职务／联系电话四列）",
      manualFormPage.status === 200
        && manualFormPage.text.includes("手工新建账号")
        && manualFormPage.text.includes('action="/admin/members/create"')
        && manualFormPage.text.includes('name="name[]"')
        && manualFormPage.text.includes('name="org[]"'),
      "状态 " + manualFormPage.status);

    const manualCreate = await postRows(admin, "/admin/members/create", {
      _token: csrfToken(manualFormPage.text),
      "name[]": ["韦东", "韦西", "", "韦北"],
      "sector[]": ["经济界", "教育界", "", "农业界"],
      "org[]": ["河池市某某公司经理", "河池市某某中学教师", "", ""],
      "mobile[]": ["13800000011", "13800000012", "", "13800000013"]
    });
    const manualPage = await admin.get("/admin/members");
    check("一次提交多条：成功 2 条、空白行跳过、漏填职务的那条被挡下",
      manualCreate.status === 302
        && manualPage.text.includes("成功 <strong>2</strong> 条")
        && manualPage.text.includes("职务为空")
        && manualPage.text.includes("第 3 条"),
      "状态 " + manualCreate.status);

    const manualList = await admin.get("/admin/members?keyword=" + encodeURIComponent("韦东"));
    check("手工新建的界别与联系电话进了名册列表",
      manualList.text.includes("经济界") && manualList.text.includes("13800000011"));

    const manualCredentials = await admin.get("/admin/members/credentials.csv");
    const manualPassword = (/^韦东,韦东,([A-Za-z0-9]+)/m.exec(manualCredentials.text) || [])[1] || "";
    check("手工新建的账号与初始密码出现在一次性清单里（可下载）",
      manualCredentials.status === 200
        && /^韦东,韦东,/m.test(manualCredentials.text)
        && /^韦西,韦西,/m.test(manualCredentials.text)
        && manualPassword.length >= 8,
      "状态 " + manualCredentials.status);

    const manualPageAfterDownload = await admin.get("/admin/members");
    check("密码清单下载后手工建号结果卡片消失（不重复显示）",
      manualPageAfterDownload.text.includes("手工新建结果") === false);

    const manualMember = makeClient(base);
    const manualLoginPage = await manualMember.get("/member/login");
    await manualMember.post("/member/login", {
      _token: csrfToken(manualLoginPage.text),
      login_name: "韦东",
      password: manualPassword
    });
    const manualAfter = await manualMember.get("/member");
    check("手工建的账号能用本人姓名＋初始密码登录（首登跳改密）",
      manualAfter.status === 302 && (manualAfter.headers.get("location") || "") === "/member/password",
      "状态 " + manualAfter.status);

    const membersAfter = await admin.get("/admin/members");
    check("委员列表显示导入的账号与提案数",
      membersAfter.text.includes("张三") && membersAfter.text.includes("13800000001") && membersAfter.text.includes("提案数"));

    // ---------- 二之二、市直单位清单（建议承办单位的下拉来源） ----------
    const unitsPage = await admin.get("/admin/units");
    check("市直单位清单页可访问且初始为空提示",
      unitsPage.status === 200 && unitsPage.text.includes("市直单位") && unitsPage.text.includes("清单还是空的"),
      "状态 " + unitsPage.status);
    check("侧栏出现「市直单位」入口",
      unitsPage.text.includes("/admin/units") && unitsPage.text.includes("提案管理"));

    const unitTemplate = await admin.get("/admin/units/import/template.csv");
    check("市直单位导入模板可下载且表头固定",
      unitTemplate.status === 200 && unitTemplate.text.includes("单位名称,排序号"),
      "状态 " + unitTemplate.status);

    const unitCsv = "单位名称,排序号\n"
      + "河池市住房和城乡建设局,10\n"
      + "河池市教育局,20\n"
      + "河池市民政局,30\n"
      + "河池市交通运输局,40\n"
      + "河池市农业农村局,50\n"
      + "河池市文化广电体育和旅游局,60\n";
    const unitImportToken = csrfToken(unitsPage.text);
    const unitImported = await admin.upload("/admin/units/import", { _token: unitImportToken }, [
      { field: "units", filename: "units.csv", content: Buffer.from(unitCsv, "utf8"), type: "text/csv" }
    ]);
    const unitsAfterImport = await admin.get("/admin/units");
    check("市直单位 CSV 导入：新增 6 个并列出",
      unitImported.status === 302
        && unitsAfterImport.text.includes("新增 6 个")
        && unitsAfterImport.text.includes("河池市住房和城乡建设局"),
      "状态 " + unitImported.status);

    const unitCreateToken = csrfToken(unitsAfterImport.text);
    const dupUnit = await admin.post("/admin/units", { _token: unitCreateToken, name: "河池市教育局", sort_no: "25" });
    const afterDupUnit = await admin.get("/admin/units");
    check("重名单位被挡下并说明原因",
      dupUnit.status === 302 && afterDupUnit.text.includes("清单里已经有"), "状态 " + dupUnit.status);

    const newUnit = await admin.post("/admin/units", {
      _token: csrfToken(afterDupUnit.text),
      name: "河池市卫生健康委员会",
      sort_no: "70"
    });
    const afterNewUnit = await admin.get("/admin/units");
    check("单个添加单位成功", newUnit.status === 302 && afterNewUnit.text.includes("河池市卫生健康委员会"));

    const statusUnitToken = csrfToken(afterNewUnit.text);
    const unitId = (/\/admin\/unit\/(\d+)\/status/.exec(afterNewUnit.text) || [])[1] || "";
    check("清单页给出停用／删除入口", unitId !== "");
    if (unitId) {
      const disabled = await admin.post("/admin/unit/" + unitId + "/status", { _token: statusUnitToken });
      const afterDisabled = await admin.get("/admin/units");
      check("单位可以停用（不被删除，历史提案不受影响）",
        disabled.status === 302 && afterDisabled.text.includes("已停用"));
      await admin.post("/admin/unit/" + unitId + "/status", { _token: csrfToken(afterDisabled.text) });
    }

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
    check("改密成功提示委员保存好新密码（系统不提供自助重置）",
      listAfterChange.text.includes("请妥善保存新密码") && listAfterChange.text.includes("联系提案委线下重置"));

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

    const passwordAgain = await memberA.get("/member/password");
    check("首登改密后不再提供自助改密入口（直接跳回工作台）",
      passwordAgain.status === 302 && (passwordAgain.headers.get("location") || "") === "/member/proposals",
      "状态 " + passwordAgain.status + " → " + passwordAgain.headers.get("location"));
    const passwordPost = await memberA.post("/member/password", {
      _token: csrfToken((await memberA.get("/member/proposals")).text),
      current_password: MEMBER_PASSWORD,
      new_password: "another-new-2026",
      confirm_password: "another-new-2026"
    });
    const afterPasswordPost = await memberA.get("/member/proposals");
    check("非首登提交改密表单被挡下并说明联系提案委",
      passwordPost.status === 302
        && (passwordPost.headers.get("location") || "") === "/member/proposals"
        && afterPasswordPost.text.includes("系统不提供自助修改密码"),
      "状态 " + passwordPost.status);
    check("工作台导航里没有「修改密码」入口",
      !afterPasswordPost.text.includes(">修改密码<"));

    // ---------- 四、提交提案 ----------
    const formPage = await memberA.get("/member/proposal/new");
    check("填写提案页可访问且字段齐全（联名、承办单位、正文、办理联系人）",
      formPage.status === 200
        && formPage.text.includes('name="title"')
        && formPage.text.includes('name="body_html"')
        && formPage.text.includes('name="co_name[]"')
        && formPage.text.includes('name="units[]"')
        && formPage.text.includes('name="contact_name"')
        && formPage.text.includes('name="contact_org"')
        && formPage.text.includes('name="contact_title"')
        && formPage.text.includes('name="contact_address"')
        && formPage.text.includes('name="contact_postcode"')
        && formPage.text.includes('name="contact_mobile"')
        && formPage.text.includes('name="attachments[]"'),
      "状态 " + formPage.status);
    check("正文字数上限按 2000 字提示，且给出需求原文的填写提示",
      formPage.text.includes("2000 字")
        && formPage.text.includes("提案一事一案，简明扼要，字数不超过 2000 字，否则无法上传，有关材料可作为附件提交"));
    check("承办单位下拉带出市直单位清单（7 个）",
      formPage.text.includes("河池市住房和城乡建设局")
        && formPage.text.includes("河池市卫生健康委员会")
        && formPage.text.includes("最多选 5 个"));
    check("承办单位是可搜索的下拉（原生多选作无脚本回退）",
      formPage.text.includes("data-unit-picker")
        && formPage.text.includes("data-unit-search")
        && formPage.text.includes("data-unit-native")
        && formPage.text.includes("/assets/unit-picker.js")
        && formPage.text.includes("/assets/unit-picker.css"));
    check("正文编辑器与勘误入口已挂上",
      formPage.text.includes("/assets/editor/suneditor.min.js")
        && formPage.text.includes("/assets/member-editor.js")
        && formPage.text.includes("/member/proposal/check-text"));

    const unitIds = [...formPage.text.matchAll(/<option value="(\d+)"[^>]*>\s*河池市/g)].map((m) => m[1]);
    check("能从页面取到承办单位编号（供下面提交用）", unitIds.length === 7, "取到 " + unitIds.length + " 个");

    const invalid = await memberA.post("/member/proposal/create", {
      _token: csrfToken(formPage.text),
      proposer_type: "personal",
      proposer_name: "张三",
      sector: "中国共产党",
      committee: "提案委员会",
      category: "经济建设",
      title: "",
      body_html: ""
    });
    check("必填项为空时留在表单页并给出提示",
      invalid.status === 400
        && invalid.text.includes("请填写案由")
        && invalid.text.includes("请填写提案内容")
        // 姓名／单位／电话会先带出委员自己的资料，缺的是剩下三项
        && invalid.text.includes("请填写提案办理联系人：职务、联系地址、邮政编码"),
      "状态 " + invalid.status);

    const longTitle = "案".repeat(51);
    const invalidTitle = await memberA.post("/member/proposal/create", {
      _token: csrfToken((await memberA.get("/member/proposal/new")).text),
      proposer_type: "collective",
      proposer_name: "张三",
      category: "社会建设",
      title: longTitle,
      body_html: "情况",
      contact_name: "张三",
      contact_org: "河池市某某局",
      contact_title: "科长",
      contact_address: "河池市宜州区某某路 1 号",
      contact_postcode: "547000",
      contact_mobile: "13800000001"
    });
    check("案由超过 50 字被挡下，且集体提案要求填写集体名称",
      invalidTitle.status === 400
        && invalidTitle.text.includes("案由不能超过 50 个字")
        && invalidTitle.text.includes("集体提案请填写提出单位或界别名称"));

    const baseFields = {
      proposer_type: "personal",
      proposer_name: "张三",
      sector: "中国共产党",
      committee: "提案委员会",
      category: "经济建设",
      contact_name: "张三",
      contact_org: "河池市某某局",
      contact_title: "科长",
      contact_address: "河池市宜州区某某路 1 号",
      contact_postcode: "547000",
      contact_mobile: "13800000001"
    };
    const postCreate = (fields) => memberA.post("/member/proposal/create", {
      _token: formFieldToken,
      ...baseFields,
      ...fields
    });
    let formFieldToken = csrfToken((await memberA.get("/member/proposal/new")).text);

    const badPostcode = await postCreate({ title: "测试邮编", body_html: "正文", contact_postcode: "54700" });
    check("邮政编码不是 6 位数字被挡下",
      badPostcode.status === 400 && badPostcode.text.includes("邮政编码请填 6 位数字"));

    const overLimit = await postCreate({ title: "测试超字数", body_html: "字".repeat(2001) });
    check("正文 2001 字被挡下并报出实际字数",
      overLimit.status === 400 && overLimit.text.includes("提案内容 2001 字")
        && overLimit.text.includes("超出 2000 字上限"), "状态 " + overLimit.status);

    // 边界：正好 2000 字不算超（这一条故意不给案由，只用来验证字数不再报错）
    formFieldToken = csrfToken((await memberA.get("/member/proposal/new")).text);
    const atLimit = await postCreate({ title: "", body_html: "字".repeat(2000) });
    check("正文正好 2000 字不再报超限",
      atLimit.status === 400
        && atLimit.text.includes("请填写案由")
        && !atLimit.text.includes("超出 2000 字上限"), "状态 " + atLimit.status);

    const jointNoMember = await postCreate({ title: "测试联名", body_html: "正文", proposer_type: "joint" });
    check("选联名但没填联名委员被挡下",
      jointNoMember.status === 400 && jointNoMember.text.includes("联名提案请至少填写一位联名委员的资料"));

    formFieldToken = csrfToken((await memberA.get("/member/proposal/new")).text);
    const jointNoName = await memberA.post("/member/proposal/create", {
      _token: formFieldToken,
      ...baseFields,
      proposer_type: "joint",
      title: "测试联名缺姓名",
      body_html: "正文",
      "co_name[]": "",
      "co_org[]": "河池市某某公司",
      "co_mobile[]": "13900000000"
    });
    check("联名委员只填了单位没填姓名被挡下",
      jointNoName.status === 400 && jointNoName.text.includes("联名委员请填写姓名（第 1 位）"));

    formFieldToken = csrfToken((await memberA.get("/member/proposal/new")).text);
    const tooManyUnits = await memberA.post("/member/proposal/create", {
      _token: formFieldToken,
      ...baseFields,
      title: "测试六个承办单位",
      body_html: "正文",
      "units[]": unitIds.slice(0, 6)
    });
    check("建议承办单位选到 6 个被挡下",
      tooManyUnits.status === 400 && tooManyUnits.text.includes("建议承办单位最多选 5 个"));

    formFieldToken = csrfToken((await memberA.get("/member/proposal/new")).text);
    const unknownUnit = await memberA.post("/member/proposal/create", {
      _token: formFieldToken,
      ...baseFields,
      title: "测试无效承办单位",
      body_html: "正文",
      "units[]": ["999999"]
    });
    check("选了清单里没有的承办单位被挡下",
      unknownUnit.status === 400 && unknownUnit.text.includes("已停用或不存在"));

    // 名册检索：联名委员带出用
    const roster = await memberA.get("/member/roster?keyword=" + encodeURIComponent("李四"));
    check("名册检索接口按姓名带出单位职务与电话",
      roster.status === 200
        && roster.headers.get("content-type").includes("application/json")
        && roster.text.includes('"name":"李四"')
        && roster.text.includes("河池市某某公司总经理")
        && roster.text.includes("13800000002"),
      "状态 " + roster.status);
    const rosterEmpty = await memberA.get("/member/roster?keyword=");
    check("名册检索不给关键词时不返回名册内容", rosterEmpty.text.includes('"items":[]'));
    const rosterGuest = await guest.get("/member/roster?keyword=" + encodeURIComponent("李四"));
    check("未登录不能检索委员名册", rosterGuest.text.includes("请先登录"));

    // 错别字勘误：只预留接口
    formFieldToken = csrfToken((await memberA.get("/member/proposal/new")).text);
    const proofread = await memberA.post("/member/proposal/check-text", {
      _token: formFieldToken,
      return_to: "/member/proposal/new",
      body_html: "<p>正文有一处错别字</p>"
    });
    const proofreadFlash = await memberA.get("/member/proposal/new");
    check("错别字勘误走了预留接口并提示待接入",
      proofread.status === 302
        && (proofread.headers.get("location") || "") === "/member/proposal/new"
        && proofreadFlash.text.includes("错别字勘误功能待接入"), "状态 " + proofread.status);

    const title = "关于完善城区老旧小区充电设施的建议";
    const created = await memberA.upload("/member/proposal/create", {
      _token: csrfToken((await memberA.get("/member/proposal/new")).text),
      ...baseFields,
      title,
      // 正文带加粗、下划线与列表；另外塞进脚本标签、事件属性与越界样式，验证清洗
      body_html: '<p>城区老旧小区电动自行车充电设施不足。</p><p>既有线路与场地条件受限。</p>'
        + '<p><strong>由住建部门牵头</strong>，<u>分批加装集中充电棚</u>。</p>'
        + '<ul><li>第一批 20 个小区</li></ul><script>alert(1)</script>'
        + '<p style="position:fixed" onclick="alert(2)">建议分批推进。</p>',
      "units[]": unitIds.slice(0, 2),
      "co_name[]": "李四",
      "co_org[]": "河池市某某公司总经理",
      "co_mobile[]": "13800000002"
    }, [
      { field: "attachments[]", filename: "调研底稿.txt", content: Buffer.from("附件内容：调研底稿", "utf8"), type: "text/plain" }
    ]);
    const location = created.headers.get("location") || "";
    const proposalId = (/\/member\/proposal\/(\d+)/.exec(location) || [])[1] || "";
    check("提交成功后跳到提案详情",
      created.status === 302 && proposalId !== "", "状态 " + created.status + " → " + location);

    const detail = await memberA.get("/member/proposal/" + proposalId);
    check("详情页显示已提交、合并后的正文、联名委员与承办单位",
      detail.status === 200
        && detail.text.includes("已提交")
        && detail.text.includes("提案内容")
        && detail.text.includes("河池市住房和城乡建设局")
        && detail.text.includes("李四")
        && detail.text.includes("调研底稿.txt"),
      "状态 " + detail.status);
    check("富文本保留加粗与下划线，脚本、事件属性与内联样式被清洗",
      detail.text.includes("<strong>由住建部门牵头</strong>")
        && detail.text.includes("<u>分批加装集中充电棚</u>")
        && detail.text.includes("<li>第一批 20 个小区</li>")
        && !detail.text.includes("<script")
        && !detail.text.includes("onclick")
        && !detail.text.includes("position:fixed"));
    check("办理联系人六项在详情页显示",
      detail.text.includes("河池市某某局") && detail.text.includes("科长")
        && detail.text.includes("547000") && detail.text.includes("河池市宜州区某某路 1 号"));

    const editFormPrefill = await memberA.get("/member/proposal/" + proposalId + "/edit");
    check("非退回状态不能进编辑页", editFormPrefill.status === 302);

    const newProposalForm = await memberA.get("/member/proposal/new");
    check("办理联系人填过一次后，下次填提案自动带出",
      newProposalForm.text.includes('value="河池市某某局"')
        && newProposalForm.text.includes('value="547000"')
        && newProposalForm.text.includes('value="河池市宜州区某某路 1 号"'));

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
    check("后台详情页带出正文、承办单位、联名委员与办理联系人",
      adminDetail.status === 200
        && adminDetail.text.includes("城区老旧小区电动自行车充电设施不足")
        && adminDetail.text.includes("河池市住房和城乡建设局")
        && adminDetail.text.includes("河池市某某局")
        && adminDetail.text.includes("李四")
        && adminDetail.text.includes("13800000001"),
      "状态 " + adminDetail.status);
    check("后台详情页给出「调整提案」表单（受理前可改）",
      adminDetail.text.includes("调整提案")
        && adminDetail.text.includes('id="proposal-edit-form"')
        && adminDetail.text.includes('name="body_html"')
        && adminDetail.text.includes("/assets/admin-proposal-editor.js"));
    check("后台改稿的承办单位同样是可搜索下拉",
      adminDetail.text.includes("data-unit-picker")
        && adminDetail.text.includes("data-unit-native")
        && adminDetail.text.includes("/assets/unit-picker.js"));

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
      ...baseFields,
      title,
      body_html: "<p>城区老旧小区电动自行车充电设施不足，消防隐患突出。</p><p>建议分批加装集中充电棚，资金来源由财政与物业共担。</p>",
      "units[]": unitIds.slice(0, 1),
      "co_name[]": "",
      "co_org[]": "",
      "co_mobile[]": ""
    });
    check("修改后重新提交成功", resubmit.status === 302 && (resubmit.headers.get("location") || "").includes("/member/proposal/" + proposalId));
    const afterResubmitDetail = await memberA.get("/member/proposal/" + proposalId);
    check("重交后承办单位与联名委员按新内容更新",
      afterResubmitDetail.text.includes("河池市住房和城乡建设局")
        && afterResubmitDetail.text.includes("河池市教育局") === false
        && afterResubmitDetail.text.includes("李四") === false);

    // ---------- 五之二、提案委调整提案（留痕 + 委员端可见） ----------
    const editPageBefore = await admin.get("/admin/proposal/" + proposalId);
    const editToken = csrfToken(editPageBefore.text);
    const adminEdit = await admin.post("/admin/proposal/" + proposalId + "/edit", {
      _token: editToken,
      title,
      category: "经济建设",
      collective_name: "",
      body_html: "<p>城区老旧小区电动自行车充电设施不足，消防隐患突出。</p>"
        + "<p><strong>提案委补充：</strong>建议先<u>做摸底台账</u>再分批实施。</p>",
      "units[]": unitIds.slice(0, 2),
      "co_name[]": "",
      "co_org[]": "",
      "co_mobile[]": "",
      contact_name: "张三",
      contact_org: "河池市某某局",
      contact_title: "科长",
      contact_address: "河池市宜州区某某路 1 号",
      contact_postcode: "547000",
      contact_mobile: "13800000001"
    });
    const afterAdminEdit = await admin.get("/admin/proposal/" + proposalId);
    check("提案委保存调整后给出改动清单",
      adminEdit.status === 302
        && afterAdminEdit.text.includes("已保存调整")
        && afterAdminEdit.text.includes("正文"),
      "状态 " + adminEdit.status);
    check("后台能看到内容调整记录与调整时间",
      afterAdminEdit.text.includes("内容调整记录") && afterAdminEdit.text.includes("内容调整"));
    check("调整后的承办单位已生效",
      afterAdminEdit.text.includes("河池市住房和城乡建设局") && afterAdminEdit.text.includes("河池市教育局"));

    const memberAfterAdminEdit = await memberA.get("/member/proposal/" + proposalId);
    check("委员端提示「提案委已对内容作了调整」并看到调整后正文",
      memberAfterAdminEdit.text.includes("提案委已对内容作了调整")
        && memberAfterAdminEdit.text.includes("提案委补充："));

    const noChangeEdit = await admin.post("/admin/proposal/" + proposalId + "/edit", {
      _token: csrfToken(afterAdminEdit.text),
      title,
      category: "经济建设",
      collective_name: "",
      body_html: "<p>城区老旧小区电动自行车充电设施不足，消防隐患突出。</p>"
        + "<p><strong>提案委补充：</strong>建议先<u>做摸底台账</u>再分批实施。</p>",
      "units[]": unitIds.slice(0, 2),
      "co_name[]": "",
      "co_org[]": "",
      "co_mobile[]": "",
      contact_name: "张三",
      contact_org: "河池市某某局",
      contact_title: "科长",
      contact_address: "河池市宜州区某某路 1 号",
      contact_postcode: "547000",
      contact_mobile: "13800000001"
    });
    const afterNoChange = await admin.get("/admin/proposal/" + proposalId);
    check("内容没变时不写留痕（提示「没有改动」）",
      noChangeEdit.status === 302 && afterNoChange.text.includes("没有改动"),
      "状态 " + noChangeEdit.status);

    const postcodeEdit = await admin.post("/admin/proposal/" + proposalId + "/edit", {
      _token: csrfToken(afterNoChange.text),
      title,
      category: "经济建设",
      body_html: "<p>正文</p>",
      contact_postcode: "5470"
    });
    const afterPostcodeEdit = await admin.get("/admin/proposal/" + proposalId);
    check("后台改稿的邮政编码同样按 6 位校验",
      postcodeEdit.status === 302 && afterPostcodeEdit.text.includes("邮政编码请填 6 位数字"));

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

    // 第二个委员也交一份，供下面验证批量导出装订两件提案
    const secondForm = await memberB.get("/member/proposal/new");
    const secondCreated = await memberB.post("/member/proposal/create", {
      _token: csrfToken(secondForm.text),
      proposer_type: "personal",
      proposer_name: "李四",
      sector: "经济界",
      committee: "经济委员会",
      category: "经济建设",
      title: "关于优化工业园区物流通道的建议",
      body_html: "<p>工业园区货运通道拥堵。</p><p>建议错峰并拓宽出口。</p>",
      contact_name: "李四",
      contact_org: "河池市某某公司",
      contact_title: "总经理",
      contact_address: "河池市金城江区某某路 8 号",
      contact_postcode: "547000",
      contact_mobile: "13800000002",
      "units[]": unitIds.slice(2, 3)
    });
    const secondId = (/\/member\/proposal\/(\d+)/.exec(secondCreated.headers.get("location") || "") || [])[1] || "";
    check("第二个委员也能提交提案（批量导出要用两件）",
      secondCreated.status === 302 && secondId !== "" && secondId !== proposalId,
      "状态 " + secondCreated.status);

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
    check("收件清单带上联名委员、办理联系人与建议承办单位三列",
      sheet.includes("联名委员") && sheet.includes("办理联系人")
        && sheet.includes("建议承办单位") && sheet.includes("河池市住房和城乡建设局"));

    const word = await admin.get("/admin/proposal/" + proposalId + "/word", { binary: true });
    const documentXml = word.status === 200 ? unzipPart(word.buffer, "word/document.xml", tmpRoot, "proposal.docx") : "";
    check("单件 Word 提案表可解析：正文、承办单位、办理联系人与意见齐全",
      word.status === 200
        && documentXml.includes("关于完善城区老旧小区充电设施的建议")
        && documentXml.includes("提案内容")
        && documentXml.includes("河池市住房和城乡建设局")
        && documentXml.includes("提案办理联系人")
        // 下划线部分自成一个运行，XML 里不会连成整句
        && documentXml.includes("提案委补充：")
        && documentXml.includes("做摸底台账")
        && documentXml.includes("予以立案"),
      "状态 " + word.status);
    check("Word 导出带政务排版设置（A4、宋体、固定行距 28 磅）",
      documentXml.includes('w:w="11906"') && documentXml.includes('w:eastAsia="宋体"') && documentXml.includes('w:line="560"'));
    check("Word 里保留正文的加粗与下划线",
      documentXml.includes("<w:b/>") && documentXml.includes('<w:u w:val="single"/>'));

    const batchWord = await admin.get("/admin/proposals/export.docx", { binary: true });
    const batchXml = batchWord.status === 200
      ? unzipPart(batchWord.buffer, "word/document.xml", tmpRoot, "proposals.docx") : "";
    check("批量导出把两件提案装进同一个 Word",
      batchWord.status === 200
        && batchXml.includes("关于完善城区老旧小区充电设施的建议")
        && batchXml.includes("关于优化工业园区物流通道的建议"),
      "状态 " + batchWord.status);
    check("批量导出每件提案独立起页",
      (batchXml.match(/w:br w:type="page"/g) || []).length >= 1);
    check("批量导出的文件名带「提案汇总」",
      decodeURIComponent(batchWord.headers.get("content-disposition") || "").includes("提案汇总"));

    const memberWord = await memberA.get("/member/proposal/" + proposalId + "/word", { binary: true });
    const memberDoc = memberWord.status === 200 ? unzipPart(memberWord.buffer, "word/document.xml", tmpRoot, "member.docx") : "";
    check("委员端可下载自己提案的 Word 版",
      memberWord.status === 200 && memberDoc.includes("关于完善城区老旧小区充电设施的建议"));

    // ---------- 八、批量重置密码 ----------
    const membersPage = await admin.get("/admin/members");
    const memberIds = [...new Set([...membersPage.text.matchAll(/name="member_ids\[\]" value="(\d+)"/g)].map((m) => m[1]))];
    check("委员管理页给出勾选框与批量重置入口",
      memberIds.length >= 3
        && membersPage.text.includes("/admin/members/reset-batch")
        && membersPage.text.includes("重置登录密码"),
      "取到 " + memberIds.length + " 个账号");

    const resetBatchToken = csrfToken(membersPage.text);
    const emptyBatch = await admin.post("/admin/members/reset-batch", { _token: resetBatchToken, action: "reset" });
    const afterEmptyBatch = await admin.get("/admin/members");
    check("没勾选任何账号时批量重置被挡下",
      emptyBatch.status === 302 && afterEmptyBatch.text.includes("请先勾选要重置密码的委员"));

    const targetIds = memberIds.slice(0, 2);
    const batchReset = await admin.post("/admin/members/reset-batch", {
      _token: csrfToken(afterEmptyBatch.text),
      action: "reset",
      "member_ids[]": targetIds
    });
    const afterBatchReset = await admin.get("/admin/members");
    check("批量重置给出结果并提示下载密码清单",
      batchReset.status === 302
        && afterBatchReset.text.includes("已重置 2 个账号的密码")
        && afterBatchReset.text.includes("/admin/members/batch-credentials.csv"),
      "状态 " + batchReset.status);

    const batchCredentials = await admin.get("/admin/members/batch-credentials.csv");
    const batchPasswords = [...batchCredentials.text.matchAll(/,([A-Za-z0-9]{8,})\r?\n/g)].map((m) => m[1]);
    const uniquePasswords = new Set(batchPasswords);
    const batchMemberAPw = (batchCredentials.text.split(/\r?\n/).find((line) => line.startsWith("张三,")) || "").split(",")[2] || "";
    check("批量重置的密码清单每人一个不同密码且可下载",
      batchCredentials.status === 200
        && batchCredentials.text.includes("姓名,登录名,新密码")
        && batchPasswords.length === 2
        && uniquePasswords.size === 2,
      "状态 " + batchCredentials.status + "，密码 " + batchPasswords.length + " 条");

    const batchCredentialsAgain = await admin.get("/admin/members/batch-credentials.csv");
    const afterBatchDownload = await admin.get("/admin/members");
    check("批量密码清单只给一次：第二次被挡回，页面也不再提示",
      batchCredentialsAgain.status === 302
        && (batchCredentialsAgain.headers.get("location") || "").includes("/admin/members")
        && !afterBatchDownload.text.includes("有 2 个账号的密码已重置"),
      "状态 " + batchCredentialsAgain.status);

    // ---------- 九、停用账号后无法登录 ----------
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
        password: batchMemberAPw
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
