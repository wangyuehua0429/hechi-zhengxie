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

  // 栏目索引由 shell.js 统一取，避免与导航重复请求
  function loadChannels() {
    if (window.SITE && window.SITE.channelsReady) return window.SITE.channelsReady;
    return fetch(CHANNEL_URL)
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (data) { return (data && data.channels) || []; });
  }

  // 栏目列表里有、但原型未内置正文的稿件：用列表信息拼出详情页，正文留待内容接口接入
  function listedItem(id) {
    let hit = null;
    state.channels.forEach(function (channel) {
      if (hit) return;
      (channel.list || []).forEach(function (item) {
        if (!hit && String(item.id) === String(id)) hit = { item: item, channel: channel };
      });
    });
    return hit;
  }

  function renderCrumb() {
    const a = state.article, ch = state.channel;
    const parts = ['<li><a href="' + HOME_URL + '">首页</a></li>'];
    if (ch) {
      // 子栏目挂在所属一级栏目下（columnId），子栏目名与一级栏目名不同时再补一级
      const parentId = ch.columnId || ch.type;
      parts.push('<li><a href="channel.html?id=' + encodeURIComponent(parentId) + '">' +
        esc(ch.name) + '</a></li>');
      if (ch.inner && ch.inner !== ch.name) {
        parts.push('<li><a href="channel.html?id=' + encodeURIComponent(ch.type) + '">' +
          esc(ch.inner) + '</a></li>');
      }
    }
    parts.push('<li class="is-current" aria-current="page">正文</li>');
    el("crumb").innerHTML = parts.join("");
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

  // 栏目列表里有该条、但原型未内置正文：照常渲染标题与元信息，正文位置给出说明
  function renderListedOnly(hit) {
    const item = hit.item;
    state.article = {
      id: item.id,
      channelType: hit.channel.type,
      channelName: hit.channel.name,
      title: item.title,
      subtitle: "",
      date: item.datetime || item.date,
      source: item.source || "",
      author: "",
      editor: "",
      views: item.views,
      content: '<div class="empty-state">本篇正文未随原型内置，正式迁移后由内容接口提供。<br>' +
        '<a href="channel.html?id=' + encodeURIComponent(hit.channel.type) + '">返回' +
        esc(hit.channel.inner || hit.channel.name) + '</a></div>'
    };
    state.channel = hit.channel;
    renderCrumb();
    renderArticle();
    renderRelated();
    renderPrevNext();
    renderSide();
    bindToolbar();
  }

  function renderMissing() {
    el("articleTitle").textContent = "未找到该篇信息";
    el("articleMeta").innerHTML = "";
    el("articleToolbar").hidden = true;
    el("articleBody").innerHTML =
      '<div class="empty-state">未找到该篇信息，链接可能已失效。' +
      '请从<a href="' + HOME_URL + '">首页</a>或上方导航重新进入。</div>';
  }

  function init() {
    Promise.all([
      fetch(ARTICLE_URL).then(function (r) { return r.json(); }),
      loadChannels()
    ]).then(function (res) {
      const articles = res[0].articles || [];
      state.channels = res[1] || [];
      const id = param("id") || DEFAULT_ID;
      const article = articles.filter(function (a) { return String(a.id) === String(id); })[0];
      if (!article) {
        const hit = listedItem(id);
        if (hit) renderListedOnly(hit);
        else renderMissing();
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
