# 河池政协网 · 新版首页（前端静态版）

本期只做前端、不写后端；数据以 `data/home.json` 静态快照复用现网“广西河池政协网”内容，后端就绪后以 REST API 平替该数据源。

## 预览

浏览器直接打开 `index.html` 时，`fetch` 本地 JSON 会被拦截，需通过本地静态服务器访问：

```bash
cd frontend/home
python3 -m http.server 8899
# 打开 http://127.0.0.1:8899/index.html
```

## 目录

```
frontend/home/
├── index.html          页面结构（语义化骨架 + 数据挂载点）
├── css/style.css       视觉、响应式断点、无障碍/适老样式
├── js/main.js          数据渲染、轮播、导航、字号/高对比度交互
├── images/head.jpg     顶部站头横幅（已本地化，桌面展示/移动端换文字品牌条）
└── data/home.json      现网内容快照（数据契约原型）
```

## 数据契约（home.json → 未来 REST API）

顶层键：

| 键 | 说明 |
| --- | --- |
| `meta` | 站名、域名、版权主体、ICP/公安备案号 |
| `nav` | 18 个主栏目（标题 + 链接） |
| `leaders` | 主席 / 副主席列表 / 秘书长 / 三个入口 |
| `slides` | 首屏要闻轮播（标题 / 链接 / 图片） |
| `notice` `bookCity` `antiGang` `videos` | 公告通知 / 网上书院 / 扫黑除恶 / 政协视频 |
| `zxdt` `sxNews` `zxMeeting` | 政协动态（tab 链接 + 列表）、时政要闻、政协会议（tab 链接 + 列表） |
| `zwhWork` `partyGroups` `theory` | 专委会工作 / 党派团体 / 理论研究 |
| `imageNews` | 图片新闻 |
| `memberWindow` | 委员之窗（featured 特写 / list 名单 / gallery 小图） |
| `countyZx` | 县（区）政协（区县列表 + 县区动态） |
| `ranking` | 2026 来稿排名（前五） |
| `topic` | 专题 |
| `scenery` | 河池风光 |
| `links` | 网站链接（logo 横条 + 分组链接） |

单个信息条目统一为 `{title, url, date?}`，图片类条目额外含 `img`。`url` 当前指向现网地址；接入 REST API 后仅需替换数据源，页面结构与`main.js` 渲染逻辑不变。

## 交互与无障碍

- 响应式：桌面 ≥1024 / 平板 768 / 手机 390，移动端汉堡导航
- 轮播：自动播放 + 圆点/箭头切换、悬停暂停
- 适老化字号：`A- / 默认 / A+`；高对比度：右上角“无障碍”按钮
- 语义化标签、图片 `alt`、键盘可聚焦、图片加载失败占位降级

> 顶部站头横幅已随页面本地化（`images/head.jpg`）；其余内容图片（轮播/领导/图片新闻/河池风光等）仍为绝对 URL 热链，待后续图片迁移完成后统一替换为本地资源。

---

## 二级栏目页与详情页（2026-09-11 新增）

按“前端二级页与详情页 → 后端 → 历史数据接入”的推进顺序，本轮先完成内页设计与静态原型，数据仍为快照，后端就绪后以 REST API 平替。

### 页面与访问方式

沿用旧站“查询参数驱动”的 URL 形态，便于后端接管后保持地址不变：

| 页面 | 文件 | 访问示例 |
| --- | --- | --- |
| 二级栏目列表页 | `channel.html` | `channel.html?id=904`（市政协动态）、`channel.html?id=306`（时政要闻）、`channel.html?id=314`（图片新闻） |
| 信息详情页 | `detail.html` | `detail.html?id=62180` |

本地预览：

```bash
cd frontend/home && python3 -m http.server 8901
# 栏目页 http://127.0.0.1:8901/channel.html?id=904
# 详情页 http://127.0.0.1:8901/detail.html?id=62180
```

### 新增文件

```
frontend/home/
├── channel.html            二级栏目列表页
├── detail.html             信息详情页
├── favicon.ico             站点图标（取自旧站）
├── css/inner.css           内页样式（栏目页 + 详情页）
├── js/shell.js             内页公共外壳（顶栏 / 导航 / 滚动要闻 / 页脚 / 无障碍交互）
├── js/channel.js           栏目页渲染与分页
├── js/detail.js            详情页渲染、字号调整、打印
├── data/channel.json       栏目页样例数据
├── data/article.json       详情页样例数据
└── images/channel/         列表缩略图与正文配图（共 48 张，已本地化）
```

外壳复用 `css/style.css` 的既有变量与站头样式，站级数据（`meta` / `nav` / `marquee`）统一读 `data/home.json`，不新增副本；`js/shell.js` 会按 `window.INNER_CHANNELS` 把导航中的栏目指向本地内页，未覆盖的栏目仍指向旧站。

### 内页数据契约

`data/channel.json`（列表页）单条栏目：

| 字段 | 说明 |
| --- | --- |
| `type` / `slug` / `name` / `inner` | 旧库栏目 ID、拼音标识、一级栏目名、当前子栏目名 |
| `intro` | 栏目简介 |
| `siblings` | 同级子栏目（`type` + `name`），用于栏目切换 |
| `total` | 栏目全量条数（旧库统计值） |
| `list[]` | `{id, title, url, date, source, views, img, hasBody}`，按发布时间倒序 |

`data/article.json`（详情页）单篇：

| 字段 | 说明 |
| --- | --- |
| `id` / `channelType` / `channelName` | 文章 ID 与所属栏目 |
| `title` / `subtitle` | 主标题 / 引题副标题 |
| `date` / `source` / `author` / `editor` / `views` | 发布时间、来源、作者、编辑、阅读量 |
| `summary` / `content` | 摘要与正文 HTML |

### 数据来源与清洗说明

样例数据由 `tools/prototype/extract_sample_data.py` 从旧库 `gxhczx_db.sql`（导出时间 2026-04-28）生成，取 `Region=22`（河池市主站）内容，每个栏目各 24 条，详情样例 6 篇。生成时已做两项清洗，正式迁移时应在 `tools/migrate` 中沿用并加强：

1. 正文去内联样式与冗余空段落（旧文多用 `<font>` / `<span style>` 排版）。
2. 来源字段清理：旧库约 7.8% 记录的 `From` 把日期版面拼在来源后（如“河池日报 2026/3/2 1 版”）。

### 当前仍为演示的部分

- 列表与分页只覆盖每个栏目的 24 条样例，页脚统计显示的是旧库全量条数。
- 头条摘要为占位文案，后端接入后改为真实摘要。
- 站内搜索仍指向旧站 `search.php`。
- 栏目页版式按栏目结构分流：有子栏目的栏目（如市政协动态）左侧栏自上而下为栏目按钮、最新新闻、图片新闻，右侧为纯稿件列表；无子栏目的栏目（如时政要闻、图片新闻）不显示左侧栏，稿件列表直接铺满。
- 稿件列表为纯文字形态（小方点 + 标题 + 完整发布时间），不含卡片与缩略图；缩略图只出现在左侧栏“图片新闻”模块。
