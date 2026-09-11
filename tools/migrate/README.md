# 旧站数据迁移（阶段 D）

本目录用于旧站到新库的迁移脚本，**当前尚未实现**——按 [../../docs/下阶段开发计划（2026-09-11）.md](../../docs/下阶段开发计划（2026-09-11）.md) 第三节，它排在阶段 C 后端之后（9 月 27 日—9 月 30 日）。

## 迁移对象

| 来源 | 规模 | 说明 |
| --- | --- | --- |
| `zhengxie2026/date/database/gxhczx_db.sql` | 73,795,613 字节，导出于 2026-04-28，16 张表 | 旧库导出，`rd_news` 20,705 条为主表 |
| `zhengxie2026/server/data/gxhczx_db/rd_news.MYD` | 71,208,372 字节，最后修改 2026-09-05 | 在线数据文件，比 SQL 导出新，**阶段 B 需重新导出一次全量** |
| `zhengxie2026/gxhczx.gov.cn/html/` | 53,542 个静态文章页，513 MB | `news-view-<id>.html`，正文兜底与 301 输入 |
| `zhengxie2026/gxhczx.gov.cn/uploadfile*` | — | 历史图片与附件（是否纳入本次迁移待甲方按合同口径确认） |

> 以上数字来自本仓库 2026-09-11 的实际统计，见计划文档“附：事实／推断／未知备注”。

## 目标表

与 [../../database/migrations/mysql/001_init.sql](../../database/migrations/mysql/001_init.sql) 对应：`rd_news → cms_article`、`rd_menu`／`rd_menu1 → sys_channel`、`rd_cr → cms_article`（领导简介）、`rd_hot`／`rd_run`／`rd_video`／`rd_about`／`rd_links`／`rd_region`／`rd_ad → cms_home_block`（原型期按模块整块），旧地址 → `sys_url_redirect`。

## 计划步骤

1. **取数**：从 `172.21.43.11` 重新导出全量数据库（现有 SQL 停在 4 月 28 日），同时备份 `uploadfile`／`uploadfiles` 与 `html/` 目录。
2. **建映射**：以 `rd_menu`／`rd_menu1` 为准产出 `Type`（98 个取值）到 `channel_id` 的完整映射表，输出《栏目与 Type 映射总表》交甲方确认。
3. **清洗**：正文去 `font`／`span` 内联样式、统一图片路径、剥离冗余空段落；`From` 字段拆出日期与版面（约 7.8% 的记录把日期版面拼在来源后，如“河池日报 2026/3/2 1 版”）。
4. **导入**：`Audit` 映射发布状态（已审 20,540 / 未审 163），按 `Time` 转 `published_at`，`Hot`／`Top` 映射置顶与热点，`Region` 区分主站与县区。
5. **301**：按 `news-view-<id>.html`、`news_list.php?id=<n>`、`cq_view.php?id=<n>` 等模式批量生成 `sys_url_redirect`，交 Nginx 层统一跳转。
6. **核对**：行数比对、栏目归属抽样、301 命中率抽样、移动端抽查，产出《迁移核对报告》。

## 可复用的现有代码

- `backend/bin/seed.php`：快照 → 新库的灌库逻辑（幂等、可重跑），迁移脚本可沿用同一套 upsert 写法。
- `tools/prototype/extract_sample_data.py`：样例数据抽取脚本（当前从旧库 SQL 取 `Region=22` 每栏目 24 条、详情 6 篇），其中的正文清洗与来源字段清理规则可直接搬到正式迁移里并加强。

## 约束

- 迁移脚本必须**幂等、可重复执行、带校验**，失败可重跑不产生重复数据。
- 涉密稿件不发外网、不调用厂商接口；迁移全程按合同保密条款执行，素材不入 git（见根 `.gitignore`）。
- 历史图片与附件是否随本次迁移，按合同口径（合同写明不在本次迁移范围内）与方案书口径（纳入迁移）存在冲突，需甲方确认后再定实现范围。
