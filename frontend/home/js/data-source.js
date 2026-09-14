/* 数据源：优先走内容接口，接口不可用时自动回退到 data/*.json 静态快照。
 *
 * 为什么要回退：GitHub Pages 这类纯静态托管跑不了 PHP，回退后预览仍然可用；
 * 同时阶段 A 的快照就是接口契约原型，两者同构，页面渲染逻辑完全一致。
 *
 * 覆盖开关（写在地址栏，存 sessionStorage 保持同一次访问一致）：
 *   ?api=1  强制走接口（接口挂了就报数据加载失败，便于排查）
 *   ?api=0  强制走静态快照（用于对照接口与快照的差异）
 */
(function () {
  "use strict";

  var API_BASE = "/api/v1";
  var STATIC_BASE = "data/";
  var OVERRIDE_KEY = "siteDataMode";

  var state = { mode: "auto", resolved: "", apiError: null };
  var modePromise = null;
  var cache = {};

  var fromQuery = new URLSearchParams(window.location.search).get("api");
  if (fromQuery === "1") state.mode = "api";
  else if (fromQuery === "0") state.mode = "static";
  if (state.mode !== "auto") {
    try { sessionStorage.setItem(OVERRIDE_KEY, state.mode); } catch (e) { /* 隐私模式忽略 */ }
  } else {
    try {
      var remembered = sessionStorage.getItem(OVERRIDE_KEY);
      if (remembered === "api" || remembered === "static") state.mode = remembered;
    } catch (e) { /* 隐私模式忽略 */ }
  }

  function getJSON(url) {
    return fetch(url, { credentials: "same-origin" }).then(function (res) {
      if (!res.ok) {
        var error = new Error("HTTP " + res.status);
        error.status = res.status;
        throw error;
      }
      return res.json();
    });
  }

  // 自动模式先探一次 /health：它是接口独有的，静态托管上一定拿不到。
  function resolvedMode() {
    if (modePromise) return modePromise;
    if (state.mode !== "auto") {
      state.resolved = state.mode;
      modePromise = Promise.resolve(state.mode);
      return modePromise;
    }
    modePromise = getJSON(API_BASE + "/health")
      .then(function (data) {
        state.resolved = data && data.status === "ok" ? "api" : "static";
        return state.resolved;
      })
      .catch(function (err) {
        state.apiError = err;
        state.resolved = "static";
        return state.resolved;
      });
    return modePromise;
  }

  function once(key, factory) {
    if (!cache[key]) cache[key] = factory();
    return cache[key];
  }

  // ---- 静态快照检索用的小工具：把正文 HTML 拍成一行纯文本，便于“内容”检索与片段展示
  function flatten(html) {
    return String(html == null ? "" : html)
      .replace(/<(?:br\s*\/?|\/p|\/div|\/li|\/h[1-6])\s*>/gi, " ")
      .replace(/<[^>]*>/g, " ")
      .replace(/&nbsp;/gi, " ")
      .replace(/&amp;/gi, "&").replace(/&lt;/gi, "<").replace(/&gt;/gi, ">")
      .replace(/&quot;/gi, "\"").replace(/&#39;/gi, "'")
      .replace(/\s+/g, " ").trim();
  }

  // 命中判定：标题命中即算命中；“内容”一档再看样例正文的摘要与正文
  function snapshotMatch(item, detail, needle, scope) {
    if (!needle) return false;
    if (String(item.title || "").toLowerCase().indexOf(needle) >= 0) return true;
    if (scope === "title" || !detail) return false;
    return (flatten(detail.summary) + " " + flatten(detail.content)).toLowerCase().indexOf(needle) >= 0;
  }

  // 命中片段：摘要优先、正文其次，取关键词前后各 46 字（与后端 attachExcerpts 同一口径）
  function snapshotExcerpt(item, detail, needle, width) {
    var pools = detail ? [flatten(detail.summary), flatten(detail.content)] : [];
    for (var i = 0; i < pools.length; i += 1) {
      var text = pools[i];
      var pos = text.toLowerCase().indexOf(needle);
      if (pos < 0) continue;
      var start = Math.max(0, pos - width);
      return (start > 0 ? "……" : "") + text.slice(start, start + width * 2 + needle.length) +
        (start + width * 2 + needle.length < text.length ? "……" : "");
    }
    var fallback = pools.length ? (pools[0] || pools[1]) : "";
    if (!fallback) return "";
    return fallback.slice(0, width * 2) + (fallback.length > width * 2 ? "……" : "");
  }

  // ---- 各数据项的取数：接口与静态快照返回结构对齐，取出来的都是同一形状
  var loaders = {
    home: {
      api: function () {
        return getJSON(API_BASE + "/home").then(function (data) { return data.home; });
      },
      static: function () { return getJSON(STATIC_BASE + "home.json"); }
    },
    channels: {
      api: function (options) {
        return getJSON(API_BASE + "/channels?listSize=" + (options.listSize || 50)).then(function (data) {
          return data.channels || [];
        });
      },
      static: function () {
        return getJSON(STATIC_BASE + "channel.json").then(function (data) { return data.channels || []; });
      }
    },
    // 栏目页列表分页：接口模式是真分页（每页 20 条，按需取），
    // 静态快照模式只能在快照自带的那几十条里本地分页，并标记 demo 让页面写明"演示数据"。
    articles: {
      api: function (options) {
        var size = options.size || 20;
        var page = options.page || 1;
        return getJSON(API_BASE + "/articles?channel=" + encodeURIComponent(options.channel) +
          "&page=" + page + "&size=" + size).then(function (data) {
          return {
            items: data.articles || [],
            page: data.page || page,
            size: data.size || size,
            total: data.total || 0,
            pages: data.pages || 1,
            demo: false
          };
        });
      },
      static: function (options) {
        var size = options.size || 20;
        var page = options.page || 1;
        return getJSON(STATIC_BASE + "channel.json").then(function (data) {
          var channel = (data.channels || []).filter(function (c) {
            return String(c.type) === String(options.channel);
          })[0];
          var list = (channel && channel.list) ? channel.list : [];
          var start = (page - 1) * size;
          return {
            items: list.slice(start, start + size),
            page: page,
            size: size,
            total: list.length,
            pages: Math.max(1, Math.ceil(list.length / size)),
            demo: true,
            channelTotal: channel ? (channel.total || list.length) : list.length
          };
        });
      }
    },
    channelIndex: {
      api: function (options) {
        return getJSON(API_BASE + "/channel-index?listSize=" + (options.listSize || 50)).then(function (data) {
          return data.channels || [];
        });
      },
      static: function () {
        return getJSON(STATIC_BASE + "channel-index.json")
          .then(function (data) { return data.channels || []; })
          .catch(function () {
            // 精简索引缺失时退回完整快照，链接映射照常可用
            return getJSON(STATIC_BASE + "channel.json").then(function (data) {
              return (data.channels || []).map(function (channel) {
                return {
                  type: channel.type,
                  ids: (channel.list || []).map(function (item) { return String(item.id); })
                };
              });
            });
          });
      }
    },
    article: {
      api: function (options) {
        return getJSON(API_BASE + "/article/" + encodeURIComponent(options.id)).then(function (data) {
          return data.article || null;
        });
      },
      static: function (options) {
        return getJSON(STATIC_BASE + "article.json").then(function (data) {
          var hit = (data.articles || []).filter(function (item) {
            return String(item.id) === String(options.id);
          })[0];
          return hit || null;
        });
      }
    },
    // 站内检索：接口模式是真检索（默认标题＋摘要＋正文，scope=title 只查标题）；
    // 静态快照模式只能在快照自带的内容里本地过一遍（43 个栏目各 24 条列表项 + 70 篇样例正文），
    // 条数与全站不一致，页面据此标注“演示数据”。
    search: {
      api: function (options) {
        var size = options.size || 20;
        var page = options.page || 1;
        return getJSON(API_BASE + "/search?q=" + encodeURIComponent(options.q || "") +
          "&scope=" + encodeURIComponent(options.scope || "all") +
          "&page=" + page + "&size=" + size).then(function (data) {
          return {
            items: data.articles || [],
            page: data.page || page,
            size: data.size || size,
            total: data.total || 0,
            pages: data.pages || 1,
            q: data.q || options.q || "",
            scope: data.scope || options.scope || "all",
            demo: false
          };
        });
      },
      static: function (options) {
        var size = options.size || 20;
        var page = options.page || 1;
        var scope = options.scope === "title" ? "title" : "all";
        var needle = String(options.q || "").trim().toLowerCase();
        return Promise.all([
          getJSON(STATIC_BASE + "channel.json"),
          getJSON(STATIC_BASE + "article.json").catch(function () { return { articles: [] }; })
        ]).then(function (res) {
          var texts = {};
          (((res[1] || {}).articles) || []).forEach(function (detail) {
            texts[String(detail.id)] = detail;
          });
          var hits = [];
          var seen = {};
          ((res[0] || {}).channels || []).forEach(function (channel) {
            (channel.list || []).forEach(function (item) {
              var id = String(item.id);
              if (seen[id]) return;
              var detail = texts[id] || null;
              if (!snapshotMatch(item, detail, needle, scope)) return;
              seen[id] = true;
              var hit = {
                id: id, title: item.title, url: item.url || ("detail.html?id=" + id),
                date: item.date || "", datetime: item.datetime || item.date || "",
                source: item.source || ""
              };
              hit.excerpt = snapshotExcerpt(item, detail, needle, 46);
              hits.push(hit);
            });
          });
          // 样例正文里不在栏目列表内的稿件也补进来（详情页样例就是这样单独存在的）
          Object.keys(texts).forEach(function (id) {
            if (seen[id]) return;
            var detail = texts[id];
            var item = {
              id: id, title: detail.title, url: "detail.html?id=" + id,
              date: detail.date || "", datetime: detail.date || "", source: detail.source || ""
            };
            if (!snapshotMatch(item, detail, needle, scope)) return;
            seen[id] = true;
            item.excerpt = snapshotExcerpt(item, detail, needle, 46);
            hits.push(item);
          });
          hits.sort(function (a, b) {
            return String(b.datetime || "").localeCompare(String(a.datetime || ""));
          });
          var total = hits.length;
          var start = (page - 1) * size;
          return {
            items: hits.slice(start, start + size),
            page: page,
            size: size,
            total: total,
            pages: Math.max(1, Math.ceil(total / size)),
            q: options.q || "",
            scope: scope,
            demo: true
          };
        });
      }
    }
  };

  function load(name, options) {
    options = options || {};
    return once(name + ":" + (options.id || ""), function () {
      return resolvedMode().then(function (mode) {
        var loader = loaders[name];
        return loader[mode](options).catch(function (err) {
          // 接口返回 404 表示"确实没有这条内容"（未发布/已删除），不再回退旧快照，
          // 否则会把已下线的内容重新显示出来。
          if (mode === "api" && err && err.status === 404) {
            return name === "article" ? null : [];
          }
          throw err;
        });
      });
    });
  }

  window.SITE_DATA = {
    apiBase: API_BASE,
    /** 当前生效的数据源："api" / "static"，自动模式下探测后给出结论 */
    mode: function () { return state.resolved || state.mode; },
    /** 供排查用：自动回退到静态时，这里能看到接口的错误原因 */
    apiError: function () { return state.apiError ? String(state.apiError.message || state.apiError) : ""; },
    home: function () { return load("home"); },
    channels: function (listSize) { return load("channels", { listSize: listSize }); },
    channelIndex: function (listSize) { return load("channelIndex", { listSize: listSize }); },
    article: function (id) { return load("article", { id: id }); },
    /** 栏目页列表分页：{items, page, size, total, pages, demo}；接口不可用时回退快照的本地分页 */
    articles: function (options) {
      options = options || {};
      var key = "articles:" + (options.channel || "") + ":" + (options.page || 1) + ":" + (options.size || 20);
      return once(key, function () {
        return resolvedMode().then(function (mode) {
          return loaders.articles[mode](options);
        });
      });
    },
    /** 站内检索：{items, page, size, total, pages, q, scope, demo}；接口不可用时回退快照本地检索 */
    search: function (options) {
      options = options || {};
      var key = "search:" + (options.q || "") + ":" + (options.scope || "all") + ":" + (options.page || 1) +
        ":" + (options.size || 20);
      return once(key, function () {
        return resolvedMode().then(function (mode) {
          return loaders.search[mode](options);
        });
      });
    }
  };
})();
