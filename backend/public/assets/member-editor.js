/* 委员端提案表单的轻量富文本编辑器与字数控制（SunEditor 3.3.3，MIT，本地自托管）
 *
 * 设计要点：
 * - 渐进增强：脚本没加载、加载失败或初始化失败时，textarea 照样能填能交；
 * - 只开提案表需要的按钮：加粗、下划线、有序／无序列表，其他一律不给，
 *   免得出委员排出一版与归档口径不符的版式；
 * - 字数口径与服务端 ProposalBody 完全一致（HTML 转纯文本、去掉所有空白后数字符），
 *   两边不一致会出现「页面上没超、提交被挡」这种最招人烦的错。
 */
(function () {
  "use strict";

  var form = document.getElementById("proposal-form");
  if (!form) {
    return;
  }
  var textarea = form.querySelector('textarea[name="body_html"]');
  var mount = form.querySelector("[data-editor-mount]");
  var counter = form.querySelector("[data-body-counter]");
  var limit = parseInt(form.getAttribute("data-body-limit") || "2000", 10);
  var editor = null;
  var editorContainer = null;

  /** 与服务端同一算法：块级边界换行、去掉空白、按码点数 */
  function countChars(html) {
    var holder = document.createElement("div");
    holder.innerHTML = html || "";
    var text = holder.textContent || "";
    return Array.from(text.replace(/\s+/g, "")).length;
  }

  function refreshCounter(content) {
    if (!counter) {
      return;
    }
    var count = countChars(content);
    counter.textContent = count + " / " + limit + " 字";
    counter.classList.toggle("m-count-over", count > limit);
  }

  function warn(message) {
    if (window.console && window.console.warn) {
      window.console.warn(message);
    }
  }

  function showFallbackNotice(message) {
    if (!mount || !mount.parentElement) {
      return;
    }
    var notice = document.createElement("p");
    notice.className = "m-hint";
    notice.setAttribute("data-editor-fallback", "1");
    notice.textContent = "富文本编辑器未能加载，已退回纯文本框：" + message + "。内容仍可正常提交。";
    mount.parentElement.insertBefore(notice, mount);
  }

  /* ------------------------------------------------ 编辑器 */

  function setup(container) {
    editorContainer = container;
    editor.$.html.set(textarea.value);
    var editable = container.querySelector('[contenteditable="true"]');
    if (editable) {
      editable.setAttribute("aria-label", "提案内容");
    }
    textarea.hidden = true;
    // textarea 藏起来后浏览器会把「必填」报在一个看不见的控件上，改由下面的提交拦截负责
    textarea.required = false;
    container.hidden = false;
    mount.setAttribute("data-editor-ready", "1");

    refreshCounter(textarea.value);
  }

  function syncFromEvent(params) {
    if (!params || typeof params.data !== "string") {
      return;
    }
    textarea.value = params.data;
    refreshCounter(params.data);
  }

  if (form && textarea && mount && window.SUNEDITOR) {
    try {
      editor = window.SUNEDITOR.create(mount, {
        // 不传 plugins 时列表按钮（list_bulleted／list_numbered）注册不上，create 会直接抛错
        plugins: window.SUNEDITOR.plugins,
        lang: (window.SUNEDITOR_LANG && window.SUNEDITOR_LANG.zh_cn) || undefined,
        height: "320px",
        placeholder: "提案一事一案，简明扼要，字数不超过 " + limit + " 字，否则无法上传；有关材料可作为附件提交。",
        buttonList: [
          ["undo", "redo"],
          ["bold", "underline"],
          ["list_bulleted", "list_numbered"],
          ["removeFormat"]
        ],
        events: { onChange: syncFromEvent, onInput: syncFromEvent }
      });
    } catch (error) {
      warn("提案正文编辑器初始化失败：" + (error && error.message ? error.message : error));
      showFallbackNotice("初始化失败");
      editor = null;
    }

    if (editor) {
      (function waitForContainer(attempts) {
        var container = mount.nextElementSibling;
        if (container && container.classList && container.classList.contains("sun-editor")) {
          setup(container);
          return;
        }
        if (attempts >= 50) {
          warn("提案正文编辑器没有渲染出容器，已退回纯文本框。");
          showFallbackNotice("容器未渲染");
          textarea.hidden = false;
          editor = null;
          return;
        }
        window.setTimeout(function () { waitForContainer(attempts + 1); }, 100);
      })(0);
    }
  }

  /* 提交前把编辑器内容写回表单，并挡下超字数的提交 */
  form.addEventListener("submit", function (event) {
    if (editor && editor.$.html) {
      textarea.value = editor.$.html.get();
    }
    var count = countChars(textarea.value);
    refreshCounter(textarea.value);
    if (count === 0) {
      event.preventDefault();
      window.alert("请填写提案内容。");
      var editable = editorContainer ? editorContainer.querySelector('[contenteditable="true"]') : null;
      if (editable) {
        editable.focus();
      }
      return;
    }
    if (count > limit) {
      event.preventDefault();
      window.alert("提案内容 " + count + " 字，超过 " + limit + " 字上限，请精简后再提交。");
      if (counter) {
        counter.scrollIntoView({ block: "center" });
      }
    }
  });

  /* 错别字勘误：本期只留入口，点一下回到服务端的预留接口 */
  var proofread = form.querySelector("[data-proofread]");
  if (proofread) {
    proofread.addEventListener("click", function () {
      if (editor && editor.$.html) {
        textarea.value = editor.$.html.get();
      }
    });
  }

  /* ------------------------------------------------ 联名委员：增删行 + 名册带出 */

  var rows = form.querySelector("[data-member-rows]");
  var template = form.querySelector("[data-member-template]");
  var rosterList = document.getElementById("roster-list");
  var rosterCache = {};

  function bindRoster(input) {
    input.addEventListener("input", function () {
      var keyword = input.value.trim();
      if (keyword.length < 2 || !window.fetch) {
        return;
      }
      window.clearTimeout(input.dataset.timer);
      input.dataset.timer = window.setTimeout(function () {
        window.fetch("/member/roster?keyword=" + encodeURIComponent(keyword), {
          headers: { "Accept": "application/json" }
        }).then(function (response) {
          return response.ok ? response.json() : null;
        }).then(function (data) {
          if (!data || !data.items) {
            return;
          }
          data.items.forEach(function (item) {
            rosterCache[item.name] = item;
            if (rosterList) {
              var option = document.createElement("option");
              option.value = item.name;
              option.label = item.org_title || "";
              rosterList.appendChild(option);
            }
            // 名字敲全了就把单位职务与电话带出来，省得再查一次名册
            if (item.name === input.value.trim()) {
              fillRow(input, item);
            }
          });
        }).catch(function (error) {
          warn("名册检索失败：" + (error && error.message ? error.message : error));
        });
      }, 250);
    });

    input.addEventListener("change", function () {
      var item = rosterCache[input.value.trim()];
      if (item) {
        fillRow(input, item);
      }
    });
  }

  function fillRow(input, item) {
    var row = input.closest("[data-member-row]");
    if (!row) {
      return;
    }
    var org = row.querySelector('input[name="co_org[]"]');
    var mobile = row.querySelector('input[name="co_mobile[]"]');
    if (org && org.value.trim() === "" && item.org_title) {
      org.value = item.org_title;
    }
    if (mobile && mobile.value.trim() === "" && item.mobile) {
      mobile.value = item.mobile;
    }
  }

  if (rows) {
    Array.prototype.forEach.call(rows.querySelectorAll('input[name="co_name[]"]'), bindRoster);

    var addButton = form.querySelector("[data-member-add]");
    if (addButton && template) {
      addButton.addEventListener("click", function () {
        var row = template.content.cloneNode(true);
        rows.appendChild(row);
        var input = rows.lastElementChild.querySelector('input[name="co_name[]"]');
        if (input) {
          bindRoster(input);
          input.focus();
        }
      });
    }

    rows.addEventListener("click", function (event) {
      var button = event.target.closest("[data-member-remove]");
      if (!button) {
        return;
      }
      var row = button.closest("[data-member-row]");
      if (!row) {
        return;
      }
      // 至少留一行，删空了界面上就没处填联名委员了
      if (rows.querySelectorAll("[data-member-row]").length <= 1) {
        Array.prototype.forEach.call(row.querySelectorAll("input"), function (input) {
          input.value = "";
        });
        return;
      }
      row.remove();
    });
  }
})();
