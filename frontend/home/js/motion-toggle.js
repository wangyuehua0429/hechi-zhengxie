/* 顶部「暂停动效」总开关（首页与内页共用）
 *
 * 为什么需要：站内有若干自动播放的内容——公告滚条（CSS 动画）、副主席照片条 /
 * 图片新闻 / 委员之窗缩略图 / 河池风光（js/main.js 逐帧位移）、首屏轮播与站头底图
 * （定时器）。原先只在鼠标移入时暂停，键盘与触屏用户没有开关，不满足
 * WCAG 2.2.2「自动更新的内容要提供暂停机制」。
 *
 * 做法：在 <html> 上切 motion-paused 类，CSS 与逐帧循环各自读这个类；
 * 选择写进 localStorage，翻内页时不丢；本脚本放在 <head>，首屏渲染前就把类打上，
 * 避免「先动一下再停」。不使用本地存储（隐私模式等）时只对当次页面生效。
 *
 * 页面侧：放一个 <button type="button" class="motion-btn" id="motionBtn" aria-pressed="false">。
 * 脚本侧：读 window.SITE_MOTION.isPaused()，或监听 document 上的 site:motionchange 事件。
 */
(function () {
  "use strict";
  var KEY = "zx-motion-paused";
  var root = document.documentElement;
  var paused = false;

  try { paused = localStorage.getItem(KEY) === "1"; } catch (e) { paused = false; }
  root.classList.toggle("motion-paused", paused);

  function syncButton() {
    var btn = document.getElementById("motionBtn");
    if (!btn) return;
    btn.setAttribute("aria-pressed", paused ? "true" : "false");
    btn.title = paused ? "已暂停页面上的自动动效，点击恢复" : "暂停页面上的自动滚动、轮播与换图";
  }

  function apply(on, persist) {
    paused = !!on;
    root.classList.toggle("motion-paused", paused);
    syncButton();
    if (persist) {
      try {
        if (paused) localStorage.setItem(KEY, "1");
        else localStorage.removeItem(KEY);
      } catch (e) { /* 写不了就只对当前页生效，不影响开关本身 */ }
    }
    document.dispatchEvent(new CustomEvent("site:motionchange", { detail: { paused: paused } }));
  }

  document.addEventListener("DOMContentLoaded", function () {
    syncButton();
    var btn = document.getElementById("motionBtn");
    if (btn) btn.addEventListener("click", function () { apply(!paused, true); });
  });

  window.SITE_MOTION = {
    isPaused: function () { return paused; },
    apply: apply
  };
})();
