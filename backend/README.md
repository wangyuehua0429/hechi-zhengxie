# 主站后端（PHP轻量CMS）

本目录是《河池政协网开发思路与技术栈方案》里主站的后端实现。当前为**可运行的最小骨架**：数据模型、内容接口、静态化发布器、旧地址301映射与一个能用的后台管理界面已经跑通阶段A的样例数据；旧库全量导入尚未实现（见文末“阶段C待办”）。

接口契约见 [../docs/api-contract.md](../docs/api-contract.md)，数据模型见 [../docs/架构与实施说明.md](../docs/架构与实施说明.md) 第2节。

## 目录

```
backend/
├── bin/
│   ├── migrate.php         建表 / 升级表结构
│   ├── seed.php            把 frontend/home/data 的快照灌进库
│   ├── publish.php静态化发布（全文静态页 + sitemap；`--data-only` 另出数据快照）
│   ├── redirects.php       旧地址 301：生成 sys_url_redirect、Nginx 片段与核对 CSV
│   ├── scan-content.php    只读体检：扫存量正文里的可疑标签，给出清洗前后预览，不改库
│   ├── fix-orig-title.php  存量修复：正文题区收进「原标题」三列，题区行统一首行空两格
│   ├── fix-author-signature.php 存量修复：删掉与作者栏重复的末尾署名，作者归口作者栏
│   ├── user.php            后台账号管理（create / passwd / disable / list）
│   └── member.php          委员账号管理（create / passwd / reset / disable / list）
├── config/config.php       运行配置（读环境变量，本地默认 SQLite）
├── public/
│   ├── index.php           接口唯一入口
│   └── router.php          PHP 内置服务器路由脚本（仅本地开发）
├── public/assets/
│   ├── admin.css           后台样式（零依赖，不引 UI 库）
│   ├── admin.js            后台渐进增强脚本（批量选择 / 正文预览 / 即时筛选，不加载也能用）
│   └── editor/             正文富文本编辑器：SunEditor 3.3.3 预构建包（MIT）+ 本项目接线脚本
├── routes/api.php          /api/v1 路由表
├── routes/admin.php        后台路由表（/admin/*，会话 + CSRF）
├── routes/member.php       委员门户路由表（/member/*，独立会话 zx_member）
├── src/
│   ├── Admin/              后台：Auth / Csrf / Flash / View + 首页四类与稿件、栏目、用户等控制器
│   ├── Api/                health / home / channels / articles / search 控制器
│   ├── Content/            正文清洗（HtmlSanitizer，HTMLPurifier 白名单）与展示归一化（BodyNormalizer）、稿库与提案状态机、权限码
│   ├── Http/               Request / Response / HtmlResponse / FileResponse（二进制下载）/ RedirectResponse / Router / ApiException / LegacyRedirect（旧地址 301 判定）
│   ├── Member/             委员门户：MemberAuth（独立会话）/ MemberView / PortalController / ProposalController
│   ├── Proposal/           提案导出与名册导入：WordExporter / XlsxExporter / OfficePackage / MemberImporter
│   ├── Publish/            Publisher（静态化）· StaticPaths（栏目页路径）· RedirectMap（301 映射与产物）
│   ├── Repository/         栏目、稿件、首页模块、委员、提案仓储（SQL 只写在这里）
│   └── Support/            Config / Db / Json / Migrator
├── storage/                运行时目录（SQLite 文件、发布产物、HTMLPurifier 定义缓存，不入库）
│                           提案附件落在 storage/proposals/，不在 webroot 下，只能经鉴权下载
├── vendor/htmlpurifier/    自托管第三方件：HTMLPurifier 4.19.0（LGPL-2.1，未改本体）
└── templates/
    ├── page.php静态页模板（正式版：站头／导航／面包屑／侧栏／页脚与frontend/home内页同结构，2026-09-17）
    ├── admin/              后台模板（layout / login / dashboard / articles / article_edit / channels / channel_edit / nav / slides / sections / banners / users / roles / logs / proposals / proposal_show / members / member_import / message）
    └── member/             委员门户模板（layout / home / password / proposals / proposal_form / proposal_show / message）
```

## 本地跑通（SQLite，无需Docker）

前置：PHP 8.1以上（`php -v` 能出结果即可，`pdo_sqlite` 为默认自带扩展）。

```bash
# 1. 建表
php backend/bin/migrate.php

# 2. 把阶段 A 的样例数据灌进库（43 个栏目、432 条列表、70 篇正文）
php backend/bin/seed.php

# 3. 起接口服务
php -S 127.0.0.1:8080 -t backend/public backend/public/router.php
```

> **迁移入库后不要再跑 `seed.php`**（2026-09-12起有安全闸）：它按 `frontend/home/data/` 的样例快照灌库，
> 会把同号稿件的正文与公开范围覆盖回样例，并把栏目归属清空重写（实测会把4,500+ 条砍回400多条）。
> 库里已有快照之外的稿件时它会直接停下并说明；确实要重灌得先备份再加 `--force`，只想补首页四大类配置用 `--home-only`。

> **已有的库升级到003（首页四大类）**：不要重跑 `seed.php`（会把栏目与稿件重新按快照覆盖），用这条：
>
> ```bash
> php backend/bin/seed.php --home-only
> ```
>
> 它只做两件事：跑 `003_home_sections` 迁移、回填首页模块/头条轮换/横幅的初始配置。没跑之前站点不会报错——首页退回改版前的快照显示，后台“头条轮换 / 其他栏目 / 站内横幅”三页会给出“先执行迁移”的提示页，导航栏目页不受影响。

> **拉取新代码后先跑迁移**：加字段的迁移（如 `004_article_orig_title` 给 `cms_article` 加 `orig_*`、给 `cms_article_channel` 加 `is_highlight` / `badge_text`）只改表结构，不灌数据，命令是：
>
> ```bash
> php backend/bin/migrate.php
> ```
>
> 漏跑的后果不是提示页，而是**直接报错**：`/admin/sections` 与 `/api/v1/home` 都会返回 `no such column: ac.is_highlight`（前台接口取不到数就提示加载失败，不会再拿快照顶）。已跑003的库再补004用的也是这条命令，不要重跑 `seed.php`。

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

服务起来后打开 **<http://127.0.0.1:8080/admin>**，会跳到登录页。库里没有账号时先建一个（密码至少8位，用 `password_hash` 存）：

```bash
php backend/bin/user.php create admin 你的密码 "管理员"
php backend/bin/user.php list             # 查看账号
php backend/bin/user.php passwd admin 新密码
php backend/bin/user.php disable admin    # 停用
```

现在能做的事：

| 功能 | 说明 |
| --- | --- |
| 概览 | 稿件总数、草稿/已发布/已下线计数、栏目数、上次发布时间与**待发布条数**、操作日志条数 |
| 导航栏目 | 首页与内页共用的18个顶部导航入口：改名、改链接、调顺序、隐藏／显示；每行显示对应栏目与稿件数 |
| 滚动公告 | 首页导航条下方、搜索框左侧那条滚动要闻（前台读 `home.meta.marquee`）：改文字、看前台效果预览、按字数计数，最多500字；换行与连续空格保存时收成单个空格，清空后前台显示“暂无要闻”。**可停用**：勾选后前台整条滚条（连喇叭图标）收掉，搜索框仍贴右上角、宽度由341px放宽到560px，横栏高度不变，文字仍保留、取消勾选即恢复（停用写 `home.meta.marqueeHidden`）。公告与站点版权／备案号／联系方式存在同一个块（`cms_home_block` 的 `meta`），这一页只改 `marquee` 与 `marqueeHidden` 两个字段，其余原样写回 |
| 头条轮换 | 首屏大图轮播：**从已发布稿件里选**（默认列最近发布，也可按标题检索，点标题可预览前台页面；自动带标题／摘要／链接，图缺省取稿件配图）或**手工新增外链条目**；上移／下移、上下线、删除；首屏效果预览支持拖动排序、点图预览，条目右上角红叉确认后删除 |
| 其他栏目 | 首页正文13个内容模块的绑定维护：改模块标题、绑定栏目（指定栏目／一级栏目含子栏目／按子栏目分标签）、取几条、上下线；顶部**栏目索引**按一级栏目分组列全部43个栏目，被首页模块用着的栏目点名带红点（悬停显示模块名），栏目号只在悬停提示里出现，点名称跳到对应位置，模块卡片右上角给“回到索引”；每个模块把当前取到的稿件**按头条轮换那样的表格列出来**（缩略图、标题、栏目、状态、↑↓ 排序、置顶／高亮／徽标三件套、预览／复制链接、编辑），多标签模块按标签分表，未入库的快照条目标出说明；页面下方列未进导航的栏目（`预览` 打开的也是前台详情页，与稿件列表同一口径） |
| 站内横幅 | 首页7个固定图片位（`hero-1/2`、`body-1…5`）：换图、改链接与alt、上下线；每个位置在文件框下面给出该位的**显示尺寸、宽高比例与建议像素**（按显示尺寸2倍、文件 ≤ 300 KB），首屏两张是固定框裁切、正文里的按图片自身比例撑高；**选中文件后立刻在上方预览框里显示预览图**（本地预览，点“保存”才生效）。下线后前台整块不占位，且**不影响相邻模块的间距**——首页区块间距已改成容器统一定的固定值（`gap`），不再靠横幅自己的上下外边距撑开 |
| 稿件管理 | 列表：**一块筛选面板**收稿库（导航条）+ 栏目（导航条）+ 关键词 + 排序（发布时间／最近更新／稿件号）+ 每页条数（20／50／100），标题即编辑入口，**批量提交／审核通过／退回／撤回／重新发布／恢复**（单次 ≤100篇，按权限位出动作），分页含页码与跳转，空结果给下一步；新建稿件（先点导航条选栏目，再填内容，状态默认“已发布”）；编辑页两栏：左侧一张“摘要与正文”写作窗（标题带64字计数、原标题三项、来源／作者／责任编辑三个署名框、正文提示、工具栏贴正文框的富文本正文、正文框下方的附件素材条），右侧只剩稿库流转、稿件信息与回收站，保存条吸底，正文可预览并显示字数；**提交环节不再设置任何排序**（置顶已从编辑页移除），列表里的**预览**打开前台正式详情页（`/detail.html?id=`）、**复制链接**给发布器产出的对外地址 `/article/{id}.html`，两者都只对“已发布 + 公开发布”的稿件开放，未发布与归档稿置灰（归档稿的提示说明不对外发布），**高亮／徽标**改到首页管理维护；删除稿件走确认页（软删除，可恢复） |
| 附件与插图 | 图片与附件的提交都在左侧写作窗里：插图走工具栏的“图片”按钮（`POST /admin/media/image`，**插在光标处**，文首／文中／文末都行；mp4／webm视频同理由工具栏的“视频”按钮走 `POST /admin/media/video`），附件走正文框下方的素材条（pdf／doc／docx／xls／xlsx／ppt／pptx／zip／rar／txt，单个 ≤32 MB；编辑页即时上传并可在素材条里删除，新建页随“保存并发布”一起提交，都在文末“附件下载”区展示）。新建稿件还没有稿件号时，素材先落 `backend/public/uploads/pending/`，保存稿件时自动迁进 `uploads/<稿件号>/` 并登记图集。**图片一律 ≤ 2 MB**（横幅、头条大图、编辑器插图都算），附件与视频仍是32 MB |
| 栏目管理 | **按一级栏目分组**展示43个栏目（组头给子栏目数、稿件条数、上下线数），组内可直接**上移／下移**，支持按栏目名／栏目号检索；可改一级栏目名、子栏目名、版式、排序、上下线、栏目简介；稿件数、前台页、编辑页都有直达入口。**新建栏目**（选归属、栏目号、URL标识、版式与状态，只做两级）与**删除栏目**（确认页；有稿件、子栏目、首页模块绑定或导航指向时逐条说明并挡下，确认后清理角色的栏目数据范围与该栏目的301映射）见 [../docs/栏目新建与删除说明（2026-09-12）.md](../docs/栏目新建与删除说明（2026-09-12）.md) |
| 一键发布 | 重新生成全文静态页 + sitemap（默认不再产出数据快照），产物在 `backend/storage/publish/` |
| 审计与安全 | 会话Cookie（HttpOnly + SameSite=Lax）、所有POST校验CSRF、口令 `password_hash`、登录与改动写 `sys_operation_log` |

上传文件落在 `backend/public/uploads/{稿件号}/`，对外地址 `/uploads/...`（该目录在 `.gitignore` 里，不入库）；稿件还没保存、拿不到稿件号时先落在 `uploads/pending/`。

**正文编辑器与清洗**（2026-09-12）：编辑页正文用SunEditor 3.3.3（MIT，自托管在 `backend/public/assets/editor/`），脚本没加载或初始化失败时自动退回原来的HTML文本框，textarea始终是提交字段。服务端清洗在 `backend/src/Content/HtmlSanitizer.php`，是保存、内容接口、静态页三处的唯一出口；白名单外的标签、事件与协议一律剥离，改白名单必须同步提升类里的 `DEFINITION_REV`，否则会命中旧的定义缓存。存量体检用 `php backend/bin/scan-content.php`（只读）。编辑器相关接口：

| 接口 | 说明 |
| --- | --- |
| `POST /admin/media/image` | 编辑器插图。字段名 `file-0`，可带 `article`（稿件号）；返回 `{"result":[{"url","name","size"}]}`（SunEditor 3的契约） |
| `POST /admin/media/video` | 同上，收mp4／webm／ogg／mov／m4v（与附件上传同一套白名单） |
| `POST /admin/article/{id}/flags` | 首页管理里按栏目设置**高亮／徽标**（只对已发布稿件开放，需该栏目权限） |
| `POST /admin/article/{id}/top` | 按栏目**置顶／取消置顶**：首页管理模块表格与稿件列表的按钮走这里（编辑表单不再设置排序） |

三个接口都接受 `X-CSRF-Token` 头（编辑器用），表单模式下仍走原有的CSRF字段。

**正文出口归一化**（2026-09-14）：清洗解决的是“安全”，归一化解决的是“排版”。旧库正文的排版毛病会原样带进新站，
所以接口与发布器在清洗之后再过一层 `backend/src/Content/BodyNormalizer.php`，**只改展示出口，不改库里的 `content_html`**
（后台编辑回填读的仍是原文，改动可回滚）。按开发库2773篇公开稿实测的四条规则：

| 规则 | 实测依据 | 处理 |
| --- | --- | --- |
| 去空段 | 2574篇在段间留了 `<div><br></div>`，配上段落下边距会出现63px（约两行）空档；2522一篇因此浪费2047px竖向空间 | 只含空白／`<br>`／`&nbsp;` 的 `p`／`div` 一律删除，段距交给样式表 |
| 裁首部缩进 | 669篇正文自带“　　”，与 `text-indent: 2em` 叠加成4字 | 块首的全角空格／`&emsp;`／`&nbsp;`／`<br>` 裁掉（正文中间的对齐空格保留） |
| 拍平嵌套块 | 旧库常见 `div` 套 `div`、`<strong>` 包块，样式只命中直接子元素，表现成“有的段落缩进、有的不缩进” | 嵌套块提升为兄弟节点，块级元素从行内标签里提出来 |
| 资源地址改写 | 720篇正文与2063条图集记录指向旧站 `gxhczx.gov.cn`（且是http），本地 `uploads/legacy` 只有227/2102个文件 | 本地有文件改写成 `/uploads/legacy/...`；没有的保留旧站地址但强制https（先消除混合内容拦截，不制造89% 破图） |

另有三条出口口径：**正文题区与标题重复**、**末尾署名归口作者栏**、**图集过滤**。

**末尾署名归口作者栏**（2026-09-15）：旧站正文结尾普遍带一行署名，形态三种——`（黄荞丹 覃可论）`、
`口黄正华`（旧站的方框署名标记导出后成了“口”）、`（作者：本报首席记者 罗昌亮）`。这些署名与
`cms_article.author` 基本重复，而标题下的元信息行已经显示“作者：…”，所以由
`backend/src/Content/AuthorSignature.php` 在展示出口删掉，判据是**署名必须与作者栏对得上**
（去掉空白与署名标签后相等、是作者栏的一部分、或把作者栏的姓名全包含在内）。

开发库4,968篇实扫：删掉末尾署名4,429篇，其中8篇作者栏为空、按末尾署名回填
（`口潘剑`、`（作者：本报评论员）`、`（韦瑞展 袁文展）` 这类能明确取名的）。以下三类**保留不动**：

| 末尾形态 | 篇数 | 为什么不删 |
| --- | --- | --- |
| `（作者系河池市政协秘书长）`“（文章刊登于…）”“（本版图片均由…/摄）”“（发言者为…）” | 318 | 是职务说明、出处与图片署名，不是作者姓名，删了会丢信息 |
| 署名与作者栏不一致（如作者栏“刁海音”、正文“（黄伟）”） | 9 | 两边对不上，留给人工判断，不擅自删 |
| 作者栏为空且括号里不是人名（`（新华社）`、`（壮族）`） | 3 | 不是作者署名 |

同一套判据也落在旧库渲染那一步（`tools/migrate/legacy_extract.py` 的 `strip_author_signature()`），
将来重跑迁移时不会把署名再灌回库里。存量修复（默认只统计，`--apply` 才写库并留回滚清单）：

```bash
php backend/bin/fix-author-signature.php            # 统计与抽样，不写库
php backend/bin/fix-author-signature.php --apply    # 写库（逐条先写回滚清单，整批在一个事务里）
php backend/bin/fix-author-signature.php --id=64088 --dump=/tmp/a.tsv   # 单篇 + 改动清单
```

题区＝正文开头连续的整块加粗行（块内只有一个 `strong`／`b`），最多看3行。详情页顶部只出一行网页标题，
题区一律留在正文里按行显示；只有“题区仅1行且与网页标题一字不差（含前面加破折号的写法）”才删掉，
因为那时正文只是把标题又抄了一遍。开发库2773篇公开稿实测：

| 正文题区 | 篇数 | 处理 |
| --- | --- | --- |
| 1行，与标题一字不差（如154《中国人民政治协商会议章程》） | 28 | 删掉这一行，正文直接进正文 |
| 1行，与标题不同（多为名单分组行，如2522“中国共产党（22人）”） | 14 | 原样保留 |
| 2行（引题＋主标题，如63904、61598、49676） | 982 | 整段保留，即使引题与网页标题相同 |
| 3行及以上（引题＋主标题＋副题） | 89 | 整段保留 |
| 无题区 | 1660 | 不动 |

这一条是从“凡是与标题相同就删”改过来的：那时会误删689篇本来带“引题＋主标题”两行的稿件，
表现为正文里少了引题（如63904“黄恩率队到都安开展专题调研”）。
图集出口则剔除 `.mp4` 等视频文件（旧库有50条图集记录是mp4，前台会渲染成空白格）。
归一化的单元检查：`php tests/normalizer-check.php`（36项，含末尾署名的删／留／回填）。

**栏目顺序**：`sys_channel.sort_no` 由 `bin/seed.php` 按前端 `data/channel.json` 的排列（即主导航顺序）写入，后台列表、栏目导航条与 `/api/v1/channels` 都按它排；栏目选择一律用二级结构的横向导航条（一级栏目 + 子栏目），不用下拉菜单——43个栏目、两级关系，导航条与前台观感一致，也少一次展开点击。首页导航条是另一份数据（`cms_home_block` 的 `nav` 块，18个入口），在“导航栏目”页单独维护。

**首页稿件**：首页13个内容模块的列表由“其他栏目”里配置的绑定栏目**实时组装**（置顶在前 → 栏目内排序值 → 发布时间），不足时用同名快照兜底；置顶写 `cms_article_channel.is_top`，首页模块与栏目页列表共用这一套顺序。**2026-09-14起排序不再出现在稿件提交/编辑表单里**，只在两处操作：稿件列表按单个栏目筛选时的 ↑ ↓、“其他栏目”页每个模块表格里的 ↑ ↓ 与置顶按钮（后者会跳回原页面）。取消置顶时按发布时间落回原位。

**原标题三项**（2026-09-14起）：引题／主标题／副题以编辑页的三个输入框为唯一维护入口，保存时按“加粗三行、每行首行空两格”拼到正文最前；正文里原有的那几行会在打开编辑页时自动收进输入框（并从编辑器正文里去掉），所以反复保存不会叠加。缩进写成 `<strong>　　文本</strong>` 而不是strong外侧的空白——实测富文本编辑器（SunEditor）同步内容时会丢掉块首的空白文本节点（64049打开编辑页后首行缩进消失、保存后库里也没了）。库里的正文仍保留题区，前台与静态页照旧从正文读题区、由CSS给2字缩进。

两条边界：回填是**逐列**判断的（哪一列空就补哪一列），避免用户清空一列后那一行在保存时被丢掉；保存时只有请求里带了这三列才按它们重拼正文题区，脚本或接口直接提交正文时整段不动，不会把题区剥没了。

存量稿件的原标题原本只躺在正文里，需要跑一次修复（默认只统计，加 `--apply` 才写库，会留回滚清单）：

```bash
php backend/bin/fix-orig-title.php            # 统计与抽样，不写库
php backend/bin/fix-orig-title.php --apply    # 正文题区写入三列 + 题区行统一首行空两格（逐条先写回滚清单，整批在一个事务里）
php backend/bin/fix-orig-title.php --show=64049   # 看单篇整理前后的正文片段
```

**高亮与徽标**（2026-09-12）：与置顶同一组，写在 `cms_article_channel.is_highlight`／`badge_text`，**只在首页管理里维护**（稿件编辑提交时不再设置）。高亮＝首页该条标题换成正红（`#ff0000`）加粗；徽标＝标题后面跟一个小标记。徽标用一个下拉选预设（最新／热点／重磅／独家／图解／视频／直播／预告）或选“自定义…”后填字（最多6个字，多余会被挡下并提示），保存成功后**该行状态列显示“徽标：xxx”并可删除**（删徽标按钮带确认），下拉也随现存值回显，随时可改。首页模块接口输出 `is_highlight` / `badge` 两个字段，前台 `frontend/home/js/main.js` 渲染成 `li.is-highlight` 与 `i.item-badge`。

**下线与撤下**（2026-09-12晚）：后台显式下线的内容，前台**整块收起、不留空框**——横幅位下线后该块不占位（相邻模块间距由容器 `gap` 统一给，不受影响）；内容模块下线、或绑定的栏目里暂时没有已发布稿件时，整张卡片隐藏（不再显示“暂无更新内容”的空框），三列模块改用 `auto-fit`，撤掉一两张后剩下的自动加宽；两栏主体整栏都空时并成一栏，头条轮播全部下线时收起轮播。口径上有一条要记住：**下线不再回退快照**——`HomeRepository::blocks()` 原先先铺快照再逐模块覆盖，被下线的模块因为“跳过覆盖”会露出快照数据（表现为后台点了下线、前台照样显示）；现在下线的键会从结果里删掉，`slides` 全部下线时返回空数组让前台收起轮播，只有“库里没有这个模块配置”或“没跑003迁移”时才用快照样例兜底。

**改前确认**：首页管理四类页面的改动保存即同步前台（前台读实时接口），所以会改前台的提交都会先弹一次确认：导航保存（含隐藏）与导航上移／下移、轮播上线／下线／删除／编辑保存与轮播上移／下移、模块保存（改绑定／条数／上下线）与模块内稿件上移／下移、置顶／取消置顶、横幅保存（换图／改链接／上下线）。文案写在模板的 `data-confirm` 上（写在表单上就是整表一个，写在提交按钮上就按按钮区分），由 `assets/admin.js` 统一拦截；预览区按住缩略图拖动排序不弹，拖完松手即保存。

还没有的（阶段C后续）：栏目拖拽排序、按角色细分到“站点 × 栏目”的权限（表已建）、登录失败次数限制、栏目软删除与历史栏目号备查；`uploads/pending/` 目前没有定期清理任务，未保存的草稿会留下素材文件。

> 稿件管理与栏目管理界面在2026-09-11做过一轮重构（筛选集中、批量操作、栏目分组与上下移、编辑页两栏 + 正文预览），改动理由、逐项清单与验证方式见 [../docs/后台稿件与栏目管理重构说明（2026-09-11）.md](../docs/后台稿件与栏目管理重构说明（2026-09-11）.md)。

> 首页按“导航栏目 / 头条轮换 / 其他栏目 / 站内横幅”四大类管理，同一批改动把首页内容模块从静态快照改为“绑定栏目 + 稿件现算”，见 [../docs/后台首页四大类管理说明.md](../docs/后台首页四大类管理说明.md)。

> 本地走HTTP，生产必须HTTPS；`APP_DEBUG=0` 时接口不回显内部错误信息。

> 稿库（草稿／待审／退回／已发布／已撤回／回收站）的完整设计——状态流转、权限矩阵、数据模型改动与分期排期，见 [docs/稿库与内容状态设计.md](../docs/稿库与内容状态设计.md)。当前实现只有草稿／已发布／已下线三态。

## 政协委员提案系统（/member）

主站内的独立门户：委员用提案委开通的账号登录后在线填写提交提案，提案委在后台收件、受理或退回补充。**账号表、会话与页面外壳都与内容管理后台分开**——委员账号在 `sys_member`，门户会话名是 `zx_member`，委员拿不到任何后台权限码，因此进不了 `/admin`。

门户首页（`/member`）是独立版式：甲方给的底图铺满整页，登录框浮在图上，下方接“登录指南”；这一页不套门户外壳（站头／页脚会让整幅底图断开），其余页面才用 `templates/member/layout.php`。

```bash
# 迁移 005 建出四张表（sys_member / cms_proposal / cms_proposal_attachment / cms_proposal_log），
# 迁移 006 按 2026-09-16 需求再加市直单位清单、联名委员、承办单位明细与改稿留痕四张表
php backend/bin/migrate.php

# 服务起来后：门户首页在 http://127.0.0.1:8080/member
# 提案委用后台账号登录 /admin，侧栏「提案管理」里导名册、维护市直单位、收件、调整、受理
```

上手指路：先在 `/admin/members/import` 下载CSV模板填好委员名册（表头 `姓名,界别,职务,联系电话`，姓名与职务必填，UTF-8与GBK都能识别）并上传，系统为每人生成随机初始密码，**下载一次性密码清单**线下发下去；零星增补不必攒名册：在 `/admin/members` 的“手工新建账号”里填一条或多条即可（与导入同一套校验、建号与一次性密码规则）。委员首次登录必须先改密，之后才能填写提案。再在 `/admin/units` 把市直单位清单导进去（表头 `单位名称,排序号`），它决定委员端“建议承办单位”那个可搜索下拉里能选什么。

**登录不进去先查这里**：门户首页 `/member` 能打开但登录总说“登录名或密码不正确”，九成是库里还没有委员账号——`sys_member` 是空的（新装或刚迁移完都是这样）。先用后台导入名册，或者用命令补开一个账号：

```bash
php backend/bin/member.php list                                  # 看有没有委员账号
php backend/bin/member.php create 13800000000 'Member#2026' 张三 中国共产党 提案委员会 13800000000
php backend/bin/member.php reset 13800000000                     # 忘了密码：生成新密码并打印一次
php backend/bin/member.php disable 13800000000                   # 停用
```

用命令行建的账号同样要首登改密。注意 `/member` 是PHP路由：必须经PHP入口访问（本地 `php -S 127.0.0.1:8080 -t backend/public backend/public/router.php`，生产走Nginx的 `location /member`）。直接在静态预览（`python3 -m http.server` 起的 `frontend/home`、GitHub Pages）里点 `/member` 必然是404，那只是静态托管，没有后端。

几处实现要点：

1. **三态流转**：已提交 → 已受理／已退回；退回后委员可改稿重交，状态回到已提交。状态定义在 `backend/src/Content/ProposalWorkflow.php`，与稿件状态机同一写法。
2. **附件不进webroot**：提案附件落在 `backend/storage/proposals/{提案号}/`，下载走带鉴权的路由，直链取不到。
3. **越权即404**：委员取提案一律按“提案号 + 本人账号”查，取不到不区分“不存在”与“不是你的”。
4. **正文字数前后端同一算法**：`backend/src/Content/ProposalBody.php` 与服务端、`backend/public/assets/member-editor.js` 与前端，都是“HTML转纯文本、去掉空白、数字符”，上限2000字；改一处必须同时改另一处。
5. **正文入库前清洗**：走 `HtmlSanitizer::cleanProposal()` 的窄白名单（只留 `p,br,strong,b,em,i,u,ul,ol,li`），导出的Word用同一份内容翻译成段落与运行格式。
6. **提案委改稿留痕**：每次真正改动都在 `cms_proposal_revision` 存一份改前快照，委员端会提示“提案委已对内容作了调整”。
7. **导出靠zip扩展**：Excel收件清单、单件与批量Word由 `backend/src/Proposal/` 手写OOXML生成，生产镜像已在 `deploy/php/Dockerfile` 里装上 `zip`；环境缺该扩展时导出入口给出明确错误。
8. **不写静态页、不进sitemap、不开对外接口**：提案数据只在门户与后台之间流转；例外是门户内的 `GET /member/roster`（已登录委员可查名册，只回姓名、单位及职务、联系电话）。
9. **委员忘记密码**：系统不提供自助重置，委员管理页单个重置或在列表里勾选多个批量重置（每人不相同的随机密码，密码清单只给一次）。

完整设计（表结构、路由、权限码、导入与导出口径、安全隔离）见 [../docs/提案系统设计说明.md](../docs/提案系统设计说明.md)，端到端检查见 `node tests/proposal-check.mjs`。

## 发稿后前台看不到？按这个顺序查

1. **状态**：只有“已发布”的内容会进前台与接口，草稿、已下线只在后台可见（这是有意为之）。后台能搜到、前台搜不到，基本就是状态问题。
2. **栏目**：稿件挂在哪个栏目，就去那个栏目页看；新建时选的栏目在编辑页顶部显示。新建稿件排在栏目列表最前（`sort_no = 0`）。
3. **数据源**：前台只走接口（`/api/v1`），接口挂了由页面提示加载失败，**不再回退** `data/*.json` 快照（2026-09-17起）；对照快照或纯静态预览时在地址栏加 `?api=0`。控制台执行 `SITE_DATA.mode()` 看当前生效的数据源，`SITE_DATA.apiError()` 看接口故障原因。
4. **缓存**：接口响应默认 `no-store`，不会因为浏览器缓存看不到；若把 `API_CACHE_MAX_AGE` 调大，改稿后要等过期。

还要分清两件事：**“保存稿件”**决定前台能不能看到（走接口，已发布即时生效）；**“立即发布全站”**只重新生成静态化产物（详情静态页、栏目静态页与sitemap），不影响走接口的前台页面。

## 前端如何取数

前端统一通过 `frontend/home/js/data-source.js` 取数：**只走 `/api/v1`**；`data/*.json` 快照只在显式 `?api=0` 时使用（对照快照、GitHub Pages这类纯静态预览），接口挂了不再自动回退。想验证接口实际返回，用地址栏开关覆盖：

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
php backend/bin/publish.php                 # 全量：静态页 + sitemap（对外唯一产物）
php backend/bin/publish.php --data-only     # 只出data/*.json（离线预览／契约对拍，生产不产）
php backend/bin/publish.php --out=/tmp/site # 指定输出目录
```

产物结构：

```
publish/
├── index.html                      首页
├── channel/<目录名>/index.html     栏目页（slug 重复的一级栏目补栏目号，见下）
├── article/<id>.html               详情页
├── data/{home,channel,article,channel-index}.json   与阶段 A 快照同构
├── redirects/nginx-301.conf        旧地址 301 的 Nginx 片段（由 redirects.php 生成）
└── sitemap.xml
```

**栏目目录名**：slug唯一时用 `/channel/<slug>/`；slug重复的一级栏目（902—906都写 `zhengxie-dongtai`、601—607都写 `dangpai-tuanti` 等，43个栏目里23个如此）补上栏目号，写成 `/channel/<slug>-<栏目号>/`。规则在 `src/Publish/StaticPaths.php`；修正前43个栏目只写出25个静态页，sitemap里还有重复地址。

**归档稿件是否出静态页跟随公开口径**（2026-09-12定，2026-09-19起默认放开）：发布器出 `status=published` 且有正文的稿件；年限口径（`config.php` 的 `content.enforce_public_scope=true`）下再加 `public_scope=public`，此时 `public_scope=archive`（超出公开年限的历史稿，见 [旧库迁移说明](../docs/旧库迁移说明.md)）只留后台，不产 `/article/<id>.html`、不进sitemap。口径判断统一走 `src/Content/PublicScope.php`。

## 旧地址301

```bash
php backend/bin/redirects.php                    # 按库内内容生成/更新 sys_url_redirect
php backend/bin/redirects.php --dry-run          # 只看统计，不写库
php backend/bin/redirects.php --out=backend/storage/publish --check=backend/storage/publish
php backend/bin/redirects.php --legacy-site="/path/to/zhengxie2026/gxhczx.gov.cn"
```

产物在发布目录的 `redirects/` 下：`nginx-301.conf`（Nginx片段，`server{}` 里 `include`）、`url-map.csv`（逐条清单，交甲方核对）、`report.txt`（分类小计、目标缺失、未登记旧地址）。

运行期：Nginx按片段把旧地址形态转给PHP入口，入口用 `src/Http/LegacyRedirect.php` 查 `sys_url_redirect` 精确判定，命中即301并把 `sys_url_redirect.hits` 加一，未命中照旧404。本地 `router.php` 同样只在“文件不存在”时查表。**只登记“已发布且有正文”且在当前公开口径下会产出静态页的稿件**（与发布器的产出条件一致），所以不会出现301指到404（年限口径下归档稿不登记，旧地址返回404）；县区子站参数（`q=<县区号>`）本期不映射。

规则明细、旧地址出处与未覆盖项见 [../docs/旧地址301映射说明.md](../docs/旧地址301映射说明.md)；回归检查 `node tests/redirect-check.mjs`（48项）。

## 生产部署

生产用MySQL 8 + Nginx + PHP-FPM，编排与说明见 [../deploy/README.md](../deploy/README.md)。切换只改环境变量：

```bash
export DB_DRIVER=mysql DB_HOST=127.0.0.1 DB_DATABASE=hechi_zx DB_USERNAME=hechi_zx DB_PASSWORD=***
php backend/bin/migrate.php
```

SQL方言只出现在 `database/migrations/<driver>/` 与 `src/Repository/`，业务代码不拼方言，为日后切达梦/金仓留出通道。

## 环境变量

变量清单见仓库根 [.env.example](../.env.example)：`APP_ENV`、`APP_DEBUG`、`SITE_*`、`DB_*`、`PUBLISH_OUT`。`config/config.php` 只读环境变量，不含口令。

## 阶段C待办

已完成（2026-09-11）：

- **后台管理**：登录、用户与角色、按“角色 × 栏目”的数据范围、栏目管理、稿件增删改查、稿库六态与状态流转（草稿／待审／退回／已发布／已撤回／回收站）、附件与正文插图、操作日志。详见 [稿库与内容状态设计](../docs/稿库与内容状态设计.md) 与 [用户分组与权限设计](../docs/用户分组与权限设计.md)。

仍待办：

1. **写接口**：`/api/v1/admin/*`（本版只有对外只读接口）。
2. **正式模板（2026-09-17已完成详情页与栏目页）**：`templates/page.php` 已换成 `frontend/home` 内页那套外壳——站头横幅、主导航（旧站栏目地址在发布时换成静态栏目页地址）、面包屑、侧栏（最新新闻10条＋图片新闻4张）、页脚备案三栏，CSS直接复用 `/css/style.css`、`/css/inner.css`；静态页自带菜单／字号／高对比度／打印／复制链接／正文字号的小交互。仍待办：栏目页分页静态化（`/channel/<目录名>/page-<n>.html`）与首页静态化。
3. **缓存与刷新**：Redis缓存、发布后按栏目/稿件粒度刷新、附件下载计数。
4. **索引**：站内检索目前走 `LIKE`（SQLite无ngram索引）；MySQL下已建 `ft_article_title`，数据量上来后切全文索引，接口不变。
5. **旧数据导入**：旧库20,705条稿件的导入清洗属阶段D，脚本落在 `tools/migrate/`；导入后重跑 `php backend/bin/redirects.php` 即把新库内容补进 `sys_url_redirect`（301生成本身已完成，见上文“旧地址301”）。
6. **后台视觉**：换UI皮（Tabler／AdminLTE一类纯CSS方案），见 [开源选型说明](../docs/开源选型说明.md) 第3.2节。
