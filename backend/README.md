# 主站后端（PHP 轻量 CMS）

本目录是《河池政协网开发思路与技术栈方案》里主站的后端实现。当前为**最小可运行骨架**：数据模型、内容接口、静态化发布器已经能跑通阶段 A 的样例数据；后台管理界面、权限、附件上传、301 映射生成尚未实现（见文末“阶段 C 待办”）。

接口契约见 [../docs/api-contract.md](../docs/api-contract.md)，数据模型见 [../docs/架构与实施说明.md](../docs/架构与实施说明.md) 第 2 节。

## 目录

```
backend/
├── bin/
│   ├── migrate.php         建表 / 升级表结构
│   ├── seed.php            把 frontend/home/data 的快照灌进库
│   └── publish.php         静态化发布（数据快照 + 全文静态页 + sitemap）
├── config/config.php       运行配置（读环境变量，本地默认 SQLite）
├── public/
│   ├── index.php           接口唯一入口
│   └── router.php          PHP 内置服务器路由脚本（仅本地开发）
├── routes/api.php          /api/v1 路由表
├── src/
│   ├── Api/                health / home / channels / articles / search 控制器
│   ├── Http/               Request / Response / Router / ApiException
│   ├── Publish/Publisher.php
│   ├── Repository/         栏目、稿件、首页模块仓储（SQL 只写在这里）
│   └── Support/            Config / Db / Json / Migrator
├── storage/                运行时目录（SQLite 文件、发布产物，不入库）
└── templates/page.php      静态页模板（正式模板待阶段 C 用 frontend/home 结构替换）
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
