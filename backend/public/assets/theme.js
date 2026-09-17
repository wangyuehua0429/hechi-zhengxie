/**
 * 后台主题引导：在任何样式生效之前，把用户选的深浅色写到 <html data-theme>。
 *
 * 必须放在 <head> 里同步执行（不加 defer）：否则浏览器先按系统主题画一帧、
 * 再跳到用户选的主题，会闪一下。没选过就什么都不写，交给 CSS 的
 * prefers-color-scheme 跟随系统（这样首帧就是对的，也不会闪）。
 *
 * 切换按钮的逻辑在 admin.js 里；这里只负责「第一帧别闪」。
 */
(function () {
  "use strict";
  try {
    const saved = localStorage.getItem("hechi-admin-theme");
    if (saved === "dark" || saved === "light") {
      document.documentElement.setAttribute("data-theme", saved);
    }
  } catch (e) {
    /* 无痕模式读不了存储：跟随系统就好 */
  }
})();
