/* 站内检索结果页：?q=<关键词>&scope=all|title&page=<页码>
 *
 * 取数走 js/data-source.js 的 search()：
 *   接口模式 → /api/v1/search（真分页，结果带命中片段）；
 *   静态快照模式 → 在 data/*.json 里本地过一遍，条数与全站不符，页面上标注“演示数据”。
 * 首页搜索框直接提交到本页（search.html?q=…），不再跳旧站 search.php；
 * 旧站检索作为“含县区稿件”的补充入口留在说明里。
 */
(function () {
  "use strict";

  const PAGE_SIZE = 20;
  // 旧站全站检索（search.php，含县（区）政协稿件）随旧站关停下线：县区稿件不在本站迁移范围，
  // 这里只作说明，不再给旧站入口（2026-09-17 Step 3）。
  const LEGACY_SEARCH_NOTE = "县（区）政协稿件不在本站检索范围";

  const el = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");

  const state = { q: "", scope: "all", page: 1, pages: 1, total: 0, demo: false, loading: false };

  function param(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  // 关键词高亮：先整体转义再包 <mark>，正则里把关键词当字面量
  function highlight(text) {
    const safe = esc(text);
    const needle = esc(state.q);
    if (!needle) return safe;
    const pattern = needle.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    return safe.replace(new RegExp(pattern, "gi"), function (hit) { return "<mark>" + hit + "</mark>"; });
  }

  // 结果地址统一过一遍站内映射（接口与快照给的都已是 detail.html?id=…，这里只兜底）
  function resultUrl(item) {
    const url = item.url || ("detail.html?id=" + encodeURIComponent(item.id || ""));
    return window.SITE_LINKS ? window.SITE_LINKS.article(url) : url;
  }

  function countLabel() {
    if (!state.q) return "";
    const suffix = state.pages > 1 ? " · 第 " + state.page + "/" + state.pages + " 页" : "";
    return state.demo
      ? "演示数据 " + state.total + " 条" + suffix
      : "共 " + state.total + " 条" + suffix;
  }

  function renderResults() {
    const wrap = el("resultWrap");
    el("resultCount").textContent = countLabel();

    if (!state.q) {
      wrap.innerHTML = '<div class="empty-state">输入关键词后点“搜索”，或直接回车。</div>';
      return;
    }
    if (!state.rows || state.rows.length === 0) {
      wrap.innerHTML = '<div class="empty-state search-empty">' +
        '没有找到与“' + esc(state.q) + '”相符的稿件。<br>' +
        (state.scope === "title"
          ? '把检索范围改成“标题＋正文”再试一次。'
          : '换个关键词再试一次。') +
        LEGACY_SEARCH_NOTE + '。</div>';
      return;
    }

    wrap.innerHTML = '<ul class="article-rows search-rows">' + state.rows.map(function (item) {
      const meta = [item.source ? "来源：" + item.source : ""].filter(Boolean).join("");
      return '<li>' +
        '<a href="' + esc(resultUrl(item)) + '" title="' + esc(item.title) + '">' + highlight(item.title) + '</a>' +
        (item.date ? '<time datetime="' + esc(item.datetime || item.date) + '">' + esc(item.date) + '</time>' : "") +
        (item.excerpt ? '<p class="search-excerpt">' + highlight(item.excerpt) +
          (meta ? '<span class="search-source">' + esc(meta) + '</span>' : "") + '</p>'
          : (meta ? '<p class="search-excerpt"><span class="search-source">' + esc(meta) + '</span></p>' : "")) +
        '</li>';
    }).join("") + '</ul>';
  }

  // 与栏目页同一套分页条：页数多时只列首页、当前页前后一页与末页
  function pageWindow(current, total) {
    if (total <= 9) {
      const all = [];
      for (let i = 1; i <= total; i += 1) all.push(i);
      return all;
    }
    const keep = [1, total, current, current - 1, current + 1]
      .filter(function (n) { return n >= 1 && n <= total; });
    const unique = keep.filter(function (n, i) { return keep.indexOf(n) === i; })
      .sort(function (a, b) { return a - b; });
    const out = [];
    unique.forEach(function (n, i) {
      if (i > 0 && n - unique[i - 1] > 1) out.push("…");
      out.push(n);
    });
    return out;
  }

  function renderPager() {
    const box = el("pager");
    const totalPages = state.pages || 1;
    if (!state.q) {
      box.innerHTML = "";
      return;
    }
    if (totalPages <= 1) {
      box.innerHTML = '<span class="pager-info">' +
        (state.total ? (state.demo ? "已显示全部演示数据" : "已显示全部 " + state.total + " 条") : "") + '</span>';
      return;
    }
    const btn = function (page, label, disabled) {
      return '<button type="button" data-page="' + page + '"' + (disabled ? " disabled" : "") +
        ' aria-label="' + esc(label) + '">' + esc(label) + '</button>';
    };
    const parts = [btn(state.page - 1, "上一页", state.page <= 1)];
    pageWindow(state.page, totalPages).forEach(function (n) {
      if (n === "…") {
        parts.push('<span class="pager-gap" aria-hidden="true">…</span>');
        return;
      }
      parts.push('<button type="button" data-page="' + n + '"' +
        (n === state.page ? ' class="is-active" aria-current="page"' : "") +
        ' aria-label="第 ' + n + ' 页">' + n + '</button>');
    });
    parts.push(btn(state.page + 1, "下一页", state.page >= totalPages));
    box.innerHTML = parts.join("");
  }

  function syncInputs() {
    el("q").value = state.q;
    el("scope").value = state.scope;
  }

  function updateUrl(push) {
    const query = "?q=" + encodeURIComponent(state.q) + "&scope=" + encodeURIComponent(state.scope) +
      (state.page > 1 ? "&page=" + state.page : "");
    const url = "search.html" + (state.q ? query : "");
    if (push) history.pushState({ q: state.q, scope: state.scope, page: state.page }, "", url);
    else history.replaceState({ q: state.q, scope: state.scope, page: state.page }, "", url);
  }

  function load() {
    syncInputs();
    if (!state.q) {
      renderResults();
      renderPager();
      document.title = "站内检索 · 广西河池政协网";
      return Promise.resolve();
    }
    state.loading = true;
    document.title = "“" + state.q + "”的检索结果 · 广西河池政协网";
    el("resultWrap").innerHTML = '<div class="empty-state">正在检索……</div>';
    el("pager").innerHTML = "";
    return window.SITE_DATA.search({
      q: state.q,
      scope: state.scope,
      page: state.page,
      size: PAGE_SIZE
    }).then(function (result) {
      state.rows = result.items || [];
      state.page = result.page || 1;
      state.pages = result.pages || 1;
      state.total = result.total || 0;
      state.demo = !!result.demo;
      renderResults();
      renderPager();
    }).catch(function () {
      state.rows = [];
      state.total = 0;
      state.pages = 1;
      el("resultCount").textContent = "";
      el("resultWrap").innerHTML = '<div class="empty-state">检索失败，请稍后重试。</div>';
      el("pager").innerHTML = "";
    }).finally(function () {
      state.loading = false;
    });
  }

  function readUrl() {
    state.q = (param("q") || "").trim();
    state.scope = param("scope") === "title" ? "title" : "all";
    const page = Number(param("page"));
    state.page = page > 0 ? Math.floor(page) : 1;
  }

  function scrollToTop() {
    const panel = document.querySelector(".panel");
    if (panel) {
      window.scrollTo({ top: panel.getBoundingClientRect().top + window.scrollY - 90, behavior: "smooth" });
    }
  }

  function bind() {
    el("searchForm").addEventListener("submit", function (e) {
      e.preventDefault();
      state.q = (el("q").value || "").trim();
      state.scope = el("scope").value === "title" ? "title" : "all";
      state.page = 1;
      updateUrl(true);
      load();
    });
    el("pager").addEventListener("click", function (e) {
      const btn = e.target.closest("button[data-page]");
      if (!btn || btn.disabled || state.loading) return;
      const page = Number(btn.dataset.page);
      if (!page || page < 1 || page > (state.pages || 1) || page === state.page) return;
      state.page = page;
      updateUrl(true);
      load().then(scrollToTop);
    });
    window.addEventListener("popstate", function () {
      readUrl();
      load();
    });
  }

  function init() {
    readUrl();
    bind();
    load();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
