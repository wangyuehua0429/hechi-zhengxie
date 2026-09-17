/* 后台「调整提案」的正文编辑器（SunEditor 3.3.3，MIT，本地自托管）
 *
 * 与稿件编辑器（editor/admin-editor.js）分开：那份绑的是 #article-form 与图片上传，
 * 提案正文只允许分段、加粗、下划线、列表，单独一份更省心。
 * 脚本没加载或初始化失败时，textarea 里的正文照旧能改能存。
 */
(function () {
  "use strict";

  var form = document.getElementById("proposal-edit-form");
  if (!form) {
    return;
  }
  var textarea = form.querySelector('textarea[name="body_html"]');
  var mount = form.querySelector("[data-editor-mount]");
  if (!textarea || !mount || !window.SUNEDITOR) {
    return;
  }

  var editor;
  try {
    editor = window.SUNEDITOR.create(mount, {
      // 不传 plugins 时列表按钮（list_bulleted／list_numbered）注册不上，create 会直接抛错
      plugins: window.SUNEDITOR.plugins,
      lang: (window.SUNEDITOR_LANG && window.SUNEDITOR_LANG.zh_cn) || undefined,
      height: "360px",
      placeholder: "提案内容",
      buttonList: [
        ["undo", "redo"],
        ["bold", "underline"],
        ["list_bulleted", "list_numbered"],
        ["removeFormat"]
      ],
      events: {
        onChange: function (params) {
          if (params && typeof params.data === "string") {
            textarea.value = params.data;
          }
        },
        onInput: function (params) {
          if (params && typeof params.data === "string") {
            textarea.value = params.data;
          }
        }
      }
    });
  } catch (error) {
    if (window.console && window.console.warn) {
      console.warn("调整提案的正文编辑器初始化失败，已退回纯文本框：", error);
    }
    return;
  }

  (function waitForContainer(attempts) {
    var container = mount.nextElementSibling;
    if (container && container.classList && container.classList.contains("sun-editor")) {
      editor.$.html.set(textarea.value);
      var editable = container.querySelector('[contenteditable="true"]');
      if (editable) {
        editable.setAttribute("aria-label", "提案内容");
      }
      textarea.hidden = true;
      // 藏起来的 textarea 上挂着 required，浏览器会对着不见的控件报错，交回服务端校验
      textarea.required = false;
      container.hidden = false;
      mount.setAttribute("data-editor-ready", "1");
      return;
    }
    if (attempts >= 50) {
      textarea.hidden = false;
      return;
    }
    window.setTimeout(function () { waitForContainer(attempts + 1); }, 100);
  })(0);

  form.addEventListener("submit", function () {
    textarea.value = editor.$.html.get();
  });
})();
