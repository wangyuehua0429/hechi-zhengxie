/* 二级栏目列表页：按 ?id=<旧库栏目ID> 渲染栏目信息、列表、分页与侧栏
 *
 * 数据来自 data/channel.json（原型样例，取旧库 rd_news 主站内容），
 * 后端就绪后由 REST API 平替，渲染逻辑不变。
 */
(function () {
  "use strict";

  const DATA_URL = "data/channel.json";
  const HOME_URL = "./index.html";
  const DEFAULT_ID = "904";
  const PAGE_SIZE_LIST = 12;
  const PAGE_SIZE_GALLERY = 9;
  const HOT_SIZE = 5;
  const THUMB_SIZE = 4;

  const el = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  const kicker = (slug) => String(slug || "").replace(/-/g, " ").toUpperCase();

  const state = { channel: null, all: [], page: 1, size: PAGE_SIZE_LIST, layout: "list" };

  function param(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  function renderCrumb(channel) {
    const box = el("crumb");
    if (!box) return;
    const tail = channel.inner && channel.inner !== channel.name
      ? '<li class="is-current" aria-current="page">' + esc(channel.inner) + '</li>'
      : '<li class="is-current" aria-current="page">' + esc(channel.name) + '</li>';
    box.innerHTML =
      '<li><a href="' + HOME_URL + '">首页</a></li>' +
      (channel.inner && channel.inner !== channel.name
        ? '<li><a href="channel.html?id=' + esc(channel.type) + '">' + esc(channel.name) + '</a></li>'
        : '') +
      tail;
  }

  function renderHero(channel) {
    el("channelKicker").textContent = kicker(channel.slug);
    el("channelTitle").textContent = channel.inner || channel.name;
    el("channelIntro").textContent = channel.intro || "";
    el("channelTotal").textContent = channel.total;
    const heading = el("listHeading");
    if (heading) heading.textContent = (channel.inner || channel.name) + "信息";
    document.title = (channel.inner || channel.name) + " · 广西河池政协网";
  }

  function renderTabs(channel) {
    const wrap = el("channelTabs");
    const box = el("channelTabList");
    if (!wrap || !box || !channel.siblings || !channel.siblings.length) return;
    box.innerHTML = channel.siblings.map(function (s) {
      const active = String(s.type) === String(channel.type);
      const label = esc(s.name);
      return active
        ? '<span class="is-active" aria-current="true">' + label + '</span>'
        : '<a href="channel.html?id=' + esc(s.type) + '">' + label + '</a>';
    }).join("");
    wrap.hidden = false;
  }

  function renderLead(channel, item) {
    const box = el("leadItem");
    if (!box || !item) return;
    box.classList.toggle("no-media", !item.img);
    box.innerHTML =
      (item.img
        ? '<a class="lead-media" href="' + esc(item.url) + '" aria-hidden="true" tabindex="-1">' +
          '<img src="' + esc(item.img) + '" alt="' + esc(item.title) + '"></a>'
        : "") +
      '<div class="lead-text">' +
      '<span class="lead-tag">' + esc(channel.inner || channel.name) + '</span>' +
      '<h2 class="lead-title"><a href="' + esc(item.url) + '">' + esc(item.title) + '</a></h2>' +
      '<p class="lead-summary">本条为演示数据中该栏目最新一条信息，后端接入后此处展示摘要与前缀图片。</p>' +
      '<div class="lead-meta"><span>' + esc(item.date) + '</span>' +
      (item.source ? '<span>来源：' + esc(item.source) + '</span>' : "") +
      '<span>阅读：' + item.views + '</span></div>' +
      '</div>';
    box.hidden = false;
  }

  function listRow(item) {
    return '<li><a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' +
      esc(item.title) + '</a>' +
      (item.source ? '<span class="row-source">' + esc(item.source) + '</span>' : "") +
      '<time datetime="' + esc(item.date) + '">' + esc(item.date) + '</time></li>';
  }

  function galleryCard(item) {
    return '<a class="gallery-card" href="' + esc(item.url) + '">' +
      '<div class="gallery-media"><img src="' + esc(item.img) + '" alt="' + esc(item.title) + '" loading="lazy"></div>' +
      '<p class="gallery-title">' + esc(item.title) + '</p>' +
      '<p class="gallery-date">' + esc(item.date) + '</p></a>';
  }

  function renderList() {
    const wrap = el("listWrap");
    const channel = state.channel;
    const start = (state.page - 1) * state.size;
    const rows = state.all.slice(start, start + state.size);
    if (!rows.length) {
      wrap.innerHTML = '<div class="empty-state">该栏目暂无内容。</div>';
      return;
    }
      wrap.innerHTML = state.layout === "gallery"
      ? '<div class="gallery-grid">' + rows.map(galleryCard).join("") + '</div>'
      : '<ul class="news-rows">' + rows.map(listRow).join("") + '</ul>';
    const totalPages = Math.max(1, Math.ceil(state.all.length / state.size));
    el("listCount").textContent = "演示数据 " + state.all.length + " 条 / 全站共 " +
      channel.total + " 条 · 第 " + state.page + "/" + totalPages + " 页";
  }

  function renderPager() {
    const box = el("pager");
    const totalPages = Math.max(1, Math.ceil(state.all.length / state.size));
    if (totalPages <= 1) {
      box.innerHTML = '<span class="pager-info">已显示全部演示数据</span>';
      return;
    }
    const btn = function (page, label, disabled) {
      return '<button type="button" data-page="' + page + '"' +
        (disabled ? " disabled" : "") +
        ' aria-label="' + esc(label) + '">' + esc(label) + '</button>';
    };
    const parts = [btn(state.page - 1, "上一页", state.page <= 1)];
    for (let i = 1; i <= totalPages; i += 1) {
      parts.push('<button type="button" data-page="' + i + '"' +
        (i === state.page ? ' class="is-active" aria-current="page"' : "") +
        ' aria-label="第 ' + i + ' 页">' + i + '</button>');
    }
    parts.push(btn(state.page + 1, "下一页", state.page >= totalPages));
    box.innerHTML = parts.join("");
  }

  function renderSide(channels, channel) {
    const hot = el("hotList");
    if (hot) {
      hot.innerHTML = channel.list.slice()
        .sort(function (a, b) { return b.views - a.views; })
        .slice(0, HOT_SIZE)
        .map(function (item, i) {
          return '<li><a href="' + esc(item.url) + '"><span class="rank-no">' + (i + 1) +
            '</span><span>' + esc(item.title) + '</span></a></li>';
        }).join("");
    }
    const thumbs = el("thumbGrid");
    if (thumbs) {
      const withImg = [];
      channels.forEach(function (ch) {
        if (ch.type === channel.type) return;
        ch.list.forEach(function (item) {
          if (item.img && withImg.length < THUMB_SIZE) withImg.push(item);
        });
      });
      thumbs.innerHTML = withImg.map(function (item) {
        return '<a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' +
          '<img src="' + esc(item.img) + '" alt="' + esc(item.title) + '" loading="lazy">' +
          '<span>' + esc(item.title) + '</span></a>';
      }).join("") || '<div class="empty-state">暂无图片</div>';
    }
  }

  function bindPager() {
    el("pager").addEventListener("click", function (e) {
      const btn = e.target.closest("button[data-page]");
      if (!btn || btn.disabled) return;
      const page = Number(btn.dataset.page);
      const totalPages = Math.max(1, Math.ceil(state.all.length / state.size));
      if (!page || page < 1 || page > totalPages || page === state.page) return;
      state.page = page;
      renderList();
      renderPager();
      const panel = document.querySelector(".panel");
      if (panel) {
        window.scrollTo({
          top: panel.getBoundingClientRect().top + window.scrollY - 90,
          behavior: "smooth"
        });
      }
    });
  }

  function renderEmpty(ids) {
    el("channelTitle").textContent = "栏目预览";
    el("channelIntro").textContent =
      "当前原型已实现以下栏目：" + ids.join("、") + "。请从导航或栏目链接进入。";
    el("channelTotal").textContent = ids.length;
    el("listWrap").innerHTML = '<div class="empty-state">未找到该栏目。<br>' +
      ids.map(function (id) {
        return '<a href="channel.html?id=' + esc(id) + '">channel.html?id=' + esc(id) + '</a>';
      }).join("　") + '</div>';
    el("pager").innerHTML = "";
  }

  function init() {
    fetch(DATA_URL)
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (data) {
        const channels = data.channels || [];
        const id = param("id") || DEFAULT_ID;
        const channel = channels.filter(function (c) { return String(c.type) === String(id); })[0];
        if (!channel) {
          renderEmpty(channels.map(function (c) { return c.type; }));
          return;
        }
        state.channel = channel;
        state.layout = channel.list.length &&
          channel.list.filter(function (i) { return i.img; }).length / channel.list.length >= 0.8
          ? "gallery" : "list";
        // 文字列表把首条升级为头条，卡片列表全部进入网格，不丢内容
        state.all = state.layout === "gallery" ? channel.list.slice() : channel.list.slice(1);
        state.size = state.layout === "gallery" ? PAGE_SIZE_GALLERY : PAGE_SIZE_LIST;
        state.page = 1;
        renderCrumb(channel);
        renderHero(channel);
        renderTabs(channel);
        if (state.layout === "list") renderLead(channel, channel.list[0]);
        renderList();
        renderPager();
        renderSide(channels, channel);
        bindPager();
      })
      .catch(function () {
        el("listWrap").innerHTML =
          '<div class="empty-state">数据加载失败，请通过本地静态服务器访问本页。</div>';
      });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
