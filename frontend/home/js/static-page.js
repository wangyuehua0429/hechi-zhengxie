/* 静态页自带的交互：与 frontend/home/js/shell.js 的 bindInteractions 同款行为，
 * 只覆盖不依赖接口的部分（菜单、字号、高对比度、打印、复制链接、正文字号）。
 *
 * 为什么在文件里而不是写内联 <script>：`/article/` 与 `/channel/` 的 CSP 是
 * `script-src 'self'`（没有 unsafe-inline，也没有逐页算哈希）。内联脚本会被浏览器整段拒绝执行，
 * 表现是线上的详情页「字号＋／－、打印、复制链接」全部没反应——本地用 router.php 起服务不带 CSP，
 * 这个坑在本地测不出来（2026-09-17 独立评审发现）。所以脚本一律走同源外链文件。
 */
(function () {
  "use strict";

  var toggle = document.getElementById("navToggle"), nav = document.getElementById("siteNav");
  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("open");
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
  var cb = document.getElementById("contrastBtn");
  if (cb) {
    cb.addEventListener("click", function () {
      document.body.classList.toggle("high-contrast");
      cb.setAttribute("aria-pressed", document.body.classList.contains("high-contrast") ? "true" : "false");
    });
  }
  var topbar = document.querySelector(".topbar"), lastY = window.scrollY;
  window.addEventListener("scroll", function () {
    if (!topbar) return;
    var y = window.scrollY;
    if (y > lastY && y > 80) topbar.classList.add("is-hidden");
    else if (y < lastY) topbar.classList.remove("is-hidden");
    lastY = y;
  }, { passive: true });
  var printBtn = document.getElementById("printBtn");
  if (printBtn) printBtn.addEventListener("click", function () { window.print(); });
  var copyBtn = document.getElementById("copyLinkBtn");
  if (copyBtn) {
    copyBtn.addEventListener("click", function () {
      var done = function () {
        copyBtn.textContent = "已复制";
        setTimeout(function () { copyBtn.textContent = "复制链接"; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(location.href).then(done, done);
      else done();
    });
  }
  var body = document.querySelector(".article-body"), scale = 1;
  document.querySelectorAll("[data-size]").forEach(function (b) {
    b.addEventListener("click", function () {
      var v = b.dataset.size;
      scale = v === "up" ? Math.min(1.4, scale + 0.1) : (v === "down" ? Math.max(0.8, scale - 0.1) : 1);
      if (body) body.style.setProperty("--body-scale", String(scale));
    });
  });
})();
