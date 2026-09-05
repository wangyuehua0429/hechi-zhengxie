# 广西河池政协网（hechi-zhengxie）

> 河池市融媒体中心承建并负责技术维护的政务门户网站源码仓库。
> 主办：中国人民政治协商会议河池市委员会办公室；官网：www.gxhczx.gov.cn

## 当前进度（2026-09-05）

- **前端静态版首页**：`frontend/home/` 已完成并提交，按现网内容做静态化重构——语义化骨架、响应式（桌面/平板/手机）、适老化字号与高对比度、轮播、站头横幅本地化。
- **数据来源**：当前用 `frontend/home/data/home.json` 静态快照复用现网内容；后端就绪后以 REST API 平替该数据源，页面结构与 `main.js` 渲染逻辑不变（详见 [frontend/home/README.md](frontend/home/README.md)）。
- **后端与编校微服务**：属目标架构，尚未开始编码；仓库已按架构预留 `backend/`、`api/`、`services/proofreader/` 等目录。

## 本地预览

```bash
cd frontend/home && python3 -m http.server 8899
# 打开 http://127.0.0.1:8899/index.html
```

> 直接双击 `index.html` 会因浏览器拦截本地 `fetch` JSON 而无法加载数据，需经静态服务器访问。

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
├── frontend/home/         # 新版首页前端静态版（本期实际交付，数据来自 home.json）
│   ├── index.html         # 页面骨架 + 数据挂载点
│   ├── css/style.css      # 视觉 / 响应式 / 无障碍样式
│   ├── js/main.js         # 数据渲染 / 轮播 / 导航 / 字号 / 高对比度
│   ├── data/home.json     # 现网内容快照（数据契约原型）
│   └── images/            # 本地化图片资源
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

`frontend/home/data/home.json` 顶层键包含：`meta`（站名/域名/版权主体/ICP/公安备案）、18 个主栏目 `nav`、`leaders`、`slides`、`notice`/`bookCity`/`antiGang`/`videos`、`zxdt`/`sxNews`/`zxMeeting`、`zwhWork`/`partyGroups`/`theory`、`imageNews`、`memberWindow`、`countyZx`、`ranking`、`topic`、`scenery`、`links`。单条信息统一为 `{title, url, date?}`，图片类条目含 `img`；详细字段表见 [frontend/home/README.md](frontend/home/README.md)。

## 一期范围与合规约束

- 本期只建**政协主站 + 校对模块**；县区子站与稿件互通缓做，按 `site_id` 预留升级接口。
- 政务云 **2026-11-01** 停服前完成迁出；主站对照 61 天窗口（9/1—11/1）推进。
- 数据策略：近 3 年数据公开访问，更早数据后台留存不公开；旧 URL 统一 301 映射保 SEO 与外链。

## 说明

详细需求与选型依据见《河池政协网开发思路与技术栈方案》。运行环境、数据库结构、接口契约随实现推进逐步补齐。

