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

## 多代理使用规则（本机实测，2026-09-16 起）

背景：本机模型走第三方 DeepSeek 兼容端点（`wire_api = "responses"`）。codex 会把跨代理消息的**正文**放进 `AgentMessage` 的 `encrypted_content` 部件（信封走 `input_text`），该部件在本机接收端不生效——子代理拿到的只有环境与规则、看不到任务正文，于是只会 `wait_agent` 空转。实测：2026-09-15 以来凡 `fork_turns:"none"` 的 `spawn_agent`，子代理 100% 回报“未收到任务内容”（6/6）。

1. **派活前先探针**：先 `spawn_agent` 一个 `fork_turns:"none"` 的子代理，正文只写“只回复 PONG”，再用 `list_agents` 读它的完成状态。收到 PONG 才派真活；不是 PONG 就直接换通道。跨代理消息本体不可靠，`list_agents` 的完成状态文本才是唯一可靠的读取路径。
2. **等待必须短**：`wait_agent` 用 60–120 秒（不要用 300000/600000）；每次醒来先 `list_agents` 检查子代理是否真的开工（有无工具调用、文件产出）。连续两次无进展立即 `interrupt_agent` 并改走单上下文，不要继续等。
3. **不用 `spawn_agent` 派正文**。替代路径二选一：省略 `fork_turns`（继承父上下文，任务在继承的上下文里）；或 `codex exec` 起独立进程（任务走命令行参数、天然隔离，适合需要独立视角的评审）。
4. **长任务书走文件**：把任务书写到磁盘（如 `/tmp/<name>-task.md`），再让子代理去读，绕开正文通道。
5. **结果只认两处**：`list_agents` 的完成状态文本、磁盘上的产物文件。不要假设 `FINAL_ANSWER` 已送达并据此往下走。
6. **复测条件**：升级 Codex 或更换模型 provider 后，用第 1 条探针复测一次；PONG 能回来时才可恢复常规 `spawn_agent` 派活。

## 数据与代码来源约束（2026-09-16 起）

**开发和调试一律对着真库与当前工作区代码；不用临时库、快照库或旧副本验收。**

起因：2026-09-16 发现首页"看不到新导入的数据"，实际是起服务时把 `DB_DATABASE` 指到了 `/tmp` 下的临时演示库（`seed.php` 从静态快照新灌，406 篇、最新 2026-04-27），而真库是 4969 篇、最新 2026-09-04。

1. **库只认真库**：`backend/storage/hechi_zx.sqlite`（[backend/config/config.php](backend/config/config.php) 里 `DB_DATABASE` 的默认值）。禁止把 `DB_DATABASE` 指向 `/tmp/**`、`seed.php` 现灌的快照库、或真库的任何副本去做页面验收——这类库的数据停在静态快照年代，界面会看起来"导入没生效"，排查半天发现是数据源不对。
2. **起服务用默认命令**：`php -S 127.0.0.1:8080 -t backend/public backend/public/router.php`，**不加**任何 `DB_*` / `PUBLISH_OUT` 覆盖。要临时隔离就写明用途与库路径，并在结论里标注数据源。
3. **静态产物**：`PUBLISH_OUT` 保持默认 `backend/storage/publish`；内容改完后要让 `/article/`、`/channel/` 静态页跟上，跑 `php backend/bin/publish.php`（该目录已在 `backend/storage/.gitignore` 内，重新发布不脏 git）。
4. **代码只认工作区**：验收跑当前工作区的代码，不用旧副本、打包产物或 `backend/storage/backup/` 里的历史库——备份只用于回滚，不用于开发调试。
5. **写库前先备份**：直接改真库（导数据、批量改字段、跑修复脚本）前先拷一份到 `backend/storage/backup/hechi_zx-<YYYYMMDD-HHMMSS>.sqlite`，沿用仓库既有命名。
6. **例外**：`node tests/*.mjs` 自带临时 SQLite、不碰真库，属有意隔离；引用其结论时写明数据源即可。
