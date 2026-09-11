/* 站头动态收缩
 *
 * 站头横幅（.masthead-layers）只有首页显示全幅；点栏目进内页后收窄为
 * css/style.css 里 --mast-ratio-folded 的比例，导航条以下的主体整体上提。
 *
 * - 首页：点主导航任一栏目时，本站链接先播放收窄动画再跳转；旧站外链仍开新标签，
 *   不做拦截（点了也是新窗口，本页不做变化）。
 * - 首页由内页返回时：<head> 内联脚本按来源页给 <html> 加上 mast-folded（首屏即
 *   收窄，不闪全幅），本模块把它展开回全幅，即“只有点首页才显示当前界面”。
 *   展开不用高度动画（高度动画每帧都要重排重绘整个页面，首次回首页时会卡），
 *   改成：站头一次摆成全幅，主体与标志先用 transform 补偿回收缩态位置，再一起过渡归位。
 * - 栏目页 / 详情页：markup 上直接带 mast-folded，静态收窄，不加载本模块。
 */
(function () {
  "use strict";

  const html = document.documentElement;
  const layers = document.querySelector(".masthead-layers");
  if (!layers) return;

  const FOLDED = "mast-folded";
  const FOLDING = "is-folding";
  const EXPANDING = "mast-expanding";
  const SHIFT = "mast-shift";
  const DURATION = 420;

  const reduceMotion = window.matchMedia
    ? window.matchMedia("(prefers-reduced-motion: reduce)")
    : { matches: false };

  /* 站头横幅之下的所有区块：站头内部的主导航（.site-nav）+ 主体（.run-bar / main / footer）。
     展开时它们要整体位移，少算了导航，横幅就会盖住导航、收窄态接不上。 */
  const below = (function () {
    const header = layers.closest ? layers.closest("header") : null;
    if (!header) return [];
    const list = [];
    const masthead = layers.closest(".masthead") || layers.parentElement;
    for (let el = masthead ? masthead.nextElementSibling : null; el; el = el.nextElementSibling) {
      if (el.tagName !== "SCRIPT") list.push(el);
    }
    for (let el = header.nextElementSibling; el; el = el.nextElementSibling) {
      if (el.tagName !== "SCRIPT") list.push(el);
    }
    return list;
  })();

  /* 比例取自 css/style.css 的 --mast-ratio / --mast-ratio-folded（“1920 / 183” 形式），
     窄屏断点会改这两个值，所以每次现取，不在脚本里写死 */
  function readRatio(name, fallback) {
    const raw = window.getComputedStyle(html).getPropertyValue(name);
    const m = /([\d.]+)\s*\/\s*([\d.]+)/.exec(raw);
    const w = m ? parseFloat(m[1]) : 0;
    const h = m ? parseFloat(m[2]) : 0;
    return (w > 0 && h > 0) ? h / w : fallback;
  }
  function ratioFull() { return readRatio("--mast-ratio", 550 / 1920); }
  function ratioFolded() { return readRatio("--mast-ratio-folded", 183 / 1920); }

  /* 站头横幅为满幅元素，高度 = 宽度 × 比例；宽度取元素实际渲染宽度 */
  function pxOf(ratio) {
    const w = layers.getBoundingClientRect().width || html.clientWidth || 0;
    return Math.round(w * ratio) + "px";
  }

  /* 先把高度固定为当前渲染值：切换收缩态会改 aspect-ratio，不固定的话高度会瞬间跳到位 */
  function pinHeight() {
    layers.style.height = layers.getBoundingClientRect().height + "px";
  }

  let inlineState = null;   // 内联高度生效时记录状态（"folded" / "full"），窗口变化时重算
  let running = false;
  let expandTimer = 0;

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
    inlineState = "folded";
    pinHeight();
    layers.classList.add(FOLDING);   // 先挂过渡：标志缩小与高度收窄同时进行
    html.classList.add(FOLDED);   // 收缩态同时收起标语，与内页落点一致
    animate(pxOf(ratioFolded()), function () {
      window.location.href = link.href;
    });
  });

  /* 展开动画收尾：清掉补偿用的内联样式与状态类 */
  function clearExpand() {
    window.clearTimeout(expandTimer);
    expandTimer = 0;
    below.forEach(function (el) {
      el.classList.remove(SHIFT);
      el.style.transform = "";
      el.style.removeProperty("background-color");
    });
    const logo = layers.querySelector(".mast-logo");
    if (logo) {
      logo.style.width = "";
      logo.style.transform = "";
    }
    layers.querySelectorAll(".mast-bg").forEach(function (img) {
      img.style.transform = "";
    });
    html.classList.remove(EXPANDING);
  }

  /* 首页由内页返回：标志放大与主体下移同时进行，且全程只做 transform（合成层位移），
     不再逐帧重排重绘整页 —— 这是首次回首页时卡顿的根因 */
  function startExpand() {
    const logo = layers.querySelector(".mast-logo");
    const foldedH = layers.getBoundingClientRect().height;
    /* 全幅高度按比例算，不在这时读布局：读布局会先把“未补偿”的全幅状态定下来，
       后面写的补偿值就会被过渡当成动画起点（标志会原地不动、尺寸也不缩回去） */
    const fullH = Math.round(layers.getBoundingClientRect().width * ratioFull());
    const delta = Math.round(fullH - foldedH);
    const foldedLogo = logo ? logo.getBoundingClientRect() : null;

    if (delta <= 0 || !below.length) {
      html.classList.remove(FOLDED);
      clearExpand();
      return;
    }

    /* 1) 先把补偿值一次写齐（此时还没挂过渡，不会播动画）：
          主体整体上移 delta、标志回到收缩态的位置与大小 */
    const pageBg = window.getComputedStyle(document.body).backgroundColor;
    below.forEach(function (el) {
      el.classList.add(SHIFT);
      el.style.transform = "translateY(-" + delta + "px)";
      /* 主体上移后要挡住横幅下半段：垫上页面底色（与 body 同色，看不出接缝） */
      if (el.tagName !== "FOOTER" && pageBg) el.style.backgroundColor = pageBg;
    });
    if (logo && foldedLogo) {
      const foldedTop = foldedLogo.top - layers.getBoundingClientRect().top;   // 收缩态下标志距站头顶边
      logo.style.width = Math.round(foldedLogo.width) + "px";
      logo.style.transform = "translateY(" + Math.round(foldedTop * (1 - fullH / foldedH)) + "px)";
    }
    /* 横幅照片在收窄态是居中裁切的：全幅布局下要先上移 (fullH - foldedH)/2，
       可见的那一段才和收窄态一模一样，之后再随动画滑回原位 */
    const bgShift = Math.round((fullH - foldedH) / 2);
    layers.querySelectorAll(".mast-bg").forEach(function (img) {
      img.style.transform = "translateY(-" + bgShift + "px)";
    });
    /* 布局一次切到全幅，并强制一次重算，让“全幅布局 + 补偿值”同时生效（这一帧视觉仍是收缩态） */
    html.classList.remove(FOLDED);
    void layers.offsetHeight;

    /* 2) 挂上过渡，下一帧把补偿归零：标志放大与主体下移同一帧起步 */
    html.classList.add(EXPANDING);
    window.requestAnimationFrame(function () {
      below.forEach(function (el) { el.style.transform = "translateY(0px)"; });
      if (logo) {
        logo.style.width = "";
        logo.style.transform = "translateY(0px)";
      }
      layers.querySelectorAll(".mast-bg").forEach(function (img) {
        img.style.transform = "translateY(0px)";
      });
    });
    expandTimer = window.setTimeout(clearExpand, DURATION + 180);
  }

  if (html.classList.contains(FOLDED)) {
    if (reduceMotion.matches) {
      html.classList.remove(FOLDED);   // 减弱动态：直接给全幅，不做展开动画
    } else {
      /* 不等首屏数据：主导航的高度已在 CSS 里占位（.nav-wrap 的 min-height），
         它异步渲染出来也不会把主体撑下去，所以展开可以直接开始。 */
      window.requestAnimationFrame(startExpand);
    }
  }

  /* 动画或收缩态下窗口尺寸变化时，按当前比例重算高度 */
  window.addEventListener("resize", function () {
    if (expandTimer) clearExpand();   // 展开途中改窗口：直接落位，避免补偿值失效
    if (inlineState === null) return;
    layers.classList.remove(FOLDING);
    layers.style.height = pxOf(inlineState === "folded" ? ratioFolded() : ratioFull());
  });
})();
