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

  // 取数统一走 js/data-source.js（接口优先、静态快照兜底）
  const HOME_URL = "./index.html";
  const DEFAULT_ID = "904";
  const PAGE_SIZE = 20;

  const el = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  // rows 是"当前这一页"的稿件（真分页后一页就是 20 条，不再把整栏读进来）；
  // total/pages 由数据源给出：接口模式是实时公开条数，静态快照模式是快照里的条数。
  const state = { channel: null, rows: [], page: 1, pages: 1, total: 0, demo: false, channelTotal: 0 };

  function param(name) {
    return new URLSearchParams(window.location.search).get(name);
  }

  // 栏目索引由 shell.js 统一取，避免与导航重复请求
  function loadChannels() {
    if (window.SITE && window.SITE.channelsReady) return window.SITE.channelsReady;
    return window.SITE_DATA.channels();
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
    // 「政协提案」一级栏目（501 及其子栏目）给一个直达提案系统的入口
    const entry = el("proposalEntry");
    if (entry) {
      const group = String(channel.columnId || channel.type || "");
      entry.hidden = group !== "501";
    }
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
    return state.rows;
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
    // 只把"有职务"的条目当花名册：政协章程、机构设置、委员名单这类一页式内容也挂在
    // 政协概况下，不能因为它们没写职务就一律归到"副主席"里。
    const roster = state.rows.filter(function (item) { return !!(item.role || "").trim(); });
    const items = roster.length ? roster : state.rows;
    state.leaderCount = items.length;
    const groups = [];
    items.forEach(function (item) {
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
    const items = state.rows.length
      ? '<ul class="article-rows">' + state.rows.map(function (item) {
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
      if (channel.layout === "leaders") {
        const seats = state.leaderCount || state.rows.length;
        el("listCount").textContent = seats ? "共 " + seats + " 位" : "";
      } else {
        el("listCount").textContent = state.rows.length ? countLabel() : "";
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
    el("listCount").textContent = countLabel();
  }

  // 接口模式写明实时条数与页码；静态快照模式保留"演示数据"字样，
  // 免得把快照里的几十条误当成全站数据（旧版就是这里显示快照常量，看着像全站）。
  function countLabel() {
    const suffix = state.pages > 1 ? " · 第 " + state.page + "/" + state.pages + " 页" : "";
    if (state.demo) {
      return "演示数据 " + state.rows.length + " 条 / 全站共 " + state.channelTotal +
        " 条 · 第 " + state.page + "/" + state.pages + " 页";
    }
    return "共 " + state.total + " 条" + suffix;
  }

  function renderPager() {
    const box = el("pager");
    // 领导型栏目一次列全，不需要分页条
    if (state.channel && state.channel.layout === "leaders") {
      box.innerHTML = "";
      box.hidden = true;
      return;
    }
    const totalPages = state.pages || 1;
    if (totalPages <= 1) {
      box.innerHTML = '<span class="pager-info">' +
        (state.demo ? "已显示全部演示数据" : "已显示全部 " + state.total + " 条") + '</span>';
      return;
    }
    const btn = function (page, label, disabled) {
      return '<button type="button" data-page="' + page + '"' +
        (disabled ? " disabled" : "") +
        ' aria-label="' + esc(label) + '">' + esc(label) + '</button>';
    };
    const parts = [btn(state.page - 1, "上一页", state.page <= 1)];
    pageWindow(state.page, totalPages).forEach(function (n) {
      if (n === "…") {
        parts.push('<span class="pager-gap" aria-hidden="true">…</span>');
        return;
      }
      const i = n;
      parts.push('<button type="button" data-page="' + i + '"' +
        (i === state.page ? ' class="is-active" aria-current="page"' : "") +
        ' aria-label="第 ' + i + ' 页">' + i + '</button>');
    });
    parts.push(btn(state.page + 1, "下一页", state.page >= totalPages));
    box.innerHTML = parts.join("");
  }

  // 页数多时只列「首页 + 当前页前后 1 页 + 末页」，中间用省略号，
  // 免得 306 这种 800 多条的栏目排出几十个按钮。
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
      if (!page || page < 1 || page > (state.pages || 1) || page === state.page) return;
      btn.disabled = true;
      loadPage(page).then(function () {
        renderList();
        renderPager();
        const panel = document.querySelector(".panel");
        if (panel) {
          window.scrollTo({
            top: panel.getBoundingClientRect().top + window.scrollY - 90,
            behavior: "smooth"
          });
        }
      }).catch(function () {
        btn.disabled = false;
      });
    });
  }

  // 取某一页：接口模式走 /api/v1/articles 真分页；静态快照模式由 data-source 本地切片。
  function loadPage(page, size) {
    // 视频、专题这类栏目（homeSourced）的内容取自首页整块配置，不进出稿件的接口，
    // 直接用栏目自带的列表渲染，一次列全、不分页。
    if (state.channel.homeSourced) {
      const items = (state.channel.list || []).slice();
      state.rows = items;
      state.page = 1;
      state.pages = 1;
      state.total = items.length;
      state.demo = false;
      state.channelTotal = items.length;
      return Promise.resolve();
    }
    return window.SITE_DATA.articles({
      channel: state.channel.type,
      page: page,
      size: size || PAGE_SIZE
    }).then(function (data) {
      state.rows = data.items || [];
      state.page = data.page || page;
      state.pages = data.pages || 1;
      state.total = data.total || 0;
      state.demo = !!data.demo;
      state.channelTotal = data.channelTotal || data.total || 0;
    });
  }

  // 左侧子栏目是整页跳转（channel.html?id=…），浏览器会回到文档顶端。
  // 这里在切换前记下视口位置，新页面渲染完成后复位，做到"位置与点击时一致"。
  const SCROLL_KEY = "channelScrollY";

  function rememberScroll(link) {
    const href = (link && link.getAttribute("href")) || "";
    // 只处理"栏目页 → 栏目页"的切换；一页式栏目里指向详情页的链接不做位置复现
    if (href.indexOf("channel.html") === -1) return;
    try {
      sessionStorage.setItem(SCROLL_KEY, JSON.stringify({
        y: Math.round(window.scrollY),
        t: Date.now()
      }));
    } catch (e) { /* 隐私模式等场景忽略 */ }
  }

  function restoreScroll() {
    let saved = null;
    try {
      const raw = sessionStorage.getItem(SCROLL_KEY);
      sessionStorage.removeItem(SCROLL_KEY);   // 只用于紧接的一次跳转
      saved = raw ? JSON.parse(raw) : null;
    } catch (e) { return; }
    const y = Number(saved && saved.y);
    if (!saved || !isFinite(y) || y <= 0) return;
    if (Date.now() - Number(saved.t || 0) > 5000) return;   // 超过 5 秒的记录不再复现
    // 列表高度在异步渲染后才稳定，等一帧再复位；并临时关掉平滑滚动，避免"从顶端滑过去"
    requestAnimationFrame(function () {
      const root = document.documentElement;
      const prev = root.style.scrollBehavior;
      root.style.scrollBehavior = "auto";
      window.scrollTo(0, y);
      root.style.scrollBehavior = prev;
    });
  }

  function bindChannelButtons() {
    const wrap = el("channelButtons");
    if (!wrap) return;
    wrap.addEventListener("click", function (e) {
      const link = e.target.closest("a.channel-btn");
      if (!link) return;                       // 当前栏目是 span，不触发
      rememberScroll(link);
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
          return null;
        }
        state.channel = channel;
        // 领导型栏目一次列全（10 来位），其余栏目每页 20 条按需取
        const size = channel.layout === "leaders" ? 200 : PAGE_SIZE;
        return loadPage(1, size).then(function () {
          renderCrumb(channel);
          renderHead(channel);
          renderButtons(channel);
          renderFeature(channel);
          renderCounties(channel);
          renderList();
          renderPager();
          renderSide(channels);
          bindPager();
          bindChannelButtons();
          restoreScroll();
        });
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
