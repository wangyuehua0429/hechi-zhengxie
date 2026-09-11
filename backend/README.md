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
├── public/assets/admin.css 后台样式（零依赖，不引 UI 库）
├── routes/api.php          /api/v1 路由表
├── routes/admin.php        后台路由表（/admin/*，会话 + CSRF）
├── src/
│   ├── Admin/              后台：Auth / Csrf / Flash / View / 五个控制器
│   ├── Api/                health / home / channels / articles / search 控制器
│   ├── Http/               Request / Response / HtmlResponse / RedirectResponse / Router / ApiException
│   ├── Publish/Publisher.php
│   ├── Repository/         栏目、稿件、首页模块仓储（SQL 只写在这里）
│   └── Support/            Config / Db / Json / Migrator
├── storage/                运行时目录（SQLite 文件、发布产物，不入库）
└── templates/
    ├── page.php            静态页模板（正式模板待阶段 C 用 frontend/home 结构替换）
    └── admin/              后台模板（layout / login / dashboard / articles / article_edit / channels / channel_edit / message）
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
| 稿件管理 | 列表（栏目用导航条筛选，另可按状态、标题关键词筛选 + 分页）；新建稿件（先点导航条选栏目，再填内容，状态默认“已发布”）；编辑标题、副题、来源、作者、责任编辑、发布时间、状态、置顶、摘要、正文（可直接写纯文本，系统按空行自动分段，也支持 HTML）；删除稿件（二次确认，连附件与上传文件一起清） |
| 附件与插图 | 上传附件（pdf／doc／xls／ppt／zip／rar／txt，单个 ≤32 MB）；上传正文插图（自动追加到正文末尾并登记为图集图片）；可删除附件 |
| 栏目管理 | 43 个栏目一览（顺序与前台导航一致，带各自稿件数），可改一级栏目名、子栏目名、版式、排序、上下线、栏目简介 |
| 一键发布 | 重新生成数据快照 + 全文静态页 + sitemap，产物在 `backend/storage/publish/` |
| 审计与安全 | 会话 Cookie（HttpOnly + SameSite=Lax）、所有 POST 校验 CSRF、口令 `password_hash`、登录与改动写 `sys_operation_log` |

上传文件落在 `backend/public/uploads/{稿件号}/`，对外地址 `/uploads/...`（该目录在 `.gitignore` 里，不入库）。

**栏目顺序**：`sys_channel.sort_no` 由 `bin/seed.php` 按前端 `data/channel.json` 的排列（即主导航顺序）写入，后台列表、栏目导航条与 `/api/v1/channels` 都按它排；栏目选择一律用二级结构的横向导航条（一级栏目 + 子栏目），不用下拉菜单——43 个栏目、两级关系，导航条与前台观感一致，也少一次展开点击。

还没有的（阶段 C 后续）：富文本编辑器（正文现在直接编辑 HTML）、新建/删除栏目、批量操作、按角色细分到“站点 × 栏目”的权限（表已建）、登录失败次数限制。

> 本地走 HTTP，生产必须 HTTPS；`APP_DEBUG=0` 时接口不回显内部错误信息。

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

1. **后台管理**：登录、角色与“站点 × 栏目”权限、栏目管理、稿件增删改查、草稿/已发布/下线三态、附件上传（表结构已就位：`sys_user`／`sys_role`／`sys_role_channel`／`cms_attachment`）。
2. **写接口**：`/api/v1/admin/*`（本版只有对外只读接口）。
3. **正式模板**：把 `frontend/home` 的首页、栏目页、详情页结构搬进 `templates/`，替换当前的 `page.php` 最小模板；发布器接口不变。
4. **缓存与刷新**：Redis 缓存、发布后按栏目/稿件粒度刷新、附件下载计数。
5. **索引**：站内检索目前走 `LIKE`（SQLite 无 ngram 索引）；MySQL 下已建 `ft_article_title`，数据量上来后切全文索引，接口不变。
6. **301 与旧数据**：`sys_url_redirect` 的生成、旧库 20,705 条稿件的导入清洗属阶段 D，脚本落在 `tools/migrate/`。
