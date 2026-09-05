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
