(function () {
  "use strict";

  const DATA_URL = "data/home.json";
  const site = "https://www.gxhczx.gov.cn";
  let state = { data: null, slideIdx: 0, slideTimer: null, slideCount: 0, slides: [], autoOn: true, hovering: false, carouselBound: false };

  /* ---------- 工具 ---------- */
  const el = (id) => document.getElementById(id);
  // 用户偏好“减弱动态效果”：不自动轮播/滚动/换图，仍可手动浏览
  const prefersReducedMotion = () =>
    window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");

  // 只取“XX委员的发言”部分（去掉冒号后的副标题）
  const shortTitle = (t) => {
    const i = String(t).search(/[:：]/);
    return i >= 0 ? t.slice(0, i) : t;
  };

  // 旧站绝对地址补偿：相对路径拼域名
  function abs(u) {
    if (!u || u === "#") return u;
    if (/^(https?:)?\/\//.test(u)) return u;
    if (u.startsWith("//")) return "https:" + u;
    if (u.startsWith("/")) return site + u;
    if (/^(\.\/|\.\.\/|images\/|data:)/.test(u)) return u;
    return site + "/" + u;
  }

  function ext(u) {
    return (u && u !== "#") ? " target=\"_blank\" rel=\"noopener\"" : "";
  }

  // 站内链接改写：内页原型已实现的栏目与稿件指向本地内页（js/site-links.js），
  // 其余地址保持旧站链接，避免首页点开落到空页。图片仍走 abs()。
  const link = (u) => {
    const full = abs(u);
    return (window.SITE_LINKS && window.SITE_LINKS.map) ? window.SITE_LINKS.map(full) : full;
  };

  // 图片加载失败降级（捕获阶段监听 error）
  document.addEventListener("error", function (e) {
    const t = e.target;
    if (t && t.tagName === "IMG") {
      t.classList.add("img-fallback");
      t.removeAttribute("src");
      t.removeAttribute("srcset");
    }
  }, true);

  /* ---------- 通用渲染 ---------- */
  function renderDate() {
    const d = new Date();
    const week = ["星期日","星期一","星期二","星期三","星期四","星期五","星期六"];
    const str = d.getFullYear() + "年" + (d.getMonth()+1) + "月" + d.getDate() + "日 " + week[d.getDay()];
    const node = el("topbarDate");
    if (node) node.textContent = str;
  }

  function renderMarquee(text) {
    const t = el("marqueeTrack");
    if (!t) return;
    t.innerHTML = text
      ? '<span>' + esc(text) + '</span><span>' + esc(text) + '</span>'
      : '<span class="empty-item">暂无要闻</span>';
  }

  function renderNav(nav) {
    // 首页单独在左侧，跨两行并配图标；其余栏目分两行排列（参照四川政协网导航）
    const home = nav[0];
    const homeEl = el("navHome");
    if (homeEl && home) {
      homeEl.href = "./index.html";
      homeEl.innerHTML =
        '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-5.999a2 2 0 0 1 2.582 0l7 5.999A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>' +
        '<span>' + esc(home.title) + '</span>';
    }
    const rowLink = function (n) {
      const url = link(n.url);
      return '<a href="' + esc(url) + '"' + ext(url) + '>' + esc(n.title) + '</a>';
    };
    const rest = nav.slice(1);
    const mid = Math.ceil(rest.length / 2);
    const rowsEl = el("navRows");
    if (rowsEl) {
      rowsEl.innerHTML =
        '<div class="nav-row">' + rest.slice(0, mid).map(rowLink).join("") + '</div>' +
        '<div class="nav-row">' + rest.slice(mid).map(rowLink).join("") + '</div>';
    }
    // 导航点击反馈：宫格与栏目轻微放大（键盘回车同样触发 click，效果一致）
    const wrapEl = rowsEl && rowsEl.closest ? rowsEl.closest(".nav-wrap") : null;
    if (wrapEl && !wrapEl.dataset.tapBound) {
      wrapEl.dataset.tapBound = "1";
      wrapEl.addEventListener("click", function (e) {
        const link = e.target && e.target.closest ? e.target.closest("a") : null;
        if (!link || !wrapEl.contains(link)) return;
        link.classList.add("is-tapped");
        window.clearTimeout(link._tapTimer);
        link._tapTimer = window.setTimeout(function () {
          link.classList.remove("is-tapped");
        }, 200);
      });
    }
  }

  function renderCarousel(slides) {
    const box = el("heroCarousel");
    if (!box) return;
    box.innerHTML =
      '<div class="carousel-track" id="carouselTrack">' +
      slides.map(function (s, i) {
        const hasLink = s.url && s.url !== "#";
        const target = hasLink ? link(s.url) : "";
        const titleHtml = hasLink
          ? '<a href="' + esc(target) + '"' + ext(target) + '>' + esc(s.title) + '</a>'
          : '<span>' + esc(s.title) + '</span>';
        const more = hasLink
          ? '<a class="carousel-more" href="' + esc(target) + '"' + ext(target) + '>阅读原文</a>'
          : '<span class="carousel-more">阅读原文</span>';
        return '<div class="carousel-slide' + (i === 0 ? " active" : "") + '"' +
          (i !== 0 ? ' aria-hidden="true"' : "") + '>' +
          '<div class="carousel-media"><img src="' + esc(abs(s.img)) + '" alt="' + esc(s.title) + '"></div>' +
          '<div class="carousel-text">' +
            '<h2 class="carousel-title">' + titleHtml + '</h2>' +
            (s.summary ? '<p class="carousel-summary">' +
              '<span class="carousel-summary-text">' + esc(s.summary) + '</span>' +
              '<span class="sr-only">' + esc(s.summary) + '</span></p>' : '') +
            more +
          '</div>' +
          '</div>';
      }).join("") +
      '</div>' +
      '<div class="carousel-nav" id="carouselDots">' +
      slides.map(function (_, i) {
        return '<button type="button" class="carousel-dot' + (i === 0 ? " active" : "") +
          '" data-slide="' + i + '" aria-label="第' + (i + 1) + '张"' +
          (i === 0 ? ' aria-current="true"' : "") + '>' +
          (i + 1) +
          '</button>';
      }).join("") +
      (slides.length > 1
        ? '<button type="button" class="carousel-pause" id="carouselPause" aria-label="暂停轮播" aria-pressed="true">' +
          '<svg class="carousel-pause-ic carousel-pause-ic--pause" viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><rect x="6" y="4" width="4" height="16" rx="1"/><rect x="14" y="4" width="4" height="16" rx="1"/></svg>' +
          '<svg class="carousel-pause-ic carousel-pause-ic--play" viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M7 4.5a1 1 0 0 1 1.5-.87l11 6.5a1 1 0 0 1 0 1.74l-11 6.5A1 1 0 0 1 7 17.5z"/></svg>' +
          '</button>'
        : "") +
      '</div>' +
      '<button type="button" class="carousel-arrow prev" id="slidePrev" aria-label="上一张">‹</button>' +
      '<button type="button" class="carousel-arrow next" id="slideNext" aria-label="下一张">›</button>';
    state.slideCount = slides.length;
    state.slides = slides;
    state.autoOn = state.slideCount > 1 && !prefersReducedMotion();
    initCarousel();
    requestAnimationFrame(applySummaryClamps);
  }

  // 摘要行数随标题折行联动：标题 1 行 → 摘要 11 行，标题 ≥2 行 → 摘要 10 行，
  // 并受轮换图文字区可用高度限制（窄屏自动减行，避免“阅读原文”被裁掉）。
  // 超出部分不走 -webkit-line-clamp 的原生省略，而是在末行中部用三个点省略号收尾，
  // 省略号前保留约 60% 行宽，便于一眼看出文字未写完。移动端（≤640px）恢复全文，
  // 交由 CSS 媒体查询设定的 2 行生效。
  function applySummaryClamps() {
    const slides = document.querySelectorAll(".carousel-slide");
    if (!slides.length) return;
    const mobile = window.matchMedia("(max-width: 640px)").matches;
    Array.prototype.forEach.call(slides, function (slide) {
      const title = slide.querySelector(".carousel-title a, .carousel-title span");
      const summary = slide.querySelector(".carousel-summary");
      if (!title || !summary) return;
      const body = summary.querySelector(".carousel-summary-text");
      if (!body) return;
      if (body.dataset.full === undefined) body.dataset.full = body.textContent;
      const full = body.dataset.full;
      if (mobile) {
        body.textContent = full;
        summary.style.webkitLineClamp = "";
        return;
      }
      const tcs = getComputedStyle(title);
      let lh = parseFloat(tcs.lineHeight);
      if (!isFinite(lh) || lh <= 0) lh = parseFloat(tcs.fontSize) * 1.4;
      const lines = Math.max(1, Math.round(title.offsetHeight / lh));
      // 期望行数基础上，再按文字区剩余高度收敛，避免摘要把“阅读原文”挤出卡片
      const col = slide.querySelector(".carousel-text");
      const more = slide.querySelector(".carousel-more");
      const ccs = getComputedStyle(col);
      const gap = parseFloat(ccs.rowGap || ccs.gap) || 0;
      const avail = col.clientHeight - parseFloat(ccs.paddingTop) - parseFloat(ccs.paddingBottom) -
        title.offsetHeight - (more ? more.offsetHeight : 0) - gap * 2;
      const want = lines >= 2 ? 10 : 11;
      const clamp = Math.max(2, Math.min(want, Math.floor(avail / lh)));
      // 先按全文测量，再按末行中部截断
      body.textContent = full;
      summary.style.webkitLineClamp = "none";
      body.textContent = truncateSummaryText(body.firstChild, full, clamp);
      summary.style.webkitLineClamp = clamp;
      summary.style.flexShrink = "0";
    });
  }

  // 前 n 个字符占几行
  function textLines(node, n) {
    const r = document.createRange();
    r.setStart(node, 0);
    r.setEnd(node, n);
    return r.getClientRects().length;
  }

  // 二分：找出能放进指定行数的最大字符数
  function charsFitting(node, total, lines) {
    let lo = 1, hi = total, best = 0;
    while (lo <= hi) {
      const mid = Math.floor((lo + hi) / 2);
      if (textLines(node, mid) <= lines) { best = mid; lo = mid + 1; } else { hi = mid - 1; }
    }
    return best;
  }

  // 超出 maxLines 行时：保留前 maxLines-1 行整行 + 末行约 60% 字符，末尾补三个点省略号
  function truncateSummaryText(node, full, maxLines) {
    if (!node || node.nodeType !== 3 || maxLines < 2) return full;
    if (textLines(node, full.length) <= maxLines) return full;
    const lastFull = charsFitting(node, full.length, maxLines);
    const prevFull = charsFitting(node, full.length, maxLines - 1);
    const lastLine = Math.max(1, lastFull - prevFull);
    const cut = prevFull + Math.max(1, Math.round(lastLine * 0.6));
    return full.slice(0, cut).replace(/\s+$/, "") + "…";
  }

  function showSlide(i) {
    const n = state.slideCount;
    if (!n) return;
    state.slideIdx = (i + n) % n;
    const track = el("carouselTrack");
    if (track) {
      Array.prototype.forEach.call(track.children, function (c, idx) {
        c.classList.toggle("active", idx === state.slideIdx);
        if (idx === state.slideIdx) c.removeAttribute("aria-hidden");
        else c.setAttribute("aria-hidden", "true");
      });
    }
    const dots = el("carouselDots");
    if (dots) {
      Array.prototype.forEach.call(dots.querySelectorAll(".carousel-dot"), function (d, idx) {
        const on = idx === state.slideIdx;
        d.classList.toggle("active", on);
        if (on) d.setAttribute("aria-current", "true");
        else d.removeAttribute("aria-current");
      });
    }
  }

  // 轮播控制：自动播放 / 胶囊进度 / 播放暂停（事件仅绑定一次）
  function initCarousel() {
    const hero = el("heroCarousel");
    const prev = el("slidePrev"), next = el("slideNext");
    const dots = el("carouselDots");
    const pb = el("carouselPause");
    if (!state.carouselBound) {
      state.carouselBound = true;
      if (prev) prev.addEventListener("click", function () {
        showSlide(state.slideIdx - 1);
        reconcileCarousel();
      });
      if (next) next.addEventListener("click", function () {
        showSlide(state.slideIdx + 1);
        reconcileCarousel();
      });
      if (dots) dots.addEventListener("click", function (e) {
        const b = e.target.closest(".carousel-dot");
        if (b) { showSlide(+b.dataset.slide); reconcileCarousel(); }
      });
      if (pb) pb.addEventListener("click", function () {
        state.autoOn = !state.autoOn;
        reconcileCarousel();
      });
      if (hero) {
        hero.addEventListener("mouseenter", function () { state.hovering = true; reconcileCarousel(); });
        hero.addEventListener("mouseleave", function () { state.hovering = false; reconcileCarousel(); });
      }
      // 窗口尺寸变化会影响标题折行数，需重算摘要 clamp（节流到每帧一次）
      var clampRaf = null;
      window.addEventListener("resize", function () {
        if (clampRaf) return;
        clampRaf = requestAnimationFrame(function () { clampRaf = null; applySummaryClamps(); });
      });
    }
    reconcileCarousel();
  }

  // 依据状态收敛定时器与进度胶囊：autoOn 且未悬停时才计时轮播
  function reconcileCarousel() {
    if (state.slideTimer) { clearInterval(state.slideTimer); state.slideTimer = null; }
    const timing = state.autoOn && !state.hovering && state.slideCount > 1;
    if (timing) {
      state.slideTimer = setInterval(function () { showSlide(state.slideIdx + 1); }, 3000);
    }
    const hero = el("heroCarousel");
    const pb = el("carouselPause");
    if (hero) hero.dataset.playing = state.autoOn ? "1" : "0";
    if (pb) {
      const single = state.slideCount <= 1;
      const on = state.autoOn && !single;
      pb.hidden = single;
      pb.setAttribute("aria-pressed", on ? "true" : "false");
      pb.setAttribute("aria-label", on ? "暂停轮播" : "播放轮播");
    }
  }

  function renderLeaders(leaders) {
    const body = el("leadersBody");
    if (!body) return;
    let html = '<div class="leaders-box">';
    // 主席（固定展示）
    html += '<div class="leader-chair">' +
      '<img src="' + esc(abs(leaders.chairman.img)) + '" alt="主席 ' + esc(leaders.chairman.name) + '">' +
      '<div><a href="' + esc(link(leaders.chairman.url)) + '"><span class="leader-name">' + esc(leaders.chairman.name) + '</span></a>' +
      '<span class="leader-role">主&nbsp;席</span></div></div>';
    // 副主席 / 秘书长 可切换标签
    html += '<div class="leader-tabs" role="tablist" aria-label="政协领导">' +
      '<button type="button" class="leader-tab active" data-tab="vice" role="tab" aria-selected="true">副主席</button>' +
      '<button type="button" class="leader-tab" data-tab="sec" role="tab" aria-selected="false">秘书长</button>' +
      '</div>';
    // 副主席面板
    html += '<div class="leader-panel active" data-panel="vice" role="tabpanel">';
    const viceItems = leaders.viceChairmen.map(function (v) {
      return '<a class="leader-vice" href="' + esc(link(v.url)) + '" title="' + esc(v.name) + '">' +
        '<img src="' + esc(abs(v.img)) + '" alt="' + esc(v.name) + '"><span>' + esc(v.name) + '</span></a>';
    }).join("");
    html += '<div class="leader-vices-marquee"><div class="leader-vices-track">' + viceItems + '</div></div>';
    html += '</div>';
    // 秘书长面板
    const sg = leaders.secretaryGeneral;
    html += '<div class="leader-panel" data-panel="sec" role="tabpanel" hidden>' +
      '<div class="leader-sec">' +
      '<img src="' + esc(abs(sg.img)) + '" alt="秘书长 ' + esc(sg.name) + '">' +
      '<div><a href="' + esc(link(sg.url)) + '"><span class="leader-name">' + esc(sg.name) + '</span></a>' +
      '</div></div></div>';
    // 三个通栏按钮
    html += '<div class="leader-btns">' + leaders.extraLinks.map(function (l) {
      return '<a class="leader-btn" href="' + esc(link(l.url)) + '">' + esc(l.title) + '</a>';
    }).join("") + '</div>';
    html += '</div>';
    body.innerHTML = html;

    // 标签切换
    const tabs = body.querySelectorAll(".leader-tab");
    const panels = body.querySelectorAll(".leader-panel");
    tabs.forEach(function (tab) {
      tab.addEventListener("click", function () {
        const target = tab.dataset.tab;
        tabs.forEach(function (t) {
          const on = t === tab;
          t.classList.toggle("active", on);
          t.setAttribute("aria-selected", on ? "true" : "false");
        });
        panels.forEach(function (p) {
          const on = p.dataset.panel === target;
          p.classList.toggle("active", on);
          p.hidden = !on;
        });
      });
    });
  }

  function listHtml(items, dated) {
    return items.map(function (it) {
      const time = (dated && it.date) ? '<time datetime="' + esc(it.date.slice(0,10)) + '">' + esc(it.date) + '</time>' : "";
      const url = link(it.url);
      return '<li><a href="' + esc(url) + '"' + ext(url) + ' title="' + esc(it.title) + '">' +
        esc(it.title) + '</a>' + time + '</li>';
    }).join("");
  }

  function headLinks(list, id) {
    const node = el(id);
    if (!node) return;
    node.innerHTML = list.map(function (l) {
      const url = link(l.url);
      return '<a href="' + esc(url) + '"' + ext(url) + '>' + esc(l.title) + '</a>';
    }).join("");
  }

  // 通用：子栏目标签切换（参照安徽政协“委员履职/媒体聚焦”布局）
  function renderTabbedSection(data, tabId, listId, moreId, dated, onHover) {
    const tabsEl = el(tabId);
    const listEl = el(listId);
    const moreEl = moreId ? el(moreId) : null;
    if (!tabsEl || !listEl) return;
    const tabs = data.tabs || [];
    function renderList(tab) {
      listEl.innerHTML = listHtml(tab.items || [], dated);
      if (moreEl) moreEl.href = link(tab.url);
    }
    function activate(btn, i) {
      btns.forEach(function (b) {
        const on = b === btn;
        b.classList.toggle("active", on);
        b.setAttribute("aria-selected", on ? "true" : "false");
      });
      renderList(tabs[i]);
    }
    tabsEl.innerHTML = tabs.map(function (t, i) {
      return '<button type="button" class="zxdt-tab' + (i === 0 ? ' active' : '') + '" role="tab" aria-selected="' + (i === 0 ? 'true' : 'false') + '">' + esc(t.title) + '</button>';
    }).join("");
    const btns = tabsEl.querySelectorAll(".zxdt-tab");
    btns.forEach(function (btn, i) {
      btn.addEventListener("click", function () { activate(btn, i); });
      if (onHover) btn.addEventListener("mouseenter", function () { activate(btn, i); });
    });
    if (tabs[0]) renderList(tabs[0]);
  }

  function renderZXDT(zxdt) { renderTabbedSection(zxdt, "zxdtTabs", "zxdtList", "zxdtMore", true, true); }
  function renderZXMeeting(zxMeeting) { renderTabbedSection(zxMeeting, "meetingTabs", "meetingList", "meetingMore", true, true); }

  function renderImageGrid(containerId, items, limit) {
    const grid = el(containerId);
    if (!grid) return;
    const arrange = (limit ? items.slice(0, limit) : items);
    grid.innerHTML = arrange.map(function (it) {
      const url = link(it.url);
      return '<figure class="image-card"><a href="' + esc(url) + '"' + ext(url) + '>' +
        '<img src="' + esc(abs(it.img)) + '" alt="' + esc(it.title) + '" loading="lazy">' +
        '<figcaption>' + esc(it.title) + '</figcaption></a></figure>';
    }).join("");
  }

  function renderImageMarquee(containerId, items) {
    const box = el(containerId);
    if (!box) return;
    const one = items.map(function (it) {
      const url = link(it.url);
      return '<a class="image-flow" href="' + esc(url) + '"' + ext(url) + ' title="' + esc(it.title) + '">' +
        '<img src="' + esc(abs(it.img)) + '" alt="' + esc(it.title) + '" loading="lazy">' +
        '<span class="flow-title">' + esc(it.title) + '</span></a>';
    }).join("");
    box.innerHTML = one;
  }

  function renderMember(mw) {
    const body = el("memberBody");
    if (!body) return;
    const f = mw.featured[0] || null;
    // 大图 + 4张小图 拼成一组
    let html = '<div class="member-media">';
    html += '<div class="member-featured">';
    if (f) {
      const featUrl = link(f.url);
      html += '<a href="' + esc(featUrl) + '"' + ext(featUrl) + '>' +
        '<img src="' + esc(abs(f.img)) + '" alt="' + esc(f.title) + '">' +
        '<div class="feat-title">' + esc(f.title) + '</div></a>';
    }
    html += '</div>';
    // 小图：默认显示 3 张，点击左右按钮手动滑动（不自动滚动）
    const gal = mw.gallery.map(function (g) {
      const url = link(g.url);
      return '<a class="member-gallery-item" href="' + esc(url) + '"' + ext(url) + ' title="' + esc(shortTitle(g.title)) + '">' +
        '<img src="' + esc(abs(g.img)) + '" alt="' + esc(shortTitle(g.title)) + '" loading="lazy"><span>' + esc(shortTitle(g.title)) + '</span></a>';
    }).join("");
    html += '<div class="member-gallery-ctrl">' +
      '<button type="button" class="member-gallery-btn prev" data-dir="-1" aria-label="向左查看上一张">' +
      '<svg class="member-gallery-arrow" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg></button>' +
      '<div class="member-gallery-marquee"><div class="member-gallery-track">' + gal + '</div></div>' +
      '<button type="button" class="member-gallery-btn next" data-dir="1" aria-label="向右查看下一张">' +
      '<svg class="member-gallery-arrow" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"/></svg></button>' +
      '</div>';
    html += '</div>';
    // 委员发言列表
    html += '<div class="member-list"><ul class="news-list news-list-dated">' + listHtml(mw.list, true) + '</ul></div>';
    body.innerHTML = html;
    initMemberGallery(body);

    // 以左侧图片组高度为参考，动态确定右侧标题条数，并使两列底部对齐
    const media = body.querySelector(".member-media");
    const listUl = body.querySelector(".member-list ul");
    if (media && listUl) {
      media.style.alignSelf = "start";
      const mediaH = media.offsetHeight;
      media.style.alignSelf = "";
      const firstLi = listUl.querySelector("li");
      if (firstLi && mediaH > 0) {
        const liH = firstLi.offsetHeight;
        const target = Math.max(1, Math.round(mediaH / liH));
        listUl.innerHTML = listHtml(mw.list.slice(0, target), true);
      }
    }
  }

  // 委员之窗小图：左右按钮手动滑动，不自动滚动
  function initMemberGallery(scope) {
    const wrap = scope.querySelector(".member-gallery-ctrl");
    const marquee = scope.querySelector(".member-gallery-marquee");
    const track = scope.querySelector(".member-gallery-track");
    if (!wrap || !marquee || !track) return;
    const first = track.querySelector(".member-gallery-item");
    const step = first ? first.offsetWidth + parseFloat(getComputedStyle(first).marginRight || "0") : 149;
    let pos = 0;
    function update() {
      const max = Math.max(0, track.scrollWidth - marquee.clientWidth);
      const prev = wrap.querySelector(".member-gallery-btn.prev");
      const next = wrap.querySelector(".member-gallery-btn.next");
      if (prev) prev.disabled = pos <= 0;
      if (next) next.disabled = pos >= max;
      return max;
    }
    function slide(dir) {
      const max = update();
      pos = Math.max(0, Math.min(max, pos + dir * step));
      track.style.transform = "translateX(" + (-pos) + "px)";
      update();
    }
    wrap.querySelectorAll(".member-gallery-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        slide(parseInt(btn.dataset.dir, 10));
      });
    });
    // 无溢出时可隐藏控制按钮
    if (update() <= 0) wrap.style.display = "none";
  }

  function renderCounty(countyZx) {
    const dyn = el("countyDynamic");
    if (dyn) dyn.innerHTML = listHtml(countyZx.dynamic.slice(0, Math.max(0, countyZx.dynamic.length - 2)), false);
  }

  // 县区地图：用 SVG 多边形覆盖，悬停/点击高亮，点击跳转子站
  function initCountyMap() {
    const map = document.querySelector("map#imgMap");
    const svg = document.querySelector(".county-svg");
    if (!map || !svg) return;
    const svgNS = "http://www.w3.org/2000/svg";
    map.querySelectorAll("area").forEach(function (area) {
      const nums = area.getAttribute("coords").split(",").map(Number);
      const pts = [];
      for (let i = 0; i < nums.length; i += 2) pts.push(nums[i] + "," + nums[i + 1]);
      const href = area.getAttribute("href");
      const poly = document.createElementNS(svgNS, "polygon");
      poly.setAttribute("points", pts.join(" "));
      poly.setAttribute("class", "county-region");
      poly.setAttribute("data-title", area.getAttribute("alt") || "");
      poly.setAttribute("data-url", (href && href !== "#") ? href : "");
      poly.addEventListener("click", function () {
        svg.querySelectorAll(".county-region").forEach(function (p) {
          p.classList.remove("active");
        });
        poly.classList.add("active");
        const url = poly.getAttribute("data-url");
        if (url) window.open(url, "_blank", "noopener");
      });
      svg.appendChild(poly);
    });
  }

  function renderRanking(list) {
    const ol = el("rankingList");
    if (!ol) return;
    ol.innerHTML = list.map(function (r) {
      return '<li><span class="rank-name">' + esc(r.name) + '</span>' +
        '<span class="rank-count">来稿' + esc(r.count) + '</span></li>';
    }).join("");
  }

  function initRankingYear() {
    const ry = el("rankingYear");
    if (ry) ry.textContent = new Date().getFullYear();
  }

  function renderTopic(items) {
    const strip = el("topicStrip");
    if (!strip) return;
    strip.innerHTML = items.map(function (it) {
      const url = link(it.url);
      return '<a class="topic-item" href="' + esc(url) + '"' + ext(url) + '>' +
        '<img src="' + esc(abs(it.img)) + '" alt="' + esc(it.title) + '" loading="lazy"></a>';
    }).join("");
    const more = el("topicMore");
    // 专题「更多」：原型已建专题栏目页时指向本地，否则回落到首个专题
    const topicChannel = window.SITE_LINKS && window.SITE_LINKS.channels
      ? window.SITE_LINKS.channels.topic : "";
    if (more && topicChannel) more.href = topicChannel;
    else if (more && items[0]) more.href = link(items[0].url);
  }

  function renderLinks(links) {
    const box = el("linksGroups");
    if (!box) return;
    const keys = Object.keys(links.groups);
    let html = '<div class="links-logos">' + links.logos.map(function (l) {
      return '<a href="' + esc(abs(l.url)) + '" target="_blank" rel="noopener" title="' + esc(l.title) + '">' +
        '<img src="' + esc(abs(l.img)) + '" alt="' + esc(l.title) + '" loading="lazy"></a>';
    }).join("") + '</div>';

    html += '<div class="links-selects">' +
      keys.map(function (k) {
        return '<select class="links-select" aria-label="' + esc(k) + '">' +
          '<option value="" disabled selected>' + esc(k) + '</option>' +
          links.groups[k].map(function (pair) {
            return '<option value="' + esc(abs(pair[1])) + '">' + esc(pair[0]) + '</option>';
          }).join("") +
          '</select>';
      }).join("") +
      '</div>';

    box.innerHTML = html;

    box.querySelectorAll(".links-select").forEach(function (sel) {
      sel.addEventListener("change", function () {
        const v = sel.value;
        if (v && v !== "#") window.open(v, "_blank", "noopener");
        sel.selectedIndex = 0;
      });
    });
  }

  function renderFooter(meta) {
    const box = el("footerBody");
    if (!box) return;
    box.innerHTML =
      '<p class="footer-org">' + esc(meta.owner) + '</p>' +
      '<p>版权所有：' + esc(meta.owner) + '</p>' +
      '<p>开发维护：河池市融媒体中心&nbsp;&nbsp;河池市数媒创新科技发展有限公司</p>' +
      '<p class="footer-copy"><a href="http://' + esc(meta.domain) + '" target="_blank" rel="noopener">' + esc(meta.copyright) + '</a></p>' +
      '<p class="footer-contact">投稿邮箱：<a href="mailto:' + esc(meta.contactEmail) + '">' + esc(meta.contactEmail) + '</a>' +
      '<span class="footer-sep"></span>联系电话：' + esc(meta.contactPhone) + '</p>' +
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

  // 滚动到末尾 → 停留 2 秒 → 回到第一张，循环
  function startScrollLoop(track, container, speed) {
    if (!track || !container) return;
    let pos = 0, last = performance.now(), paused = false, dwelling = false, dwellTimer = null, raf;
    function hold(ms, done) {
      dwelling = true;
      clearTimeout(dwellTimer);
      dwellTimer = setTimeout(function () {
        dwelling = false;
        done();
      }, ms);
    }
    function move(now) {
      // 每帧重新计算可滚距离，避免初始化时布局未就绪导致误判
      const max = Math.max(0, track.scrollWidth - container.clientWidth);
      const dt = (now - last) / 1000;
      last = now;
      if (!paused && !dwelling && max > 2) {
        pos += speed * dt;
        if (pos >= max) {
          pos = max;
          track.style.transform = "translateX(" + (-max) + "px)";
          hold(2000, function () {            // 滚到底 → 停留 2 秒
            pos = 0;
            track.style.transform = "translateX(0)";
            hold(2000, function () {          // 回第一张 → 停留 2 秒
              last = performance.now();
            });
          });
        } else {
          track.style.transform = "translateX(" + (-pos) + "px)";
        }
      }
      raf = requestAnimationFrame(move);
    }
    container.addEventListener("mouseenter", function () { paused = true; });
    container.addEventListener("mouseleave", function () { paused = false; last = performance.now(); });
    raf = requestAnimationFrame(move);
  }

  function initMarquees() {
    if (prefersReducedMotion()) return; // 减弱动态：不做自动滚动，内容静止展示
    requestAnimationFrame(function () {
      startScrollLoop(document.querySelector(".leader-vices-track"), document.querySelector(".leader-vices-marquee"), 60);
      document.querySelectorAll(".image-marquee").forEach(function (m) {
        const t = m.querySelector(".image-marquee-track");
        if (t) startScrollLoop(t, m, 60);
      });
      startScrollLoop(document.querySelector(".member-gallery-track"), document.querySelector(".member-gallery-marquee"), 60);
    });
  }

  // 顶部：底图每 3 秒轮换（文字图层固定）
  function initMasthead() {
    const bgs = Array.from(document.querySelectorAll(".masthead-layers .mast-bg"));
    if (bgs.length < 2) return;
    if (prefersReducedMotion()) return; // 减弱动态：固定首张背景图，不做轮换
    let idx = 0;
    setInterval(function () {
      bgs[idx].classList.remove("active");
      idx = (idx + 1) % bgs.length;
      bgs[idx].classList.add("active");
    }, 5000);
  }

  /* ---------- 交互：导航汉堡 / 字号 / 对比度 ---------- */
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
        requestAnimationFrame(applySummaryClamps);
      });
    });
    const cb = el("contrastBtn");
    if (cb) cb.addEventListener("click", function () {
      document.body.classList.toggle("high-contrast");
      cb.setAttribute("aria-pressed", document.body.classList.contains("high-contrast") ? "true" : "false");
    });

    // 顶部工具条：向下滚动自动隐藏，向上滚动显示
    const topbar = document.querySelector(".topbar");
    let lastTopbarY = window.scrollY;
    window.addEventListener("scroll", function () {
      if (!topbar) return;
      const y = window.scrollY;
      if (y > lastTopbarY && y > 80) topbar.classList.add("is-hidden");
      else if (y < lastTopbarY) topbar.classList.remove("is-hidden");
      lastTopbarY = y;
    }, { passive: true });

    // 视口宽度变化会改变标题折行，随之重算摘要行数
    let summaryResizeT;
    window.addEventListener("resize", function () {
      clearTimeout(summaryResizeT);
      summaryResizeT = setTimeout(applySummaryClamps, 120);
    });
  }

  /* ---------- 主入口 ---------- */
  async function init() {
    renderDate();
    bindInteractions();
    // 等站内链接映射就绪，导航与列表才能指向新版内页而不是旧站
    if (window.SITE_LINKS && window.SITE_LINKS.ready) {
      try { await window.SITE_LINKS.ready; } catch (e) { /* 映射失败时保持旧站链接 */ }
    }
    try {
      const res = await fetch(DATA_URL);
      if (!res.ok) throw new Error("HTTP " + res.status);
      const d = await res.json();
      state.data = d;
      renderMarquee(d.meta.marquee);
      renderNav(d.nav);
      renderCarousel(d.slides);
      renderLeaders(d.leaders);

      // 政协动态 / 会议 tab 链接 + 列表
      renderZXDT(d.zxdt);
      el("sxList").innerHTML = listHtml(d.sxNews, true);
      renderZXMeeting(d.zxMeeting);
      renderImageMarquee("imageMarquee", d.imageNews);

      // 侧栏（标题已在 HTML 固定，这里填充列表与“更多”链接）
      fillBox("notice", d.notice);
      fillBox("book", d.bookCity);
      fillBox("anti", d.antiGang);
      renderVideos(d.videos);
      setMore("noticeMore", "https://www.gxhczx.gov.cn/news_list.php?id=302");
      setMore("bookMore", "https://www.gxhczx.gov.cn/news_list.php?id=1301");
      setMore("antiMore", "https://www.gxhczx.gov.cn/news_list.php?id=400");
      setMore("videoMore", "https://www.gxhczx.gov.cn/news_list.php?id=316");
      setMore("rankingMore", "https://www.gxhczx.gov.cn/top.php");
      setMore("countyMore", "https://www.gxhczx.gov.cn/qy_list.php");
      renderRanking(d.ranking);
      initRankingYear();

      // 三列
      el("zwhList").innerHTML = listHtml(d.zwhWork, false);
      el("partyList").innerHTML = listHtml(d.partyGroups, false);
      el("theoryList").innerHTML = listHtml(d.theory, false);

      renderMember(d.memberWindow);
      renderCounty(d.countyZx);
      initCountyMap();
      renderTopic(d.topic);
      renderImageMarquee("sceneryGrid", d.scenery);
      renderLinks(d.links);
      // index.html 里静态写死的旧站「更多」与横幅入口，统一改指新版内页
      document.querySelectorAll("a.more, a.banner-single").forEach(function (a) {
        const href = a.getAttribute("href") || "";
        // 已经是本地内页地址的归一化为相对路径（setMore 赋值后可能带上站点前缀）
        const local = /(?:^|\/)(channel\.html\?[^"'#]*|detail\.html\?[^"'#]*)/.exec(href);
        if (local) a.setAttribute("href", local[1]);
        else if (href) a.setAttribute("href", link(href));
      });
      renderFooter(d.meta);
      initMarquees();
      initMasthead();
    } catch (err) {
      console.error("首页数据加载失败:", err);
      showDataError();
    }
  }

  function fillBox(prefix, items) {
    const list = el(prefix + "List");
    if (list) {
      list.innerHTML = items && items.length
        ? listHtml(items, false)
        : '<li class="empty-item">暂无更新内容</li>';
    }
  }

  // 政协视频：缩略图 + 标题
  function renderVideos(items) {
    const list = el("videoList");
    if (!list) return;
    list.innerHTML = items && items.length
      ? items.slice(0, 2).map(function (v) {
          const url = link(v.url);
          return '<li class="video-item"><a href="' + esc(url) + '"' + ext(url) + ' title="' + esc(v.title) + '">' +
            '<img class="video-thumb" src="' + esc(abs(v.img)) + '" alt="' + esc(v.title) + '" loading="lazy">' +
            '<span class="video-title">' + esc(v.title) + '</span></a></li>';
        }).join("")
      : '<li class="empty-item">暂无更新内容</li>';
  }

  function setMore(id, url) {
    const a = el(id);
    if (a) a.href = link(url);
  }

  function showDataError() {
    const host = el("main");
    if (!host) return;
    const div = document.createElement("div");
    div.className = "section-card";
    div.style.cssText = "padding:30px;text-align:center;color:#8a6d3b;background:#fdf6e2;border-color:#ecd18b;";
    div.innerHTML = "首页数据暂未加载（请通过本地静态服务器访问，例如 <code>python3 -m http.server</code>）。";
    host.insertBefore(div, host.firstChild);
  }

  document.addEventListener("DOMContentLoaded", init);
})();
