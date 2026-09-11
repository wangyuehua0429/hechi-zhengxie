/* 二级栏目页：按 ?id=<旧库栏目ID> 渲染栏目信息、稿件列表、分页与左侧栏
 *
 * 版式：左侧为栏目按钮 + 最新新闻 + 图片新闻，右侧为纯稿件列表（无卡片、无缩略图）；
 * 单栏目（无子栏目）时不显示左侧栏，稿件列表直接铺满。
 * 数据来自 data/channel.json（原型样例，取旧库 rd_news 主站内容），
 * 后端就绪后由 REST API 平替，渲染逻辑不变。
 */
(function () {
  "use strict";

  const DATA_URL = "data/channel.json";
  const HOME_URL = "./index.html";
  const DEFAULT_ID = "904";
  const PAGE_SIZE = 20;
  const LATEST_SIZE = 8;
  const THUMB_SIZE = 4;

  const el = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  const kicker = (slug) => String(slug || "").replace(/-/g, " ").toUpperCase();

  const state = { channel: null, all: [], page: 1 };

  function param(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  function renderCrumb(channel) {
    const box = el("crumb");
    if (!box) return;
    const hasInner = channel.inner && channel.inner !== channel.name;
    box.innerHTML =
      '<li><a href="' + HOME_URL + '">首页</a></li>' +
      (hasInner
        ? '<li><a href="channel.html?id=' + esc(channel.type) + '">' + esc(channel.name) + '</a></li>' +
          '<li class="is-current" aria-current="page">' + esc(channel.inner) + '</li>'
        : '<li class="is-current" aria-current="page">' + esc(channel.name) + '</li>');
  }

  function renderHero(channel) {
    el("channelKicker").textContent = kicker(channel.slug);
    el("channelTitle").textContent = channel.inner || channel.name;
    el("channelIntro").textContent = channel.intro || "";
    el("channelTotal").textContent = channel.total;
    const heading = el("listHeading");
    if (heading) heading.textContent = (channel.inner || channel.name) + "稿件";
    document.title = (channel.inner || channel.name) + " · 广西河池政协网";
  }

  // 左侧栏：栏目按钮（仅多栏目时显示）；单栏目页隐藏整个左栏，列表铺满
  function renderButtons(channel) {
    const wrap = el("channelButtons");
    const siblings = channel.siblings || [];
    if (siblings.length) {
      wrap.innerHTML = siblings.map(function (s) {
        const active = String(s.type) === String(channel.type);
        return active
          ? '<span class="channel-btn is-active" aria-current="true">' + esc(s.name) + '</span>'
          : '<a class="channel-btn" href="channel.html?id=' + esc(s.type) + '">' + esc(s.name) + '</a>';
      }).join("");
      wrap.hidden = false;
      return;
    }
    const layout = el("innerLayout");
    if (layout) layout.classList.add("inner-layout--full");
  }

  function renderList() {
    const wrap = el("listWrap");
    const channel = state.channel;
    const start = (state.page - 1) * PAGE_SIZE;
    const rows = state.all.slice(start, start + PAGE_SIZE);
    if (!rows.length) {
      wrap.innerHTML = '<div class="empty-state">该栏目暂无稿件。</div>';
      return;
    }
    wrap.innerHTML = '<ul class="article-rows">' + rows.map(function (item) {
      return '<li><a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' +
        esc(item.title) + '</a>' +
        '<time datetime="' + esc(item.datetime) + '">' + esc(item.datetime) + '</time></li>';
    }).join("") + '</ul>';
    const totalPages = Math.max(1, Math.ceil(state.all.length / PAGE_SIZE));
    el("listCount").textContent = "演示数据 " + state.all.length + " 条 / 全站共 " +
      channel.total + " 条 · 第 " + state.page + "/" + totalPages + " 页";
  }

  function renderPager() {
    const box = el("pager");
    const totalPages = Math.max(1, Math.ceil(state.all.length / PAGE_SIZE));
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

  // 左侧栏下方：最新新闻（全站汇总）+ 图片新闻（缩略图）
  function renderSide(channels, channel) {
    const latest = el("latestList");
    if (latest) {
      const pool = [];
      channels.forEach(function (ch) {
        ch.list.forEach(function (item) { pool.push(item); });
      });
      pool.sort(function (a, b) { return String(b.datetime).localeCompare(String(a.datetime)); });
      latest.innerHTML = pool.slice(0, LATEST_SIZE).map(function (item) {
        return '<li><a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' +
          esc(item.title) + '</a></li>';
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
      const totalPages = Math.max(1, Math.ceil(state.all.length / PAGE_SIZE));
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
    el("innerSide").hidden = true;
    el("innerLayout").classList.add("inner-layout--full");
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
        state.all = channel.list.slice();
        state.page = 1;
        renderCrumb(channel);
        renderHero(channel);
        renderButtons(channel);
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
