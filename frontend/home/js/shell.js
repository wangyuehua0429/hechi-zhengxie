/* 内页公共外壳：顶部工具条 / 站头导航 / 页脚 / 无障碍交互
 *
 * 供 channel.html、detail.html 等内页复用，站级数据统一取 data/home.json
 * 的 meta、nav、marquee 三节（不新增数据副本，避免与首页漂移）。
 * 栏目索引与链接改写统一取自 js/site-links.js：导航里的旧站栏目链接，凡原型
 * 已实现的栏目一律改跳本地内页（channel.html?id=<栏目>），未实现的仍指向旧站。
 * 首页仍用 js/main.js 渲染自身模块，后续后端模板化时两者合并。
 */
(function () {
  "use strict";

  const site = "https://www.gxhczx.gov.cn";

  const el = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");

  function abs(u) {
    if (!u || u === "#") return u;
    if (/^(https?:)?\/\//.test(u)) return u;
    if (u.startsWith("//")) return "https:" + u;
    if (u.startsWith("/")) return site + u;
    if (/^(\.\/|\.\.\/|images\/|data:|channel\.html|detail\.html)/.test(u)) return u;
    return site + "/" + u;
  }

  // 旧站栏目链接 -> 本地栏目页（仅限原型已覆盖的栏目，其余保持旧站链接）
  function channelLink(url) {
    return window.SITE_LINKS ? window.SITE_LINKS.channel(url) : url;
  }

  function ext(u) {
    return (u && u !== "#" && /^https?:/.test(u)) ? " target=\"_blank\" rel=\"noopener\"" : "";
  }

  // 图片加载失败降级：隐藏破图，避免正文出现裂图占位
  document.addEventListener("error", function (e) {
    const t = e.target;
    if (t && t.tagName === "IMG") t.classList.add("img-failed");
  }, true);

  function renderDate() {
    const d = new Date();
    const week = ["星期日", "星期一", "星期二", "星期三", "星期四", "星期五", "星期六"];
    const node = el("topbarDate");
    if (node) {
      node.textContent = d.getFullYear() + "年" + (d.getMonth() + 1) + "月" +
        d.getDate() + "日 " + week[d.getDay()];
    }
  }

  function renderNav(nav) {
    const home = nav[0];
    const homeEl = el("navHome");
    if (homeEl && home) {
      homeEl.href = "./index.html";
      homeEl.innerHTML =
        '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" ' +
        'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/>' +
        '<path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>' +
        '<span>' + esc(home.title) + '</span>';
    }
    const rest = nav.slice(1);
    const mid = Math.ceil(rest.length / 2);
    const rowLink = function (n) {
      const url = channelLink(n.url);
      return '<a href="' + esc(abs(url)) + '"' + ext(abs(url)) + '>' + esc(n.title) + '</a>';
    };
    const rowsEl = el("navRows");
    if (rowsEl) {
      rowsEl.innerHTML =
        '<div class="nav-row">' + rest.slice(0, mid).map(rowLink).join("") + '</div>' +
        '<div class="nav-row">' + rest.slice(mid).map(rowLink).join("") + '</div>';
    }
  }

  function renderMarquee(text) {
    const track = el("marqueeTrack");
    if (!track) return;
    track.innerHTML = '<span class="marquee-text">' + esc(text) + '</span>';
  }

  function renderFooter(meta) {
    const box = el("footerBody");
    if (!box) return;
    box.innerHTML =
      '<p class="footer-org">' + esc(meta.owner) + '</p>' +
      '<p>版权所有：' + esc(meta.owner) + '</p>' +
      '<p>开发维护：河池市融媒体中心&nbsp;&nbsp;河池市数媒创新科技发展有限公司</p>' +
      '<p class="footer-copy"><a href="http://' + esc(meta.domain) + '" target="_blank" rel="noopener">' +
      esc(meta.copyright) + '</a></p>' +
      '<p class="footer-contact">投稿邮箱：<a href="mailto:' + esc(meta.contactEmail) + '">' +
      esc(meta.contactEmail) + '</a><span class="footer-sep"></span>联系电话：' + esc(meta.contactPhone) + '</p>' +
      '<div class="footer-icp">' +
      '<a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener">' + esc(meta.icp) + '</a>' +
      '<span class="footer-police"><img src="images/ghs.png" alt="公安备案徽标">' + esc(meta.police) + '</span>' +
      '</div>' +
      '<p>建议使用 Chrome / Edge 等现代浏览器访问，分辨率 1280×768 及以上</p>' +
      '<div class="footer-badges">' +
      '<img class="badge-tall" src="images/td1.gif" alt="广西网络警察">' +
      '<img class="badge-wide" src="images/td3.gif" alt="广西网警虚拟岗亭">' +
      '<img class="badge-wide" src="images/baicp.gif" alt="广西网警网站备案">' +
      '<img class="badge-tall" src="images/td2.gif" alt="广西网络警察">' +
      '</div>';
  }

  function initMasthead() {
    const bgs = Array.from(document.querySelectorAll(".masthead-layers .mast-bg"));
    if (bgs.length < 2) return;
    if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    let idx = 0;
    setInterval(function () {
      bgs[idx].classList.remove("active");
      idx = (idx + 1) % bgs.length;
      bgs[idx].classList.add("active");
    }, 5000);
  }

  function bindInteractions() {
    const toggle = el("navToggle"), nav = el("siteNav");
    if (toggle && nav) {
      toggle.addEventListener("click", function () {
        const open = nav.classList.toggle("open");
        toggle.setAttribute("aria-expanded", open ? "true" : "false");
      });
    }
    document.querySelectorAll(".font-tools button").forEach(function (b) {
      b.addEventListener("click", function () {
        document.body.setAttribute("data-font", b.dataset.font);
        document.querySelectorAll(".font-tools button").forEach(function (x) {
          x.classList.toggle("active", x === b);
        });
        document.dispatchEvent(new CustomEvent("site:fontchange"));
      });
    });
    const cb = el("contrastBtn");
    if (cb) {
      cb.addEventListener("click", function () {
        document.body.classList.toggle("high-contrast");
        cb.setAttribute("aria-pressed",
          document.body.classList.contains("high-contrast") ? "true" : "false");
      });
    }
    const topbar = document.querySelector(".topbar");
    let lastY = window.scrollY;
    window.addEventListener("scroll", function () {
      if (!topbar) return;
      const y = window.scrollY;
      if (y > lastY && y > 80) topbar.classList.add("is-hidden");
      else if (y < lastY) topbar.classList.remove("is-hidden");
      lastY = y;
    }, { passive: true });
  }

  window.SITE = window.SITE || {};

  /* ---------- 内页侧栏：最新新闻 + 图片新闻 ----------
   * 全站统一内容，不随当前页面变化：
   *   最新新闻 —— 各栏目稿件汇总（排除首页聚合栏目与领导简介），按发布时间倒序；
   *   图片新闻 —— 只取「图片新闻」栏目（type 314）里有图的稿件，按时间倒序取，
   *               不回退到其它栏目的图片，避免把领导照片当成图片新闻。
   * 条数/张数在基准值基础上自动配平：渲染后测量左栏内容高度与右栏稿件列表高度，
   * 增删最新新闻条数与图片新闻行数（2 列 1 行 = 2 张），使左栏底部与右栏对齐。
   */
  const SIDE_LATEST_BASE = 8;      // 最新新闻基准条数
  const SIDE_LATEST_MIN = 3;       // 配平下限
  const SIDE_LATEST_MAX = 30;      // 配平上限
  const SIDE_THUMB_ROWS = 2;       // 图片新闻基准行数（每行 2 张，即 4 张）
  const SIDE_THUMB_ROWS_MAX = 3;   // 图片新闻最多 3 行 6 张
  const SIDE_THUMB_COLS = 2;
  const SIDE_FIT_TOLERANCE = 8;    // 允许的高度差（px）
  const IMAGE_NEWS_TYPE = "314";

  function timeOf(item) {
    return String(item.datetime || item.date || "");
  }

  function byTimeDesc(a, b) {
    return timeOf(b).localeCompare(timeOf(a));
  }

  function imageNewsChannel(channels) {
    const byType = channels.filter(function (c) { return String(c.type) === IMAGE_NEWS_TYPE; })[0];
    if (byType) return byType;
    return channels.filter(function (c) {
      return c.layout === "gallery" && String(c.name || "").indexOf("图片新闻") >= 0;
    })[0] || null;
  }

  // 侧栏数据池：渲染与配平共用，避免重复计算
  const sidePools = { latest: [], thumbs: [] };

  // 容器内可见内容的高度：网格行高会把两栏盒子拉成等高，盒子高度不可用
  function contentHeight(container) {
    const kids = Array.prototype.filter.call(container.children, function (child) {
      return !child.hidden && child.offsetParent !== null;
    });
    if (!kids.length) return 0;
    return kids[kids.length - 1].getBoundingClientRect().bottom -
      container.getBoundingClientRect().top;
  }

  function paintSide(latestCount, thumbCount) {
    const latest = el("latestList");
    if (latest) {
      latest.innerHTML = sidePools.latest.slice(0, latestCount).map(function (item) {
        return '<li><a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' +
          esc(item.title) + '</a></li>';
      }).join("") || '<li class="empty-state">暂无新闻。</li>';
    }
    const thumbs = el("thumbGrid");
    if (thumbs) {
      const card = thumbs.closest ? thumbs.closest(".side-card") : null;
      // 0 张表示这一栏暂不展示（短列表栏目配平用）
      if (card) card.hidden = thumbCount === 0;
      if (thumbCount > 0) {
        thumbs.innerHTML = sidePools.thumbs.slice(0, thumbCount).map(function (item) {
          return '<a href="' + esc(item.url) + '" title="' + esc(item.title) + '">' +
            '<img src="' + esc(item.img) + '" alt="' + esc(item.title) + '" loading="lazy">' +
            '<span>' + esc(item.title) + '</span></a>';
        }).join("") || '<div class="empty-state">暂无图片</div>';
      }
    }
  }

  // 左栏与右栏底部对齐：按右栏内容高度增删最新新闻条数与图片新闻行数
  // 窄屏（侧栏换到列表下方）不适用
  function fitSideHeights() {
    const side = document.querySelector(".inner-side");
    const main = document.querySelector(".inner-main");
    if (!side || !main || side.offsetParent === null) return;
    if (window.matchMedia("(max-width: 860px)").matches) return;
    if (!sidePools.latest.length && !sidePools.thumbs.length) return;

    const target = contentHeight(main);
    if (target < 200) return;
    const maxLatest = Math.min(SIDE_LATEST_MAX,
      Math.max(SIDE_LATEST_BASE, sidePools.latest.length));
    const maxRows = sidePools.thumbs.length
      ? Math.min(SIDE_THUMB_ROWS_MAX, Math.ceil(sidePools.thumbs.length / SIDE_THUMB_COLS))
      : 1;
    const candidates = [];

    const measure = function (latestCount, rows) {
      paintSide(latestCount, rows * SIDE_THUMB_COLS);
      const height = contentHeight(side);
      candidates.push({ latest: latestCount, rows: rows, height: height,
                        diff: Math.abs(height - target), index: candidates.length });
      return height;
    };

    // 图片行数可选 2 → 1 → 3（2 行为基准，超高的页面先收到 1 行，
    // 腾出的空间用新闻条数补；不够高时才扩到 3 行）
    const rowOptions = [SIDE_THUMB_ROWS, 1, SIDE_THUMB_ROWS_MAX].filter(function (rows, i, arr) {
      return rows >= 1 && rows <= maxRows && arr.indexOf(rows) === i;
    });

    // 右栏过短时（连「3 条新闻 + 1 行图片」都放不下），才把图片新闻整卡隐藏
    if (measure(SIDE_LATEST_MIN, 1) > target + SIDE_FIT_TOLERANCE) rowOptions.push(0);

    // 每个行数下用二分法找不超过右栏的最大新闻条数
    rowOptions.forEach(function (rows) {
      let low = SIDE_LATEST_MIN;
      let high = maxLatest;
      let fit = null;
      while (low <= high) {
        const mid = Math.floor((low + high) / 2);
        const height = measure(mid, rows);
        if (height <= target + SIDE_FIT_TOLERANCE) {
          fit = mid;
          low = mid + 1;
        } else {
          high = mid - 1;
        }
      }
      if (fit === null) measure(SIDE_LATEST_MIN, rows);
      else if (fit < maxLatest) measure(fit + 1, rows);
    });

    // 取与右栏最接近的一档；超出右栏的加一点惩罚，同样接近时取更靠前的组合
    candidates.sort(function (a, b) {
      const scoreA = a.diff + (a.height > target ? SIDE_FIT_TOLERANCE * 1.5 : 0);
      const scoreB = b.diff + (b.height > target ? SIDE_FIT_TOLERANCE * 1.5 : 0);
      if (scoreA !== scoreB) return scoreA - scoreB;
      return a.index - b.index;
    });
    const pick = candidates[0];
    paintSide(pick.latest, pick.rows * SIDE_THUMB_COLS);
    lastFittedTarget = target;
  }

  let lastFittedTarget = null;
  let sideFitTimer = null;
  function scheduleSideFit() {
    window.clearTimeout(sideFitTimer);
    sideFitTimer = window.setTimeout(fitSideHeights, 120);
  }

  function renderSidePanels(channels) {
    const list = channels || [];
    const seen = {};
    sidePools.latest = [];
    list.forEach(function (channel) {
      // 首页模块聚合栏目（视频/专题/互动）与领导简介不算新闻
      if (channel.homeSourced || channel.layout === "leaders") return;
      (channel.list || []).forEach(function (item) {
        if (seen[item.id]) return;
        seen[item.id] = true;
        sidePools.latest.push(item);
      });
    });
    sidePools.latest.sort(byTimeDesc);
    const imageChannel = imageNewsChannel(list);
    sidePools.thumbs = ((imageChannel && imageChannel.list) || [])
      .filter(function (item) { return !!item.img; })
      .sort(byTimeDesc);

    paintSide(SIDE_LATEST_BASE, SIDE_THUMB_ROWS * SIDE_THUMB_COLS);
    fitSideHeights();
    // 字号缩放、窗口尺寸变化、正文图片加载导致右栏变高后重新配平
    window.addEventListener("resize", scheduleSideFit);
    document.addEventListener("site:fontchange", scheduleSideFit);
    const main = document.querySelector(".inner-main");
    if (main && window.ResizeObserver && !main._sideFitObserver) {
      main._sideFitObserver = new ResizeObserver(function () {
        if (lastFittedTarget === null ||
            Math.abs(contentHeight(main) - lastFittedTarget) > 2) {
          scheduleSideFit();
        }
      });
      main._sideFitObserver.observe(main);
    }
  }

  window.SITE.renderSidePanels = renderSidePanels;

  // 栏目数据要完整的（channel.js 用 list 渲染列表、detail.js 用 name 拼标题），
  // 而 js/site-links.js 那份精简索引只有 type 与 ids，是给链接改写用的，不能当栏目数据。
  // 全站只此一次请求，channel.js / detail.js 复用 channelsReady。
  window.SITE.channelsReady = window.SITE_DATA.channels()
    .catch(function () { return []; });

  function init() {
    renderDate();
    bindInteractions();
    initMasthead();
    window.SITE.dataReady = Promise.all([window.SITE_DATA.home(), window.SITE.channelsReady])
      .then(function (res) {
        const data = res[0];
        renderMarquee(data.meta.marquee);
        renderNav(data.nav);
        renderFooter(data.meta);
        window.SITE.data = data;
        window.SITE.channels = res[1];
        return data;
      })
      .catch(function (err) {
        window.SITE.error = err;
        const box = el("footerBody");
        if (box) box.innerHTML = '<p>站级数据加载失败，请通过本地静态服务器访问本页。</p>';
        return null;
      });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
