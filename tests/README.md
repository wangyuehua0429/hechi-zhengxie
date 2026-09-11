# 前端页面回归检查

`check-pages.mjs` 是前端静态版的提交前检查：起一个本地静态服务器，用无头浏览器把首页、二级栏目页、详情页跑一遍，断言页面真的渲染出来了。

## 为什么要这个脚本

2026-09-11 提交 `85918c2` 把站内链接映射的数据源换成 4.4 KB 精简索引后，`js/shell.js` 误把这份索引当栏目数据转发给 `channel.js`、`detail.js`，导致**全部栏目页打不开、详情页标题多出 `undefined`**，当天靠人工才发现（详见 [../frontend/home/README.md](../frontend/home/README.md) 的“回归与修复”一节）。这类回归的两种症状——页面出现“数据加载失败”、标题或链接里出现 `undefined`——本脚本都能自动抓到。

## 运行

```bash
cd <仓库根目录>
node tests/check-pages.mjs
```

常用参数：

| 参数 | 说明 |
| --- | --- |
| `--only <关键词>` | 只跑名字含关键词的用例，如 `--only 详情`、`--only 刷新` |
| `--url <base>` | 检查已在运行的站点（如 `http://127.0.0.1:8899`、GitHub Pages 地址），不再另起静态服务器 |
| `--port <端口>` | 自起静态服务器的端口，默认 8973 |
| `--browser <chrome\|chromium\|msedge>` | 指定浏览器；默认先用 playwright 自带 Chromium，版本不匹配时回退系统 Chrome |
| `--headed` | 显示浏览器窗口，便于肉眼对照 |
| `--keep` | 检查结束后保留静态服务器（排查问题时用） |

退出码：全部通过为 `0`，有用例失败为 `1`，脚本自身出错为 `2`。

## 依赖

- Node 18 以上（本机 v26）。
- playwright 库：先找项目内 `node_modules`，再找全局 `npm root -g` 下的 `@playwright/cli/node_modules/playwright`。本机已通过全局 `@playwright/cli` 提供，无需额外安装。
- 浏览器：优先 playwright 自带 Chromium；本机缓存里的版本与驱动不匹配时会自动回退到系统 Google Chrome（`channel=chrome`）。
- `python3`：与仓库 README 的本地预览方式一致，用 `python3 -m http.server` 起静态服务。

> 页面数据来自 `fetch` 本地 JSON，必须经静态服务器访问；直接双击 HTML 文件会失败。

## 检查项

每个用例都会断言：

1. 页面返回 200，且首屏关键容器已经渲染出来（等 `ready` 选择器出现再判分）。
2. 文档标题与正文不出现 `undefined`，不出现“数据加载失败”，详情页标题不残留“信息加载中”，`href`／`src` 里不出现 `undefined`。
3. 没有横向溢出（`scrollWidth - clientWidth ≤ 1px`）。
4. 没有破图（已加载完成但 `naturalWidth === 0`；未触发的懒加载不计入）。
5. 无 JS 异常、无控制台 error、无本地请求失败（4xx／5xx）。
6. 用例指定的容器数量下限、必须可见的区块、必须出现或不许出现的文案。

另有两类专项用例：

- **分页**：点栏目页第 2 页，列表仍有内容且页码文案同步。
- **刷新回顶**：先把页面滚到 1200px，再 `reload`，断言回到顶部（`scrollY ≤ 50`）。这条对应提交 `366d7a4`、`c9096ee` 的行为。

## 用例覆盖

首页（桌面 1440／手机 390）、栏目页七种版式（`list` `leaders` `gallery` `video` `topic` `county` `interactive`，分别取 `?id=904`／`202`／`314`／`316`／`topic`／`qy`／`interactive`）、未知栏目 id、详情页（常规含图集 62180、带附件 40029、正文未内置 62212、未知 id）、详情页手机端、首页／栏目页／详情页刷新回顶，共 19 个用例。

## 已知限制

- 样例数据只有每栏目 24 条，列表分页只在样例范围内生效；全量数据接入后需同步调整预期条数。
- 检查的是渲染结果与运行时报错，不校验视觉细节（间距、配色、折行），视觉仍需肉眼看 `qa-*.png`。
- 站内搜索仍指向旧站 `search.php`，脚本不对其断言。
