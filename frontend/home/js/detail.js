/* 信息详情页：按 ?id=<旧库文章ID> 渲染标题、元信息、正文、图集与附件
 *
 * 数据来自 data/article.json 与 data/channel.json（原型样例，取旧库 rd_news），
 * 后端就绪后由 REST API 平替，正文排版样式不变。
 */
(function () {
  "use strict";

  // 取数统一走 js/data-source.js（接口优先、静态快照兜底）
  const HOME_URL = "./index.html";
  const DEFAULT_ID = "62180";
  const BODY_SIZES = [0.92, 1, 1.12, 1.24];
  const BODY_DEFAULT = 1;
  // 旧库图集记录里混着视频文件（cms_article_image 有 50 条 mp4），当图片渲染会出空白格
  const MEDIA_EXT = ["mp4", "mov", "avi", "wmv", "flv", "mkv", "webm", "m4v", "mpeg", "mpg", "rmvb", "ogv"];

  const el = (id) => document.getElementById(id);
  const esc = (s) => String(s == null ? "" : s)
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");

  function isImagePath(path) {
    const clean = String(path || "").split("?")[0];
    const dot = clean.lastIndexOf(".");
    if (dot < 0) return true;
    return MEDIA_EXT.indexOf(clean.slice(dot + 1).toLowerCase()) < 0;
  }

  // 接口不可用、回退静态快照时的兜底：旧站地址一律换成 https，
  // 免得整站上了 HTTPS 之后这批图被浏览器按混合内容拦掉（接口出口已做同样处理）。
  function safeResourceUrl(url) {
    return String(url || "").replace(/^http:\/\/(www\.)?gxhczx\.gov\.cn\//i, "https://www.gxhczx.gov.cn/");
  }

  function safeResourceHtml(html) {
    return String(html || "").replace(/http:\/\/(www\.)?gxhczx\.gov\.cn\//gi, "https://www.gxhczx.gov.cn/");
  }

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
    return window.SITE_DATA.channels();
  }

  // 栏目列表里有、但原型未内置正文的稿件：用列表信息拼出详情页，正文留待内容接口接入
  function listedItem(id) {
    let hit = null;
    state.channels.forEach(function (channel) {
      if (hit || channel.homeSourced) return;
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
    // 顶部只出一行标题：引题属于正文内容，留在正文开头第一行；这里只放接口给的副题（一般为空）
    const subtitle = String(a.subtitle || "").trim();
    if (subtitle) {
      sub.innerHTML = '<span class="article-subtitle">' + esc(subtitle) + '</span>';
      sub.hidden = false;
    }
    el("articleTitle").textContent = a.title;

    // 元信息口径（2026-09-14）：发布时间只到年月日（时分不进详情页）；不显示点击／阅读数据；
    // 空值项不渲染；「编辑」只在文末落款出现一次，meta 行不再重复。
    const pubDate = String(a.dateText || a.date || "").slice(0, 10);
    const meta = [
      pubDate ? '<span>发布时间：<b>' + esc(pubDate) + '</b></span>' : "",
      a.source ? '<span>来源：<b>' + esc(a.source) + '</b></span>' : "",
      a.author ? '<span>作者：<b>' + esc(a.author) + '</b></span>' : ""
    ].filter(Boolean).join("");
    el("articleMeta").innerHTML = meta;

    const body = el("articleBody");
    body.innerHTML = safeResourceHtml(a.content);
    // 领导简介照片源图尺寸不一（130×162 与 960×1200 混用），统一显示宽度
    body.classList.toggle("is-leader",
      !!(state.channel && state.channel.layout === "leaders"));
    applyBodySize();
    // 正文图张数要在降级替换之前数：图片全失败时它们会变成占位块，
    // 之后再看就数不到图，图集条会误以为「正文没图」又冒出来
    const bodyImgs = body.querySelectorAll("img").length;
    bindMediaFallback(body);
    renderGalleryStrip(a, bodyImgs);
    renderAttachments(a);

    if (a.editor) {
      const sign = el("articleSign");
      sign.textContent = "责任编辑：" + a.editor;
      sign.hidden = false;
    }
  }

  function applyBodySize() {
    const body = el("articleBody");
    // 用 CSS 变量而不是直接写 font-size：基准 16px、行高与段距都由样式算，
    // 直接写内联 font-size 会把适老化（--font-scale）与参照页版式一起盖掉。
    if (body) body.style.setProperty("--body-scale", String(state.bodySize));
  }

  // 附件下载区：数据里有 attachments 才显示（旧站正文里的 doc/pdf/xls 等原件链接）
  function renderAttachments(a) {
    const box = el("articleAttach");
    if (!box) return;
    const files = a.attachments || [];
    if (!files.length) {
      box.hidden = true;
      return;
    }
    box.innerHTML = '<h3>附件下载</h3><ul>' + files.map(function (file) {
      return '<li>' +
        '<span class="attach-ext">' + esc(file.ext || "file") + '</span>' +
        '<a href="' + esc(file.url) + '" target="_blank" rel="noopener">' + esc(file.name) + '</a>' +
        '</li>';
    }).join("") + '</ul>' +
      '<p class="attach-hint">附件为旧站原件链接，迁移后改由内容接口提供下载地址。</p>';
    box.hidden = false;
  }

  // 图集型详情：正文含 2 张以上图片时给出缩略图条，点击可放大浏览
  function renderGalleryStrip(a, bodyImgCount) {
    const box = el("articleGallery");
    if (!box) return;
    // 正文里已有图就不再重复出图集条（49643 此前是正文 10 张 + 图集 11 格同一批图），
    // 图集只服务纯图集型稿件；非图片文件先过滤掉。
    const bodyImgs = typeof bodyImgCount === "number"
      ? bodyImgCount
      : el("articleBody").querySelectorAll("img").length;
    const imgs = (a.images || []).filter(isImagePath);
    if (bodyImgs > 0 || imgs.length < 2) {
      box.hidden = true;
      return;
    }
    const urls = imgs.map(safeResourceUrl);
    box.innerHTML = urls.map(function (url, i) {
      url = esc(url);
      return '<a href="' + url + '" data-index="' + i + '" aria-label="查看第 ' + (i + 1) + ' 张图">' +
        '<img src="' + url + '" alt="" loading="lazy"></a>';
    }).join("");
    box.hidden = false;
    bindMediaFallback(box);
    bindLightbox(box, urls);
  }

  // 破图／视频加载失败：换成占位块。此前只是 visibility:hidden，原地留一块空白
  function mediaFallback(node, text) {
    if (!node || !node.parentNode) return;
    const box = document.createElement("div");
    box.className = "media-fallback";
    box.textContent = text;
    node.parentNode.replaceChild(box, node);
  }

  function bindMediaFallback(scope) {
    if (!scope) return;
    scope.querySelectorAll("img").forEach(function (img) {
      const fail = function () {
        mediaFallback(img, String(img.getAttribute("alt") || "").trim() || "图片暂时无法显示");
      };
      if (img.complete && img.naturalWidth === 0) fail();
      else img.addEventListener("error", fail, { once: true });
    });
    scope.querySelectorAll("video").forEach(function (video) {
      video.addEventListener("error", function () {
        mediaFallback(video, "视频暂时无法播放");
      }, { once: true });
    });
  }

  let lightboxIndex = 0;
  function drawLightbox() {
    const box = document.getElementById("articleLightbox");
    if (!box) return;
    const imgs = box._images || [];
    const img = box.querySelector("img");
    const counter = box.querySelector(".lightbox-count");
    if (img) img.src = imgs[lightboxIndex];
    if (counter) counter.textContent = (lightboxIndex + 1) + " / " + imgs.length;
  }

  function moveLightbox(step) {
    const box = document.getElementById("articleLightbox");
    if (!box) return;
    const total = (box._images || []).length;
    if (!total) return;
    lightboxIndex = (lightboxIndex + step + total) % total;
    drawLightbox();
  }

  function closeLightbox() {
    const box = document.getElementById("articleLightbox");
    if (!box) return;
    box.remove();
    document.removeEventListener("keydown", onLightboxKey);
  }

  function onLightboxKey(e) {
    if (e.key === "Escape") closeLightbox();
    else if (e.key === "ArrowLeft") moveLightbox(-1);
    else if (e.key === "ArrowRight") moveLightbox(1);
  }

  function showLightbox(imgs, index) {
    closeLightbox();
    lightboxIndex = index;
    const box = document.createElement("div");
    box.className = "article-lightbox";
    box.id = "articleLightbox";
    box._images = imgs;
    box.setAttribute("role", "dialog");
    box.setAttribute("aria-modal", "true");
    box.setAttribute("aria-label", "图片浏览");
    box.innerHTML =
      '<button type="button" class="lightbox-nav prev" aria-label="上一张">‹</button>' +
      '<figure><img src="' + esc(imgs[index]) + '" alt="">' +
      '<figcaption class="lightbox-count">' + (index + 1) + " / " + imgs.length + '</figcaption></figure>' +
      '<button type="button" class="lightbox-nav next" aria-label="下一张">›</button>' +
      '<button type="button" class="lightbox-close" aria-label="关闭">×</button>';
    box.addEventListener("click", function (e) {
      if (e.target.closest(".lightbox-nav.prev")) moveLightbox(-1);
      else if (e.target.closest(".lightbox-nav.next")) moveLightbox(1);
      else if (e.target.closest(".lightbox-close") || e.target === box) closeLightbox();
    });
    document.body.appendChild(box);
    document.addEventListener("keydown", onLightboxKey);
  }

  function bindLightbox(box, imgs) {
    box.addEventListener("click", function (e) {
      const a = e.target.closest("a[data-index]");
      if (!a) return;
      e.preventDefault();
      showLightbox(imgs, Number(a.dataset.index) || 0);
    });
  }

  function renderSide() {
    // 侧栏「最新新闻 + 图片新闻」逻辑见 js/shell.js；详情页传 "detail" 策略：
    // 正文短也固定 10 条 + 2 行 4 张，正文长按高度往上配，最多 15 条 + 3 行 6 张
    if (window.SITE && window.SITE.renderSidePanels) {
      window.SITE.renderSidePanels(state.channels, "detail");
    }
    syncStickySide();
  }

  // 右栏比视口还高时取消粘性：长文页（正文 9000px 级）此前会把「图片新闻」一直压在视口外
  function syncStickySide() {
    const side = el("innerSide");
    if (!side) return;
    side.classList.toggle("is-sticky", side.getBoundingClientRect().height < window.innerHeight - 90);
  }

  let stickyObserver = null;
  function bindStickySide() {
    syncStickySide();
    window.addEventListener("resize", syncStickySide);
    document.addEventListener("site:fontchange", syncStickySide);
    const side = el("innerSide");
    if (side && window.ResizeObserver && !stickyObserver) {
      stickyObserver = new ResizeObserver(syncStickySide);
      stickyObserver.observe(side);
    }
  }

  // 回到顶部：只加在详情页，滚动过一屏后出现
  function initBackTop() {
    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "to-top";
    btn.title = "回到顶部";
    btn.setAttribute("aria-label", "回到顶部");
    btn.textContent = "↑";
    btn.hidden = true;
    btn.addEventListener("click", function () {
      const reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      window.scrollTo({ top: 0, behavior: reduce ? "auto" : "smooth" });
    });
    document.body.appendChild(btn);

    const sync = function () { btn.hidden = window.scrollY < window.innerHeight * 0.8; };
    window.addEventListener("scroll", sync, { passive: true });
    window.addEventListener("resize", sync);
    sync();
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
      attachments: [],
      images: [],
      content: noBodyHtml(hit.channel.type, hit.channel.inner || hit.channel.name)
    };
    state.channel = hit.channel;
    renderCrumb();
    renderArticle();
    renderSide();
    bindToolbar();
  }

  // 列表里有这篇、但库里还没有正文（旧库未收正文，或后台新建后还没写内容）
  function noBodyHtml(backType, backName) {
    return '<div class="empty-state">该篇暂无正文内容。' +
      (backType
        ? '<br><a href="channel.html?id=' + encodeURIComponent(backType) + '">返回' +
          esc(backName || "栏目") + '</a>'
        : '') +
      '</div>';
  }

  // 接口返回的稿件没有正文时，标题与元信息照常显示，正文位置给说明与返回入口
  function renderNoBody(article) {
    state.article = {
      id: article.id,
      channelType: article.channelType,
      channelName: article.channelName,
      title: article.title,
      subtitle: article.subtitle || "",
      date: article.date,
      source: article.source || "",
      author: article.author || "",
      editor: article.editor || "",
      views: article.views,
      attachments: article.attachments || [],
      images: article.images || [],
      content: noBodyHtml(article.channelType,
        state.channel ? (state.channel.inner || state.channel.name) : article.channelName)
    };
    renderCrumb();
    renderArticle();
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
    const id = param("id") || DEFAULT_ID;
    initBackTop();
    bindStickySide();
    Promise.all([
      window.SITE_DATA.article(id),
      loadChannels()
    ]).then(function (res) {
      const article = res[0];
      state.channels = res[1] || [];
      if (!article) {
        const hit = listedItem(id);
        if (hit) renderListedOnly(hit);
        else renderMissing();
        return;
      }
      state.article = article;
      state.channel = channelOf(article.channelType);
      // hasBody=false：稿件在库里（列表可见），但正文还没录，正文位置出说明
      if (article.hasBody === false || !String(article.content || "").trim()) {
        renderNoBody(article);
        return;
      }
      renderCrumb();
      renderArticle();
      renderSide();
      bindToolbar();
    }).catch(function () {
      el("articleBody").innerHTML =
        '<div class="empty-state">内容加载失败，请刷新重试；若持续失败请联系网站管理员。</div>';
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
