/* 信息详情页：按 ?id=<旧库文章ID> 渲染标题、元信息、正文、图集与附件
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
    // 领导简介照片源图尺寸不一（130×162 与 960×1200 混用），统一显示宽度
    body.classList.toggle("is-leader",
      !!(state.channel && state.channel.layout === "leaders"));
    applyBodySize();
    renderGalleryStrip(a);
    renderAttachments(a);

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
  function renderGalleryStrip(a) {
    const box = el("articleGallery");
    if (!box) return;
    const imgs = a.images || [];
    if (imgs.length < 2) {
      box.hidden = true;
      return;
    }
    box.innerHTML = imgs.map(function (src, i) {
      return '<a href="' + esc(src) + '" data-index="' + i + '" aria-label="查看第 ' + (i + 1) + ' 张图">' +
        '<img src="' + esc(src) + '" alt="" loading="lazy"></a>';
    }).join("");
    box.hidden = false;
    bindLightbox(box, imgs);
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
    // 侧栏「最新新闻 + 图片新闻」与栏目页统一，逻辑见 js/shell.js
    if (window.SITE && window.SITE.renderSidePanels) {
      window.SITE.renderSidePanels(state.channels);
    }
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
      content: '<div class="empty-state">本篇正文未随原型内置，正式迁移后由内容接口提供。<br>' +
        '<a href="channel.html?id=' + encodeURIComponent(hit.channel.type) + '">返回' +
        esc(hit.channel.inner || hit.channel.name) + '</a></div>'
    };
    state.channel = hit.channel;
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
