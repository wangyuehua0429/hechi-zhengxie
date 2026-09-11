/* 二级栏目页：按 ?id=<旧库栏目ID> 渲染栏目信息、稿件列表、分页与左侧栏
 *
 * 版式由数据里的 layout 决定（data/channel.json）：
 *   list   稿件列表：左侧栏目按钮 + 最新新闻 + 图片新闻，右侧纯稿件列表（无卡片、无缩略图）；
 *          无子栏目的栏目不显示左侧栏，列表直接铺满。
 *   about  一页式：在列表上方加一块栏目简介（feature），左侧为固定子导航。
 *   county 县区：面板上方加县（区）政协站点入口，下方为县区动态列表。
 *   gallery 图集：图片卡片网格（图片新闻、河池风光等）。
 *   video  视频：视频卡片网格，点击跳转来源播放页（政协视频等）。
 *   topic  专题：专题封面卡片列表（专题栏目）。
 *   interactive 互动：栏目说明与建言入口占位（委员直通车）。
 * 数据来自 data/channel.json（原型样例，取旧库 rd_news 内容），
 * 后端就绪后由 REST API 平替，渲染逻辑不变。
 */
(function () {
  "use strict";

  const DATA_URL = "data/channel.json";
  const HOME_URL = "./index.html";
  const DEFAULT_ID = "904";
  const PAGE_SIZE = 20;

  const el = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  const state = { channel: null, all: [], page: 1 };

  function param(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  // 栏目索引由 shell.js 统一取，避免与导航重复请求
  function loadChannels() {
    if (window.SITE && window.SITE.channelsReady) return window.SITE.channelsReady;
    return fetch(DATA_URL)
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then(function (data) { return (data && data.channels) || []; });
  }

  function renderCrumb(channel) {
    const box = el("crumb");
    if (!box) return;
    const hasInner = channel.inner && channel.inner !== channel.name;
    // 子栏目挂在所属一级栏目下（columnId），面包屑第二级回一级栏目页
    const parentId = channel.columnId || channel.type;
    box.innerHTML =
      '<li><a href="' + HOME_URL + '">首页</a></li>' +
      (hasInner
        ? '<li><a href="channel.html?id=' + encodeURIComponent(parentId) + '">' + esc(channel.name) + '</a></li>' +
          '<li class="is-current" aria-current="page">' + esc(channel.inner) + '</li>'
        : '<li class="is-current" aria-current="page">' + esc(channel.name) + '</li>');
  }

  // 栏目页不设页头，栏目名落在面包屑与列表标题上
  function renderHead(channel) {
    const name = channel.inner || channel.name;
    const heading = el("listHeading");
    if (heading) heading.textContent = name;
    document.title = name + " · 广西河池政协网";
  }

  // 左侧栏：栏目按钮（仅多栏目时显示）
  // 单栏目页保留左栏的「最新新闻 + 图片新闻」，只隐藏栏目按钮一栏；
  // 图集/视频/专题/互动是整幅卡片版式，不设左栏。
  const FULL_WIDTH_LAYOUTS = { gallery: true, video: true, topic: true, interactive: true };

  function renderButtons(channel) {
    const wrap = el("channelButtons");
    const siblings = channel.siblings || [];
    if (siblings.length) {
      wrap.innerHTML = siblings.map(function (s) {
        // 一页式栏目的子导航指向详情页，用 active 标记当前项
        const active = s.active === true || String(s.type) === String(channel.type);
        const href = s.url || ("channel.html?id=" + encodeURIComponent(s.type));
        return active
          ? '<span class="channel-btn is-active" aria-current="true">' + esc(s.name) + '</span>'
          : '<a class="channel-btn" href="' + esc(href) + '">' + esc(s.name) + '</a>';
      }).join("");
      wrap.hidden = false;
      return;
    }
    wrap.hidden = true;
    const layout = el("innerLayout");
    if (layout && FULL_WIDTH_LAYOUTS[channel.layout]) layout.classList.add("inner-layout--full");
  }

  // 一页式栏目：列表上方展示栏目简介与「完整简介」入口
  function renderFeature(channel) {
    const box = el("aboutBox");
    if (!box) return;
    const feature = channel.feature;
    if (!feature) {
      box.hidden = true;
      return;
    }
    box.innerHTML =
      '<div class="panel-head"><h2>' + esc(feature.title) + '</h2></div>' +
      '<div class="about-body">' +
      '<p class="about-text">' + esc(feature.summary) + '…</p>' +
      '<a class="about-more" href="' + esc(feature.url) + '">完整简介 &gt;&gt;</a>' +
      '</div>';
    box.hidden = false;
  }

  // 县（区）政协：面板上方先列「全部」（当前列表即全部稿件），再列各县（区）政协入口，
  // 未建站的只显示名称
  function renderCounties(channel) {
    const box = el("countyLinks");
    if (!box) return;
    const counties = channel.counties || [];
    if (!counties.length) {
      box.hidden = true;
      return;
    }
    box.innerHTML =
      '<span class="county-link is-active" aria-current="true">全部</span>' +
      counties.map(function (county) {
        return county.url
          ? '<a class="county-link" href="' + esc(county.url) + '" target="_blank" rel="noopener">' +
            esc(county.name) + '</a>'
          : '<span class="county-link is-plain">' + esc(county.name) + '</span>';
      }).join("");
    box.hidden = false;
  }

  function pageRows() {
    const start = (state.page - 1) * PAGE_SIZE;
    return state.all.slice(start, start + PAGE_SIZE);
  }

  // 图集型：图片卡片网格，点开进详情页看大图
  function renderGallery(wrap) {
    const rows = pageRows();
    if (!rows.length) {
      wrap.innerHTML = '<div class="empty-state">该栏目暂无图片。</div>';
      return;
    }
    wrap.innerHTML = '<div class="gallery-grid">' + rows.map(function (item) {
      const title = esc(item.title);
      return '<figure class="gallery-card">' +
        '<a href="' + esc(item.url) + '" title="' + title + '">' +
        (item.img
          ? '<img src="' + esc(item.img) + '" alt="' + title + '" loading="lazy">'
          : '<span class="gallery-noimg" aria-hidden="true">暂无图片</span>') +
        '<figcaption>' + title + '</figcaption>' +
        '</a>' +
        (item.date ? '<time datetime="' + esc(item.datetime || item.date) + '">' + esc(item.date) + '</time>' : "") +
        '</figure>';
    }).join("") + '</div>';
  }

  // 领导型（政协领导）：按职务分组，照片 + 姓名卡片，卡片与「简介」都指向个人简介正文
  const ROLE_ORDER = ["主席", "副主席", "秘书长"];

  function renderLeaders(wrap) {
    const groups = [];
    state.all.forEach(function (item) {
      const role = item.role || "副主席";
      let group = groups.filter(function (g) { return g.role === role; })[0];
      if (!group) {
        group = { role: role, items: [] };
        groups.push(group);
      }
      group.items.push(item);
    });
    if (!groups.length) {
      wrap.innerHTML = '<div class="empty-state">该栏目暂无领导信息。</div>';
      return;
    }
    groups.sort(function (a, b) {
      const ia = ROLE_ORDER.indexOf(a.role), ib = ROLE_ORDER.indexOf(b.role);
      return (ia < 0 ? ROLE_ORDER.length : ia) - (ib < 0 ? ROLE_ORDER.length : ib);
    });
    wrap.innerHTML = groups.map(function (group) {
      const single = group.role !== "副主席";
      return '<section class="leader-group">' +
        '<h3 class="leader-group-title">' + esc(group.role) + '</h3>' +
        '<ul class="leader-grid' + (single ? " leader-grid--single" : "") + '">' +
        group.items.map(function (item) {
          const name = esc(item.title);
          return '<li class="leader-card">' +
            '<a class="leader-photo" href="' + esc(item.url) + '" title="' + name + '">' +
            (item.img
              ? '<img src="' + esc(item.img) + '" alt="' + name + '" loading="lazy">'
              : '<span class="leader-noimg" aria-hidden="true">' + name + '</span>') +
            '</a>' +
            '<a class="leader-name" href="' + esc(item.url) + '">' + name + '</a>' +
            '</li>';
        }).join("") + '</ul></section>';
    }).join("");
  }

  // 视频型：视频卡片网格，点击到来源播放页（原型不内置播放器）
  function renderVideo(wrap) {
    const rows = pageRows();
    if (!rows.length) {
      wrap.innerHTML = '<div class="empty-state">该栏目暂无视频。</div>';
      return;
    }
    wrap.innerHTML = '<div class="video-grid">' + rows.map(function (item) {
      const title = esc(item.title);
      const external = /^https?:/.test(item.url || "");
      return '<figure class="video-card">' +
        '<a href="' + esc(item.url) + '" title="' + title + '"' +
        (external ? ' target="_blank" rel="noopener"' : "") + '>' +
        (item.img
          ? '<img src="' + esc(item.img) + '" alt="' + title + '" loading="lazy">'
          : '<span class="video-noimg" aria-hidden="true">视频</span>') +
        '<span class="video-play" aria-hidden="true"></span>' +
        '<figcaption>' + title + '</figcaption>' +
        '</a>' +
        (item.date ? '<time datetime="' + esc(item.datetime || item.date) + '">' + esc(item.date) + '</time>' : "") +
        '</figure>';
    }).join("") + '</div>';
  }

  // 专题型：专题封面卡片
  function renderTopic(wrap) {
    const rows = pageRows();
    if (!rows.length) {
      wrap.innerHTML = '<div class="empty-state">该栏目暂无专题。</div>';
      return;
    }
    wrap.innerHTML = '<div class="topic-grid">' + rows.map(function (item) {
      const title = esc(item.title);
      const external = /^https?:/.test(item.url || "");
      return '<a class="topic-card" href="' + esc(item.url) + '" title="' + title + '"' +
        (external ? ' target="_blank" rel="noopener"' : "") + '>' +
        (item.img ? '<img src="' + esc(item.img) + '" alt="' + title + '" loading="lazy">' : "") +
        '<span class="topic-card-title">' + title + '</span>' +
        '</a>';
    }).join("") + '</div>';
  }

  // 互动型：栏目说明 + 建言入口说明（内容与提交链路由后端接入）
  function renderInteractive(wrap, channel) {
    const note = channel.note || "";
    const items = state.all.length
      ? '<ul class="article-rows">' + state.all.map(function (item) {
          return '<li><a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' +
            esc(item.title) + '</a>' +
            (item.date ? '<time datetime="' + esc(item.datetime || item.date) + '">' + esc(item.date) + '</time>' : "") +
            '</li>';
        }).join("") + '</ul>'
      : '<div class="empty-state">本栏目暂无可公开的来信与回复。</div>';
    wrap.innerHTML =
      '<div class="interactive-box">' +
      (note ? '<p class="interactive-note">' + esc(note) + '</p>' : "") +
      '<div class="interactive-actions">' +
      '<a class="interactive-btn" href="https://www.gxhczx.gov.cn/" target="_blank" rel="noopener">前往旧站互动入口</a>' +
      '<span class="interactive-hint">在线提交与回复查询待后端接入后开放。</span>' +
      '</div>' +
      '</div>' + items;
  }

  const LAYOUT_RENDER = {
    gallery: renderGallery,
    leaders: renderLeaders,
    video: renderVideo,
    topic: renderTopic,
    interactive: renderInteractive
  };

  function renderList() {
    const wrap = el("listWrap");
    const channel = state.channel;
    const render = LAYOUT_RENDER[channel.layout];
    if (render) {
      render(wrap, channel);
      const totalPages = Math.max(1, Math.ceil(state.all.length / PAGE_SIZE));
      if (channel.layout === "leaders") {
        el("listCount").textContent = state.all.length ? "共 " + channel.total + " 位" : "";
      } else {
        el("listCount").textContent = state.all.length
          ? "演示数据 " + state.all.length + " 条 / 全站共 " + channel.total +
            " 条 · 第 " + state.page + "/" + totalPages + " 页"
          : "";
      }
      return;
    }
    const rows = pageRows();
    if (!rows.length) {
      wrap.innerHTML = '<div class="empty-state">该栏目暂无稿件。</div>';
      return;
    }
    wrap.innerHTML = '<ul class="article-rows">' + rows.map(function (item) {
      return '<li><a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' +
        esc(item.title) + '</a>' +
        '<time datetime="' + esc(item.datetime) + '">' + esc(item.date) + '</time></li>';
    }).join("") + '</ul>';
    const totalPages = Math.max(1, Math.ceil(state.all.length / PAGE_SIZE));
    el("listCount").textContent = "演示数据 " + state.all.length + " 条 / 全站共 " +
      channel.total + " 条 · 第 " + state.page + "/" + totalPages + " 页";
  }

  function renderPager() {
    const box = el("pager");
    // 领导型栏目一次列全，不需要分页条
    if (state.channel && state.channel.layout === "leaders") {
      box.innerHTML = "";
      box.hidden = true;
      return;
    }
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

  // 左侧栏下方：最新新闻 + 图片新闻，内容与详情页统一（见 js/shell.js）
  function renderSide(channels) {
    if (window.SITE && window.SITE.renderSidePanels) {
      window.SITE.renderSidePanels(channels);
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

  function renderEmpty(id) {
    const heading = el("listHeading");
    if (heading) heading.textContent = "未找到该栏目";
    if (el("listCount")) el("listCount").textContent = "";
    document.title = "栏目 · 广西河池政协网";
    el("innerSide").hidden = true;
    el("innerLayout").classList.add("inner-layout--full");
    el("listWrap").innerHTML = '<div class="empty-state">未找到栏目 <b>' + esc(id) + '</b>。' +
      '本原型已实现主导航各栏目，请从上方导航或' +
      '<a href="' + HOME_URL + '">首页</a>进入。</div>';
    el("pager").innerHTML = "";
  }

  function init() {
    const id = param("id") || DEFAULT_ID;
    loadChannels()
      .then(function (channels) {
        const channel = channels.filter(function (c) { return String(c.type) === String(id); })[0];
        if (!channel) {
          renderEmpty(id);
          return;
        }
        state.channel = channel;
        state.all = channel.list.slice();
        state.page = 1;
        renderCrumb(channel);
        renderHead(channel);
        renderButtons(channel);
        renderFeature(channel);
        renderCounties(channel);
        renderList();
        renderPager();
        renderSide(channels);
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
