(function () {
  "use strict";

  const DATA_URL = "data/home.json";
  const site = "https://www.gxhczx.gov.cn";
  let state = { data: null, slideIdx: 0, slideTimer: null };

  /* ---------- 工具 ---------- */
  const el = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");

  // 旧站绝对地址补偿：相对路径拼域名
  function abs(u) {
    if (!u || u === "#") return u;
    if (/^(https?:)?\/\//.test(u)) return u;
    if (u.startsWith("//")) return "https:" + u;
    if (u.startsWith("/")) return site + u;
    return site + "/" + u;
  }

  function ext(u) {
    return (u && u !== "#") ? " target=\"_blank\" rel=\"noopener\"" : "";
  }

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

  function renderNav(nav) {
    const ul = el("mainNav");
    if (!ul) return;
    ul.innerHTML = nav.map(function (n, i) {
      return '<li><a href="' + esc(abs(n.url)) + '"' + (i === 0 ? ' class="active"' : "") +
        (i === nav.length - 1 ? ' target="_blank" rel="noopener"' : '') + '>' + esc(n.title) + '</a></li>';
    }).join("");
  }

  function renderCarousel(slides) {
    const box = el("heroCarousel");
    if (!box) return;
    box.innerHTML =
      '<div class="carousel-track" id="carouselTrack">' +
      slides.map(function (s, i) {
        const link = s.url && s.url !== "#"
          ? '<a href="' + esc(abs(s.url)) + '" target="_blank" rel="noopener">' + esc(s.title) + '</a>'
          : '<span>' + esc(s.title) + '</span>';
        return '<div class="carousel-slide' + (i === 0 ? " active" : "") + '"' +
          (i !== 0 ? ' aria-hidden="true"' : "") + '>' +
          '<img src="' + esc(abs(s.img)) + '" alt="' + esc(s.title) + '">' +
          '<div class="carousel-caption">' + link + '</div>' +
          '</div>';
      }).join("") +
      '</div>' +
      '<div class="carousel-nav" id="carouselDots">' +
      slides.map(function (_, i) {
        return '<button type="button" class="carousel-dot' + (i === 0 ? " active" : "") +
          '" data-slide="' + i + '" aria-label="第' + (i+1) + '张"></button>';
      }).join("") +
      '</div>' +
      '<button type="button" class="carousel-arrow prev" id="slidePrev" aria-label="上一张">‹</button>' +
      '<button type="button" class="carousel-arrow next" id="slideNext" aria-label="下一张">›</button>';
    state.slideCount = slides.length;
    state.slides = slides;
    startCarousel();
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
      Array.prototype.forEach.call(dots.children, function (d, idx) {
        d.classList.toggle("active", idx === state.slideIdx);
      });
    }
  }

  function startCarousel() {
    stopCarousel();
    state.slideTimer = setInterval(function () { showSlide(state.slideIdx + 1); }, 5000);
    const prev = el("slidePrev"), next = el("slideNext");
    if (prev) prev.onclick = function () { showSlide(state.slideIdx - 1); stopCarousel(); };
    if (next) next.onclick = function () { showSlide(state.slideIdx + 1); stopCarousel(); };
    const dots = el("carouselDots");
    if (dots) dots.addEventListener("click", function (e) {
      const b = e.target.closest(".carousel-dot");
      if (b) { showSlide(+b.dataset.slide); stopCarousel(); }
    });
    const hero = el("heroCarousel");
    if (hero) {
      hero.addEventListener("mouseenter", stopCarousel);
      hero.addEventListener("mouseleave", startCarousel);
    }
  }

  function stopCarousel() {
    if (state.slideTimer) { clearInterval(state.slideTimer); state.slideTimer = null; }
  }

  function renderLeaders(leaders) {
    const body = el("leadersBody");
    if (!body) return;
    let html = '<div class="leaders-box">';
    html += '<div class="leader-chair">' +
      '<img src="' + esc(abs(leaders.chairman.img)) + '" alt="主席 ' + esc(leaders.chairman.name) + '">' +
      '<div><a href="' + esc(abs(leaders.chairman.url)) + '"><span class="leader-name">' + esc(leaders.chairman.name) + '</span></a>' +
      '<span class="leader-role">主&nbsp;席</span></div></div>';
    html += '<div class="leader-row-label">副主席</div>';
    html += '<div class="leader-vices">' + leaders.viceChairmen.map(function (v) {
      return '<a class="leader-vice" href="' + esc(abs(v.url)) + '" title="' + esc(v.name) + '">' +
        '<img src="' + esc(abs(v.img)) + '" alt="' + esc(v.name) + '"><span>' + esc(v.name) + '</span></a>';
    }).join("") + '</div>';
    html += '<div class="leader-row-label">秘书长：<a href="' + esc(abs(leaders.secretaryGeneral.url)) + '">' + esc(leaders.secretaryGeneral.name) + '</a></div>';
    html += '<div class="leader-extra">' + leaders.extraLinks.map(function (l) {
      return '<a href="' + esc(abs(l.url)) + '">' + esc(l.title) + '</a>';
    }).join("") + '</div>';
    html += '</div>';
    body.innerHTML = html;
  }

  function listHtml(items, dated) {
    return items.map(function (it) {
      const time = (dated && it.date) ? '<time datetime="' + esc(it.date.slice(0,10)) + '">' + esc(it.date) + '</time>' : "";
      return '<li><a href="' + esc(abs(it.url)) + '"' + ext(it.url) + ' title="' + esc(it.title) + '">' +
        esc(it.title) + '</a>' + time + '</li>';
    }).join("");
  }

  function headLinks(list, id) {
    const node = el(id);
    if (!node) return;
    node.innerHTML = list.map(function (l) {
      return '<a href="' + esc(abs(l.url)) + '" target="_blank" rel="noopener">' + esc(l.title) + '</a>';
    }).join("");
  }

  function renderImageGrid(containerId, items, limit) {
    const grid = el(containerId);
    if (!grid) return;
    const arrange = (limit ? items.slice(0, limit) : items);
    grid.innerHTML = arrange.map(function (it) {
      return '<figure class="image-card"><a href="' + esc(abs(it.url)) + '"' + ext(it.url) + '>' +
        '<img src="' + esc(abs(it.img)) + '" alt="' + esc(it.title) + '" loading="lazy">' +
        '<figcaption>' + esc(it.title) + '</figcaption></a></figure>';
    }).join("");
  }

  function renderMember(mw) {
    const body = el("memberBody");
    if (!body) return;
    const f = mw.featured[0] || null;
    let html = '<div class="member-featured">';
    if (f) {
      html += '<a href="' + esc(abs(f.url)) + '"' + ext(f.url) + '>' +
        '<img src="' + esc(abs(f.img)) + '" alt="' + esc(f.title) + '">' +
        '<div class="feat-title">' + esc(f.title) + '</div></a>';
    }
    html += '</div>';
    html += '<div class="member-list"><ul class="news-list news-list-dated">' + listHtml(mw.list, true) + '</ul></div>';
    html += '<div class="member-gallery">' + mw.gallery.map(function (g) {
      return '<a href="' + esc(abs(g.url)) + '"' + ext(g.url) + ' title="' + esc(g.title) + '">' +
        '<img src="' + esc(abs(g.img)) + '" alt="' + esc(g.title) + '" loading="lazy"><span>' + esc(g.title) + '</span></a>';
    }).join("") + '</div>';
    body.innerHTML = html;
  }

  function renderCounty(countyZx) {
    const body = el("countyBody");
    if (!body) return;
    let html = '<div class="county-cols">';
    html += '<div class="county-dyn-title">' + esc(countyZx.dynamicTitle) +
      ' <a class="more" href="' + esc(abs(countyZx.more)) + '" target="_blank" rel="noopener">更多&gt;&gt;</a></div>';
    html += '<ul class="news-list news-list-compact">' + listHtml(countyZx.dynamic) + '</ul>';
    html += '</div>';
    html += '<div class="county-cols"><div class="county-dyn-title">' + esc(el("countyTitle") ? el("countyTitle").textContent : "县（区）政协") + '</div>';
    html += '<div class="county-grid">' + countyZx.list.map(function (c) {
      return '<a class="county-tag" href="' + esc(abs(c.url)) + '"' + ext(c.url) + '>' + esc(c.name) + '</a>';
    }).join("") + '</div></div>';
    body.innerHTML = html;
  }

  function renderRanking(list) {
    const ol = el("rankingList");
    if (!ol) return;
    ol.innerHTML = list.map(function (r) {
      return '<li><span class="rank-name">' + esc(r.name) + '</span>' +
        '<span class="rank-count">来稿' + esc(r.count) + '</span></li>';
    }).join("");
    const t = el("rankingTitle");
    if (t) t.textContent = "2026来稿排名（前五）";
  }

  function renderTopic(items) {
    const strip = el("topicStrip");
    if (!strip) return;
    strip.innerHTML = items.map(function (it) {
      return '<a class="topic-item" href="' + esc(abs(it.url)) + '" target="_blank" rel="noopener">' +
        '<img src="' + esc(abs(it.img)) + '" alt="' + esc(it.title) + '" loading="lazy"></a>';
    }).join("");
  }

  function renderLinks(links) {
    const box = el("linksGroups");
    if (!box) return;
    let html = '<div class="links-logos">' + links.logos.map(function (l) {
      return '<a href="' + esc(abs(l.url)) + '" target="_blank" rel="noopener" title="' + esc(l.title) + '">' +
        '<img src="' + esc(abs(l.img)) + '" alt="' + esc(l.title) + '" loading="lazy"></a>';
    }).join("") + '</div>';
    Object.keys(links.groups).forEach(function (k) {
      html += '<div class="links-group"><h3>' + esc(k) + '</h3><ul>' +
        links.groups[k].map(function (pair) {
          return '<li><a href="' + esc(abs(pair[1])) + '" target="_blank" rel="noopener">' + esc(pair[0]) + '</a></li>';
        }).join("") + '</ul></div>';
    });
    box.innerHTML = html;
  }

  function renderFooter(meta) {
    const box = el("footerBody");
    if (!box) return;
    box.innerHTML =
      '<p class="footer-org">' + esc(meta.owner) + '</p>' +
      '<p>版权所有：' + esc(meta.owner) + '</p>' +
      '<p>' + esc(meta.copyright) + '</p>' +
      '<div class="footer-icp">' +
      '<a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener">' + esc(meta.icp) + '</a>' +
      '<span>' + esc(meta.police) + '</span>' +
      '</div>' +
      '<p>建议使用 1024×768 或更高分辨率浏览</p>';
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
      });
    });
    const cb = el("contrastBtn");
    if (cb) cb.addEventListener("click", function () {
      document.body.classList.toggle("high-contrast");
      cb.setAttribute("aria-pressed", document.body.classList.contains("high-contrast") ? "true" : "false");
    });
  }

  /* ---------- 主入口 ---------- */
  async function init() {
    renderDate();
    bindInteractions();
    try {
      const res = await fetch(DATA_URL);
      if (!res.ok) throw new Error("HTTP " + res.status);
      const d = await res.json();
      state.data = d;
      renderNav(d.nav);
      renderCarousel(d.slides);
      renderLeaders(d.leaders);

      // 政协动态 / 会议 tab 链接 + 列表
      headLinks(d.zxdt.tabs, "zxdtLinks");
      el("zxdtList").innerHTML = listHtml(d.zxdt.items, true);
      el("sxList").innerHTML = listHtml(d.sxNews, true);
      headLinks(d.zxMeeting.tabs, "meetingLinks");
      el("meetingList").innerHTML = listHtml(d.zxMeeting.items, false);
      renderImageGrid("imageGrid", d.imageNews, 8);

      // 侧栏（标题已在 HTML 固定，这里填充列表与“更多”链接）
      fillBox("notice", d.notice);
      fillBox("book", d.bookCity);
      fillBox("anti", d.antiGang);
      fillBox("video", d.videos);
      setMore("noticeMore", "https://www.gxhczx.gov.cn/news_list.php?id=302");
      setMore("bookMore", "https://www.gxhczx.gov.cn/news_list.php?id=1301");
      setMore("antiMore", "https://www.gxhczx.gov.cn/news_list.php?id=400");
      setMore("videoMore", "https://www.gxhczx.gov.cn/news_list.php?id=316");
      setMore("rankingMore", "https://www.gxhczx.gov.cn/top.php");
      setMore("countyMore", "https://www.gxhczx.gov.cn/qy_list.php");
      renderRanking(d.ranking);

      // 三列
      el("zwhList").innerHTML = listHtml(d.zwhWork, false);
      el("partyList").innerHTML = listHtml(d.partyGroups, false);
      el("theoryList").innerHTML = listHtml(d.theory, false);

      renderMember(d.memberWindow);
      renderCounty(d.countyZx);
      renderTopic(d.topic);
      renderImageGrid("sceneryGrid", d.scenery, 8);
      renderLinks(d.links);
      renderFooter(d.meta);
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

  function setMore(id, url) {
    const a = el(id);
    if (a) a.href = url;
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
