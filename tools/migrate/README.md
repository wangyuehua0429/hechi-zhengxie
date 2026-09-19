# 旧站数据迁移（阶段 D）

把旧库（宝塔每日全库备份 `gxhczx_db_2026-09-05_02-30-02_mysql_data.sql.gz`，或更早的 Navicat 导出）的主站内容迁进新库。
口径、基线数字、字段映射与验收指标见 [../../docs/旧库迁移说明.md](../../docs/旧库迁移说明.md)，本文件只讲怎么跑。

解析器同时认两种导出格式：Navicat（一条语句一行、一行一个元组）与 mysqldump／宝塔备份（一条语句一行、一行多个元组），文件后缀 `.gz` 自动解压。

历史图片不再走现网抓取（旧站单请求慢、整体要数小时），改由人工从旧站服务器拷 `uploadfiles/` 目录到
`backend/public/uploads/legacy/`，再用 `render --local-check` 按本地文件认账。

## 两段式

```bash
# 1) 解析：旧 SQL → articles.jsonl + 媒体清单 + 报表（只读旧库，不连数据库）
~/py-tools/bin/python tools/migrate/legacy_extract.py parse \
  --sql "/path/to/gxhczx_db_2026-09-05_02-30-02_mysql_data.sql.gz" --out tools/migrate/out \
  --expect-rows 21363 --expect-max-id 64092 \
  --deleted-from "/path/to/gxhczx_db.sql"

# 2) 图片来源：从旧站服务器拷 /www/wwwroot/gxhczx.gov.cn/uploadfiles/ 到
#    backend/public/uploads/legacy/uploadfiles/（保持 <年月>/<文件名> 层级）

# 3) 渲染：清洗正文 + 按本地已有文件改写媒体地址（缺的保留旧站外链，不写成死链）
~/py-tools/bin/python tools/migrate/legacy_extract.py render \
  --in tools/migrate/out/articles.jsonl \
  --manifest tools/migrate/out/media_manifest.csv --out tools/migrate/out --local-check

# 4) 入库：先干跑，再提交
php tools/migrate/legacy_import.php --in tools/migrate/out/articles.final.jsonl --dry-run \
  --archive-ids tools/migrate/out/deleted_ids.txt
php tools/migrate/legacy_import.php --in tools/migrate/out/articles.final.jsonl --commit \
  --archive-ids tools/migrate/out/deleted_ids.txt
```

| 脚本 | 作用 | 关键参数 |
| --- | --- | --- |
| `legacy_extract.py parse` | 按口径筛稿、生成媒体清单、映射报表、未映射清单、次要表产物 | `--sql`（`.sql`／`.sql.gz`）、`--out`、`--channels`（栏目快照，默认 `frontend/home/data/channel.json`）、`--skip-side-tables`、`--expect-rows`／`--expect-max-id`（导出完整性自检，对不上退出码 2）、`--deleted-from`（对比更早的导出，产出 `deleted_ids.txt`） |
| `legacy_extract.py render` | 正文清洗（去 `font`／`span`／内联样式、折叠空段落）＋媒体地址改写＋标题字段映射 | `--in`、`--out`、`--manifest`（parse 产出）、`--local-check`（按 `target` 看本地文件是否到位，不联网；不传则只认清单里 `status=ok` 的条目） |
| `legacy_import.php` | 校验 → 报告 → upsert 入库（`cms_article`／`cms_article_channel`／`cms_article_image`／`cms_attachment`），次要表合并进首页整块 | `--in`、`--dry-run`／`--commit`、`--out`、`--side-tables`、`--archive-ids`（把清单里的既存稿件转 `archive`） |
| `type_mapping_report.py` | 由 `unmapped.csv` + 旧库 `rd_menu` 生成《栏目与 Type 映射总表》（Markdown），交甲方勾选归属 | `--sql`、`--unmapped`、`--channels`、`--out`、`--date` |
| `reconcile.py` | 对账：逐栏目比对旧库（`Type`＋对应 `Region`）与新库的稿件号，列出缺失／多出／前台可见／归档／草稿；有缺失时退出码 1 | `--sql`、`--db`、`--channels`、`--out`（写 CSV） |

### 补抓导出库里没有的新稿（`--extra-ids` / `--extra-from-slides`）

导出库停在 2026-04-28，此后的新稿不在里面；首页轮换图指向的 8—9 月稿件就属于这种情况。补抓命令：

```bash
~/py-tools/bin/python tools/migrate/legacy_extract.py parse \
  --sql "/path/to/gxhczx_db.sql" --out tools/migrate/out \
  --extra-from-slides --site "/path/to/zhengxie2026/gxhczx.gov.cn"
# 也可指定单篇：--extra-ids 63983,63904
```

- 解析顺序：优先本地静态页 `html/news-view-<id>.html`，缺了抓现网 `news_view.php?id=<id>`；
- 栏目号只认面包屑 `.box_where` 里的 `news_list.php?id=`；旧站对 902—906 查不到栏目导致面包屑为空时，回列表页（904／906／306／314／902／903…）认领；
- 补抓的稿件走完全一样的后续流程（拷贝图片 → `render --local-check` → `legacy_import.php --commit`），图片同样进媒体清单；
- 补抓清单与来源写在 `tools/migrate/out/extra_articles.txt`。

## 产物（`tools/migrate/out/`，不入库）

`articles.jsonl`（解析结果）、`articles.final.jsonl`（入库字段）、`media_manifest.csv`（`url,target,status,bytes,sha256,error`）、`mapping_report.csv`（栏目对拍）、`unmapped.csv`（未映射稿件）、`deleted_ids.txt`／`deleted_summary.txt`（旧站已删稿件与分类小计）、`side_tables.json`（视频／链接／互动）、`stats.json`／`render_stats.json`、`report.txt`（入库报告）、`imported_ids.txt`（入库回滚清单）、`archived_ids.txt`（转归档清单）、`reconcile.csv`（逐栏目对账）。上一版口径的产物留在 `out-20260428/` 供对拍。

## 约束

- 迁移脚本幂等：按 `article_id` upsert，可反复执行；`--dry-run` 不写库。
- 只迁主站口径；县区内容与未映射栏目按口径排除，分别写进报告。
- 归档稿件（`public_scope=archive`）在年限口径下不产静态页、不登记 301；是否按年限过滤由 `backend/config/config.php` 的 `content.enforce_public_scope` 决定（当前默认放开，归档稿照常对外）。
- 生产执行前先备份数据库；回滚按 `imported_ids.txt` 处理。
- **迁移入库后不要再跑 `backend/bin/seed.php`**：它按样例快照重灌，会覆盖同号稿件与栏目归属；`seed.php` 已有安全闸会拦下，确要重灌需加 `--force`。
- 涉密稿件不发外网；图片一律从旧站服务器自取，不调用任何第三方接口。

## 从服务器拷贝图片

本地目标路径与旧站目录一一对应：

```
backend/public/uploads/legacy/uploadfiles/<年月>/<文件名>
```

服务器上的站点根目录是 `/www/wwwroot/gxhczx.gov.cn`，把它的 `uploadfiles/`（以及单数形式的
`uploadfile/`）整个拷到这个位置（保持 `年月/文件名` 层级），然后在仓库根目录依次执行：

```bash
~/py-tools/bin/python tools/migrate/legacy_extract.py render \
  --in tools/migrate/out/articles.jsonl --manifest tools/migrate/out/media_manifest.csv \
  --out tools/migrate/out --local-check
php tools/migrate/legacy_import.php --in tools/migrate/out/articles.final.jsonl --commit \
  --archive-ids tools/migrate/out/deleted_ids.txt
php backend/bin/publish.php --out=backend/storage/publish
```

`--local-check` 只认清单里已经在本地存在的 target 路径：没拷到的仍保留旧站外链（不会写成死链），
补齐后再跑一次即可。入库与发布都是幂等的。需要挑着拷时按 `tools/migrate/out/image_clues/copy_dirs.txt`
的 103 个年月目录走；只要本次迁移范围内那 60 个目录见
[../../docs/旧站图片线索与拷贝清单.md](../../docs/旧站图片线索与拷贝清单.md)。

## 检查

```bash
node tests/migrate-check.mjs        # 45 项：用 fixture 跑完整两段式（不联网），含多行 INSERT／gzip、删除同步、转归档、本地图认账

# 入库后证明“一篇没少”（缺失不为 0 时退出码 1）
~/py-tools/bin/python tools/migrate/reconcile.py \
  --sql "/path/to/gxhczx_db.sql" --db backend/storage/hechi_zx.sqlite \
  --out tools/migrate/out/reconcile.csv
```
