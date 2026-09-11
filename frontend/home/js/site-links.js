/* 站内链接映射：把旧站栏目页与稿件页地址改写为新版内页地址
 *
 * 映射表来自 data/channel.json：只有内页原型已实现的栏目、以及这些栏目列表里
 * 出现过的稿件才改写；其余地址原样返回，仍指向旧站，避免点开落到空页。
 * 首页（js/main.js）与内页外壳（js/shell.js）共用本模块，规则不在两处各写一份。
 */
(function () {
  "use strict";

  // 先取精简索引（4KB：栏目 type + 稿件 id），拿不到再退回完整的 channel.json（190KB）。
  // 首页导航等链接映射，索引足够，且不会把首屏拖慢。
  var INDEX_DATA = "data/channel-index.json";
  var CHANNEL_DATA = "data/channel.json";
  var site = "https://www.gxhczx.gov.cn";

  // 全站规则：手动刷新（F5 / ⌘R）后回到页面顶部。
  // 浏览器默认会在 reload 时恢复上次的滚动位置；这里按需求关闭该恢复。
  // 前进/后退（back_forward）保持默认恢复；栏目页之间"点击栏目"的位置保持
  // 由 channel.js 单独处理，与本逻辑互不影响。本文件三个页面都会加载。
  (function pinTopOnReload() {
    try {
      var entries = performance.getEntriesByType && performance.getEntriesByType("navigation");
      var type = entries && entries[0] && entries[0].type;
      if (type !== "reload") return;
      if ("scrollRestoration" in history) history.scrollRestoration = "manual";
      var root = document.documentElement;
      var prev = root.style.scrollBehavior;
      root.style.scrollBehavior = "auto";
      window.scrollTo(0, 0);
      root.style.scrollBehavior = prev;
    } catch (e) { /* 拿不到导航类型时保持浏览器默认行为 */ }
  })();

  // 旧库栏目号 / 稿件号 -> 本地内页地址
  var channelMap = {};
  var articleMap = {};

  function abs(u) {
    if (!u || u === "#") return u;
    if (/^(https?:)?\/\//.test(u)) return u;
    if (u.startsWith("//")) return "https:" + u;
    if (u.startsWith("/")) return site + u;
    if (/^(\.\/|\.\.\/|images\/|data:|channel\.html|detail\.html)/.test(u)) return u;
    return site + "/" + u;
  }

  function load() {
    return fetch(INDEX_DATA)
      .then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .catch(function () { return fetch(CHANNEL_DATA).then(function (res) {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      }); })
      .then(function (data) {
        var channels = (data && data.channels) || [];
        channels.forEach(function (channel) {
          channelMap[String(channel.type)] = "channel.html?id=" + encodeURIComponent(channel.type);
          // 精简索引是 ids: ["62180", ...]，完整 channel.json 是 list: [{id, ...}, ...]
          var ids = channel.ids || (channel.list || []).map(function (item) { return item.id; });
          ids.forEach(function (id) {
            articleMap[String(id)] = "detail.html?id=" + encodeURIComponent(id);
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
  function article(u) {
    if (!u) return u;
    var match = VIEW_ID.exec(u) || STATIC_VIEW.exec(u);
    if (match && articleMap[match[1]]) return articleMap[match[1]];
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
