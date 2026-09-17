/* 站内链接映射：把旧站栏目页与稿件页地址改写为本站对外地址
 *
 * 2026-09-17（A1）起口径是：**对外只有一套地址**——栏目页 /channel/<目录名>/、
 * 详情页 /article/<id>.html（发布器产出，Nginx 直出）。
 *   · 栏目地址：取精简索引里的 path（发布器同一套 StaticPaths 规则）；
 *   · 稿件地址：只要形状是旧站稿件地址（news_view.php?id= / cq_view.php?id= /
 *     html/news-view-<id>.html）就一律改指 /article/<id>.html，不再依赖“索引里有没有
 *     这个 id”——旧站关停后没改写的老链接同样打不开，留着只会把用户送去死域名。
 * 首页（js/main.js）与内页外壳（js/shell.js）共用本模块，规则不在两处各写一份。
 */
(function () {
  "use strict";

  // 链接映射只要「栏目 type + 稿件 id」，取精简索引即可，不拉完整栏目数据。
  // 取数统一走 js/data-source.js（接口优先，静态快照兜底）。
  var site = "https://www.gxhczx.gov.cn";

  // 全站规则：手动刷新（F5 / ⌘R）后回到页面顶部。
  // 为什么不是"设一次 scrollRestoration 就完事"：是否恢复滚动位置由"上一份文档"
  // 决定（本页加载时已经晚了），而本页开始加载后，浏览器又会在首次排版、以及内容
  // 陆续撑高的过程中多次把旧位置恢复回来。实测只关恢复或只在 load 前回顶，滚动位置
  // 较浅时（如 1200px）刷新后仍停在原位。故改为刷新后在短时间内持续压回顶部，
  // 一旦用户自己滚动或按键就放手；前进/后退不是 reload，本逻辑完全不介入。
  (function pinTopOnReload() {
    try {
      var entries = performance.getEntriesByType && performance.getEntriesByType("navigation");
      var type = entries && entries[0] && entries[0].type;
      if (type !== "reload") return;
      var stop = false;
      ["wheel", "touchstart", "keydown", "mousedown"].forEach(function (name) {
        addEventListener(name, function () { stop = true; }, { passive: true, once: true });
      });
      var pin = function () { if (!stop) window.scrollTo(0, 0); };
      pin();
      document.addEventListener("DOMContentLoaded", pin);
      addEventListener("load", pin);
      var t0 = Date.now();
      (function tick() {
        pin();
        if (!stop && Date.now() - t0 < 1200) requestAnimationFrame(tick);
      })();
    } catch (e) { /* 拿不到导航类型时保持浏览器默认行为 */ }
  })();

  // 旧库栏目号 / 稿件号 -> 本地内页地址
  var channelMap = {};
  var articleMap = {};

  // 本站路由：由 backend/public/router.php 与 nginx 的 location /member、/admin 提供，
  // 另外 /article/、/channel/ 是发布器产出的静态页地址。这些以 / 开头的地址必须原样保留，
  // 不能补旧站域名——否则首页「提案填报系统」条幅会指到旧站的 /member（不存在），
  // 点开就是 404（2026-09-15 修）。
  // 以 / 开头的本站地址一律原样保留（含根路径 "/"）：否则 abs() 会给它补上站点域名，
  // 本地预览时点「首页」会被送到正式域名去（2026-09-17 Step 3 修）。
  var LOCAL_ROUTE = /^\/(?:$|member|admin|search\.html|article\/|channel\/)/;

  function abs(u) {
    if (!u || u === "#") return u;
    if (/^(https?:)?\/\//.test(u)) return u;
    if (u.startsWith("//")) return "https:" + u;
    if (/^\/?(?:channel|detail)\.html(?:[?#]|$)/.test(u)) return u;   // 站内内页：本站地址，不能补旧站域名
    if (LOCAL_ROUTE.test(u)) return u;                                // 本站路由：同上
    // 以 / 开头的是本站站点根路径，原样保留（补域名会把本站图片指到旧站）
    if (u.startsWith("/")) return u;
    if (/^(\.\/|\.\.\/|images\/|data:|channel\.html|detail\.html)/.test(u)) return u;
    return site + "/" + u;
  }

  function load() {
    return window.SITE_DATA.channelIndex()
      .then(function (channels) {
        channels = channels || [];
        channels.forEach(function (channel) {
          channelMap[String(channel.type)] = String(channel.path || "") ||
            ("channel.html?id=" + encodeURIComponent(channel.type));
          // 精简索引是 ids: ["62180", ...]，完整 channel.json 是 list: [{id, ...}, ...]
          var ids = channel.ids || (channel.list || []).map(function (item) { return item.id; });
          ids.forEach(function (id) {
            articleMap[String(id)] = staticArticleUrl(id);
          });
        });
        return channels;
      })
      .catch(function () { return []; });
  }

  // 栏目地址：news_list.php?id= / news_list_about.php?id= / cq_list.php?id= / qy_list.php
  var LIST_ID = /(?:news_list|news_list_about|cq_list)\.php\?[^"']*?\bid=([A-Za-z0-9_]+)/;
  function channel(u) {
    if (!u) return u;
    if (/qy_list\.php/.test(u) && channelMap.qy) return channelMap.qy;
    var match = LIST_ID.exec(u);
    if (match && channelMap[match[1]]) return channelMap[match[1]];
    return u;
  }

  // 稿件地址：news_view.php?id= / cq_view.php?id= / html/news-view-<id>.html
  var VIEW_ID = /(?:news_view|cq_view)\.php\?[^"']*?\bid=(\d+)/;
  var STATIC_VIEW = /news-view-(\d+)\.html/;
  var OWN_HOST = /^(?:www\.)?gxhczx\.gov\.cn$/i;
  function staticArticleUrl(id) {
    return "/article/" + encodeURIComponent(id) + ".html";
  }
  // 只改本站在（含相对地址）：第三方站点也有 news_view.php?id= 这类脚本，
  // 不加这道判断会把别站的链接静默改成我们自己的详情页（与后端 articleIdInUrl 同口径）。
  function isOwnUrl(u) {
    var m = /^(?:https?:)?\/\/([^\/?#]+)/i.exec(u);
    if (!m) return true;
    return OWN_HOST.test(m[1].replace(/:\d+$/, ""));
  }
  function article(u) {
    if (!u) return u;
    if (!isOwnUrl(u)) return u;
    var match = VIEW_ID.exec(u) || STATIC_VIEW.exec(u);
    if (match) return staticArticleUrl(match[1]);
    return u;
  }

  function map(u) {
    return article(channel(abs(u)));
  }

  window.SITE_LINKS = {
    ready: load(),
    abs: abs,
    channel: channel,
    article: article,
    map: map,
    channels: channelMap,
    articles: articleMap
  };
})();
