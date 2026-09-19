# 河池政协网 · 内容与资源API契约（v1）

> 编制日期：2026年9月11日
> 状态：契约先行，后端已按本契约实现只读部分；2026-09-12修订第5节的图片大小上限（图片2 MB／附件32 MB）
> 依据：`frontend/home/data/` 下四份静态快照（`home.json`／`channel.json`／`article.json`／`channel-index.json`）的**实际字段**，以及《河池政协网开发思路与技术栈方案》的接口先行要求

## 1. 定位与原则

1. **字段对齐现有快照**：阶段A的静态快照就是本契约的原型，`/api/v1` 的响应体与快照**同构**，前端只需把 `fetch("data/channel.json")` 换成 `fetch("/api/v1/channels")`，页面结构与渲染逻辑不变（见 `docs/下阶段开发计划（2026-09-11）.md` 第三节阶段C第5条）。
2. **一个数据出口**：前台、后台、智能编校、以后的多端共用同一套REST接口，不允许各端直连数据库。
3. **只读公开态先行**：本版只定义对外只读接口；写接口（稿件增删改、附件上传、发布）留在 `/api/v1/admin/*`，随阶段C后台一起定稿。
4. **ID沿用旧库号**：稿件 `id` = 旧库 `rd_news.ID`，栏目 `type` = 旧库栏目号（`rd_news.Type`／`rd_menu`），便于301映射与迁移核对。

## 2. 通用约定

| 项 | 约定 |
| --- | --- |
| 基础路径 | `/api/v1` |
| 编码 | UTF-8，`Content-Type: application/json; charset=utf-8` |
| 时间格式 | `date` 为 `YYYY-MM-DD HH:MM`，`dateText` 为 `YYYY-MM-DD`（与快照一致，非ISO 8601，接入时统一转） |
| 分页参数 | `page` 从1开始，默认1；`size` 默认20，上限100，越界按上限处理 |
| 成功响应 | HTTP 200，直接返回数据对象，**不额外包 `code`／`msg` 信封**（与本仓库前端现有取数方式一致） |
| 错误响应 | 对应HTTP状态码 + `{"error":{"code":"not_found","message":"未找到栏目 999999"}}` |
| 错误码 | `bad_request`（400）／`not_found`（404）／`method_not_allowed`（405）／`internal_error`（500） |
| 跨域 | 本期前后台同源（Nginx同站点），默认不开CORS；多端接入时按白名单开 |
| 版本策略 | 路径带版本（`/api/v1`）；破坏性调整升 `/api/v2`，`v1` 至少保留一个并行周期 |
| 缓存 | 接口默认**不缓存**（`Cache-Control: no-store`，可用 `API_CACHE_MAX_AGE` 调大）。后台改稿、发稿后前台要立刻可见，因此默认不做浏览器缓存；等接入Redis/CDN并实现“发布即失效”之后再开缓存 |
| 鉴权 | 本期对外接口全部公开只读；`/api/v1/admin/*` 用会话（`sys_user` + `sys_role`），不走本版的匿名约定 |

**可见性规则**：对外接口只返回 `status = published` 的稿件（草稿、待审、已撤回、回收站内容一律不出现）。是否再按 `public_scope` 过滤由 `config.php` 的 `content.enforce_public_scope` 决定：`true` 只出 `public_scope = public`（近3年口径），`false`（当前）放开年限、归档稿同样对外。后台按稿件号直读不受此限制，因此“保存为草稿”在后台可见、在前台与接口上都看不到。判断条件统一取 `HechiZx\Content\PublicScope`，不要在各处写死 `public_scope`。

**提案数据不对外**：政协委员提案（`cms_proposal` 及其附件、流转记录）**不进本契约**——它只在委员门户 `/member` 与后台 `/admin` 之间经服务端渲染页面流转，不设任何对外接口，也不写静态页、不进sitemap。设计与字段见 [提案系统设计说明](提案系统设计说明.md)。

## 3. 数据对象

### 3.1 Channel（栏目）

与 `data/channel.json` 的单个栏目同构：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `type` | string | 栏目号（旧库 `Type`），前端 `?id=` 用的就是它 |
| `columnId` | string | 所属一级栏目入口参数，一级栏目与自身相同 |
| `slug` | string | 拼音标识，用于后续静态化路径与SEO |
| `name` | string | 一级栏目名，如“政协动态” |
| `inner` | string | 当前子栏目名，如“市政协动态” |
| `intro` | string | 栏目简介 |
| `layout` | string | 版式：`list`／`leaders`／`about`／`county`／`gallery`／`video`／`topic`／`interactive` |
| `siblings` | array | 同级子栏目 `{type, name, url, active}` |
| `total` | int | 栏目**当前对外条数**（`status=published`，年限口径下再加 `public_scope=public`；实时统计，2026-09-12起不再用快照常量 `sys_channel.total_count`） |
| `list` | array | 稿件列表项，见3.2；`?withList=0` 时省略 |
| `counties` | array? | 仅 `layout=county`：`{name, url}`，url为空表示该县区尚未建站 |
| `note` | string? | 仅 `layout=interactive`：互动栏目说明 |
| `homeSourced` | bool? | 数据取自首页模块（视频、专题、互动），不参与侧栏“最新新闻”汇总 |
| `feature` | object? | 仅 `layout=about`：一页式栏目简介块 `{title, summary, url}`（当前无栏目使用） |

### 3.2 ArticleListItem（列表项）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | string | 稿件号 |
| `title` | string | 标题 |
| `url` | string | 详情地址，**一律是发布器产出的对外唯一地址 `/article/<id>.html`**（2026-09-17起；原型期的 `detail.html?id=<id>` 只留给后台预览页） |
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
| `content` | string | 正文HTML：先过白名单清洗，再过展示归一化（去空段、裁多余首部空格、拍平嵌套块、资源地址改写、删掉与作者栏重复的末尾署名、按题区规则处理标题重复行） |
| `images` | array | 正文图片路径，2张以上前端出图集灯箱；已剔除视频文件（旧库图集记录里有mp4），并做与正文同一套地址改写 |
| `attachments` | array | 附件 `{name, url, ext}` |
| `hasBody` | bool | 是否已有正文；`false` 表示列表可见但正文未录入，前端给说明块而非空正文 |

**正文归一化（2026-09-14，末尾署名口径2026-09-15）**：接口与发布器（`Publish\Publisher`）都对清洗后的正文调用 `Content\BodyNormalizer`，
两处输出同构，库里的 `cms_article.content_html` 保持原样（后台编辑回填读的仍是原文）。规则与实测依据见
[../backend/README.md](../backend/README.md) 的“正文出口归一化”一节。

## 4. 接口清单

### 4.1 `GET /api/v1/health`

探活，用于部署自检与监控。响应：`{"status":"ok","driver":"mysql","time":"2026-09-11 18:00:00"}`

### 4.2 `GET /api/v1/home`

首页聚合数据，与 `data/home.json` 同构（快照21个顶层键：`meta`、`nav`、`leaders`、`slides`、`notice`、`bookCity`、`antiGang`、`videos`、`zxdt`、`sxNews`、`zxMeeting`、`zwhWork`、`partyGroups`、`theory`、`imageNews`、`memberWindow`、`countyZx`、`ranking`、`topic`、`scenery`、`links`；2026-09-11起接口另返回 `banners`，格式为“槽位 ⇒ 条目数组”，槽位固定为 `hero-1`、`hero-2`、`body-1`…`body-5`）。

> 其中 `zxdt`／`sxNews`／`zxMeeting`／`notice`／`bookCity`／`antiGang`／`zwhWork`／`partyGroups`／`theory`／`imageNews`／`scenery`／`memberWindow`／`countyZx` 的列表由后台“其他栏目”里配置的绑定栏目**实时从稿件表组装**（置顶在前、再按栏目内顺序、再按发布时间），不足条数时用 `cms_home_block` 的同名快照兜底；其余键仍来自快照。`slides` 改由 `cms_home_slide` 提供。

> `slides[].url` 一律是**站内地址**：引用了稿件的条目返回 `/article/<id>.html`；外链条目里若填的是旧站稿件地址（`news_view.php?id=`、`cq_view.php?id=`、`html/news-view-<id>.html`）且这篇已在新库公开发布，也会自动改写成同一个静态详情页地址，只有真正的外部链接（或尚未入库的稿件）保持原样，避免点开落到空页。
>
> **全站地址口径（2026-09-17，A1）**：栏目地址 `/channel/<目录名>/`、详情地址 `/article/<id>.html` 是对外唯一地址，由发布器产出、Nginx直出；`nav` 块里的旧站栏目地址在接口出口就换成了静态栏目页地址，指向旧站稿件页的条目也统一改成 `/article/<id>.html`。前端内页 `channel.html?id=`／`detail.html?id=` 自同日降级为后台预览与本地调试用，页面已加 `noindex`。

响应：`{"home": { ... }}`

> 首页各模块的字段说明见 `frontend/home/README.md` 的“数据契约”一节，此处不重复。

### 4.3 `GET /api/v1/channels`

栏目索引。参数：

| 参数 | 说明 |
| --- | --- |
| `withList` | `1`（默认）带稿件的 `list`；`0` 只出栏目元数据 |
| `listSize` | 每个栏目带的列表条数，默认50，上限200 |
| `column` | 只要某个一级栏目下的子栏目，如 `column=904` 的上级 |
| `layout` | 按版式过滤，如 `layout=gallery` |

响应：`{"channels": [Channel, ...]}`

> `withList=0` 的返回等价于现前端用的4.4 KB精简索引 `data/channel-index.json` 的用途（链接改写）。

### 4.4 `GET /api/v1/channels/{type}`

单个栏目（含 `list`，条数同样受 `listSize`／默认50控制）。`type` 不存在时404 + `not_found`。

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
  "total": 530,
  "pages": 27
}
```

> `total` 是**当前公开条数**（实时统计，不含草稿与归档），与栏目页列表能翻到的条数一致：
> 迁移后（2026-09-05全量备份口径）904栏目共927篇，其中公开530篇、超出公开年限进 `archive` 382篇、
> 草稿15篇，所以 `total` 是530、`pages` 是27；原型期样例库只有24条时 `total` 即为24。
> 栏目页列表用本接口真分页，每页20条（2026-09-12之前前端只对首屏取的50条做本地分页）。

### 4.6 `GET /api/v1/article/{id}`

单篇详情。`id` 不存在时404 + `not_found`；只有列表信息、未入库正文时返回 `hasBody=false` 与空正文，由前端出说明块。

响应：`{"article": Article}`

### 4.7 `GET /api/v1/attachments?article={id}`

某篇稿件的附件列表（详情页“附件下载”区）。

响应：`{"attachments": [{"id": 1, "name": "...", "url": "...", "ext": "docx", "size": 0}]}`

### 4.8 `GET /api/v1/search?q=&scope=&page=&size=`

站内检索（标题 + 摘要 + 正文）。响应结构与 `/articles` 相同，另带 `q`、`scope` 两个回显字段，
每条结果加一段 `excerpt` 命中片段（**纯文本**，转义与关键词高亮由前端做，接口不返回HTML）。

| 参数 | 默认 | 说明 |
| --- | --- | --- |
| `q` | 必填 | 检索词；为空返回400 `bad_request` |
| `scope` | `all` | `all`＝标题＋摘要＋正文，`title`＝只查标题；其它取值返回400 `bad_request` |
| `page` | 1 | 页码 |
| `size` | 20 | 每页条数，上限100 |

- **排序**：带检索词时先按“标题命中优先”（`CASE WHEN a.title LIKE … THEN 0 ELSE 1 END`），
  再按发布时间倒序、稿件号倒序——正文命中的长尾不会把标题命中的稿件压到几十页之后。
- **通配符**：关键词里的 `%` 与 `_` 按字面量处理（`LIKE … ESCAPE '!'`），不当作通配符，
  否则检索词里一个 `%` 就会命中全库。
- **占位符**：三段条件（标题／摘要／正文）各用一个命名占位符。`Support/Db.php` 关掉了预处理模拟
  （`ATTR_EMULATE_PREPARES = false`），同一个命名占位符在一条语句里出现两次，MySQL上会报HY093。

> 本期先做 `LIKE` 检索；数据量增长或需要分词排序时，再在SQL层换成MySQL全文索引，接口不变。

### 4.9 `GET /api/v1/channel-index`

站内链接映射用的精简索引（前端 `js/site-links.js` 靠它把旧站栏目地址改写成静态栏目页地址），等价于阶段A的 `data/channel-index.json`。

每条含 `type`、`ids`、`path`：`path` 是发布器同一套规则（`Publish\StaticPaths`）算出的静态栏目页地址（如 `/channel/zhengxie-dongtai-904/`）。稿件地址不再依赖这份索引——前端对旧站稿件地址一律改写为 `/article/<id>.html`（2026-09-17 A1）。

| 参数 | 默认 | 说明 |
| --- | --- | --- |
| `listSize` | 50 | 每个栏目最多带多少个稿件id |

响应：`{"channels":[{"type":"904","ids":["62180","62147"]}]}`

> 视频、专题这类取自首页模块的栏目没有稿件id，`ids` 返回空数组。

## 5. 上传文件（后台写入，前台直出）

后台的附件与正文插图落在 `backend/public/uploads/{稿件号}/`，对外地址 `/uploads/{稿件号}/{文件名}`，与站点同源直出。

| 类型 | 允许扩展名 | 大小上限 | 说明 |
| --- | --- | --- | --- |
| 附件 | pdf／doc／docx／xls／xlsx／ppt／pptx／zip／rar／txt | 32 MB | 详情页“附件下载”区展示 |
| 正文插图 | jpg／jpeg／png／gif／webp | **2 MB** | 走 `POST /admin/media/image`，插在编辑器光标处（文首／文中／文末都行），并登记为图集图片（≥2张出灯箱） |
| 视频 | mp4／webm／ogg／mov／m4v | 32 MB | 编辑器内插入 `<video>`，走 `POST /admin/media/video` |

2026-09-12起**图片一律 ≤ 2 MB**（站内横幅、头条轮换大图、正文插图、编辑器插图同一口径），超限时接口返回的提示是“图片超过服务器允许的上传大小（2 MB）”，不再只有PHP的错误码。编辑器两个上传接口（`/admin/media/image`、`/admin/media/video`）为JSON契约：字段名 `file-0`，可选 `article` 传稿件号，返回 `{"result":[{"url","name","size"}]}`，CSRF走 `X-CSRF-Token` 头；新建页没有稿件号时先落 `/uploads/pending/`，保存稿件时迁入 `uploads/{稿件号}/`。

删除稿件时，该稿件目录下的上传文件一并清除；上传目录不入git（见根 `.gitignore`）。

## 6. 静态化发布产物

全文静态化是本项目的必做项（Nginx直出、保SEO与访问速度），发布器按模板产出静态文件，接口用于后台预览与多端：

| 产物 | 路径 | 说明 |
| --- | --- | --- |
| 首页 | `/index.html` | 首页全文静态 |
| 栏目页 | `/channel/<目录名>/index.html`、分页 `/channel/<目录名>/page-<n>.html` | 列表分页静态；目录名规则见下 |
| 详情页 | `/article/<id>.html` | 正文静态 |

**归档稿件是否出页跟随公开口径**（2026-09-12定，2026-09-19起默认放开）：产出 `/article/<id>.html` 并进sitemap的条件是 `status=published` 且有正文；年限口径（`content.enforce_public_scope=true`）下再加 `public_scope=public`，此时 `public_scope=archive`（超出公开年限的历史稿）只留后台、旧地址返回404。放开年限时归档稿照常出页并登记301。
| 数据快照 | `/data/home.json`、`/data/channel.json`、`/data/article.json` | 与接口同构；**仅 `php backend/bin/publish.php --data-only` 产出**，默认发布不再产出（2026-09-17起，线上无消费者） |
| 站点地图 | `/sitemap.xml` | 首页 + 栏目 + 详情 |

发布触发：后台保存／下线 → 该稿件与所属栏目、首页增量刷新；全量重建走命令行（`php backend/bin/publish.php`，只出静态页与sitemap）。

**栏目目录名**（2026-09-12修正）：slug唯一时用 `/channel/<slug>/`；slug重复的一级栏目（如902—906都写 `zhengxie-dongtai`）补上栏目号，写成 `/channel/<slug>-<栏目号>/`。规则实现在 `backend/src/Publish/StaticPaths.php`。此前的写法会让43个栏目只产出25个静态页，已修正并纳入 `tests/redirect-check.mjs`。

## 7. 与旧站301的衔接

旧地址（`/html/news-view-<id>.html`、`/news_view.php?id=<id>`、`/cq_view.php?id=<id>`、`/news_list.php?id=<栏目号>` 等）由 `sys_url_redirect` 表落到新地址：详情落 `/article/<id>.html`，栏目落 `/channel/<目录名>/`，入口页与专题目录落首页／专题栏目页。

生成与判定：

1. `php backend/bin/redirects.php --out=<发布目录>` 按库内内容生成映射，并产出Nginx片段、核对用CSV与报告；
2. Nginx按片段的旧地址形态把请求转给PHP入口，入口查表命中即301，并把命中数累加到 `sys_url_redirect.hits`；
3. 只登记“已发布 + 有正文”且在当前公开口径下会产出静态页的稿件，保证每条301都指向真实存在的静态页，不会301到404（年限口径下归档稿不登记，旧地址404）；县区子站（`q=<县区号>`）本期不映射。

逐条规则、旧地址出处与未覆盖项见 [旧地址301映射说明.md](旧地址301映射说明.md)。

## 8. 待定项

1. 详情页静态化路径（`/article/<id>.html` 还是按栏目 + 别名）需与甲方确认SEO与旧链路口径后冻结。
2. 写接口（`/api/v1/admin/*`）随阶段C后台开发定稿，本版不含。
3. 县区子站与稿件互通的接口（`site_id` 维度）本期预留，不开通。
4. 接口鉴权方案（会话Cookie还是Token）待与后台一并确定。
