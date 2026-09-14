/* 正文富文本编辑器接入（SunEditor 3.3.3，MIT，本地自托管）
 *
 * 设计要点：
 * - 渐进增强：没有脚本、脚本加载失败或初始化失败时保持原 textarea 可用，不阻断保存；
 * - textarea 仍是提交字段（`content_html`），编辑器内容同步回去并派发 input 事件，
 *   后台既有的字数统计、正文预览、未保存提醒都不用改；
 * - 粘贴进来的 base64 图片（网页与 Word 复制常见）先上传成站内地址再插入，
 *   否则保存时会被服务端白名单当作 data: 协议剥掉，用户会以为图片"自己没了"。
 *
 * 实现注意：SunEditor 3 的容器是**异步**渲染的，且容器被插到目标元素的旁边、
 * 目标元素自己被隐藏——所以要等容器出现后再接线，不能拿挂载点的 hidden 当就绪信号。
 */
(function () {
  "use strict";

  var form = document.getElementById("article-form");
  var textarea = document.querySelector('textarea[name="content_html"]');
  var mount = document.querySelector("[data-editor-mount]");
  var toggle = document.querySelector("[data-editor-toggle]");
  if (!form || !textarea || !mount || !window.SUNEDITOR) {
    return;
  }

  var uploadUrl = form.getAttribute("data-image-upload-url") || "";
  var videoUploadUrl = form.getAttribute("data-video-upload-url") || "";
  var articleId = form.getAttribute("data-article-id") || "";
  var csrfToken = form.getAttribute("data-csrf-token") || "";

  var editor;
  try {
    editor = window.SUNEDITOR.create(mount, {
      plugins: window.SUNEDITOR.plugins,
      lang: (window.SUNEDITOR_LANG && window.SUNEDITOR_LANG.zh_cn) || undefined,
      height: "420px",
      placeholder: "从这里开始写正文",
      // 工具栏按钮项：v3 用 buttonList（与 v2 同名），按钮名也要用 v3 的
      // （标题是 blockStyle、列表是 list_bulleted／list_numbered，v2 的 formatBlock 在 v3 不存在）
      buttonList: [
        ["undo", "redo"],
        ["bold", "underline", "italic", "strike"],
        ["font", "fontSize"],
        ["fontColor", "backgroundColor"],
        ["blockStyle"],
        ["list_bulleted", "list_numbered"],
        ["align"],
        ["link", "image", "video"],
        ["removeFormat"],
      ],
      // 字体只给政务文档常用的几种，避免每篇稿子的字体各不相同
      font: { items: ["宋体", "仿宋", "黑体", "楷体", "微软雅黑", "Arial"] },
      image: uploadUrl
        ? { uploadUrl: uploadUrl, uploadHeaders: csrfToken ? { "X-CSRF-Token": csrfToken } : undefined, acceptedFormats: "image/*" }
        : { createFileInput: false },
      video: videoUploadUrl
        ? { uploadUrl: videoUploadUrl, uploadHeaders: csrfToken ? { "X-CSRF-Token": csrfToken } : undefined, acceptedFormats: "video/*", createFileInput: true }
        : { createFileInput: false },
      // v3 的事件回调统一放在 events 里，回调参数是 {data} 而不是字符串
      events: { onChange: syncFromEvent, onInput: syncFromEvent },
    });
  } catch (error) {
    warn("正文编辑器初始化失败，已退回纯文本框：", error);
    showFallbackNotice(error);
    return;
  }

  function warn() {
    if (window.console && console.warn) {
      console.warn.apply(console, arguments);
    }
  }

  /** 初始化失败时在页面上留个可见提示：静默退回会让配置错误一直没人发现 */
  function showFallbackNotice(error) {
    var notice = document.createElement("p");
    notice.className = "muted";
    notice.setAttribute("data-editor-fallback", "1");
    notice.textContent = "富文本编辑器加载失败，已退回纯文本框；内容仍可正常保存。（" + (error && error.message ? error.message : "未知错误") + "）";
    mount.parentElement.insertBefore(notice, mount);
  }

  function syncToTextarea(content) {
    if (typeof content !== "string" || textarea.value === content) {
      return;
    }
    textarea.value = content;
    // 让既有的字数统计、未保存提醒等监听器照常收到通知
    textarea.dispatchEvent(new Event("input", { bubbles: true }));
  }

  function syncFromEvent(params) {
    if (params && typeof params.data === "string") {
      syncToTextarea(params.data);
    }
  }

  /* ------------------------------------------------ 纸张里的字段排布 */

  /**
   * 把标题、原标题、来源、正文提示摆到写作窗里。
   *
   * 富文本模式下：标题压在工具栏上方，原标题／来源／正文提示落在工具栏与正文框之间；
   * 编辑器没加载时它们留在模板原位，顺序与这里一致。切到源码模式时富文本容器整块
   * 隐藏，这几块必须挪回纸张里，否则「原标题」「来源」会跟着工具栏一起消失、没处编辑。
   */
  function layoutFields(container, richText) {
    // 容器的父节点是编辑器自己的 .sun-editor 外壳，再上一层才是纸张；
    // 标题要落在工具栏之上，就得插到外壳前面（插进 .se-container 会进到工具栏下面）。
    var editorRoot = container.closest(".sun-editor") || container;
    var paper = editorRoot.parentElement;
    var titleLine = document.querySelector(".writing-title-line");
    var blocks = [".writing-orig", ".writing-meta", ".writing-hint"]
      .map(function (selector) { return document.querySelector(selector); })
      .filter(Boolean);
    if (richText) {
      if (titleLine) {
        paper.insertBefore(titleLine, editorRoot);
      }
      var anchor = container.querySelector(".se-toolbar");
      if (!anchor) {
        return;
      }
      blocks.forEach(function (block) {
        anchor.insertAdjacentElement("afterend", block);
        anchor = block;
      });
      return;
    }
    var stacked = (titleLine ? [titleLine] : []).concat(blocks);
    stacked.forEach(function (block) {
      paper.insertBefore(block, textarea);
    });
  }

  /* ---------------------------------------------- 粘贴 base64 图片先上传 */

  function uploadDataUri(dataUri) {
    return new Promise(function (resolve, reject) {
      var blob;
      try {
        var parts = dataUri.split(",");
        var mime = /:(.*?);/.exec(parts[0]);
        var binary = atob(parts[1]);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i += 1) {
          bytes[i] = binary.charCodeAt(i);
        }
        blob = new Blob([bytes], { type: mime ? mime[1] : "image/png" });
      } catch (error) {
        reject(error);
        return;
      }

      var body = new FormData();
      body.append("file-0", blob, "pasted." + (blob.type.split("/")[1] || "png").replace("jpeg", "jpg"));
      if (articleId) {
        body.append("article", articleId);
      }
      var request = new XMLHttpRequest();
      request.open("POST", uploadUrl, true);
      if (csrfToken) {
        request.setRequestHeader("X-CSRF-Token", csrfToken);
      }
      request.onload = function () {
        try {
          var data = JSON.parse(request.responseText);
          var item = data && data.result && data.result[0];
          if (request.status === 200 && item && item.url) {
            resolve(item.url);
          } else {
            reject(new Error("上传接口返回异常"));
          }
        } catch (error) {
          reject(error);
        }
      };
      request.onerror = function () { reject(new Error("上传请求失败")); };
      request.send(body);
    });
  }

  function replaceDataUris(html) {
    var attributes = html.match(/src="data:image\/[^"]+"/g) || [];
    return attributes.reduce(function (chain, attribute) {
      return chain.then(function (result) {
        return uploadDataUri(attribute.slice(5, -1)).then(function (url) {
          return result.split(attribute).join('src="' + url + '"');
        }).catch(function () {
          // 上传失败就把这张图去掉，避免插进去保存时又静默消失
          return result.split(attribute).join('data-upload-failed="1"');
        });
      });
    }, Promise.resolve(html));
  }

  function bindPaste(container) {
    if (!uploadUrl) {
      return;
    }
    // 挂在容器的捕获阶段：先于编辑器自己的粘贴处理执行，处理完阻断事件
    container.addEventListener("paste", function (event) {
      var clipboard = event.clipboardData;
      if (!clipboard) {
        return;
      }
      var html = clipboard.getData("text/html") || "";
      if (html.indexOf("data:image/") === -1) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      replaceDataUris(html).then(function (cleaned) {
        editor.$.html.insert(cleaned);
        syncToTextarea(editor.$.html.get());
      });
    }, true);
  }

  /* ------------------------------------------------ 就绪后接线 */

  function setup(container) {
    // SunEditor 从目标元素取初始内容，而正文在 textarea 里，所以要显式灌进去
    editor.$.html.set(textarea.value);
    // textarea 被隐藏，正文区的可访问名称落到编辑区上
    var editable = container.querySelector('[contenteditable="true"]');
    if (editable) {
      editable.setAttribute("aria-label", "正文");
    }
    bindPaste(container);

    layoutFields(container, true);

    if (toggle) {
      toggle.hidden = false;
      toggle.textContent = "切到源码";
      toggle.addEventListener("click", function () {
        if (container.hidden) {
          editor.$.html.set(textarea.value);
          container.hidden = false;
          textarea.hidden = true;
          toggle.textContent = "切到源码";
          layoutFields(container, true);
          return;
        }
        textarea.value = editor.$.html.get();
        textarea.hidden = false;
        container.hidden = true;
        toggle.textContent = "回到富文本";
        layoutFields(container, false);
      });
    }

    // 初始化成功才隐藏 textarea，失败时保持原样
    textarea.hidden = true;
    container.hidden = false;
    mount.setAttribute("data-editor-ready", "1");

    // 提交前确保把编辑器里的最新内容写回表单字段
    form.addEventListener("submit", function () {
      syncToTextarea(editor.$.html.get());
    });
  }

  var attempts = 0;
  (function waitForContainer() {
    var container = mount.parentElement ? mount.parentElement.querySelector(".se-container") : null;
    if (container) {
      setup(container);
      return;
    }
    if (attempts >= 50) {
      warn("正文编辑器没有渲染出容器，已退回纯文本框。");
      showFallbackNotice(new Error("编辑器容器未渲染"));
      textarea.hidden = false;
      return;
    }
    attempts += 1;
    window.setTimeout(waitForContainer, 100);
  })();

  window.AdminEditor = {
    editor: function () { return editor; },
    getContent: function () { return editor.$.html.get(); },
    setContent: function (html) { editor.$.html.set(html); },
  };
})();
