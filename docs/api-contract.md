# 河池政协网 · 内容与资源 API 契约（v1）

> 编制日期：2026 年 9 月 11 日
> 状态：契约先行，待阶段 C 后端实现后按实际联调结果修订
> 依据：`frontend/home/data/` 下四份静态快照（`home.json`／`channel.json`／`article.json`／`channel-index.json`）的**实际字段**，以及《河池政协网开发思路与技术栈方案》的接口先行要求

## 1. 定位与原则

1. **字段对齐现有快照**：阶段 A 的静态快照就是本契约的原型，`/api/v1` 的响应体与快照**同构**，前端只需把 `fetch("data/channel.json")` 换成 `fetch("/api/v1/channels")`，页面结构与渲染逻辑不变（见 `docs/下阶段开发计划（2026-09-11）.md` 第三节阶段 C 第 5 条）。
2. **一个数据出口**：前台、后台、智能编校、以后的多端共用同一套 REST 接口，不允许各端直连数据库。
3. **只读公开态先行**：本版只定义对外只读接口；写接口（稿件增删改、附件上传、发布）留在 `/api/v1/admin/*`，随阶段 C 后台一起定稿。
4. **ID 沿用旧库号**：稿件 `id` = 旧库 `rd_news.ID`，栏目 `type` = 旧库栏目号（`rd_news.Type`／`rd_menu`），便于 301 映射与迁移核对。

## 2. 通用约定

| 项 | 约定 |
| --- | --- |
| 基础路径 | `/api/v1` |
| 编码 | UTF-8，`Content-Type: application/json; charset=utf-8` |
| 时间格式 | `date` 为 `YYYY-MM-DD HH:MM`，`dateText` 为 `YYYY-MM-DD`（与快照一致，非 ISO 8601，接入时统一转） |
| 分页参数 | `page` 从 1 开始，默认 1；`size` 默认 20，上限 100，越界按上限处理 |
| 成功响应 | HTTP 200，直接返回数据对象，**不额外包 `code`／`msg` 信封**（与本仓库前端现有取数方式一致） |
| 错误响应 | 对应 HTTP 状态码 + `{"error":{"code":"not_found","message":"未找到栏目 999999"}}` |
| 错误码 | `bad_request`(400)／`not_found`(404)／`method_not_allowed`(405)／`internal_error`(500) |
| 跨域 | 本期前后台同源（Nginx 同站点），默认不开 CORS；多端接入时按白名单开 |
| 版本策略 | 路径带版本（`/api/v1`）；破坏性调整升 `/api/v2`，`v1` 至少保留一个并行周期 |
| 缓存 | 接口默认**不缓存**（`Cache-Control: no-store`，可用 `API_CACHE_MAX_AGE` 调大）。后台改稿、发稿后前台要立刻可见，因此默认不做浏览器缓存；等接入 Redis/CDN 并实现“发布即失效”之后再开缓存 |
| 鉴权 | 本期对外接口全部公开只读；`/api/v1/admin/*` 用会话（`sys_user` + `sys_role`），不走本版的匿名约定 |

**可见性规则**：对外接口只返回 `status = published` 且 `public_scope = public` 的稿件（草稿、已下线、后台留存内容不出现）。后台按稿件号直读不受此限制，因此“保存为草稿”在后台可见、在前台与接口上都看不到。

## 3. 数据对象

### 3.1 Channel（栏目）

与 `data/channel.json` 的单个栏目同构：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `type` | string | 栏目号（旧库 `Type`），前端 `?id=` 用的就是它 |
| `columnId` | string | 所属一级栏目入口参数，一级栏目与自身相同 |
| `slug` | string | 拼音标识，用于后续静态化路径与 SEO |
| `name` | string | 一级栏目名，如“政协动态” |
| `inner` | string | 当前子栏目名，如“市政协动态” |
| `intro` | string | 栏目简介 |
| `layout` | string | 版式：`list`／`leaders`／`about`／`county`／`gallery`／`video`／`topic`／`interactive` |
| `siblings` | array | 同级子栏目 `{type, name, url, active}` |
| `total` | int | 栏目全量条数（旧库统计值，非样例条数） |
| `list` | array | 稿件列表项，见 3.2；`?withList=0` 时省略 |
| `counties` | array? | 仅 `layout=county`：`{name, url}`，url 为空表示该县区尚未建站 |
| `note` | string? | 仅 `layout=interactive`：互动栏目说明 |
| `homeSourced` | bool? | 数据取自首页模块（视频、专题、互动），不参与侧栏“最新新闻”汇总 |
| `feature` | object? | 仅 `layout=about`：一页式栏目简介块 `{title, summary, url}`（当前无栏目使用） |

### 3.2 ArticleListItem（列表项）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | string | 稿件号 |
| `title` | string | 标题 |
| `url` | string | 详情地址；后端产出为 `/article/<id>.html`（静态化后），原型期仍可返回 `detail.html?id=<id>` |
| `date` | string | 发布时间 `YYYY-MM-DD HH:MM` |
| `datetime` | string | `date` 的可排序形式 |
| `source` | string | 来源 |
| `views` | string | 阅读量（旧库为字符串，保持原样） |
| `img` | string | 缩略图路径，无图为 `""` |
| `hasBody` | bool | 是否已入库正文（`false` 表示只有列表、点开只有说明块） |
| `role` | string? | 仅 `leaders` 版式：职务，如“主席”“副主席” |

### 3.3 Article（详情）

与 `data/article.json` 的单篇同构：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | int | 稿件号 |
| `channelType` | string | 所属栏目号 |
| `channelName` | string | 所属栏目名 |
| `title` / `subtitle` | string | 主标题／引题副标题 |
| `date` / `dateText` | string | 发布时间 |
| `source` / `author` / `editor` | string | 来源／作者／编辑 |
| `views` | string | 阅读量 |
| `summary` | string | 摘要 |
| `content` | string | 正文 HTML（已清洗：去 `font`／`span` 内联样式、统一图片路径） |
| `images` | array | 正文图片路径，2 张以上前端出图集灯箱 |
| `attachments` | array | 附件 `{name, url, ext}` |
| `hasBody` | bool | 是否已有正文；`false` 表示列表可见但正文未录入，前端给说明块而非空正文 |

## 4. 接口清单

### 4.1 `GET /api/v1/health`

探活，用于部署自检与监控。响应：`{"status":"ok","driver":"mysql","time":"2026-09-11 18:00:00"}`

### 4.2 `GET /api/v1/home`

首页聚合数据，与 `data/home.json` 同构（快照 21 个顶层键：`meta`、`nav`、`leaders`、`slides`、`notice`、`bookCity`、`antiGang`、`videos`、`zxdt`、`sxNews`、`zxMeeting`、`zwhWork`、`partyGroups`、`theory`、`imageNews`、`memberWindow`、`countyZx`、`ranking`、`topic`、`scenery`、`links`；2026-09-11 起接口另返回 `banners`，格式为“槽位 ⇒ 条目数组”，槽位固定为 `hero-1`、`hero-2`、`body-1`…`body-5`）。

> 其中 `zxdt`／`sxNews`／`zxMeeting`／`notice`／`bookCity`／`antiGang`／`zwhWork`／`partyGroups`／`theory`／`imageNews`／`scenery`／`memberWindow`／`countyZx` 的列表由后台“其他栏目”里配置的绑定栏目**实时从稿件表组装**（置顶在前、再按栏目内顺序、再按发布时间），不足条数时用 `cms_home_block` 的同名快照兜底；其余键仍来自快照。`slides` 改由 `cms_home_slide` 提供。

响应：`{"home": { ... }}`

> 首页各模块的字段说明见 `frontend/home/README.md` 的“数据契约”一节，此处不重复。

### 4.3 `GET /api/v1/channels`

栏目索引。参数：

| 参数 | 说明 |
| --- | --- |
| `withList` | `1`（默认）带稿件的 `list`；`0` 只出栏目元数据 |
| `listSize` | 每个栏目带的列表条数，默认 50，上限 200 |
| `column` | 只要某个一级栏目下的子栏目，如 `column=904` 的上级 |
| `layout` | 按版式过滤，如 `layout=gallery` |

响应：`{"channels": [Channel, ...]}`

> `withList=0` 的返回等价于现前端用的 4.4 KB 精简索引 `data/channel-index.json` 的用途（链接改写）。

### 4.4 `GET /api/v1/channels/{type}`

单个栏目（含 `list`，条数同样受 `listSize`／默认 50 控制）。`type` 不存在时 404 + `not_found`。

响应：`{"channel": Channel}`

### 4.5 `GET /api/v1/articles`

稿件列表，用于栏目页分页、更多列表与检索结果。

| 参数 | 默认 | 说明 |
| --- | --- | --- |
| `channel` | 空 | 栏目号，支持逗号分隔多栏目 |
| `q` | 空 | 标题关键词（与 `/search` 同语义，便于前端复用） |
| `page` / `size` | 1 / 20 | 分页 |
| `order` | `date_desc` | 仅支持 `date_desc`／`date_asc` |

响应：

```json
{
  "articles": [ArticleListItem],
  "page": 1,
  "size": 20,
  "total": 825,
  "pages": 42
}
```

> `total` 是**库里真实条数**（旧库同口径），不是当前样例条数；原型期样例库只有 24 条时，`total` 即为 24。

### 4.6 `GET /api/v1/article/{id}`

单篇详情。`id` 不存在时 404 + `not_found`；只有列表信息、未入库正文时返回 `hasBody=false` 与空正文，由前端出说明块。

响应：`{"article": Article}`

### 4.7 `GET /api/v1/attachments?article={id}`

某篇稿件的附件列表（详情页“附件下载”区）。

响应：`{"attachments": [{"id": 1, "name": "...", "url": "...", "ext": "docx", "size": 0}]}`

### 4.8 `GET /api/v1/search?q=&page=&size=`

站内检索（标题 + 摘要 + 正文）。响应结构与 `/articles` 相同。

> 本期先做 `LIKE` 检索；数据量增长或需要分词排序时，再在 SQL 层换成 MySQL 全文索引，接口不变。

### 4.9 `GET /api/v1/channel-index`

站内链接映射用的精简索引（前端 `js/site-links.js` 靠它把旧站地址改写成新版内页地址），等价于阶段 A 的 `data/channel-index.json`。

| 参数 | 默认 | 说明 |
| --- | --- | --- |
| `listSize` | 50 | 每个栏目最多带多少个稿件 id |

响应：`{"channels":[{"type":"904","ids":["62180","62147"]}]}`

> 视频、专题这类取自首页模块的栏目没有稿件 id，`ids` 返回空数组。

## 5. 上传文件（后台写入，前台直出）

后台的附件与正文插图落在 `backend/public/uploads/{稿件号}/`，对外地址 `/uploads/{稿件号}/{文件名}`，与站点同源直出。

| 类型 | 允许扩展名 | 大小上限 | 说明 |
| --- | --- | --- | --- |
| 附件 | pdf／doc(x)／xls(x)／ppt(x)／zip／rar／txt | 32 MB | 详情页“附件下载”区展示 |
| 正文插图 | jpg／jpeg／png／gif／webp | 32 MB | 上传后追加到正文末尾，并登记为图集图片（≥2 张出灯箱） |

删除稿件时，该稿件目录下的上传文件一并清除；上传目录不入 git（见根 `.gitignore`）。

## 6. 静态化发布产物

全文静态化是本项目的必做项（Nginx 直出、保 SEO 与访问速度），发布器按模板产出静态文件，接口用于后台预览与多端：

| 产物 | 路径 | 说明 |
| --- | --- | --- |
| 首页 | `/index.html` | 首页全文静态 |
| 栏目页 | `/channel/<slug>/index.html`、分页 `/channel/<slug>/page-<n>.html` | 列表分页静态 |
| 详情页 | `/article/<id>.html` | 正文静态 |
| 数据快照 | `/data/home.json`、`/data/channel.json`、`/data/article.json` | 与接口同构，供前端异步取数 |
| 站点地图 | `/sitemap.xml` | 首页 + 栏目 + 详情 |

发布触发：后台保存／下线 → 该稿件与所属栏目、首页增量刷新；全量重建走命令行（`php backend/bin/publish.php --all`）。

## 7. 与旧站 301 的衔接

旧地址（`news-view-<id>.html`、`news_list.php?id=<n>`、`cq_view.php?id=<n>`）由 `sys_url_redirect` 表落到新地址，映射规则见阶段 B 的《旧 URL 清单》。接口层不处理 301，由 Nginx／入口统一转发。

## 8. 待定项

1. 详情页静态化路径（`/article/<id>.html` 还是按栏目 + 别名）需与甲方确认 SEO 与旧链路口径后冻结。
2. 写接口（`/api/v1/admin/*`）随阶段 C 后台开发定稿，本版不含。
3. 县区子站与稿件互通的接口（`site_id` 维度）本期预留，不开通。
4. 接口鉴权方案（会话 Cookie 还是 Token）待与后台一并确定。
