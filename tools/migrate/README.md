# 旧站数据迁移（阶段 D）

把旧库（Navicat 导出 `gxhczx_db.sql`）的主站内容迁进新库，并把历史图片抓回本地。
口径、基线数字、字段映射与验收指标见 [../../docs/旧库迁移说明.md](../../docs/旧库迁移说明.md)，本文件只讲怎么跑。

## 两段式

```bash
# 1) 解析：旧 SQL → articles.jsonl + 媒体清单 + 报表（只读旧库，不连数据库）
~/py-tools/bin/python tools/migrate/legacy_extract.py parse \
  --sql "/path/to/gxhczx_db.sql" --out tools/migrate/out

# 2) 抓图：图片与视频抓回 backend/public/uploads/legacy/，回填清单
~/py-tools/bin/python tools/migrate/fetch_media.py \
  --manifest tools/migrate/out/media_manifest.csv --base http://www.gxhczx.gov.cn

# 2b) 改从服务器拷文件的走这条：拷完在仓库根目录做离线核对（不联网、算 sha256）
~/py-tools/bin/python tools/migrate/fetch_media.py \
  --manifest tools/migrate/out/media_manifest.csv --verify

# 3) 渲染：清洗正文 + 按清单改写媒体地址
~/py-tools/bin/python tools/migrate/legacy_extract.py render \
  --in tools/migrate/out/articles.jsonl \
  --manifest tools/migrate/out/media_manifest.csv --out tools/migrate/out

# 4) 入库：先干跑，再提交
php tools/migrate/legacy_import.php --in tools/migrate/out/articles.final.jsonl --dry-run
php tools/migrate/legacy_import.php --in tools/migrate/out/articles.final.jsonl --commit
```

| 脚本 | 作用 | 关键参数 |
| --- | --- | --- |
| `legacy_extract.py parse` | 按口径筛稿、生成媒体清单、映射报表、未映射清单、次要表产物 | `--sql`、`--out`、`--channels`（栏目快照，默认 `frontend/home/data/channel.json`）、`--skip-side-tables` |
| `legacy_extract.py render` | 正文清洗（去 `font`／`span`／内联样式、折叠空段落）＋媒体地址改写＋标题字段映射 | `--in`、`--out`、`--manifest`（不传则保留旧站地址） |
| `fetch_media.py` | 4 并发、超时 15s、重试 3 次、间隔 200ms 抓取；记 `status/bytes/sha256`，误返回网页视为失败 | `--manifest`、`--base`、`--limit`、`--force`、`--dry-run` |
| `fetch_media.py --verify` | 离线核对：按清单的 `target` 看本地有没有文件，有就标 `ok` 并算 `sha256`，缺的留 `pending`（不联网） | `--manifest`、`--root` |
| `legacy_import.php` | 校验 → 报告 → upsert 入库（`cms_article`／`cms_article_channel`／`cms_article_image`／`cms_attachment`），次要表合并进首页整块 | `--in`、`--dry-run`／`--commit`、`--out`、`--side-tables` |
| `reconcile.py` | 对账：逐栏目比对旧库（`Type`＋对应 `Region`）与新库的稿件号，列出缺失／多出／前台可见／归档／草稿；有缺失时退出码 1 | `--sql`、`--db`、`--channels`、`--out`（写 CSV） |

## 产物（`tools/migrate/out/`，不入库）

`articles.jsonl`（解析结果）、`articles.final.jsonl`（入库字段）、`media_manifest.csv`（`url,target,status,bytes,sha256,error`）、`mapping_report.csv`（栏目对拍）、`unmapped.csv`（未映射稿件）、`side_tables.json`（视频／链接／互动）、`stats.json`／`render_stats.json`、`report.txt`（入库报告）、`imported_ids.txt`（回滚清单）。

## 约束

- 迁移脚本幂等：按 `article_id` upsert，可反复执行；`--dry-run` 不写库。
- 只迁主站口径；县区内容与未映射栏目按口径排除，分别写进报告。
- 归档稿件（`public_scope=archive`）不产静态页、不登记 301；发布了也不会出现在前台。
- 生产执行前先备份数据库；回滚按 `imported_ids.txt` 处理。
- **迁移入库后不要再跑 `backend/bin/seed.php`**：它按样例快照重灌，会覆盖同号稿件与栏目归属；`seed.php` 已有安全闸会拦下，确要重灌需加 `--force`。
- 涉密稿件不发外网；抓图只访问旧站本域，不调用厂商接口。

## 从服务器拷贝图片（比抓网快，推荐）

本地目标路径与旧站目录一一对应：

```
backend/public/uploads/legacy/uploadfiles/<年月>/<文件名>
```

服务器上的 `uploadfile`／`uploadfiles` 目录整个拷到这个位置（保持 `年月/文件名` 层级），然后在仓库根目录依次执行：

```bash
~/py-tools/bin/python tools/migrate/fetch_media.py --manifest tools/migrate/out/media_manifest.csv --verify
~/py-tools/bin/python tools/migrate/legacy_extract.py render \
  --in tools/migrate/out/articles.jsonl --manifest tools/migrate/out/media_manifest.csv --out tools/migrate/out
php tools/migrate/legacy_import.php --in tools/migrate/out/articles.final.jsonl --commit
php backend/bin/publish.php
```

`--verify` 只认清单里已有的 target 路径：没拷到的仍保留旧站外链（不会写成死链），补齐后再跑一次即可。入库与发布都是幂等的。

## 检查

```bash
node tests/migrate-check.mjs        # 36 项：用 fixture 跑完整两段式（不联网）

# 入库后证明“一篇没少”（缺失不为 0 时退出码 1）
~/py-tools/bin/python tools/migrate/reconcile.py \
  --sql "/path/to/gxhczx_db.sql" --db backend/storage/hechi_zx.sqlite \
  --out tools/migrate/out/reconcile.csv
```
