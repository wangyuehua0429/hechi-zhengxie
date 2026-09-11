/**
 * 后台渐进增强脚本：不加载也能完成全部操作，加载后少点几下。
 *
 * 这里只做四件事：
 * 1. 稿件列表的批量选择（勾选、全选半选、按动作显示备注框、提交前自检）；
 * 2. 栏目列表的即时筛选（不必回车，服务端筛选照旧可用）；
 * 3. 稿件编辑页的正文预览与字数统计（预览走 sandbox iframe，脚本不执行）；
 * 4. Ctrl/⌘+S 保存与「有改动未保存」离开提醒。
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
    const body = toPreviewHtml(contentArea.value);
    previewFrame.setAttribute(
      "srcdoc",
      '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">' +
      '<style>body{margin:0;padding:16px 18px;font:16px/1.9 "Microsoft YaHei","PingFang SC",sans-serif;color:#222}' +
      'p{margin:0 0 12px}img{max-width:100%;height:auto}.empty{color:#5f6771}</style></head><body>' + body + "</body></html>"
    );
  };

  if (contentArea && countNode) {
    const refreshCount = () => {
      const text = contentArea.value.replace(/<[^>]*>/g, "").replace(/&nbsp;/g, " ").trim();
      countNode.textContent = text.length > 0 ? "正文约 " + text.length + " 字" : "";
    };
    contentArea.addEventListener("input", refreshCount);
    refreshCount();
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
})();
