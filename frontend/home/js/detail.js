/* 信息详情页：按 ?id=<旧库文章ID> 渲染标题、元信息、正文与相关阅读
 *
 * 数据来自 data/article.json 与 data/channel.json（原型样例，取旧库 rd_news），
 * 后端就绪后由 REST API 平替，正文排版样式不变。
 */
(function () {
  "use strict";

  const ARTICLE_URL = "data/article.json";
  const CHANNEL_URL = "data/channel.json";
  const HOME_URL = "./index.html";
  const DEFAULT_ID = "62180";
  const LATEST_SIZE = 5;
  const RELATED_SIZE = 4;
  const THUMB_SIZE = 4;
  const BODY_SIZES = [0.92, 1, 1.12, 1.24];
  const BODY_DEFAULT = 1;

  const el = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");

  const state = {
    article: null,
    channel: null,
    channels: [],
    bodySize: BODY_DEFAULT
  };

  function param(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  function channelOf(type) {
    return state.channels.filter(function (c) { return String(c.type) === String(type); })[0] || null;
  }

  function renderCrumb() {
    const a = state.article, ch = state.channel;
    el("crumb").innerHTML =
      '<li><a href="' + HOME_URL + '">首页</a></li>' +
      (ch ? '<li><a href="channel.html?id=' + esc(ch.type) + '">' + esc(ch.name) + '</a></li>' : "") +
      '<li class="is-current" aria-current="page">正文</li>';
    document.title = a.title + " · " + (ch ? ch.name + " · " : "") + "广西河池政协网";
  }

  function renderArticle() {
    const a = state.article;
    const sub = el("articleSub");
    if (a.subtitle) {
      sub.textContent = a.subtitle;
      sub.hidden = false;
    }
    el("articleTitle").textContent = a.title;

    const meta = [
      '<span>发布时间：<b>' + esc(a.date) + '</b></span>',
      a.source ? '<span>来源：<b>' + esc(a.source) + '</b></span>' : "",
      a.author ? '<span>作者：<b>' + esc(a.author) + '</b></span>' : "",
      a.editor ? '<span>编辑：<b>' + esc(a.editor) + '</b></span>' : "",
      '<span>阅读：<b>' + esc(a.views) + '</b></span>'
    ].filter(Boolean).join("");
    el("articleMeta").innerHTML = meta;

    const body = el("articleBody");
    body.innerHTML = a.content;
    applyBodySize();

    if (a.editor) {
      const sign = el("articleSign");
      sign.textContent = "责任编辑：" + a.editor;
      sign.hidden = false;
    }
  }

  function applyBodySize() {
    const body = el("articleBody");
    if (body) body.style.fontSize = (1.06 * state.bodySize) + "em";
  }

  function renderRelated() {
    const a = state.article;
    const ch = state.channel;
    const box = el("relatedList");
    if (!box) return;
    const pool = ch ? ch.list.filter(function (i) { return i.id !== a.id; }) : [];
    const rows = pool.slice(0, RELATED_SIZE);
    box.innerHTML = rows.length ? rows.map(function (item) {
      return '<li><a href="' + esc(item.url) + '"><span class="rel-title">' + esc(item.title) +
        '</span><time datetime="' + esc(item.date) + '">' + esc(item.date) + '</time></a></li>';
    }).join("") : '<li class="empty-state">暂无相关阅读。</li>';
  }

  function renderPrevNext() {
    const a = state.article;
    const ch = state.channel;
    if (!ch) return;
    const items = ch.list;
    const idx = items.findIndex(function (i) { return i.id === a.id; });
    const row = function (item, label) {
      const body = item
        ? '<a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' + esc(item.title) + '</a>'
        : '<span>—</span>';
      return '<li><span class="nav-label">' + label + '：</span>' + body + '</li>';
    };
    // 列表按时间倒序：前一条为更新的一篇，后一条为更早的一篇
    const prev = idx > 0 ? items[idx - 1] : null;
    const next = idx >= 0 && idx < items.length - 1 ? items[idx + 1] : null;
    el("articleNavList").innerHTML = row(prev, "上一篇") + row(next, "下一篇");
  }

  function renderSide() {
    const a = state.article;
    const ch = state.channel;
    if (ch) {
      el("sideChannelName").textContent = ch.name + " · 最新";
      el("channelLatest").innerHTML = ch.list
        .filter(function (i) { return i.id !== a.id; })
        .slice(0, LATEST_SIZE)
        .map(function (item) {
          return '<li><a href="' + esc(item.url) + '"><span>' + esc(item.title) + '</span></a></li>';
        }).join("");
    }
    const withImg = [];
    state.channels.forEach(function (c) {
      c.list.forEach(function (item) {
        if (item.img && withImg.length < THUMB_SIZE) withImg.push(item);
      });
    });
    el("thumbGrid").innerHTML = withImg.map(function (item) {
      return '<a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' +
        '<img src="' + esc(item.img) + '" alt="' + esc(item.title) + '" loading="lazy">' +
        '<span>' + esc(item.title) + '</span></a>';
    }).join("") || '<div class="empty-state">暂无图片</div>';
  }

  function bindToolbar() {
    el("articleToolbar").addEventListener("click", function (e) {
      const btn = e.target.closest("button");
      if (!btn) return;
      if (btn.id === "printBtn") {
        window.print();
        return;
      }
      if (btn.id === "copyLinkBtn") {
        const done = function () {
          const old = btn.textContent;
          btn.textContent = "已复制";
          window.setTimeout(function () { btn.textContent = old; }, 1600);
        };
        if (navigator.clipboard) {
          navigator.clipboard.writeText(window.location.href).then(done, function () {});
        }
        return;
      }
      const dir = btn.dataset.size;
      const idx = BODY_SIZES.indexOf(state.bodySize);
      if (dir === "up") state.bodySize = BODY_SIZES[Math.min(BODY_SIZES.length - 1, idx + 1)];
      else if (dir === "down") state.bodySize = BODY_SIZES[Math.max(0, idx - 1)];
      else state.bodySize = BODY_DEFAULT;
      applyBodySize();
    });
  }

  function renderMissing() {
    el("articleTitle").textContent = "未找到该篇信息";
    el("articleBody").innerHTML =
      '<div class="empty-state">当前原型内置的详情样例为：' +
      state.channels.reduce(function (acc, c) {
        return acc.concat(c.list.slice(0, 2).map(function (i) {
          return '<a href="detail.html?id=' + esc(i.id) + '">' + esc(i.id) + '</a>';
        }));
      }, []).join("　") + '</div>';
    el("articleToolbar").hidden = true;
    el("articleMeta").innerHTML = "";
  }

  function init() {
    Promise.all([
      fetch(ARTICLE_URL).then(function (r) { return r.json(); }),
      fetch(CHANNEL_URL).then(function (r) { return r.json(); })
    ]).then(function (res) {
      const articles = res[0].articles || [];
      state.channels = res[1].channels || [];
      const id = param("id") || DEFAULT_ID;
      const article = articles.filter(function (a) { return String(a.id) === String(id); })[0];
      if (!article) {
        state.article = { id: id, title: "", content: "" };
        renderMissing();
        return;
      }
      state.article = article;
      state.channel = channelOf(article.channelType);
      renderCrumb();
      renderArticle();
      renderRelated();
      renderPrevNext();
      renderSide();
      bindToolbar();
    }).catch(function () {
      el("articleBody").innerHTML =
        '<div class="empty-state">数据加载失败，请通过本地静态服务器访问本页。</div>';
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
