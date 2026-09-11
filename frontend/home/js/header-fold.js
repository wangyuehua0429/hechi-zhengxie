/* 站头动态收缩
 *
 * 站头横幅（.masthead-layers）只有首页显示全幅；点栏目进内页后收窄为
 * css/style.css 里 --mast-ratio-folded 的比例，导航条以下的主体整体上提。
 *
 * - 首页：点主导航任一栏目时，本站链接先播放收窄动画再跳转；旧站外链仍开新标签，
 *   不做拦截（点了也是新窗口，本页不做变化）。
 * - 首页由内页返回时：<head> 内联脚本按来源页给 <html> 加上 mast-folded（首屏即
 *   收窄，不闪全幅），本模块把它展开回全幅，即“只有点首页才显示当前界面”。
 * - 栏目页 / 详情页：markup 上直接带 mast-folded，静态收窄，不加载本模块。
 */
(function () {
  "use strict";

  const html = document.documentElement;
  const layers = document.querySelector(".masthead-layers");
  if (!layers) return;

  const FOLDED = "mast-folded";
  const FOLDING = "is-folding";
  /* 与 css/style.css 的 --mast-ratio / --mast-ratio-folded 保持一致 */
  const RATIO_FULL = 550 / 1920;
  const RATIO_FOLDED = 387 / 1920;
  const DURATION = 420;

  const reduceMotion = window.matchMedia
    ? window.matchMedia("(prefers-reduced-motion: reduce)")
    : { matches: false };

  /* 站头横幅为满幅元素，高度 = 宽度 × 比例；宽度取元素实际渲染宽度 */
  function pxOf(ratio) {
    const w = layers.getBoundingClientRect().width || html.clientWidth || 0;
    return Math.round(w * ratio) + "px";
  }

  let inlineRatio = null;   // 内联高度生效时记录对应比例，窗口变化时重算
  let running = false;

  /* 从当前渲染高度过渡到目标高度；结束（含兜底计时）后回调 */
  function animate(targetPx, done) {
    if (running) return;
    running = true;
    layers.classList.add(FOLDING);
    layers.style.height = layers.getBoundingClientRect().height + "px";
    void layers.offsetHeight;   // 先固定起点，保证过渡从当前高度开始
    layers.style.height = targetPx;

    let timer = 0;
    const finish = function () {
      if (!running) return;
      running = false;
      layers.removeEventListener("transitionend", onEnd);
      window.clearTimeout(timer);
      layers.classList.remove(FOLDING);
      if (done) done();
    };
    const onEnd = function (e) {
      if (e.target === layers && e.propertyName === "height") finish();
    };
    layers.addEventListener("transitionend", onEnd);
    timer = window.setTimeout(finish, DURATION + 180);
  }

  /* 首页：点主导航里的本站栏目链接 -> 先收窄站头，再跳转 */
  document.addEventListener("click", function (e) {
    if (e.defaultPrevented || e.button !== 0) return;
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    if (html.classList.contains(FOLDED)) return;      // 内页已是收窄态，直接跳转
    if (reduceMotion.matches) return;

    const link = e.target && e.target.closest ? e.target.closest("#navRows a") : null;
    if (!link || running) return;
    if (link.target && link.target !== "_self") return;

    const href = link.getAttribute("href") || "";
    if (!href || href.charAt(0) === "#") return;
    if (/^https?:\/\//i.test(href) || /^\/\//.test(href)) return;   // 旧站外链：不拦

    e.preventDefault();
    inlineRatio = RATIO_FOLDED;
    /* 先按当前渲染高度固定起点，再切收缩态（否则 aspect-ratio 一变，高度会瞬间跳到位） */
    layers.style.height = layers.getBoundingClientRect().height + "px";
    html.classList.add(FOLDED);   // 收缩态同时收起标语，与内页落点一致
    animate(pxOf(RATIO_FOLDED), function () {
      window.location.href = link.href;
    });
  });

  /* 首页由内页返回：把收窄态展开回全幅 */
  if (html.classList.contains(FOLDED)) {
    if (reduceMotion.matches) {
      html.classList.remove(FOLDED);   // 减弱动态：直接给全幅，不做展开动画
    } else {
      inlineRatio = RATIO_FOLDED;
      layers.style.height = pxOf(RATIO_FOLDED);
      window.requestAnimationFrame(function () {
        inlineRatio = RATIO_FULL;
        animate(pxOf(RATIO_FULL), function () {
          html.classList.remove(FOLDED);
          inlineRatio = null;
          layers.style.height = "";   // 交回 CSS 的 aspect-ratio
        });
      });
    }
  }

  /* 动画或收缩态下窗口尺寸变化时，按当前比例重算高度 */
  window.addEventListener("resize", function () {
    if (inlineRatio === null) return;
    layers.classList.remove(FOLDING);
    layers.style.height = pxOf(inlineRatio);
  });
})();
