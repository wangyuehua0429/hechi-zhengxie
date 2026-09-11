# 主站后端（PHP 轻量 CMS）

本目录是《河池政协网开发思路与技术栈方案》里主站的后端实现。当前为**可运行的最小骨架**：数据模型、内容接口、静态化发布器与一个能用的后台管理界面已经跑通阶段 A 的样例数据；角色权限细分、附件上传、301 映射生成尚未实现（见文末“阶段 C 待办”）。

接口契约见 [../docs/api-contract.md](../docs/api-contract.md)，数据模型见 [../docs/架构与实施说明.md](../docs/架构与实施说明.md) 第 2 节。

## 目录

```
backend/
├── bin/
│   ├── migrate.php         建表 / 升级表结构
│   ├── seed.php            把 frontend/home/data 的快照灌进库
│   ├── publish.php         静态化发布（数据快照 + 全文静态页 + sitemap）
│   └── user.php            后台账号管理（create / passwd / disable / list）
├── config/config.php       运行配置（读环境变量，本地默认 SQLite）
├── public/
│   ├── index.php           接口唯一入口
│   └── router.php          PHP 内置服务器路由脚本（仅本地开发）
├── public/assets/
│   ├── admin.css           后台样式（零依赖，不引 UI 库）
│   └── admin.js            后台渐进增强脚本（批量选择 / 正文预览 / 即时筛选，不加载也能用）
├── routes/api.php          /api/v1 路由表
├── routes/admin.php        后台路由表（/admin/*，会话 + CSRF）
├── src/
│   ├── Admin/              后台：Auth / Csrf / Flash / View + 首页四类与稿件、栏目、用户等控制器
│   ├── Api/                health / home / channels / articles / search 控制器
│   ├── Http/               Request / Response / HtmlResponse / RedirectResponse / Router / ApiException
│   ├── Publish/Publisher.php
│   ├── Repository/         栏目、稿件、首页模块仓储（SQL 只写在这里）
│   └── Support/            Config / Db / Json / Migrator
├── storage/                运行时目录（SQLite 文件、发布产物，不入库）
└── templates/
    ├── page.php            静态页模板（正式模板待阶段 C 用 frontend/home 结构替换）
    └── admin/              后台模板（layout / login / dashboard / articles / article_edit / channels / channel_edit / nav / slides / sections / banners / users / roles / logs / message）
```

## 本地跑通（SQLite，无需 Docker）

前置：PHP 8.1 以上（`php -v` 能出结果即可，`pdo_sqlite` 为默认自带扩展）。

```bash
# 1. 建表
php backend/bin/migrate.php

# 2. 把阶段 A 的样例数据灌进库（43 个栏目、432 条列表、70 篇正文）
php backend/bin/seed.php

# 3. 起接口服务
php -S 127.0.0.1:8080 -t backend/public backend/public/router.php
```

> **已有的库升级到 003（首页四大类）**：不要重跑 `seed.php`（会把栏目与稿件重新按快照覆盖），用这条：
>
> ```bash
> php backend/bin/seed.php --home-only
> ```
>
> 它只做两件事：跑 `003_home_sections` 迁移、回填首页模块/头条轮换/横幅的初始配置。没跑之前站点不会报错——首页退回改版前的快照显示，后台“头条轮换 / 其他栏目 / 站内横幅”三页会给出“先执行迁移”的提示页，导航栏目页不受影响。

自检：

```bash
curl -s http://127.0.0.1:8080/api/v1/health
curl -s "http://127.0.0.1:8080/api/v1/channels/904?listSize=3"
curl -s "http://127.0.0.1:8080/api/v1/article/62180"
curl -s "http://127.0.0.1:8080/api/v1/articles?channel=904&page=1&size=5"
# 检索词必须 URL 编码，直接把中文塞进 URL 会被 curl 当成非法请求
curl -s --get --data-urlencode "q=政协" --data "size=3" http://127.0.0.1:8080/api/v1/search
```

自动对拍（接口返回与 `frontend/home/data/` 的快照逐项比对）：

```bash
node tests/api-check.mjs
```

## 后台管理

服务起来后打开 **<http://127.0.0.1:8080/admin>**，会跳到登录页。库里没有账号时先建一个（密码至少 8 位，用 `password_hash` 存）：

```bash
php backend/bin/user.php create admin 你的密码 "管理员"
php backend/bin/user.php list             # 查看账号
php backend/bin/user.php passwd admin 新密码
php backend/bin/user.php disable admin    # 停用
```

现在能做的事：

| 功能 | 说明 |
| --- | --- |
| 概览 | 稿件总数、草稿/已发布/已下线计数、栏目数、上次发布时间、操作日志条数 |
| 导航栏目 | 首页与内页共用的 18 个顶部导航入口：改名、改链接、调顺序、隐藏／显示；每行显示对应栏目与稿件数 |
| 头条轮换 | 首屏大图轮播：**从已发布稿件里选**（默认列最近发布，也可按标题检索，点标题可预览前台页面；自动带标题／摘要／链接，图缺省取稿件配图）或**手工新增外链条目**；上移／下移、上下线、删除；首屏效果预览支持拖动排序、点图预览，条目右上角红叉确认后删除 |
| 其他栏目 | 首页正文 13 个内容模块的绑定维护：改模块标题、绑定栏目（指定栏目／一级栏目含子栏目／按子栏目分标签）、取几条、上下线；每个模块把当前取到的稿件**按头条轮换那样的表格列出来**（缩略图、标题、栏目、状态、↑↓ 排序、置顶／取消置顶、编辑、前台），多标签模块按标签分表，未入库的快照条目标出说明；页面下方列未进导航的栏目 |
| 站内横幅 | 首页 7 个固定图片位（`hero-1/2`、`body-1…5`）：换图、改链接与 alt、上下线 |
| 稿件管理 | 列表：**一块筛选面板**收稿库（导航条）+ 栏目（导航条）+ 关键词 + 排序（发布时间／最近更新／稿件号）+ 每页条数（20／50／100），标题即编辑入口，**批量提交／审核通过／退回／撤回／重新发布／恢复**（单次 ≤100 篇，按权限位出动作），分页含页码与跳转，空结果给下一步；新建稿件（先点导航条选栏目，再填内容，状态默认“已发布”）；编辑页两栏：左侧基本信息／发布设置／摘要与正文，右侧稿库流转、稿件信息、附件、正文插图、回收站，保存条吸底，正文可预览并显示字数；删除稿件走确认页（软删除，可恢复） |
| 附件与插图 | 上传附件（pdf／doc／xls／ppt／zip／rar／txt，单个 ≤32 MB）；上传正文插图（自动追加到正文末尾并登记为图集图片）；可删除附件 |
| 栏目管理 | **按一级栏目分组**展示 43 个栏目（组头给子栏目数、稿件条数、上下线数），组内可直接**上移／下移**，支持按栏目名／栏目号检索；可改一级栏目名、子栏目名、版式、排序、上下线、栏目简介；稿件数、前台页、编辑页都有直达入口 |
| 一键发布 | 重新生成数据快照 + 全文静态页 + sitemap，产物在 `backend/storage/publish/` |
| 审计与安全 | 会话 Cookie（HttpOnly + SameSite=Lax）、所有 POST 校验 CSRF、口令 `password_hash`、登录与改动写 `sys_operation_log` |

上传文件落在 `backend/public/uploads/{稿件号}/`，对外地址 `/uploads/...`（该目录在 `.gitignore` 里，不入库）。

**栏目顺序**：`sys_channel.sort_no` 由 `bin/seed.php` 按前端 `data/channel.json` 的排列（即主导航顺序）写入，后台列表、栏目导航条与 `/api/v1/channels` 都按它排；栏目选择一律用二级结构的横向导航条（一级栏目 + 子栏目），不用下拉菜单——43 个栏目、两级关系，导航条与前台观感一致，也少一次展开点击。首页导航条是另一份数据（`cms_home_block` 的 `nav` 块，18 个入口），在“导航栏目”页单独维护。

**首页稿件**：首页 13 个内容模块的列表由“其他栏目”里配置的绑定栏目**实时组装**（置顶在前 → 栏目内排序值 → 发布时间），不足时用同名快照兜底；置顶写 `cms_article_channel.is_top`，首页模块与栏目页列表共用这一套顺序。三个地方都能操作：稿件编辑页勾选“在本栏目置顶”、稿件列表按单个栏目筛选时的 ↑ ↓、“其他栏目”页每个模块表格里的 ↑ ↓ 与置顶按钮（后两者会跳回原页面）。取消置顶时按发布时间落回原位。

**改前确认**：首页管理四类页面的改动保存即同步前台（前台读实时接口），所以会改前台的提交都会先弹一次确认：导航保存（含隐藏）、轮播上线／下线／删除／编辑保存、模块保存（改绑定／条数／上下线）、置顶／取消置顶、横幅保存（换图／改链接／上下线）。文案写在模板的 `data-confirm` 上（写在表单上就是整表一个，写在提交按钮上就按按钮区分），由 `assets/admin.js` 统一拦截；上移／下移与预览区拖动排序这类只换位置的操作不弹。

还没有的（阶段 C 后续）：富文本编辑器（正文现在直接编辑 HTML，界面已提供预览与字数）、新建/删除栏目、栏目拖拽排序、按角色细分到“站点 × 栏目”的权限（表已建）、登录失败次数限制。

> 稿件管理与栏目管理界面在 2026-09-11 做过一轮重构（筛选集中、批量操作、栏目分组与上下移、编辑页两栏 + 正文预览），改动理由、逐项清单与验证方式见 [../docs/后台稿件与栏目管理重构说明（2026-09-11）.md](../docs/后台稿件与栏目管理重构说明（2026-09-11）.md)。

> 首页按“导航栏目 / 头条轮换 / 其他栏目 / 站内横幅”四大类管理，同一批改动把首页内容模块从静态快照改为“绑定栏目 + 稿件现算”，见 [../docs/后台首页四大类管理说明.md](../docs/后台首页四大类管理说明.md)。

> 本地走 HTTP，生产必须 HTTPS；`APP_DEBUG=0` 时接口不回显内部错误信息。

> 稿库（草稿／待审／退回／已发布／已撤回／回收站）的完整设计——状态流转、权限矩阵、数据模型改动与分期排期，见 [docs/稿库与内容状态设计.md](../docs/稿库与内容状态设计.md)。当前实现只有草稿／已发布／已下线三态。

## 发稿后前台看不到？按这个顺序查

1. **状态**：只有“已发布”的内容会进前台与接口，草稿、已下线只在后台可见（这是有意为之）。后台能搜到、前台搜不到，基本就是状态问题。
2. **栏目**：稿件挂在哪个栏目，就去那个栏目页看；新建时选的栏目在编辑页顶部显示。新建稿件排在栏目列表最前（`sort_no = 0`）。
3. **数据源**：前台优先走接口，取不到才回退静态快照（`data/*.json`）。地址栏加 `?api=1` 强制走接口；控制台执行 `SITE_DATA.mode()` 看当前生效的数据源，`SITE_DATA.apiError()` 看回退原因。
4. **缓存**：接口响应默认 `no-store`，不会因为浏览器缓存看不到；若把 `API_CACHE_MAX_AGE` 调大，改稿后要等过期。

还要分清两件事：**“保存稿件”**决定前台能不能看到（走接口，已发布即时生效）；**“立即发布全站”**只重新生成静态化产物（详情静态页、sitemap、数据快照），不影响走接口的前台页面。

## 前端如何取数

前端统一通过 `frontend/home/js/data-source.js` 取数：**优先走 `/api/v1`，接口不可用时自动回退到 `data/*.json` 静态快照**（GitHub Pages 这类纯静态托管上预览照常可用）。想验证接口实际返回，用地址栏开关覆盖：

```bash
http://127.0.0.1:8080/channel.html?id=904&api=1   # 强制接口
http://127.0.0.1:8080/channel.html?id=904&api=0   # 强制静态快照（对照用）
```

本地开发用一个进程同时提供站点、接口与后台：

```bash
php -S 127.0.0.1:8080 -t backend/public backend/public/router.php
# /            站点页面（frontend/home，含 js/css/data/images）
# /api/v1/*    内容接口
# /admin*      后台
# /assets/*    /uploads/*   后台样式与上传文件
```

## 静态化发布

```bash
php backend/bin/publish.php                 # 全量：数据快照 + 静态页 + sitemap
php backend/bin/publish.php --data-only     # 只出 data/*.json（前端可直接指向这里）
php backend/bin/publish.php --out=/tmp/site # 指定输出目录
```

产物结构：

```
publish/
├── index.html                      首页
├── channel/<slug>/index.html       栏目页
├── article/<id>.html               详情页
├── data/{home,channel,article,channel-index}.json   与阶段 A 快照同构
└── sitemap.xml
```

## 生产部署

生产用 MySQL 8 + Nginx + PHP-FPM，编排与说明见 [../deploy/README.md](../deploy/README.md)。切换只改环境变量：

```bash
export DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_DATABASE=hechi_zx DB_USERNAME=hechi_zx DB_PASSWORD=***
php backend/bin/migrate.php
```

SQL 方言只出现在 `database/migrations/<driver>/` 与 `src/Repository/`，业务代码不拼方言，为日后切达梦/金仓留出通道。

## 环境变量

变量清单见仓库根 [.env.example](../.env.example)：`APP_ENV`、`APP_DEBUG`、`SITE_*`、`DB_*`、`PUBLISH_OUT`。`config/config.php` 只读环境变量，不含口令。

## 阶段 C 待办

已完成（2026-09-11）：

- **后台管理**：登录、用户与角色、按“角色 × 栏目”的数据范围、栏目管理、稿件增删改查、稿库六态与状态流转（草稿／待审／退回／已发布／已撤回／回收站）、附件与正文插图、操作日志。详见 [稿库与内容状态设计](../docs/稿库与内容状态设计.md) 与 [用户分组与权限设计](../docs/用户分组与权限设计.md)。

仍待办：

1. **写接口**：`/api/v1/admin/*`（本版只有对外只读接口）。
2. **正式模板**：把 `frontend/home` 的首页、栏目页、详情页结构搬进 `templates/`，替换当前的 `page.php` 最小模板；发布器接口不变。
3. **缓存与刷新**：Redis 缓存、发布后按栏目/稿件粒度刷新、附件下载计数。
4. **索引**：站内检索目前走 `LIKE`（SQLite 无 ngram 索引）；MySQL 下已建 `ft_article_title`，数据量上来后切全文索引，接口不变。
5. **301 与旧数据**：`sys_url_redirect` 的生成、旧库 20,705 条稿件的导入清洗属阶段 D，脚本落在 `tools/migrate/`。
6. **后台视觉**：换 UI 皮（Tabler／AdminLTE 一类纯 CSS 方案），见 [开源选型说明](../docs/开源选型说明.md) 第 3.2 节。
