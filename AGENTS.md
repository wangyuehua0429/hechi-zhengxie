<!-- code-review-graph MCP tools -->
## MCP Tools: code-review-graph

**This project has a knowledge graph. Start with the code-review-graph
MCP tools to narrow scope, then read the source.** The graph is cheaper than scanning files and
gives you structural context (callers, dependents, test coverage) that file search cannot.

### When to use graph tools FIRST

- **Exploring code**: `semantic_search_nodes_tool` or `query_graph_tool` instead of Grep
- **Understanding impact**: `get_impact_radius_tool` instead of manually tracing imports
- **Code review**: `detect_changes_tool` + `get_review_context_tool` instead of reading entire files
- **Finding relationships**: `query_graph_tool` with callers_of/callees_of/imports_of/tests_for
- **Architecture questions**: `get_architecture_overview_tool` + `list_communities_tool`

### Verify in the source

- Narrow scope with the graph, then read the source. Do not change code from graph output alone.
- For any non-trivial change, read the implementation and the relevant tests before concluding.
- Verify the exact source when touching behavior, database logic, migrations, retries, fallbacks,
  recovery, or compatibility code.
- When the graph and the source disagree, the source wins. The graph may be stale or may not
  model that relationship.
- An empty graph result can mean "not indexed" or "not statically visible", not "does not exist".

### Key Tools

| Tool | Use when |
| ------ | ---------- |
| `detect_changes_tool` | Reviewing code changes — gives risk-scored analysis |
| `get_review_context_tool` | Need source snippets for review — token-efficient |
| `get_impact_radius_tool` | Understanding blast radius of a change |
| `get_affected_flows_tool` | Finding which execution paths are impacted |
| `query_graph_tool` | Tracing callers, callees, imports, tests, dependencies |
| `semantic_search_nodes_tool` | Finding functions/classes by name or keyword |
| `get_architecture_overview_tool` | Understanding high-level codebase structure |
| `refactor_tool` | Planning renames, finding dead code |

### Workflow

1. The graph auto-updates on file changes (via hooks).
2. Use `detect_changes_tool` for code review.
3. Use `get_affected_flows_tool` to understand impact.
4. Use `query_graph_tool` pattern="tests_for" to check coverage.
<!-- /code-review-graph MCP tools -->

## 多代理使用规则（本机实测，2026-09-16起）

背景：本机模型走第三方DeepSeek兼容端点（`wire_api = "responses"`）。codex会把跨代理消息的**正文**放进 `AgentMessage` 的 `encrypted_content` 部件（信封走 `input_text`），该部件在本机接收端不生效——子代理拿到的只有环境与规则、看不到任务正文，于是只会 `wait_agent` 空转。实测：2026-09-15以来凡 `fork_turns:"none"` 的 `spawn_agent`，子代理100% 回报“未收到任务内容”（6/6）。

1. **派活前先探针**：先 `spawn_agent` 一个 `fork_turns:"none"` 的子代理，正文只写“只回复PONG”，再用 `list_agents` 读它的完成状态。收到PONG才派真活；不是PONG就直接换通道。跨代理消息本体不可靠，`list_agents` 的完成状态文本才是唯一可靠的读取路径。
2. **等待必须短**：`wait_agent` 用60–120秒（不要用300000/600000）；每次醒来先 `list_agents` 检查子代理是否真的开工（有无工具调用、文件产出）。连续两次无进展立即 `interrupt_agent` 并改走单上下文，不要继续等。
3. **不用 `spawn_agent` 派正文**。替代路径二选一：省略 `fork_turns`（继承父上下文，任务在继承的上下文里）；或 `codex exec` 起独立进程（任务走命令行参数、天然隔离，适合需要独立视角的评审）。
4. **长任务书走文件**：把任务书写到磁盘（如 `/tmp/<name>-task.md`），再让子代理去读，绕开正文通道。
5. **结果只认两处**：`list_agents` 的完成状态文本、磁盘上的产物文件。不要假设 `FINAL_ANSWER` 已送达并据此往下走。
6. **复测条件**：升级Codex或更换模型provider后，用第1条探针复测一次；PONG能回来时才可恢复常规 `spawn_agent` 派活。

## 数据与代码来源约束（2026-09-16起）

**开发和调试一律对着真库与当前工作区代码；不用临时库、快照库或旧副本验收。**

起因：2026-09-16发现首页“看不到新导入的数据”，实际是起服务时把 `DB_DATABASE` 指到了 `/tmp` 下的临时演示库（`seed.php` 从静态快照新灌，406篇、最新2026-04-27），而真库是4969篇、最新2026-09-04。

1. **库只认真库**：`backend/storage/hechi_zx.sqlite`（[backend/config/config.php](backend/config/config.php) 里 `DB_DATABASE` 的默认值）。禁止把 `DB_DATABASE` 指向 `/tmp/**`、`seed.php` 现灌的快照库、或真库的任何副本去做页面验收——这类库的数据停在静态快照年代，界面会看起来“导入没生效”，排查半天发现是数据源不对。
2. **起服务用默认命令**：`php -S 127.0.0.1:8080 -t backend/public backend/public/router.php`，**不加**任何 `DB_*` / `PUBLISH_OUT` 覆盖。要临时隔离就写明用途与库路径，并在结论里标注数据源。
3. **静态产物**：`PUBLISH_OUT` 保持默认 `backend/storage/publish`；内容改完后要让 `/article/`、`/channel/` 静态页跟上，跑 `php backend/bin/publish.php`（该目录已在 `backend/storage/.gitignore` 内，重新发布不脏git）。
4. **代码只认工作区**：验收跑当前工作区的代码，不用旧副本、打包产物或 `backend/storage/backup/` 里的历史库——备份只用于回滚，不用于开发调试。
5. **写库前先备份**：直接改真库（导数据、批量改字段、跑修复脚本）前先拷一份到 `backend/storage/backup/hechi_zx-<YYYYMMDD-HHMMSS>.sqlite`，沿用仓库既有命名。
6. **例外**：`node tests/*.mjs` 自带临时SQLite、不碰真库，属有意隔离；引用其结论时写明数据源即可。

## 内容来源与页面口径（2026-09-17起）

**内容只有一个来源：库。快照是测试夹具，不是数据源；对外只有一套页面地址。**

1. **前端不自动回退快照**：`frontend/home/js/data-source.js` 只走 `/api/v1`；`data/*.json` 仅在显式 `?api=0` 时使用（对照快照、纯静态预览）。接口挂了由页面提示加载失败，不得拿阶段A的样例数据顶现网内容。
2. **生产路径不挂快照**：`docker-compose.yml` 不挂 `frontend/home/data`；`seed.php` 的全量灌库只用于开发与演示，生产只在老库升级补首页四类配置时用 `--home-only`。
3. **发布默认只出静态页**：`php backend/bin/publish.php` 产 `/article/`、`/channel/`、`sitemap.xml`；`data/*.json` 只在 `--data-only` 时产出（离线预览与契约对拍），线上无消费者。
4. **公开口径只有一个开关**：出口（首页／栏目页／详情／检索／静态发布／旧地址301）一律走 `HechiZx\Content\PublicScope`，由 `backend/config/config.php` 的 `content.enforce_public_scope` 决定——`true` 只出 `public_scope=public`，`false`（当前默认）放开年限、`status=published` 的稿件全部对外。新增查询照同一开关取条件，不得写死 `public_scope='public'`；快照兜底条目与快照块（nav／leaders／topic／links等）里的稿件链接同样按它过滤，库里没有或不可对外的整条摘掉。
5. **常设政务信息不按年限归档**：领导简介、机构设置、章程、委员名单这类长期有效内容保持 `public`；发现被年限规则误判时按第5条的备份流程改回并留操作日志。
