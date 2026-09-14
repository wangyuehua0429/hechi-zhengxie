#!/usr/bin/env node
/**
 * 旧库迁移检查（阶段 D）：用一份小样本旧库 fixture 跑完整两段式流程。
 *
 *   1. legacy_extract.py parse  → 口径筛选、映射报表、未映射清单、媒体清单
 *   2. 手工把媒体清单标成“已抓取”（不联网）+ legacy_extract.py render → 清洗与地址改写
 *   3. legacy_import.php --dry-run / --commit → 入库、栏目归属、图集、附件、次要表、幂等
 *
 * 用法：
 *   node tests/migrate-check.mjs          # 全自动（需要本机有 php 与 python3）
 *   node tests/migrate-check.mjs --keep   # 保留临时目录便于排查
 */

import { spawnSync } from "node:child_process";
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { gzipSync } from "node:zlib";

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
  for (const bin of ["php", "/opt/homebrew/bin/php", "/usr/local/bin/php", "/usr/bin/php"]) {
    const probe = spawnSync(bin, ["-v"], { encoding: "utf8" });
    if (!probe.error && probe.status === 0) return bin;
  }
  return null;
}

function resolvePython() {
  const candidates = [
    process.env.HOME + "/py-tools/bin/python",
    "python3",
    "/usr/bin/python3",
  ];
  for (const bin of candidates) {
    const probe = spawnSync(bin, ["-c", "import sys; print(sys.version)"], { encoding: "utf8" });
    if (!probe.error && probe.status === 0) return bin;
  }
  return null;
}

function run(bin, args, env = {}) {
  return spawnSync(bin, args, {
    cwd: REPO,
    env: { ...process.env, ...env },
    encoding: "utf8",
  });
}

function phpEval(php, env, code) {
  const result = run(php, ["-r", code], env);
  return (result.stdout || "").trim();
}

/** 小样本旧库的 rd_news 行：覆盖主站口径内/外、已审/未审、新老年限、领导、未映射、县区 */
const FIXTURE_NEWS_ROWS = [
  "(9001, '市政协开展专题协商', '导语一句', NULL, 'http://gxhczx.gov.cn/uploadfiles/20260101/9001.jpg', '<div><span style=\"font-size:14px\"><font color=\"red\">正文第一段</font></span></div><p>&nbsp;</p><p><img src=\"http://gxhczx.gov.cn/uploadfiles/20260101/9001-1.jpg\" width=\"600\"></p><p><a href=\"http://gxhczx.gov.cn/uploadfiles/20260101/9001.docx\">附件下载</a></p>', 1, 22, 1, 1, 904, 128, 0, 1767225600, '河池日报 2026/1/2 1版', '张三', '李四')",
  "(9002, '早年的一篇稿件', '', NULL, '', '<p>旧稿正文</p>', 1, 22, 0, 1, 904, 20, 0, 1588320000, '本站', '', '')",
  "(9003, '未审稿件', '', NULL, '', '<p>未审正文</p>', 0, 22, 0, 0, 904, 3, 0, 1748736000, '本站', '', '')",
  "(9004, '全国政协要闻', '', NULL, '', '<p>区外稿件</p>', 1, 55, 0, 0, 902, 55, 0, 1767225600, '全国政协网', '', '')",
  "(9005, '口径不符的 902 稿', '', NULL, '', '<p>不应入库</p>', 1, 22, 0, 0, 902, 5, 0, 1767225600, '本站', '', '')",
  "(9006, '未映射栏目稿件', '', NULL, '', '<p>待甲方确认栏目</p>', 1, 22, 0, 0, 7101, 7, 0, 1767225600, '本站', '', '')",
  "(9007, '县区稿件', '', NULL, '', '<p>县区内容本期不迁</p>', 1, 3, 0, 0, 306, 9, 0, 1767225600, '本站', '', '')",
  "(9008, '韦某某', '副主席', NULL, '', '<p>领导简介正文</p>', 1, 22, 0, 1, 202, 66, 3, 1767225600, '本站', '', '副主席')",
];

const FIXTURE_SIDE_SQL = `INSERT INTO \`rd_video\` VALUES (1, '专题片：协商在河池', '', 1, 0, 1, 22, '', 'http://player.youku.com/embed/abc');
INSERT INTO \`rd_links\` VALUES (1, '中国政协', 'http://www.cppcc.gov.cn/', '', 1, 1, 22);
INSERT INTO \`rd_hudong\` VALUES (5, '互动测试来信', '', '', '', 1, 55, 0, 0, 4122, 1, 0, 1495005926, '', '', '', '我最爱互动', 0, '小白', 1495005926, '群众来信内容', 1495041926, '办理回复内容', '', 0, '', 0, '', 0, '', '', 0);
`;

/** 小样本旧库（Navicat 风格：一条语句一行、一行一个元组） */
const FIXTURE_SQL = `-- 迁移检查用小样本（结构与 Navicat 导出一致）
${FIXTURE_NEWS_ROWS.map((row) => `INSERT INTO \`rd_news\` VALUES ${row};`).join("\n")}
${FIXTURE_SIDE_SQL}`;

const FIXTURE_CHANNELS = {
  channels: [
    { type: "904", name: "政协动态", inner: "市政协动态", slug: "zhengxie-dongtai" },
    { type: "902", name: "政协动态", inner: "全国政协动态", slug: "zhengxie-dongtai" },
    { type: "306", name: "时政要闻", inner: "时政要闻", slug: "shizheng-yaowen" },
    { type: "202", name: "政协概况", inner: "政协领导", slug: "zhengxie-gaikuang" },
    { type: "interactive", name: "互动交流", inner: "互动交流", slug: "interactive" },
  ],
};

function main() {
  const keep = process.argv.includes("--keep");
  const php = resolvePhp();
  const python = resolvePython();
  if (!php || !python) {
    console.error("需要本机有 php 与 python3（或 ~/py-tools/bin/python）才能跑迁移检查。");
    return 2;
  }

  const tmpRoot = mkdtempSync(path.join(tmpdir(), "hechi-migrate-check-"));
  const outDir = path.join(tmpRoot, "out");
  const sqlFile = path.join(tmpRoot, "legacy.sql");
  const channelFile = path.join(tmpRoot, "channel.json");
  const dbFile = path.join(tmpRoot, "check.sqlite");
  const env = { DB_DRIVER: "sqlite", DB_DATABASE: dbFile, APP_ENV: "local", APP_DEBUG: "1" };

  try {
    mkdirSync(outDir, { recursive: true });
    writeFileSync(sqlFile, FIXTURE_SQL);
    writeFileSync(channelFile, JSON.stringify(FIXTURE_CHANNELS, null, 2));

    // ---- 1) parse
    const parsed = run(python, [
      "tools/migrate/legacy_extract.py", "parse",
      "--sql", sqlFile, "--out", outDir, "--channels", channelFile,
    ]);
    check("parse 执行成功", parsed.status === 0, (parsed.stderr || parsed.stdout || "").trim().split("\n")[0]);
    const stats = JSON.parse(readFileSync(path.join(outDir, "stats.json"), "utf8"));
    check("口径筛选：范围内 5 篇（904×3、902×1、202×1）",
      stats.in_scope === 5 && stats.published_public === 3 && stats.published_archive === 1 && stats.draft === 1,
      JSON.stringify({ in_scope: stats.in_scope, pub: stats.published_public, arc: stats.published_archive, draft: stats.draft }));
    check("未映射 Type 只进报表（1 篇）", stats.unmapped_main === 1, String(stats.unmapped_main));
    check("县区与口径不符的稿件跳过（2 篇）", stats.county_skipped === 2, String(stats.county_skipped));
    check("公开年限切分点为 2023-11-20", stats.public_cutoff === "2023-11-20", stats.public_cutoff);

    const unmappedCsv = readFileSync(path.join(outDir, "unmapped.csv"), "utf8");
    check("未映射清单写出旧栏目号与稿件号",
      unmappedCsv.includes("7101") && unmappedCsv.includes("9006") && !unmappedCsv.includes("9007"));
    const mappingCsv = readFileSync(path.join(outDir, "mapping_report.csv"), "utf8");
    check("映射报表按栏目给出条数与 Region 口径",
      mappingCsv.includes("904") && mappingCsv.includes("902") && mappingCsv.includes("55") && mappingCsv.includes("202"));

    // ---- 1b) 多行 INSERT + gzip（宝塔每日备份的写法）＋ 删除同步清单
    const gzRows = FIXTURE_NEWS_ROWS.filter((row) => !row.startsWith("(9003,"));
    const gzSql = "-- 多行 INSERT 样本（结构与宝塔 mysqldump 一致）\n"
      + `INSERT INTO \`rd_news\` VALUES ${gzRows.slice(0, 4).join(",")};\n`
      + `INSERT INTO \`rd_news\` VALUES ${gzRows.slice(4).join(",")};\n`;
    const gzFile = path.join(tmpRoot, "legacy-v2.sql.gz");
    const gzOut = path.join(tmpRoot, "out-v2");
    mkdirSync(gzOut, { recursive: true });
    writeFileSync(gzFile, gzipSync(Buffer.from(gzSql, "utf8")));

    const gzParsed = run(python, [
      "tools/migrate/legacy_extract.py", "parse",
      "--sql", gzFile, "--out", gzOut, "--channels", channelFile,
      "--expect-rows", String(gzRows.length), "--expect-max-id", "9008",
      "--deleted-from", sqlFile,
    ]);
    const gzStats = JSON.parse(readFileSync(path.join(gzOut, "stats.json"), "utf8"));
    check("多行 INSERT + gzip：解析行数与最大稿件号自检通过",
      gzParsed.status === 0 && gzStats.old_news_rows === gzRows.length && gzStats.max_news_id === 9008,
      JSON.stringify({ rows: gzStats.old_news_rows, max: gzStats.max_news_id }));
    check("多行 INSERT + gzip：口径筛选与单行导出一致（范围内 4 篇）",
      gzStats.in_scope === 4 && gzStats.published_public === 3 && gzStats.published_archive === 1 && gzStats.draft === 0,
      JSON.stringify({ in_scope: gzStats.in_scope, pub: gzStats.published_public, arc: gzStats.published_archive }));
    const deletedIds = readFileSync(path.join(gzOut, "deleted_ids.txt"), "utf8").trim().split("\n");
    const deletedSummary = readFileSync(path.join(gzOut, "deleted_summary.txt"), "utf8");
    check("删除同步：旧站已删稿件进清单、范围内稿件不在清单里",
      deletedIds.length === 1 && deletedIds[0] === "9003" && !deletedIds.includes("9001"),
      deletedIds.join(","));
    check("删除同步：分类小计写出各类条数",
      gzStats.deleted_total === 1 && gzStats.deleted_draft === 1 && gzStats.deleted_public === 0
      && deletedSummary.includes("范围内-草稿（draft）：1 篇"),
      deletedSummary.split("\n")[2] || "");
    const badExpect = run(python, [
      "tools/migrate/legacy_extract.py", "parse",
      "--sql", gzFile, "--out", gzOut, "--channels", channelFile, "--expect-rows", "99",
    ]);
    check("自检：行数对不上时退出码非 0 并给出原因",
      badExpect.status !== 0 && (badExpect.stderr || "").includes("自检失败"), String(badExpect.status));

    // ---- 2) 模拟抓图成功：把清单标成 ok，并落一个空文件（不联网）
    const manifestPath = path.join(outDir, "media_manifest.csv");
    const csvLines = readFileSync(manifestPath, "utf8").trim().split("\n");
    check("媒体清单列出正文图片、缩略图与附件（≥3 条）",
      csvLines.length - 1 >= 3 && csvLines.some((l) => l.includes("9001-1.jpg"))
      && csvLines.some((l) => l.includes("9001.docx")),
      String(csvLines.length - 1) + " 条");
    const header = csvLines[0];
    const filled = [header];
    for (const line of csvLines.slice(1)) {
      const url = line.split(",")[0];
      const target = line.split(",")[1];
      const abs = path.join(REPO, target);
      mkdirSync(path.dirname(abs), { recursive: true });
      writeFileSync(abs, "fake-image");
      filled.push([url, target, "ok", "10", "deadbeef", ""].join(","));
    }
    writeFileSync(manifestPath, filled.join("\n") + "\n");

    const rendered = run(python, [
      "tools/migrate/legacy_extract.py", "render",
      "--in", path.join(outDir, "articles.jsonl"), "--out", outDir, "--manifest", manifestPath,
    ]);
    check("render 执行成功", rendered.status === 0, (rendered.stderr || rendered.stdout || "").trim().split("\n")[0]);

    const finalPath = path.join(outDir, "articles.final.jsonl");
    const rows = readFileSync(finalPath, "utf8").trim().split("\n").map((line) => JSON.parse(line));
    const byId = new Map(rows.map((row) => [row.article_id, row]));
    const first = byId.get(9001);
    check("正文清洗：去掉了 font/span/style", first && !/font|span|style=/i.test(first.content_html), (first?.content_html || "").slice(0, 80));
    check("正文图片改写为站内抓取地址",
      first && first.content_html.includes("src=\"/uploads/legacy/uploadfiles/20260101/9001-1.jpg\""),
      (first?.content_html || "").slice(0, 160));
    check("图片按出现顺序登记为图集", first && first.images.length === 1 && first.images[0].startsWith("/uploads/legacy/"));
    check("正文附件登记为附件下载项", first && first.attachments.length === 1 && first.attachments[0].ext === "docx");
    check("来源字段去掉日期版面", first && first.source === "河池日报", first?.source);
    check("公开范围按年限切分",
      byId.get(9001).public_scope === "public" && byId.get(9002).public_scope === "archive");
    check("未审稿件进草稿箱", byId.get(9003).status === "draft" && byId.get(9003).public_scope === "public");
    check("领导稿：Title1 落 role、Order 落 sort_no",
      byId.get(9008).role === "副主席" && byId.get(9008).sort_no === 3 && byId.get(9008).summary === "");
    check("非领导稿：Title1 落 summary",
      byId.get(9001).summary === "导语一句" && byId.get(9001).role === "");

    // ---- 2b) 离线核对（改从服务器拷文件时的用法，不联网）
    const verifyAll = run(python, ["tools/migrate/fetch_media.py", "--manifest", manifestPath, "--verify"]);
    check("离线核对：本地已有的文件标为已就绪",
      verifyAll.status === 0 && (verifyAll.stdout || "").includes("本地已有 3 个、缺 0 个"),
      (verifyAll.stdout || "").trim());
    const firstTarget = path.join(REPO, csvLines[1].split(",")[1]);
    rmSync(firstTarget, { force: true });
    const verifyPartial = run(python, ["tools/migrate/fetch_media.py", "--manifest", manifestPath, "--verify"]);
    check("离线核对：缺文件时留 pending 并给出原因",
      verifyPartial.status === 0 && (verifyPartial.stdout || "").includes("本地已有 2 个、缺 1 个")
      && readFileSync(manifestPath, "utf8").includes("本地没有该文件"),
      (verifyPartial.stdout || "").trim());
    writeFileSync(firstTarget, "fake-image");
    run(python, ["tools/migrate/fetch_media.py", "--manifest", manifestPath, "--verify"]);

    // ---- 3) 入库（临时 SQLite）
    const migrated = run(php, ["backend/bin/migrate.php"], env);
    const seeded = run(php, ["backend/bin/seed.php"], env);
    check("临时库建表与灌入样例数据", migrated.status === 0 && seeded.status === 0,
      (migrated.stderr || seeded.stderr || "").split("\n")[0]);

    const dry = run(php, ["tools/migrate/legacy_import.php", "--in", finalPath, "--dry-run", "--out", outDir], env);
    check("--dry-run 校验通过并给出报告",
      dry.status === 0 && (dry.stdout || "").includes("可入库 5 篇") && (dry.stdout || "").includes("干跑模式未写库"),
      (dry.stdout || "").split("\n")[1] || "");
    check("干跑不写库", phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " echo (int) $db->scalar('SELECT COUNT(*) FROM cms_article WHERE article_id IN (9001,9002,9003,9004,9008)');") === "0");

    const commit = run(php, ["tools/migrate/legacy_import.php", "--in", finalPath, "--commit", "--out", outDir], env);
    check("--commit 入库成功", commit.status === 0, (commit.stderr || commit.stdout || "").trim().split("\n").slice(-1)[0]);

    const readOne = (id, column) => phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + ` echo (string) $db->scalar("SELECT ${column} FROM cms_article WHERE article_id = ${id}");`);
    check("入库字段正确（标题/时间/浏览量）",
      readOne(9001, "title") === "市政协开展专题协商" && readOne(9001, "published_at") === "2026-01-01 08:00:00"
      && readOne(9001, "views") === "128",
      readOne(9001, "published_at"));
    check("入库正文已过清洗出口（无 font/style）",
      !/font|style=/i.test(readOne(9001, "content_html")), readOne(9001, "content_html").slice(0, 60));
    check("归档与草稿状态入库正确",
      readOne(9002, "public_scope") === "archive" && readOne(9003, "status") === "draft");
    check("栏目归属写成主归属", phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " echo (int) $db->scalar(\"SELECT is_primary FROM cms_article_channel WHERE article_id = 9001 AND channel_type = '904'\");") === "1");
    check("图集与附件登记入库", phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " echo (int) $db->scalar('SELECT COUNT(*) FROM cms_article_image WHERE article_id = 9001') . '/' ."
      + " (int) $db->scalar('SELECT COUNT(*) FROM cms_attachment WHERE article_id = 9001');") === "1/1");
    check("次要表：视频与友情链接合并进首页整块",
      phpEval(php, env,
        "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
        + " $v = (string) $db->scalar(\"SELECT payload_json FROM cms_home_block WHERE block_key = 'videos'\");"
        + " $l = (string) $db->scalar(\"SELECT payload_json FROM cms_home_block WHERE block_key = 'links'\");"
        + " echo (strpos($v, 'youku') !== false ? 'v' : '-') . (strpos($l, 'cppcc.gov.cn') !== false ? 'l' : '-');") === "vl");
    check("次要表：互动条目入库且按年限进 archive",
      readOne(5, "channel_type") === "interactive" && readOne(5, "public_scope") === "archive");
    check("操作日志记录迁移动作", phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " echo (int) $db->scalar(\"SELECT COUNT(*) FROM sys_operation_log WHERE action = 'migrate.legacy'\");") === "1");
    check("写出回滚清单与报告",
      existsSync(path.join(outDir, "imported_ids.txt"))
      && readFileSync(path.join(outDir, "imported_ids.txt"), "utf8").trim().split("\n").length === 5
      && readFileSync(path.join(outDir, "report.txt"), "utf8").includes("旧库迁移入库报告"));

    // ---- 幂等：再跑一次，条数不变
    const again = run(php, ["tools/migrate/legacy_import.php", "--in", finalPath, "--commit", "--out", outDir], env);
    check("重复执行仍成功", again.status === 0);
    check("重复执行不产生重复数据", phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " echo (int) $db->scalar('SELECT COUNT(*) FROM cms_article WHERE article_id IN (9001,9002,9003,9004,9008)') . '/' ."
      + " (int) $db->scalar(\"SELECT COUNT(*) FROM cms_article_channel WHERE article_id IN (9001,9002,9003,9004,9008)\");") === "5/5");

    // ---- 校验拦截：栏目不存在时整批拒绝
    const badFile = path.join(tmpRoot, "bad.jsonl");
    const badRow = JSON.parse(readFileSync(finalPath, "utf8").split("\n")[0]);
    badRow.channel_type = "no-such-channel";
    writeFileSync(badFile, JSON.stringify(badRow) + "\n");
    const bad = run(php, ["tools/migrate/legacy_import.php", "--in", badFile, "--dry-run", "--out", outDir], env);
    check("栏目不存在的条目被挡下且退出码非 0",
      bad.status !== 0 && (bad.stdout || "").includes("栏目不存在"), String(bad.status));

    // ---- 4) 删除同步：旧站已删稿件转 archive（后台留档、前台下线），可重复执行
    // 模拟一篇"迁移前就在库、本次导出里没有"的稿件
    phpEval(php, env,
      "require 'backend/src/bootstrap.php'; $db = new HechiZx\\Support\\Db((array) hechi_config('db'));"
      + " $db->execute(\"INSERT INTO cms_article (article_id, site_id, channel_type, title, content_html, has_body,"
      + " status, public_scope, published_at) VALUES (9100, 1, '904', '旧站已删稿件', '<p>正文</p>', 1, 'published',"
      + " 'public', '2026-01-01 08:00:00')\");");
    const archiveList = path.join(outDir, "deleted_ids.txt");
    writeFileSync(archiveList, "9100\n9999\n");
    const archiveCommit = run(php, [
      "tools/migrate/legacy_import.php", "--in", finalPath, "--commit", "--out", outDir,
      "--archive-ids", archiveList,
    ], env);
    const archivedIds = readFileSync(path.join(outDir, "archived_ids.txt"), "utf8").trim().split("\n");
    check("转归档：--archive-ids 把既存稿件改为 archive 且不动标题与审核状态",
      archiveCommit.status === 0 && readOne(9100, "public_scope") === "archive"
      && readOne(9100, "status") === "published" && readOne(9100, "title") === "旧站已删稿件",
      readOne(9100, "public_scope"));
    check("转归档：报告写明命中数，回滚清单只含库内命中的稿件号",
      (archiveCommit.stdout || "").includes("删除同步（--archive-ids）")
      && (archiveCommit.stdout || "").includes("库内命中 1 篇")
      && archivedIds.length === 1 && archivedIds[0] === "9100",
      archivedIds.join(","));
    const archiveAgain = run(php, [
      "tools/migrate/legacy_import.php", "--in", finalPath, "--commit", "--out", outDir,
      "--archive-ids", archiveList,
    ], env);
    check("转归档幂等：再跑一次不再改动，稿件仍是 archive",
      archiveAgain.status === 0 && readOne(9100, "public_scope") === "archive"
      && readFileSync(path.join(outDir, "archived_ids.txt"), "utf8").trim() === "",
      readFileSync(path.join(outDir, "archived_ids.txt"), "utf8").trim());

    return failures === 0 ? 0 : 1;
  } finally {
    if (keep) {
      console.log("保留临时目录：" + tmpRoot);
    } else {
      rmSync(tmpRoot, { recursive: true, force: true });
      // 清掉本检查写入的假图片（真实迁移产物在 backend/public/uploads/ 下，不入库）
      for (const file of ["uploadfiles/20260101/9001.jpg", "uploadfiles/20260101/9001-1.jpg", "uploadfiles/20260101/9001.docx"]) {
        rmSync(path.join(REPO, "backend/public/uploads/legacy", file), { force: true });
      }
      rmSync(path.join(REPO, "backend/public/uploads/legacy/uploadfiles/20260101"), { force: true, recursive: true });
    }
    console.log("\n合计 " + results.length + " 项检查，" + (results.length - failures) + " 通过，" + failures + " 失败");
  }
}

process.exit(main());
