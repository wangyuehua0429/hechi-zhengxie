/**
 * 后台渐进增强脚本：不加载也能完成全部操作，加载后少点几下。
 *
 * 这里只做七件事：
 * 1. 稿件列表的批量选择（勾选、全选半选、按动作显示备注框、提交前自检）；
 * 2. 栏目列表的即时筛选（不必回车，服务端筛选照旧可用）；
 * 3. 稿件编辑页的正文预览与字数统计（预览走 sandbox iframe，脚本不执行）；
 * 4. Ctrl/⌘+S 保存与「有改动未保存」离开提醒；
 * 5. 上移／下移这类排序提交后还原滚动位置，长列表里不用每次再滚回去。
 * 6. 头条轮换「首屏效果预览」里拖动缩略图排序（拖完一次性提交整串顺序）；
 *    首页管理里会改前台的提交（下线／删除／隐藏／置顶／改绑定／换图等）先弹一次确认。
 * 7. 长页面滚过一屏后，左下角出现「回到顶部」（模板默认 hidden，没有脚本就不显示）。
 * 8. 站内横幅选了图片文件后，先在页面上方的预览框里显示这张图（本地预览，点「保存」才生效）。
 * 9. 首页徽标的下拉选「自定义…」时才显示文本框，选预设或「不显示徽标」时把文本框禁掉。
 * 10. 委员管理「手工新建账号」的增行／删行（表单默认给三行，没有脚本也能直接提交）。
 *
 * 所有逻辑都用 data-* 钩子，模板改名不影响；没有匹配元素时静默跳过。
 */
(function () {
  "use strict";

  document.documentElement.classList.add("js");

  /* 读屏播报：站内提示都是视觉反馈，键盘／读屏用户需要一条 live region
     才知道「刚刚那一下到底成功了没有」。节点一开始就挂上（有些读屏只在节点已存在时
     才盯着它的变化），之后反复写同一个节点，不堆节点。 */
  const liveRegion = document.createElement("p");
  liveRegion.className = "visually-hidden";
  liveRegion.setAttribute("role", "status");
  document.body.appendChild(liveRegion);
  const announce = (message) => {
    // 同一句连着播两次时，节点内容没变化读屏不会重播；先清空再写回来。
    if (liveRegion.textContent === message) liveRegion.textContent = "";
    window.setTimeout(() => { liveRegion.textContent = message; }, 20);
  };

  /* ------------------------------------------------------ 0. 深浅色开关 */
  // 首帧的主题由 /assets/theme.js 在 <head> 里写好；这里只管点击、记住选择与状态文案。
  // 没选过就跟随系统（CSS 的 prefers-color-scheme），系统变了按钮文案也要跟着变。
  const themeToggle = document.querySelector("[data-theme-toggle]");
  if (themeToggle) {
    const THEME_KEY = "hechi-admin-theme";
    const media = window.matchMedia ? window.matchMedia("(prefers-color-scheme: dark)") : null;
    const savedTheme = () => {
      try {
        const value = localStorage.getItem(THEME_KEY);
        return value === "dark" || value === "light" ? value : null;
      } catch (e) { return null; }
    };
    const effectiveTheme = () => savedTheme() || (media && media.matches ? "dark" : "light");
    const syncThemeToggle = () => {
      const state = effectiveTheme();
      themeToggle.hidden = false;
      themeToggle.dataset.state = state;
      themeToggle.setAttribute("aria-pressed", state === "dark" ? "true" : "false");
      // 无障碍名称只说「这是什么」，开关状态交给 aria-pressed；
      // 「点了会怎样」放 title——两条信息都塞进名称，读屏会连读成一句别扭的话。
      themeToggle.setAttribute("aria-label", "深色模式");
      themeToggle.setAttribute("title", state === "dark" ? "当前已开启，点击切回浅色" : "当前关闭，点击切换为深色");
    };
    themeToggle.addEventListener("click", () => {
      const next = effectiveTheme() === "dark" ? "light" : "dark";
      try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* 存不了就只在本次会话生效 */ }
      document.documentElement.setAttribute("data-theme", next);
      syncThemeToggle();
      announce(next === "dark" ? "已切换到深色模式" : "已切换到浅色模式");
    });
    if (media) {
      const onSystemChange = () => { if (!savedTheme()) syncThemeToggle(); };
      if (media.addEventListener) media.addEventListener("change", onSystemChange);
      else if (media.addListener) media.addListener(onSystemChange);
    }
    syncThemeToggle();
  }

  /* ---------------------------------------------------------- 1. 批量选择 */
  const bulkForm = document.getElementById("bulk-form");
  const bulkBar = document.querySelector("[data-bulk-bar]");
  const rows = Array.prototype.slice.call(document.querySelectorAll("[data-row-select]"));

  /* 跨页选择的篮子键由服务端算好（data-bulk-key，只跟归一化后的筛选有关）：
     站内各条链接生成的查询串顺序、空值与默认值都不一样，前端自己解析 URL 会把篮子算丢。 */
  const BULK_STORE_PREFIX = "hechi-admin-bulk:";
  const bulkStoreKey = bulkForm
    ? BULK_STORE_PREFIX + (bulkForm.getAttribute("data-bulk-key") || location.pathname)
    : null;
  // 只留当前筛选这一篮子。清理放在 rows 判空之前：0 结果的筛选页也要把旧篮子收掉，
  // 否则切回上一个筛选时篮子会「复活」。
  if (bulkStoreKey) {
    try {
      for (let i = sessionStorage.length - 1; i >= 0; i -= 1) {
        const key = sessionStorage.key(i);
        if (key && key.indexOf(BULK_STORE_PREFIX) === 0 && key !== bulkStoreKey) sessionStorage.removeItem(key);
      }
    } catch (e) { /* 无痕模式读不了存储：退化成只认本页 */ }
  }

  if (bulkForm && bulkBar && rows.length > 0) {
    const selectAll = document.querySelector("[data-select-all]");
    const countNode = document.querySelector("[data-bulk-count]");
    const actionSelect = document.querySelector("[data-bulk-action]");
    const noteField = document.querySelector("[data-bulk-note]");
    const noteInput = noteField ? noteField.querySelector("input") : null;
    const clearButton = document.querySelector("[data-bulk-clear]");

    const scopeNode = document.querySelector("[data-bulk-scope]");
    // 单次上限由模板写进 data-bulk-max（源头是 ArticleController::MAX_BULK），
    // 前端不再自己记一个数，免得与服务端各改一半。
    const maxBulk = Number(bulkForm.getAttribute("data-bulk-max")) || 100;
    /* 隐藏域字段名与提示语里的量词都跟着页面走：稿件页用 ids[]／篇／稿件，
       委员页用 member_ids[]／人／委员。默认值就是稿件页原来的写法，那边不用改模板。 */
    const bulkField = bulkForm.getAttribute("data-bulk-field") || "ids[]";
    const bulkUnit = bulkForm.getAttribute("data-bulk-unit") || "篇";
    const bulkNoun = bulkForm.getAttribute("data-bulk-noun") || "稿件";

    /* 跨页选择：勾了谁记在 sessionStorage 里，翻页回来还算数；
       不在本页的 id 用隐藏域补进表单，服务端收到的仍然是同一串 ids[]。
       没有脚本时退化成「只认本页」，与之前的行为一致。 */
    const storeKey = bulkStoreKey;
    const readStore = () => {
      try {
        const raw = JSON.parse(sessionStorage.getItem(storeKey) || "[]");
        return Array.isArray(raw) ? raw.map(String) : [];
      } catch (e) { return []; }
    };
    const writeStore = (ids) => {
      try { sessionStorage.setItem(storeKey, JSON.stringify(ids)); } catch (e) { /* 无痕模式：退化成只认本页 */ }
    };
    const idOf = (box) => String(box.getAttribute("value") || "");
    const onPageIds = new Set(rows.map(idOf));
    const checkedOnPage = () => rows.filter((box) => box.checked).map(idOf);
    const offPageIds = () => readStore().filter((id) => id !== "" && !onPageIds.has(id));
    const allIds = () => {
      const ids = checkedOnPage();
      offPageIds().forEach((id) => { if (ids.indexOf(id) === -1) ids.push(id); });
      return ids;
    };

    const syncHiddenFields = () => {
      Array.prototype.forEach.call(bulkForm.querySelectorAll("[data-cross-page]"), (node) => node.remove());
      offPageIds().forEach((id) => {
        const field = document.createElement("input");
        field.type = "hidden";
        field.name = bulkField;
        field.value = id;
        field.setAttribute("data-cross-page", "1");
        bulkForm.appendChild(field);
      });
    };

    const persist = () => {
      writeStore(allIds());
      syncHiddenFields();
    };

    const refresh = () => {
      const ids = allIds();
      const pageCount = checkedOnPage().length;
      const offCount = ids.length - pageCount;
      if (countNode) countNode.textContent = String(ids.length);
      bulkBar.classList.toggle("is-active", ids.length > 0);
      // 吸顶挂在 form 上而不是条子上：sticky 只能在包含块内偏移，
      // 条子的包含块就是这根条子本身（可偏移量恒为 0），挂在 form 上才真的吸得住。
      bulkForm.classList.toggle("is-sticky", ids.length > 0);
      if (scopeNode) {
        scopeNode.hidden = ids.length === 0;
        scopeNode.textContent = offCount > 0
          ? "（本页 " + pageCount + " " + bulkUnit + "，另 " + offCount + " " + bulkUnit + "在其它页）"
          : "（都在本页）";
      }
      if (selectAll) {
        selectAll.checked = rows.length > 0 && pageCount === rows.length;
        selectAll.indeterminate = pageCount > 0 && pageCount < rows.length;
      }
      rows.forEach((box) => {
        const row = box.closest("tr");
        if (row) row.classList.toggle("is-selected", box.checked);
      });
    };

    const refreshNote = () => {
      if (!actionSelect || !noteField) return;
      const option = actionSelect.options[actionSelect.selectedIndex];
      noteField.hidden = !option || option.getAttribute("data-need-note") !== "1";
    };

    // 用户一动选择就把上一次的报错收掉（含「超过 100 篇」那条），
    // 否则取消选择后提示还赖在条子上，看着像没生效。
    const remember = () => { clearError(); persist(); refresh(); };

    rows.forEach((box) => box.addEventListener("change", remember));

    // Shift + 勾选 = 选中区间：一页 100 条时逐条点太慢，键盘用户只有 Tab + 空格更吃力
    let lastIndex = -1;
    rows.forEach((box, index) => {
      box.addEventListener("click", (event) => {
        if (event.shiftKey && lastIndex >= 0 && lastIndex !== index) {
          const from = Math.min(lastIndex, index);
          const to = Math.max(lastIndex, index);
          for (let i = from; i <= to; i += 1) rows[i].checked = box.checked;
          remember();
        }
        lastIndex = index;
      });
    });
    if (selectAll) {
      selectAll.addEventListener("change", () => {
        rows.forEach((box) => { box.checked = selectAll.checked; });
        remember();
      });
    }
    if (actionSelect) actionSelect.addEventListener("change", refreshNote);
    if (clearButton) {
      clearButton.addEventListener("click", () => {
        rows.forEach((box) => { box.checked = false; });
        writeStore([]);
        remember();
      });
    }

    // 表单自检走页面内的错误条，和站内其它提示同一套视觉语言：
    // 原生弹窗会离开上下文，读屏也不会把它当成页面状态播报。
    const errorNode = document.querySelector("[data-bulk-error]");
    const showError = (message, focusTarget) => {
      if (errorNode) {
        errorNode.textContent = message;
        errorNode.hidden = false;
      }
      if (focusTarget && typeof focusTarget.focus === "function") focusTarget.focus();
    };
    const clearError = () => {
      if (!errorNode || errorNode.hidden) return;
      errorNode.hidden = true;
      errorNode.textContent = "";
    };
    rows.forEach((box) => box.addEventListener("change", clearError));
    [actionSelect, noteInput].forEach((node) => {
      if (!node) return;
      node.addEventListener("input", clearError);
      node.addEventListener("change", clearError);
    });

    bulkForm.addEventListener("submit", (event) => {
      const ids = allIds();
      if (ids.length === 0) {
        event.preventDefault();
        showError("请先勾选要处理的" + bulkNoun + "。", selectAll || rows[0]);
        return;
      }
      if (ids.length > maxBulk) {
        event.preventDefault();
        showError("已选 " + ids.length + " " + bulkUnit + "，超过单次上限 " + maxBulk + " " + bulkUnit + "，请先取消一部分。", clearButton || rows[0]);
        return;
      }
      const option = actionSelect ? actionSelect.options[actionSelect.selectedIndex] : null;
      if (!option || option.value === "") {
        event.preventDefault();
        showError("请先选择要执行的批量操作。", actionSelect);
        return;
      }
      if (option.getAttribute("data-need-note") === "1" && noteInput && noteInput.value.trim() === "") {
        event.preventDefault();
        showError("这个批量操作要填备注（撤回原因或退回意见）。", noteInput);
        return;
      }
    });

    // 回到这一页时把上次勾过的恢复出来（跨页选择），并补上不在本页的隐藏域
    const storedIds = readStore();
    if (storedIds.length > 0) {
      rows.forEach((box) => { box.checked = storedIds.indexOf(idOf(box)) !== -1; });
    }
    persist();
    refresh();
    refreshNote();
  }

  /* ------------------------------------------ 1.5 侧栏：展开态记忆与当前项可见 */
  const sidenav = document.querySelector(".sidenav");
  if (sidenav) {
    const NAV_KEY = "hechi-admin-nav-open";
    const groups = Array.prototype.slice.call(sidenav.querySelectorAll("details.sidenav-group"));
    const groupKey = (group) => {
      const label = group.querySelector("summary span");
      return label ? label.textContent.trim() : "";
    };
    let savedOpen = null;
    try { savedOpen = JSON.parse(localStorage.getItem(NAV_KEY) || "null"); } catch (e) { savedOpen = null; }
    if (savedOpen && typeof savedOpen === "object") {
      groups.forEach((group) => {
        const key = groupKey(group);
        // 当前页就在这个组里时必须展开：恢复成折叠的话，用户进来只看到一行组标题，
        // 连自己在哪一页都看不见。
        if (group.querySelector("a.active")) {
          group.open = true;
          return;
        }
        if (key && Object.prototype.hasOwnProperty.call(savedOpen, key)) group.open = !!savedOpen[key];
      });
    }
    // 折起来是用户自己的选择，刷新后不该又弹开
    groups.forEach((group) => group.addEventListener("toggle", () => {
      const state = {};
      groups.forEach((item) => { state[groupKey(item)] = item.open; });
      try { localStorage.setItem(NAV_KEY, JSON.stringify(state)); } catch (e) { /* 无痕模式就算了 */ }
    }));

    // 窄屏下侧栏是一条横向滚动的条带，当前页可能落在视口右侧之外，进来先把它滚到中间
    const activeNav = sidenav.querySelector("a.active");
    if (activeNav && sidenav.scrollWidth > sidenav.clientWidth + 1) {
      sidenav.scrollLeft = Math.max(
        0,
        activeNav.offsetLeft - Math.round(sidenav.clientWidth / 2) + Math.round(activeNav.offsetWidth / 2)
      );
    }

    // 右边／左边还有内容时给一层渐隐（CSS 按类名出 mask），
    // 否则窄屏上被切掉的那几个入口完全没有「还能滑」的提示。
    const syncScrollHint = () => {
      const max = sidenav.scrollWidth - sidenav.clientWidth;
      const scrollable = max > 1;
      const atStart = !scrollable || sidenav.scrollLeft <= 1;
      const atEnd = !scrollable || sidenav.scrollLeft >= max - 1;
      sidenav.classList.toggle("is-scroll-start", atStart);
      sidenav.classList.toggle("is-scroll-end", atEnd);
      sidenav.classList.toggle("is-scroll-middle", scrollable && !atStart && !atEnd);
    };
    sidenav.addEventListener("scroll", syncScrollHint, { passive: true });
    window.addEventListener("resize", syncScrollHint);
    syncScrollHint();
  }

  /* ------------------------------------------------------ 2. 栏目即时筛选 */
  const channelSearch = document.querySelector('.filter-panel input[name="q"]');
  if (channelSearch) {
    const groups = Array.prototype.slice.call(document.querySelectorAll(".channel-block"));
    const filter = () => {
      const keyword = channelSearch.value.trim().toLowerCase();
      groups.forEach((group) => {
        let visible = 0;
        Array.prototype.forEach.call(group.querySelectorAll("tr"), (row) => {
          if (row.classList.contains("group-head")) return;
          const hit = keyword === "" || row.textContent.toLowerCase().indexOf(keyword) !== -1;
          row.hidden = !hit;
          if (hit) visible += 1;
        });
        group.hidden = visible === 0;
      });
    };
    channelSearch.addEventListener("input", filter);
    filter();
  }

  /* ---------------------------------------------------------- 3. 正文预览 */
  const contentArea = document.querySelector('textarea[name="content_html"]');
  const previewToggle = document.querySelector("[data-preview-toggle]");
  const previewPanel = document.querySelector("[data-preview-panel]");
  const previewFrame = document.querySelector("[data-preview-frame]");
  const countNode = document.querySelector("[data-content-count]");
  const titleArea = document.querySelector('input[name="title"]');
  const titleCount = document.querySelector("[data-title-count]");

  // 编辑页挂了富文本编辑器时以内里的内容为准；没有编辑器就用 textarea（渐进增强）
  const readContent = () => (
    window.AdminEditor && typeof window.AdminEditor.getContent === "function"
      ? window.AdminEditor.getContent()
      : (contentArea ? contentArea.value : "")
  );

  const toPreviewHtml = (raw) => {
    const text = raw.trim();
    if (text === "") return "<p class=\"empty\">（正文还是空的）</p>";
    if (/<[a-z][^>]*>/i.test(text)) return text;
    return text
      .split(/\n\s*\n/)
      .map((block) => "<p>" + block.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/\n/g, "<br>") + "</p>")
      .join("");
  };

  const refreshPreview = () => {
    if (!previewFrame || !contentArea) return;
    const body = toPreviewHtml(readContent());
    previewFrame.setAttribute(
      "srcdoc",
      '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">' +
      '<style>body{margin:0;padding:16px 18px;font:16px/1.9 "Microsoft YaHei","PingFang SC",sans-serif;color:#222}' +
      'p{margin:0 0 12px}img{max-width:100%;height:auto}.empty{color:#5f6771}</style></head><body>' + body + "</body></html>"
    );
  };

  if (contentArea && countNode) {
    const refreshCount = () => {
      const text = readContent().replace(/<[^>]*>/g, "").replace(/&nbsp;/g, " ").trim();
      countNode.textContent = text.length > 0 ? "正文约 " + text.length + " 字" : "";
    };
    contentArea.addEventListener("input", refreshCount);
    refreshCount();
  }

  /* ---------------------------------------------------- 标题字数（标题输入框） */
  if (titleArea && titleCount) {
    const refreshTitleCount = () => {
      const used = titleArea.value.length;
      titleCount.textContent = used + "/64";
      // 快到上限时变色：标题超长前台会被截断，写之前就该看见
      titleCount.classList.toggle("is-near", used >= 56 && used < 64);
      titleCount.classList.toggle("is-full", used >= 64);
    };
    titleArea.addEventListener("input", refreshTitleCount);
    refreshTitleCount();
  }

  if (previewToggle && previewPanel && previewFrame && contentArea) {
    previewToggle.hidden = false;
    previewToggle.addEventListener("click", () => {
      const showing = !previewPanel.hidden;
      if (showing) {
        previewPanel.hidden = true;
        previewToggle.textContent = "预览正文";
        previewToggle.setAttribute("aria-expanded", "false");
        return;
      }
      refreshPreview();
      previewPanel.hidden = false;
      previewToggle.textContent = "回到编辑";
      previewToggle.setAttribute("aria-expanded", "true");
    });
    previewToggle.setAttribute("aria-expanded", "false");
  }

  /* ---------------------------------------------- 3.5 复制正式链接 */
  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-copy-link]");
    if (!button) return;
    const url = new URL(button.getAttribute("data-copy-link"), window.location.origin).href;
    // 原值只读一次并记在 data 上：1.5 秒内连点两次时读 textContent 会读到「已复制」，
    // 还原时又写回「已复制」，按钮就再也回不去了。
    const label = button.dataset.copyLabel || button.textContent;
    button.dataset.copyLabel = label;
    const done = () => {
      button.textContent = "已复制";
      announce("链接已复制");
      window.clearTimeout(Number(button.dataset.copyTimer) || 0);
      button.dataset.copyTimer = String(window.setTimeout(() => {
        button.textContent = label;
      }, 1500));
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done).catch(() => window.prompt("复制这个链接：", url));
      return;
    }
    window.prompt("复制这个链接：", url);
  });

  /* ------------------------------------------------ 4. 快捷键与未保存提醒 */
  const articleForm = document.getElementById("article-form");
  if (articleForm) {
    let dirty = false;
    let submitting = false;
    articleForm.addEventListener("input", () => { dirty = true; });
    articleForm.addEventListener("submit", () => { submitting = true; });
    window.addEventListener("beforeunload", (event) => {
      if (!dirty || submitting) return;
      event.preventDefault();
      event.returnValue = "";
    });
    document.addEventListener("keydown", (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "s") {
        event.preventDefault();
        const submit = articleForm.querySelector('button[type="submit"].btn-primary');
        if (submit) submit.click();
      }
    });
  }

  /* -------------------------------------------- 5. 排序后保持滚动位置 */
  // 稿件「本栏目内上移／下移」（/order）与栏目、导航、头条的「上移／下移」（/move）
  // 都是表单 POST + 302 回到本页，浏览器重新加载后停在页面顶部，一排 20 条里挪一次
  // 就得重新滚回去。这里在提交前记下位置，回到同一路径时还原。
  // 只认这两个排序端点，发稿、批量流转、改绑定等提交保持浏览器默认行为。
  const SCROLL_KEY = "hechi-admin-scroll";

  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!form || form.tagName !== "FORM" || event.defaultPrevented) return;
    const action = form.getAttribute("action") || "";
    if (!/\/(move|order)$/.test(action)) return;
    try {
      sessionStorage.setItem(SCROLL_KEY, JSON.stringify({
        path: location.pathname,
        y: window.scrollY,
        hadFlash: document.querySelector(".flash") !== null
      }));
    } catch (e) { /* 无痕模式写不了存储就按默认行为走 */ }
  });

  (() => {
    let saved = null;
    try {
      saved = sessionStorage.getItem(SCROLL_KEY);
      if (saved) sessionStorage.removeItem(SCROLL_KEY);
    } catch (e) { return; }
    if (!saved) return;

    let state = null;
    try { state = JSON.parse(saved); } catch (e) { return; }
    // 路径不一致说明这次提交去了别的页面，不还原
    if (!state || state.path !== location.pathname) return;
    let y = Number(state.y) || 0;
    if (y <= 0) return;

    // 排序后本页会多出一条操作提示条（提交前没有、返回后有），它把下方内容整体推低，
    // 量出它的高度补进偏移量，第一次操作也不会错位一行。
    const flash = document.querySelector(".flash");
    if (flash && !state.hadFlash) {
      y += flash.offsetHeight + (parseFloat(getComputedStyle(flash).marginBottom) || 0);
    }

    // 浏览器自己的滚动恢复会先跳一次，统一交给下面的 restore 控制
    if ("scrollRestoration" in history) history.scrollRestoration = "manual";

    // 缩略图加载完页面还会长高一点，所以首次排版与 load 后各还原一次；
    // 用户自己滚动或按键后立即放手，不再跟他抢滚动位置。
    let stop = false;
    ["wheel", "touchstart", "keydown", "mousedown"].forEach((name) => {
      addEventListener(name, () => { stop = true; }, { passive: true, once: true });
    });
    const restore = () => { if (!stop) window.scrollTo(0, y); };
    restore();
    document.addEventListener("DOMContentLoaded", restore);
    addEventListener("load", restore);
  })();

  /* ------------------------------------ 6. 首屏预览：拖动缩略图排序 */
  // 拖的过程中直接把元素挪到位，松手前就能看到落点；松手后把整串顺序交给
  // /admin/slides/order 一次提交（服务端只认「与当前上线条目完全一致」的顺序）。
  // 没有脚本时，列表里的上移／下移照旧可用。
  const slideStrip = document.querySelector("[data-slide-strip]");
  const slideOrderForm = document.getElementById("slide-order-form");

  if (slideStrip && slideOrderForm && typeof window.DataTransfer === "function") {
    const chips = () => Array.prototype.slice.call(slideStrip.querySelectorAll("[data-slide-id]"));
    let draggedChip = null;

    slideStrip.addEventListener("dragstart", (event) => {
      const chip = event.target.closest ? event.target.closest("[data-slide-id]") : null;
      if (!chip) return;
      draggedChip = chip;
      chip.classList.add("is-dragging");
      if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = "move";
        try {
          event.dataTransfer.setData("text/plain", chip.getAttribute("data-slide-id") || "");
        } catch (e) { /* 个别浏览器不让写拖拽数据，不影响排序 */ }
      }
    });

    slideStrip.addEventListener("dragover", (event) => {
      if (!draggedChip) return;
      event.preventDefault();
      if (event.dataTransfer) event.dataTransfer.dropEffect = "move";
      const over = event.target.closest ? event.target.closest("[data-slide-id]") : null;
      if (!over || over === draggedChip) return;
      const rect = over.getBoundingClientRect();
      const after = event.clientX > rect.left + rect.width / 2;
      slideStrip.insertBefore(draggedChip, after ? over.nextSibling : over);
    });

    slideStrip.addEventListener("drop", (event) => {
      if (draggedChip) event.preventDefault();
    });

    slideStrip.addEventListener("dragend", () => {
      if (!draggedChip) return;
      draggedChip.classList.remove("is-dragging");
      draggedChip = null;
      const order = chips().map((chip) => chip.getAttribute("data-slide-id")).join(",");
      if (order === (slideOrderForm.getAttribute("data-order") || "")) return;
      const field = slideOrderForm.querySelector('input[name="order"]');
      if (field) field.value = order;
      slideOrderForm.requestSubmit();
    });
  }

  // 首页管理：改动直接同步前台，凡是会改前台的提交先问一句再提交。
  // 确认文案写在模板的 data-confirm 上（每条都不一样，写清这一下会造成什么），
  // 没有脚本时不拦，按原来的方式提交。
  document.addEventListener("submit", (event) => {
    const form = event.target;
    if (!form || form.tagName !== "FORM") return;
    // 页面自己的提交自检（批量表单的空选／超限／缺备注）已经挡下这次提交时不再弹确认框，
    // 否则用户点了「确定」页面毫无反应，像是按钮坏了。
    if (event.defaultPrevented) return;
    // 文案可以写在表单上（一条记录一个），也可以写在提交按钮上（同一表单多个按钮）
    const submitter = event.submitter;
    const source = submitter && submitter.hasAttribute && submitter.hasAttribute("data-confirm")
      ? submitter
      : (form.hasAttribute("data-confirm") ? form : null);
    if (!source) return;
    if (!window.confirm(source.getAttribute("data-confirm") || "确认执行这次改动？")) {
      event.preventDefault();
    }
  });

  /* -------------------------------------- 6.5 批量提交成功后清空篮子 */
  // 挂在 document 上、且排在「表单自检」和上面那段二次确认之后注册：
  // 只有这一步真的会提交（event.defaultPrevented 为假）时才清，
  // 用户点「取消」或校验没过时，勾选原样留着。
  if (bulkForm && bulkStoreKey) {
    document.addEventListener("submit", (event) => {
      if (event.defaultPrevented || event.target !== bulkForm) return;
      try { sessionStorage.setItem(bulkStoreKey, "[]"); } catch (e) { /* 存不了就算了 */ }
    });
  }

  /* ---------------------------------------------------------- 7. 提交中的反馈 */
  // 统一给表单一个「处理中…」状态：整页提交要等一次往返，没有反馈时用户会以为没点上、再点一次。
  // 两个约束：
  //   1. 不能用 disabled——稿件保存、稿库流转靠 button 的 name/value 传参，禁用按钮会让参数丢失，
  //      服务端就收不到 status/action 了；这里改用 aria-busy + 类名 + 让按钮不再响应点击；
  //   2. 必须排在二次确认之后注册：确认框里点「取消」时 event.defaultPrevented 已经为真，不再改文案。
  const busyText = "处理中…";
  document.addEventListener("submit", (event) => {
    if (event.defaultPrevented) return;
    const form = event.target;
    if (!form || form.tagName !== "FORM" || form.hasAttribute("data-no-busy")) return;

    const buttons = Array.prototype.slice
      .call(form.querySelectorAll('button[type="submit"], button:not([type])'))
      .filter((button) => !button.disabled);
    if (buttons.length === 0) return;

    const submitter = event.submitter && buttons.indexOf(event.submitter) >= 0
      ? event.submitter
      : buttons[0];
    if (submitter.dataset.busy === "1") return;

    submitter.dataset.busy = "1";
    submitter.dataset.busyLabel = submitter.textContent;
    submitter.textContent = busyText;
    submitter.classList.add("is-busy");
    submitter.setAttribute("aria-busy", "true");
    // 按钮本身不 disabled，只挡住后续点击；表单数据照常提交
    submitter.style.pointerEvents = "none";
  });

  /* ------------------------------------ 7. 长页面回到顶部 */
  // 「其他栏目」这类页面有一万多像素高，滚到下面想回顶部得一直往上搓。
  // 按钮平时藏着，滚过一屏才出现（模板里默认 hidden，没有脚本就不会留下一个点不动的按钮）。
  const toTop = document.querySelector("[data-to-top]");
  if (toTop) {
    const reduceMotion = typeof window.matchMedia === "function" &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    // 稿件页底部有吸底保存条：窄屏上把「回到顶部」抬到条子上方，
    // 免得它压住保存按钮。条子吸在视口下沿时按高度让位；滚到页面末尾时条子停在
    // 正文下方（离视口下沿还差 main 的下内边距），所以每次滚动都按它的实际位置重算。
    const stickyBar = document.querySelector(".form-actions");
    const liftToTop = () => {
      if (!stickyBar || window.innerWidth > 720) {
        toTop.style.bottom = "";
        return;
      }
      const rect = stickyBar.getBoundingClientRect();
      toTop.style.bottom = Math.max(0, window.innerHeight - rect.top + 16) + "px";
    };
    const syncToTop = () => {
      toTop.hidden = window.scrollY < 400;
      liftToTop();
    };

    toTop.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
    });
    window.addEventListener("scroll", syncToTop, { passive: true });
    window.addEventListener("resize", syncToTop);
    syncToTop();
  }

  /* ------------------ 8. 选好图片先在上方预览（保存后才真正生效） */
  // 站内横幅这类「先选文件、再点保存」的表单：选中图片后立刻把图显示进上面的预览框，
  // 免得上传完才发现选错。这里只在浏览器里读成 data: 地址做本地预览（后台 CSP 的
  // img-src 只放行 'self' 与 data:，blob: 会被拦掉），服务端与已保存的图都不动，
  // 真要替换仍然是点「保存」之后的事（预览框里那行提示就是提醒这一点）。
  document.querySelectorAll("[data-preview-file]").forEach((input) => {
    const box = document.querySelector(input.getAttribute("data-preview-file") || "");
    if (!box) return;

    const image = box.querySelector("[data-preview-image]");
    const empty = box.querySelector("[data-preview-empty]");
    const note = box.querySelector("[data-preview-pending]");

    input.addEventListener("change", () => {
      const file = input.files && input.files[0];
      // accept="image/*" 只是给文件选择器看的，这里再挡一次，别把非图片塞进 <img>
      if (!file || (file.type && file.type.indexOf("image/") !== 0)) return;

      const reader = new FileReader();
      reader.addEventListener("load", () => {
        if (image) {
          image.src = String(reader.result || "");
          image.hidden = false;
        }
        if (empty) empty.hidden = true;
        if (note) note.hidden = false;
      });
      reader.readAsDataURL(file);
    });
  });

  /* ------------------------- 9. 徽标：下拉与自定义输入联动 */
  // 下拉给预设，选「自定义…」才用文本框。没有脚本时文本框一直可见（也能用），
  // 有脚本时把不用的那个禁掉——disabled 的字段不会提交，避免自定义的旧值压过刚选的预设。
  document.querySelectorAll("[data-badge-form]").forEach((form) => {
    const select = form.querySelector("[data-badge-preset]");
    const custom = form.querySelector("[data-badge-custom]");
    if (!select || !custom) return;

    const sync = () => {
      const useCustom = select.value === "__custom__";
      custom.hidden = !useCustom;
      custom.disabled = !useCustom;
    };

    select.addEventListener("change", () => {
      const wasHidden = custom.hidden;
      sync();
      if (wasHidden && !custom.hidden) custom.focus();
    });
    sync();
  });

  /* ------------------------- 10. 委员管理：手工建号增删行 */
  // 表单默认渲染三行，没有脚本也能填能提交；有脚本时「删除本行」才显示（见 admin.css）。
  const manualRows = document.querySelector("[data-manual-rows]");
  const manualTemplate = document.querySelector("[data-manual-template]");
  if (manualRows && manualTemplate) {
    const manualAdd = document.querySelector("[data-manual-add]");
    if (manualAdd) {
      manualAdd.addEventListener("click", () => {
        manualRows.appendChild(manualTemplate.content.cloneNode(true));
        const input = manualRows.lastElementChild.querySelector('input[name="name[]"]');
        if (input) input.focus();
      });
    }

    manualRows.addEventListener("click", (event) => {
      const button = event.target.closest("[data-manual-remove]");
      if (!button) return;
      const row = button.closest("[data-manual-row]");
      if (!row) return;
      // 至少留一行：删空了就没处填了，清空内容即可
      if (manualRows.querySelectorAll("[data-manual-row]").length <= 1) {
        row.querySelectorAll("input").forEach((input) => { input.value = ""; });
        return;
      }
      row.remove();
    });
  }
})();
