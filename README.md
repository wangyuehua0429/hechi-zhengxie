# 广西河池政协网（hechi-zhengxie）

> 河池市融媒体中心承建并负责技术维护的政务门户网站源码仓库。
> 主办：中国人民政治协商会议河池市委员会办公室；官网：www.gxhczx.gov.cn

## 当前进度（2026-09-11）

- **前端静态版首页**：`frontend/home/index.html` 已完成，按现网内容做静态化重构——语义化骨架、响应式（桌面/平板/手机）、适老化字号与高对比度、轮播、站头横幅本地化。
- **内页模板全集**：`channel.html`（二级栏目页）与 `detail.html`（信息详情页）按 `list`／`leaders`／`about`／`county`／`gallery`／`video`／`topic`／`interactive` 八类版式实现（`about` 暂无栏目使用），配套样例数据 43 个栏目页、70 篇详情，图片全部本地化。
- **站头与链接映射**：内页站头收窄为全幅约三分之一，首页进出内页带收缩／展开动画；`js/site-links.js` 把旧站栏目与稿件地址改写为新版内页，首页与内页共用同一份映射。
- **数据来源**：当前用 `frontend/home/data/` 下的静态快照（`home.json`／`channel.json`／`article.json`／`channel-index.json`）复用现网内容；后端就绪后以 REST API 平替该数据源，页面结构与渲染逻辑不变（详见 [frontend/home/README.md](frontend/home/README.md)）。
- **后端与编校微服务**：属目标架构，仍为零行代码；仓库已按架构预留 `backend/`、`api/`、`services/proofreader/`、`database/`、`tools/migrate/`、`tests/` 等目录。
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
│   ├── js/shell.js        # 内页公共外壳（顶栏 / 导航 / 页脚 / 侧栏 / 无障碍）
│   ├── js/channel.js      # 栏目页渲染与分页
│   ├── js/detail.js       # 详情页渲染、附件下载、图集灯箱、字号、打印
│   ├── js/header-fold.js  # 站头收窄 / 展开动画
│   ├── js/site-links.js   # 站内链接映射（旧站地址 → 新内页）
│   ├── data/              # 四份内容快照（home / channel / article / channel-index）
│   └── images/            # 本地化图片资源（含 images/channel/ 138 张）
├── docs/                  # 需求、方案、接口契约、运维文档
├── backend/               # 主站 PHP CMS（应用层 + 前端 SSR + 静态化发布）
│   ├── public/            # Web 根目录（Nginx 指向 / 入口）
│   └── src/ · config/ · routes/ · templates/ · resources/   # 源码与模板（规划）
├── api/                   # 内容/资源 REST API（规划）
├── services/proofreader/  # 智能编校 Python 微服务（规划）
├── database/              # migrations + seed（规划）
├── tools/migrate/         # 旧站数据迁移（规划）
└── tests/                 # 跨模块集成测试（规划）
```

> 目录中标注“规划”的为预留骨架，尚无实现代码；`frontend/home/` 为本期实际交付。

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
