# 广西河池政协网（hechi-zhengxie）

> 河池市融媒体中心承建并负责技术维护的政务门户网站源码仓库。
> 主办：中国人民政治协商会议河池市委员会办公室；官网：www.gxhczx.gov.cn

## 当前进度（2026-09-12）

- **前端静态版首页**：`frontend/home/index.html` 已完成，按现网内容做静态化重构——语义化骨架、响应式（桌面/平板/手机）、适老化字号与高对比度、轮播、站头横幅本地化。
- **内页模板全集**：`channel.html`（二级栏目页）与 `detail.html`（信息详情页）按 `list`／`leaders`／`about`／`county`／`gallery`／`video`／`topic`／`interactive` 八类版式实现（`about` 暂无栏目使用），配套样例数据 43 个栏目页、70 篇详情，图片全部本地化。
- **站头与链接映射**：内页站头收窄为全幅约三分之一，首页进出内页带收缩／展开动画；`js/site-links.js` 把旧站栏目与稿件地址改写为新版内页，首页与内页共用同一份映射。
- **数据来源**：当前用 `frontend/home/data/` 下的静态快照（`home.json`／`channel.json`／`article.json`／`channel-index.json`）复用现网内容；后端就绪后以 REST API 平替该数据源，页面结构与渲染逻辑不变（详见 [frontend/home/README.md](frontend/home/README.md)）。
- **后端最小骨架（2026-09-11 新增）**：`backend/` 已从空骨架变为可运行的 PHP 轻量 CMS——MySQL／SQLite 双方言建表脚本（19 张业务表，另有迁移记录表 `schema_migrations`）、内容 REST 接口（`health`／`home`／`channels`／`channel-index`／`articles`／`article`／`attachments`／`search`，只出已发布且公开的内容）、静态化发布器（数据快照 + 全文静态页 + sitemap）、把阶段 A 快照灌库的 seed 脚本。本机以 SQLite 实测：接口对拍 49 项全通过（`node tests/api-check.mjs`）。
- **后台管理界面（2026-09-11 新增）**：`/admin` 可登录操作——概览、稿件列表（栏目用导航条，顺序与前台一致）、新建/编辑/删除稿件（新建默认“已发布”）、附件与正文插图上传、栏目管理、一键发布；会话 Cookie + CSRF + `password_hash`，登录与改动写操作日志。实测 240 项检查全通过（`node tests/admin-check.mjs`）。尚未做：编校微服务（`services/proofreader/` 仍为空骨架）。
- **栏目新建与删除（2026-09-12 新增）**：栏目管理补上新建入口（选归属、栏目号、URL 标识、版式与状态；挂在一级栏目下时自动沿用该组的一级名，只做两级）与删除入口（确认页；有稿件、子栏目、首页模块绑定或导航指向时逐条说明并挡下；确认后一并清理角色的栏目数据范围与该栏目的 301 映射记录）。顺带修掉一级栏目把“自己”当上级栏目的显示问题（`ChannelRepository::isTopLevel()`）。理由、字段说明与验证见 [docs/栏目新建与删除说明（2026-09-12）.md](docs/栏目新建与删除说明（2026-09-12）.md)，后台检查由 223 项增至 240 项。
- **旧地址 301 与栏目静态页路径（2026-09-12 新增）**：新增 `php backend/bin/redirects.php`，按库内内容生成 `sys_url_redirect` 映射（详情落 `/article/<id>.html`、栏目落 `/channel/<目录名>/`、旧入口页与 13 个 `/zl<日期>/` 专题目录一并登记），产出 `nginx-301.conf`、逐条核对的 `url-map.csv` 与 `report.txt`，并支持 `--legacy-site=` 对照旧站目录列出未登记地址、`--check=` 校验每条 301 的目标产物真实存在；运行期由 PHP 入口查表精确判定 301 并累计 `hits` 命中数。同时修掉发布器的栏目路径冲突——slug 是**一级栏目**标识，43 个栏目里 23 个共用 5 个 slug，此前只写出 25 个栏目静态页且有 18 个被覆盖、sitemap 出现重复地址，现按 `/channel/<slug>-<栏目号>/` 唯一化；`deploy/nginx/default.conf` 里那条取不到 `$1` 的旧 301 规则（`^~ /news-view-`）已删除。规则、旧地址出处与未覆盖项见 [docs/旧地址301映射说明.md](docs/旧地址301映射说明.md)，回归检查 50 项全通过（`node tests/redirect-check.mjs`）。
- **稿件管理与栏目管理界面重构（2026-09-11 新增）**：稿件列表把稿库、栏目、关键词、排序、每页条数收进一块筛选面板，标题即编辑入口，新增**批量提交／审核通过／退回／撤回／重新发布／恢复**与分页跳转；稿件编辑页改成“左表单 + 右状态栏”两栏，保存条吸底，正文可预览并显示字数；栏目管理改为**按一级栏目分组**，组内可直接上移／下移并支持检索。新增零依赖的 `assets/admin.js` 做渐进增强（不加载脚本时全部操作仍可用）。改动理由、逐项清单与验证见 [docs/后台稿件与栏目管理重构说明（2026-09-11）.md](docs/后台稿件与栏目管理重构说明（2026-09-11）.md)。
- **后台按首页四大类组织（2026-09-11 新增）**：侧栏为“概览 / 首页管理（树形，默认展开）/ 稿件管理 / 用户管理 / 操作日志”，四大类收在“首页管理”下（导航栏目、头条轮换、其他栏目、站内横幅），分别对应首页的顶部导航条（18 个入口）、首屏轮播、正文 13 个内容模块与 7 处横幅位。首页内容模块由**静态快照改为按绑定栏目实时组装**（置顶在前 → 栏目内排序值 → 发布时间，不足时用快照兜底），编辑发稿即上首页；稿件编辑页的“置顶”改为**按栏目置顶**，首页模块与栏目页列表共用一套顺序；新增权限码 `home.manage` 与迁移 `003_home_sections.sql`。改动清单、数据模型与验证见 [docs/后台首页四大类管理说明.md](docs/后台首页四大类管理说明.md)。
- **前后端已接通（2026-09-11 新增）**：前端取数统一走 `frontend/home/js/data-source.js`——**优先 `/api/v1`，接口不可用时自动回退 `data/*.json`**，地址栏 `?api=1`／`?api=0` 可强制切换；本地 `php -S 127.0.0.1:8080 -t backend/public backend/public/router.php` 一个进程同时提供站点页面、接口与后台（同源，无跨域）。实测：接口模式 20 例、静态模式 19 例全通过（`node tests/check-pages.mjs [--via-api]`）。
- **当日回归已修复**：`85918c2` 的精简索引一度让 `channel.html` 数据加载失败、`detail.html` 标题多出 `undefined`，`b95ccf8` 已修复并实测通过；成因与验证见 [frontend/home/README.md](frontend/home/README.md) 的“回归与修复”一节。
- **前端回归检查**：`tests/check-pages.mjs` 起本地静态服务、用无头浏览器跑 19 个用例（首页／栏目页七种版式／详情页／分页／刷新回顶），断言无 `undefined`、无“数据加载失败”、无破图与横向溢出、控制台干净。首跑即发现“首页手动刷新未回顶”这一遗留缺陷，已一并修正（详见 [tests/README.md](tests/README.md)）。
- **正文富文本与清洗（2026-09-12 新增）**：编辑页正文换成 SunEditor 3.3.3（MIT，自托管在 `backend/public/assets/editor/`），工具栏含字体／字号／文字颜色／背景色，可在光标处插图与 mp4／webm 视频；服务端以自托管 HTMLPurifier 4.19.0 作保存、内容接口、静态页三处**唯一清洗出口**，白名单以外的标签、事件与协议一律剥离。选型与四家候选的剪贴板实测见 [docs/富文本编辑器选型说明（2026-09-12）.md](docs/富文本编辑器选型说明（2026-09-12）.md)，许可与落盘位置见 [docs/开源选型说明.md](docs/开源选型说明.md) 第 3.4 节。
- **稿件编辑页收敛为写作窗（2026-09-12 新增）**：删掉“基本信息”“发布设置”两张卡与摘要字段；标题带 64 字计数，作者／责任编辑／来源一行，新增**原标题**三项（引题／主标题／副题，保存时按加粗三行拼在正文最前，同字体同字号，不进网页标题与首页）；摘要只在首页管理的轮换头条里维护；发布时间不再手填，以审核人员点发布时的实时时间为准。
- **首页管理补三件套与预览（2026-09-12 新增）**：**置顶／高亮／徽标**成组维护——高亮＝首页标题换成正红（`#ff0000`）加粗；徽标从下拉预设（最新／热点／重磅／独家／图解／视频／直播／预告）里选，或选“自定义…”填字（≤ 6 个字），保存成功后该行状态列显示“徽标：xxx”并可一键删除、随时改；稿件列表与首页管理表格新增**预览／复制链接**，已发布打开发布器产出的 `/article/{id}.html`，未发布置灰。临时链接功能按当日决策取消，不做。
- **站内横幅（2026-09-12 新增）**：每个图片位在文件框下面给出**出图建议**（显示尺寸、宽高比例、建议像素、≤ 300 KB），数字按 1440／1920 两档视口量出的外框尺寸取 2 倍；选中文件后**立刻在上方预览框里显示预览图**，点“保存”才生效。
- **上传限制（2026-09-12 新增）**：图片（站内横幅、头条大图、正文插图、编辑器插图）一律 **≤ 2 MB**，附件（pdf／doc／xls／ppt／zip／rar／txt）与视频仍是 32 MB；超限时给出“图片超过服务器允许的上传大小（2 MB）”而不是错误码。
- **前端调整（2026-09-12）**：首页字号整体加大一档（首页基准 16px，内页仍 15px，适老 A-／A+ 随之变化）；修复接口模式下站内相对链接（`channel.html`／`detail.html`）被补成旧站域名的问题；修复 Edge 上轮播标题被摘要裁切的问题；清理 `frontend/home/images/` 内无引用的残留图片。
- **栏目页真分页与实时条数（2026-09-12 新增）**：栏目页不再只对首屏取的 50 条做本地分页——列表改走 `/api/v1/articles` 按页取（每页 20 条，接口不可用时用快照本地分页并标注“演示数据”），分页条对页数多的栏目只列首页／当前页前后一页／末页；栏目接口的 `total` 也从快照常量 `sys_channel.total_count` 改为**实时公开条数**（`published` + `public`），与列表能翻到的条数一致。以迁移后的 904 为例：前台“共 464 条 · 第 1/24 页”，末页 4 条（旧库已审 825 篇，其中 361 篇超出公开年限只留后台）。同批修正政协领导页：花名册只收有职务的条目（政协章程、委员名单等一页式内容不再被当成“副主席”卡片），职务按旧库 `Edit` 映射，页面分主席／副主席／秘书长 3 组。
- **旧库迁移工具（2026-09-12 新增）**：`tools/migrate/` 落地两段式迁移——`legacy_extract.py` 解析旧库导出并按口径筛稿、清洗正文、产出媒体清单与映射报表（`--extra-from-slides` 还能补抓导出库里没有的新稿，首页轮换图指向的 8—9 月稿件就靠它入库）；`fetch_media.py` 抓取或**离线核对**历史图片与视频（`--verify` 模式用于从服务器直接拷文件的场景，4 并发抓取时记 sha256，误返回网页视为失败）；`legacy_import.php` 校验后幂等 upsert 入库，并写操作日志、回滚清单与核对报告；`reconcile.py` 逐栏目对账旧库与新库稿件号。按 4 月 28 日导出加 6 篇补抓共 **4,496 篇**导入开发库（2,715 public + 1,732 archive + 49 draft），未映射 436 篇出表待甲方确认，县区 15,779 篇本期不迁；发布产出 2,720 篇详情静态页、301 映射 8,319 条且目标全命中，发布同时清理不再产出的旧静态页（本次 14 个），避免归档内容被 Nginx 继续直出。历史图片抓网较慢（旧站单请求约 10 秒）已暂停，改为从服务器拷贝后补齐，流程见 [docs/旧库迁移说明.md](docs/旧库迁移说明.md) 第 5.2、5.3 节。
- **后台编辑页与原标题修复（2026-09-14）**：编辑页取消“在本栏目置顶”（排序只在首页管理与稿件列表里点）；正文本来的原标题三行会自动收进“原标题”输入框、编辑器正文不再重复，保存时按加粗三行拼回正文最前、每行首行空两格，缩进写在 `<strong>` 内（实测编辑器会吃掉 strong 外侧的行首空白，64049 就是这样丢的）；存量修复脚本 `php backend/bin/fix-orig-title.php`（默认只统计，`--apply` 才写库并留回滚清单），实测全站 2255 篇正文带题区、其中 2254 篇的“原标题”三列是空的、4294 行题区行缺首行缩进。详见 [backend/README.md](backend/README.md) 的“原标题三项”。
- **详情页排版与显示修复（2026-09-14）**：正文归一化落在**展示出口**（`backend/src/Content/BodyNormalizer.php`，接口与发布器共用，不动库里的 `content_html`）——去掉旧库段间的 `<div><br></div>` 空段（实测 2574/2773 篇，段间空档由 63px 回落到约 1 行）、裁掉与 2 字缩进叠加的首部空格（669 篇）、拍平 `div` 套 `div`／`<strong>` 包块、旧站资源地址按“本地有文件才改写，其余强制 https”处理（本地 227/2102）。详情页顶部只出一行标题，正文题区（引题＋主标题等连续加粗行）按行留在正文里；只有题区仅一行且与标题一字不差时才去掉，`images` 出口剔除视频文件。前端详情页补齐视频／列表／标题／引用／表格样式（修 390px 下视频撑破版面的 273px 溢出）、工具栏不再折字、正文有图时不再重复出图集条、空值元信息不渲染、破图出占位、补 `@media print` 与“回到顶部”，长文右栏不再被粘住；静态页模板 `templates/page.php` 同步补正文样式。详见 [backend/README.md](backend/README.md) 的“正文出口归一化”。
- **检查脚本（2026-09-14）**：`tests/` 现有 10 个脚本，最近一次全绿——页面 22（接口模式 24、`--publish` 另加 2）、接口 49、后台 240、排序 19、正文清洗 33＋31、归一化 20、编辑器 55、旧地址 301 54、旧库迁移 44。

## 本地预览

```bash
cd frontend/home && python3 -m http.server 8899
# 打开 http://127.0.0.1:8899/index.html
# 栏目页 http://127.0.0.1:8899/channel.html?id=904
# 详情页 http://127.0.0.1:8899/detail.html?id=62180
```

> 直接双击 `index.html` 会因浏览器拦截本地 `fetch` JSON 而无法加载数据，需经静态服务器访问。

提交前跑一遍页面回归检查（自起静态服务，无需先开预览）：

```bash
node tests/check-pages.mjs
```

后端接口与发布器自检（用临时 SQLite 库建表、灌数、对拍，不动开发库）：

```bash
php backend/bin/migrate.php && php backend/bin/seed.php     # 首次准备
node tests/api-check.mjs                                    # 49 项接口检查
node tests/redirect-check.mjs                               # 50 项：旧地址 301 与栏目静态页路径
php -S 127.0.0.1:8080 -t backend/public backend/public/router.php   # 起服务（站点 + 接口 + 后台）
```

起来后三处都能访问（同一个端口、同源）：

- 站点页面：<http://127.0.0.1:8080/>（取数走接口，接口不可用时自动回退静态快照）
- 后台管理：<http://127.0.0.1:8080/admin>（首次需 `php backend/bin/user.php create admin 你的密码 "管理员"` 建账号）
- 内容接口：<http://127.0.0.1:8080/api/v1/health>

旧地址 301（生成映射、产出 Nginx 片段与核对 CSV，规则见 [docs/旧地址301映射说明.md](docs/旧地址301映射说明.md)）：

```bash
php backend/bin/redirects.php --out=backend/storage/publish --check=backend/storage/publish
```

旧库迁移（阶段 D，两段式；口径与验收见 [docs/旧库迁移说明.md](docs/旧库迁移说明.md)）：

```bash
~/py-tools/bin/python tools/migrate/legacy_extract.py parse --sql "<gxhczx_db.sql>" --out tools/migrate/out
~/py-tools/bin/python tools/migrate/legacy_extract.py render --in tools/migrate/out/articles.jsonl --out tools/migrate/out
php tools/migrate/legacy_import.php --in tools/migrate/out/articles.final.jsonl --dry-run
node tests/migrate-check.mjs                               # 36 项，用 fixture，不联网
```

细节见 [backend/README.md](backend/README.md)（本地跑通、后台、发布、生产部署）与 [docs/api-contract.md](docs/api-contract.md)（接口契约）。

## 目标架构（规划，源自《河池政协网开发思路与技术栈方案》）

| 层 | 选型 |
| --- | --- |
| 云资源 | 移动云（本期选定） |
| Web 服务 | Nginx（virtualhost 多站点） |
| 后端 | PHP 8.x 轻量 CMS：首页/栏目/详情全文静态化 + Nginx 直出 |
| 数据库 | MySQL 8（SQL 层做方言隔离，为后续信创切换预留） |
| 缓存 | Redis |
| 前端 | 服务端渲染静态 HTML + 响应式 CSS + 原生 JS/轻量库 |
| 内容/资源 API | REST（JSON），前台/后台/校对/多端共用 |
| 无障碍/适老 | govwza.cn 组件或开发等价方案 |
| 编校微服务 | 独立 Python 服务 + 厂商校对 API + 本地词库 |
| 数据迁移 | 旧库导出、清洗去重、图片附件迁移、301 URL 映射 |
| 安全 | WAF/安全组、等保二级、日志 180 天、三级备份、敏感词过滤 |

规划要点：主站按“全国政协网形态”落地（全文静态化 + 响应式 + 无障碍 + 等保 + 多站点预留），内容/资源 API 化，实现前后台分离、多端复用与日后换代。详细分层、数据模型与模块划分见 [docs/架构与实施说明.md](docs/架构与实施说明.md)。

## 目录结构

```
hechi-zhengxie/
├── frontend/home/         # 前端静态版（本期实际交付，首页 + 栏目页 + 详情页）
│   ├── index.html         # 首页骨架 + 数据挂载点
│   ├── channel.html       # 二级栏目页（按 ?id=<旧库栏目ID> 渲染）
│   ├── detail.html        # 信息详情页（按 ?id=<稿件ID> 渲染）
│   ├── css/style.css      # 首页与内页共用变量、站头、响应式与无障碍样式
│   ├── css/inner.css      # 内页样式（栏目页 + 详情页）
│   ├── js/main.js         # 首页渲染 / 轮播 / 导航 / 字号 / 高对比度
│   ├── js/data-source.js  # 取数统一入口：优先 /api/v1，接口不可用时回退 data/*.json
│   ├── js/shell.js        # 内页公共外壳（顶栏 / 导航 / 页脚 / 侧栏 / 无障碍）
│   ├── js/channel.js      # 栏目页渲染与分页
│   ├── js/detail.js       # 详情页渲染、附件下载、图集灯箱、字号、打印
│   ├── js/header-fold.js  # 站头收窄 / 展开动画
│   ├── js/site-links.js   # 站内链接映射（旧站地址 → 新内页）
│   ├── data/              # 四份内容快照（home / channel / article / channel-index）
│   └── images/            # 本地化图片资源（178 个文件 14 MB，含 images/channel/ 111 张）
├── docs/                  # 需求、方案、接口契约、运维文档
├── backend/               # 主站 PHP CMS（应用层 + 前端 SSR + 静态化发布）
│   ├── bin/               # migrate（建表）· seed（快照灌库）· publish（静态化发布）· redirects（旧地址 301）· scan-content（存量正文体检）· user（账号）
│   ├── public/index.php   # 接口唯一入口（Nginx 指向）
│   ├── public/assets/editor/   # 正文富文本编辑器 SunEditor 3.3.3（MIT，自托管预构建包）
│   ├── vendor/htmlpurifier/    # 正文清洗件 HTMLPurifier 4.19.0（LGPL-2.1，未改本体）
│   └── src/ · config/ · routes/ · templates/   # 源码与模板，实现见 backend/README.md
├── api/                   # 内容/资源 REST API（实现落在 backend/，本目录预留独立部署位）
├── services/proofreader/  # 智能编校 Python 微服务（规划）
├── database/migrations/   # MySQL / SQLite 双方言建表脚本
├── deploy/                # Nginx + PHP-FPM + MySQL 编排（docker-compose.yml 在根目录）
├── tools/migrate/         # 旧站数据迁移（阶段 D）：解析清洗、抓图、入库与核对报告
└── tests/                 # 九个检查脚本：页面／接口／后台／排序／正文清洗两层／富文本编辑器／旧地址 301／旧库迁移
```

> 标注“规划”的为预留骨架，尚无实现代码；已实现的为 `frontend/home/`（前端静态版）、`backend/`（后端最小骨架）、`database/`（建表脚本）、`tools/migrate/`（旧库迁移工具）、`tests/`（九个检查脚本）。

## 数据契约

`frontend/home/data/` 下的四份静态快照即后续 REST API 的数据契约原型：

| 文件 | 用途 | 当前规模 |
| --- | --- | --- |
| `home.json` | 首页数据 | 21 个顶层键：`meta`、18 个主栏目 `nav`、`leaders`、`slides`、`notice`／`bookCity`／`antiGang`／`videos`、`zxdt`／`sxNews`／`zxMeeting`、`zwhWork`／`partyGroups`／`theory`、`imageNews`、`memberWindow`、`countyZx`、`ranking`、`topic`、`scenery`、`links` |
| `channel.json` | 栏目页数据 | 43 个栏目（31 个带稿件列表，共 432 条） |
| `article.json` | 详情页数据 | 70 篇正文、正文图片 53 张、附件 1 条 |
| `channel-index.json` | 站内链接映射精简索引 | 43 个栏目 + 432 个稿件 id（约 4.4 KB） |

单条信息统一为 `{title, url, date?}`，图片类条目含 `img`；栏目页与详情页的字段级说明见 [frontend/home/README.md](frontend/home/README.md)，不在此重复维护。

## 一期范围与合规约束

- 本期只建**政协主站 + 校对模块**；县区子站与稿件互通缓做，按 `site_id` 预留升级接口。
- 政务云停服日期存在两种口径：《河池政协网迁移建设和技术维护合同（9 月 3 日）》为 **2026-11-20 起关停**，《河池政协网开发思路与技术栈方案》与执行时间表为 **2026-11-01 停服**。本仓库文档统一按合同口径 11 月 20 日编排，该冲突待甲方确认。
- 数据策略：近 3 年数据公开访问，更早数据后台留存不公开；旧 URL 统一 301 映射保 SEO 与外链。

## 说明

详细需求与选型依据见《河池政协网开发思路与技术栈方案》。运行环境、数据库结构、接口契约随实现推进逐步补齐。
