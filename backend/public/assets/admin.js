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
 *
 * 所有逻辑都用 data-* 钩子，模板改名不影响；没有匹配元素时静默跳过。
 */
(function () {
  "use strict";

  document.documentElement.classList.add("js");

  /* ---------------------------------------------------------- 1. 批量选择 */
  const bulkForm = document.getElementById("bulk-form");
  const bulkBar = document.querySelector("[data-bulk-bar]");
  const rows = Array.prototype.slice.call(document.querySelectorAll("[data-row-select]"));

  if (bulkForm && bulkBar && rows.length > 0) {
    const selectAll = document.querySelector("[data-select-all]");
    const countNode = document.querySelector("[data-bulk-count]");
    const actionSelect = document.querySelector("[data-bulk-action]");
    const noteField = document.querySelector("[data-bulk-note]");
    const noteInput = noteField ? noteField.querySelector("input") : null;
    const clearButton = document.querySelector("[data-bulk-clear]");

    const selected = () => rows.filter((box) => box.checked);

    const refresh = () => {
      const checked = selected().length;
      if (countNode) countNode.textContent = String(checked);
      bulkBar.classList.toggle("is-active", checked > 0);
      if (selectAll) {
        selectAll.checked = checked === rows.length;
        selectAll.indeterminate = checked > 0 && checked < rows.length;
      }
    };

    const refreshNote = () => {
      if (!actionSelect || !noteField) return;
      const option = actionSelect.options[actionSelect.selectedIndex];
      noteField.hidden = !option || option.getAttribute("data-need-note") !== "1";
    };

    rows.forEach((box) => box.addEventListener("change", refresh));
    if (selectAll) {
      selectAll.addEventListener("change", () => {
        rows.forEach((box) => { box.checked = selectAll.checked; });
        refresh();
      });
    }
    if (actionSelect) actionSelect.addEventListener("change", refreshNote);
    if (clearButton) {
      clearButton.addEventListener("click", () => {
        rows.forEach((box) => { box.checked = false; });
        refresh();
      });
    }

    bulkForm.addEventListener("submit", (event) => {
      const checked = selected();
      if (checked.length === 0) {
        event.preventDefault();
        window.alert("请先勾选要处理的稿件。");
        return;
      }
      const option = actionSelect ? actionSelect.options[actionSelect.selectedIndex] : null;
      if (!option || option.value === "") {
        event.preventDefault();
        window.alert("请先选择要执行的批量操作。");
        return;
      }
      if (option.getAttribute("data-need-note") === "1" && noteInput && noteInput.value.trim() === "") {
        event.preventDefault();
        noteInput.focus();
        window.alert("这个批量操作要填备注（撤回原因或退回意见）。");
      }
    });

    refresh();
    refreshNote();
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
      titleCount.textContent = titleArea.value.length + "/64";
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
    const done = () => {
      const original = button.textContent;
      button.textContent = "已复制";
      window.setTimeout(() => { button.textContent = original; }, 1500);
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
    const syncToTop = () => { toTop.hidden = window.scrollY < 400; };

    toTop.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
    });
    window.addEventListener("scroll", syncToTop, { passive: true });
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
})();
