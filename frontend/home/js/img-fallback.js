/* 破图兜底：历史稿件与栏目缩略图里有指向旧站上传目录（/uploadfiles/）的地址，
 * 文件还没从旧站服务器补齐时必然是 404，浏览器会画一个破图图标。
 *
 * 为什么不用给 <img> 写 onerror：
 *   ① 正文 HTML 过服务端白名单（HtmlSanitizer），内联事件属性会被剥掉；
 *   ② /article/ 静态页的 CSP 是 script-src 'self'，内联处理器本来也不执行。
 * 所以用同源脚本，在捕获阶段收 document 上的 error 事件（error 不冒泡，但会捕获），
 * 每个元素只兜一次，换成内联的同源占位图（data URI，不再发额外请求）。
 */
(function () {
  "use strict";

  var PLACEHOLDER = "data:image/svg+xml;charset=utf-8," + encodeURIComponent(
    '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="200" viewBox="0 0 320 200" role="img" aria-label="图片暂缺">'
    + '<rect width="320" height="200" fill="#f2f3f5"/>'
    + '<g fill="none" stroke="#c2c7cf" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">'
    + '<rect x="98" y="58" width="124" height="88" rx="8"/>'
    + '<path d="M104 132l34-36 26 28 18-18 26 30"/>'
    + '<circle cx="196" cy="82" r="9"/>'
    + '</g>'
    + '<text x="160" y="180" text-anchor="middle" font-size="16" fill="#8b929c" font-family="sans-serif">图片暂缺</text>'
    + '</svg>'
  );

  function applyFallback(img) {
    if (!img.dataset) {
      return;
    }
    // 按「加载失败的地址」记，而不是记一个布尔位：图集灯箱（js/detail.js 的 drawLightbox）
    // 复用同一个 <img> 元素翻页，用布尔位会让第二张破图拿不到占位图。
    var failed = img.getAttribute("src") || "";
    if (img.dataset.imgFallbackSrc === failed) {
      return;
    }
    img.dataset.imgFallbackSrc = failed;
    if (!img.getAttribute("alt")) {
      img.alt = "图片暂缺";
    }
    img.removeAttribute("srcset");
    img.src = PLACEHOLDER;
  }

  document.addEventListener("error", function (event) {
    var el = event.target;
    if (!el || el.tagName !== "IMG") {
      return;
    }
    // 占位图自己再失败就不递归了（data URI 基本不会失败，这层只是兜底）
    if ((el.getAttribute("src") || "").indexOf("data:image/svg+xml") === 0) {
      return;
    }
    applyFallback(el);
  }, true);
})();
