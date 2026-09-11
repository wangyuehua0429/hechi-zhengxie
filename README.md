# 广西河池政协网（hechi-zhengxie）

> 河池市融媒体中心承建并负责技术维护的政务门户网站源码仓库。
> 主办：中国人民政治协商会议河池市委员会办公室；官网：www.gxhczx.gov.cn

## 当前进度（2026-09-11）

- **前端静态版首页**：`frontend/home/index.html` 已完成，按现网内容做静态化重构——语义化骨架、响应式（桌面/平板/手机）、适老化字号与高对比度、轮播、站头横幅本地化。
- **内页模板全集**：`channel.html`（二级栏目页）与 `detail.html`（信息详情页）按 `list`／`leaders`／`about`／`county`／`gallery`／`video`／`topic`／`interactive` 八类版式实现（`about` 暂无栏目使用），配套样例数据 43 个栏目页、70 篇详情，图片全部本地化。
- **站头与链接映射**：内页站头收窄为全幅约三分之一，首页进出内页带收缩／展开动画；`js/site-links.js` 把旧站栏目与稿件地址改写为新版内页，首页与内页共用同一份映射。
- **数据来源**：当前用 `frontend/home/data/` 下的静态快照（`home.json`／`channel.json`／`article.json`／`channel-index.json`）复用现网内容；后端就绪后以 REST API 平替该数据源，页面结构与渲染逻辑不变（详见 [frontend/home/README.md](frontend/home/README.md)）。
- **后端最小骨架（2026-09-11 新增）**：`backend/` 已从空骨架变为可运行的 PHP 轻量 CMS——MySQL／SQLite 双方言建表脚本（17 张表）、内容 REST 接口（`health`／`home`／`channels`／`channel-index`／`articles`／`article`／`attachments`／`search`，只出已发布且公开的内容）、静态化发布器（数据快照 + 全文静态页 + sitemap）、把阶段 A 快照灌库的 seed 脚本。本机以 SQLite 实测：接口对拍 39 项全通过（`node tests/api-check.mjs`）。
- **后台管理界面（2026-09-11 新增）**：`/admin` 可登录操作——概览、稿件列表（栏目用导航条，顺序与前台一致）、新建/编辑/删除稿件（新建默认“已发布”，纯文本正文自动分段）、附件与正文插图上传、栏目管理、一键发布；会话 Cookie + CSRF + `password_hash`，登录与改动写操作日志。实测 133 项检查全通过（`node tests/admin-check.mjs`）。尚未做：富文本编辑器、栏目新建与删除、角色权限细分、301 生成、编校微服务（`services/proofreader/` 仍为空骨架）。
- **稿件管理与栏目管理界面重构（2026-09-11 新增）**：稿件列表把稿库、栏目、关键词、排序、每页条数收进一块筛选面板，标题即编辑入口，新增**批量提交／审核通过／退回／撤回／重新发布／恢复**与分页跳转；稿件编辑页改成“左表单 + 右状态栏”两栏，保存条吸底，正文可预览并显示字数；栏目管理改为**按一级栏目分组**，组内可直接上移／下移并支持检索。新增零依赖的 `assets/admin.js` 做渐进增强（不加载脚本时全部操作仍可用）。改动理由、逐项清单与验证见 [docs/后台稿件与栏目管理重构说明（2026-09-11）.md](docs/后台稿件与栏目管理重构说明（2026-09-11）.md)。
- **前后端已接通（2026-09-11 新增）**：前端取数统一走 `frontend/home/js/data-source.js`——**优先 `/api/v1`，接口不可用时自动回退 `data/*.json`**，地址栏 `?api=1`／`?api=0` 可强制切换；本地 `php -S 127.0.0.1:8080 -t backend/public backend/public/router.php` 一个进程同时提供站点页面、接口与后台（同源，无跨域）。实测：接口模式 20 例、静态模式 19 例全通过（`node tests/check-pages.mjs [--via-api]`）。
- **当日回归已修复**：`85918c2` 的精简索引一度让 `channel.html` 数据加载失败、`detail.html` 标题多出 `undefined`，`b95ccf8` 已修复并实测通过；成因与验证见 [frontend/home/README.md](frontend/home/README.md) 的“回归与修复”一节。
- **前端回归检查**：`tests/check-pages.mjs` 起本地静态服务、用无头浏览器跑 19 个用例（首页／栏目页七种版式／详情页／分页／刷新回顶），断言无 `undefined`、无“数据加载失败”、无破图与横向溢出、控制台干净。首跑即发现“首页手动刷新未回顶”这一遗留缺陷，已一并修正（详见 [tests/README.md](tests/README.md)）。

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
node tests/api-check.mjs                                    # 41 项接口检查
php -S 127.0.0.1:8080 -t backend/public backend/public/router.php   # 起服务（站点 + 接口 + 后台）
```

起来后三处都能访问（同一个端口、同源）：

- 站点页面：<http://127.0.0.1:8080/>（取数走接口，接口不可用时自动回退静态快照）
- 后台管理：<http://127.0.0.1:8080/admin>（首次需 `php backend/bin/user.php create admin 你的密码 "管理员"` 建账号）
- 内容接口：<http://127.0.0.1:8080/api/v1/health>

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
│   └── images/            # 本地化图片资源（含 images/channel/ 138 张）
├── docs/                  # 需求、方案、接口契约、运维文档
├── backend/               # 主站 PHP CMS（应用层 + 前端 SSR + 静态化发布）
│   ├── bin/               # migrate（建表）· seed（快照灌库）· publish（静态化发布）
│   ├── public/index.php   # 接口唯一入口（Nginx 指向）
│   └── src/ · config/ · routes/ · templates/   # 源码与模板，实现见 backend/README.md
├── api/                   # 内容/资源 REST API（实现落在 backend/，本目录预留独立部署位）
├── services/proofreader/  # 智能编校 Python 微服务（规划）
├── database/migrations/   # MySQL / SQLite 双方言建表脚本
├── deploy/                # Nginx + PHP-FPM + MySQL 编排（docker-compose.yml 在根目录）
├── tools/migrate/         # 旧站数据迁移（阶段 D，规划）
└── tests/                 # 前端页面回归检查 + 后端接口对拍（已可用）
```

> 标注“规划”的为预留骨架，尚无实现代码；已实现的为 `frontend/home/`（前端静态版）、`backend/`（后端最小骨架）、`database/`（建表脚本）、`tests/`（两项检查）。

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
