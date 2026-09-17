/* 建议承办单位选择器：可搜索的多选下拉（委员端填写页与后台调整提案页共用）
 *
 * 渐进增强：没有脚本或脚本失败时，页面上的原生多选 <select multiple> 照旧能用、照旧提交，
 * 这里只是把它替换成「输入关键词 → 下拉里勾选」的形态。原生 select 在接管后置为 disabled，
 * 值改由隐藏字段 units[] 提交，避免同一个字段名交两遍。
 */
(function () {
  "use strict";

  var pickers = document.querySelectorAll("[data-unit-picker]");
  if (!pickers.length) {
    return;
  }
  Array.prototype.forEach.call(pickers, init);

  function init(root) {
    var select = root.querySelector("select[data-unit-native]");
    var search = root.querySelector("[data-unit-search]");
    var panel = root.querySelector("[data-unit-panel]");
    var list = root.querySelector("[data-unit-list]");
    var values = root.querySelector("[data-unit-values]");
    var empty = root.querySelector("[data-unit-empty]");
    var hint = root.querySelector("[data-unit-hint]");
    var field = root.querySelector("[data-unit-field]");
    if (!select || !search || !panel || !list || !values || !field) {
      return;
    }

    var max = parseInt(root.getAttribute("data-max") || "5", 10);
    var options = Array.prototype.map.call(select.options, function (option) {
      return { value: option.value, label: option.textContent.trim() };
    });
    var selected = options.filter(function (item, index) {
      return select.options[index].selected;
    }).map(function (item) { return item.value; });
    var matches = [];
    var active = -1;

    select.disabled = true;
    select.hidden = true;
    if (!panel.id) {
      panel.id = "unit-panel-" + Math.random().toString(36).slice(2, 8);
    }
    search.setAttribute("role", "combobox");
    search.setAttribute("aria-expanded", "false");
    search.setAttribute("aria-controls", panel.id);

    function isSelected(value) {
      return selected.indexOf(value) !== -1;
    }

    function toggle(value) {
      var index = selected.indexOf(value);
      if (index === -1) {
        if (selected.length >= max) {
          return;
        }
        selected.push(value);
      } else {
        selected.splice(index, 1);
      }
      render();
      search.focus();
    }

    function render() {
      // 隐藏字段：真正提交的就是这几个 units[]
      values.innerHTML = "";
      selected.forEach(function (value) {
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = "units[]";
        input.value = value;
        values.appendChild(input);
      });
      renderChips();
      renderList(search.value);
      if (hint) {
        hint.textContent = "已选 " + selected.length + " / " + max +
          (selected.length >= max ? "（已达上限，如需更换请先取消已选的单位）" : "");
      }
    }

    function renderChips() {
      Array.prototype.forEach.call(root.querySelectorAll(".unit-picker-chip"), function (chip) {
        chip.parentNode.removeChild(chip);
      });
      selected.forEach(function (value) {
        var item = options.filter(function (option) { return option.value === value; })[0];
        if (!item) {
          return;
        }
        var chip = document.createElement("span");
        chip.className = "unit-picker-chip";
        chip.appendChild(document.createTextNode(item.label));
        var remove = document.createElement("button");
        remove.type = "button";
        remove.setAttribute("aria-label", "取消选择 " + item.label);
        remove.appendChild(document.createTextNode("×"));
        remove.addEventListener("click", function (event) {
          event.stopPropagation();
          toggle(value);
        });
        chip.appendChild(remove);
        field.insertBefore(chip, search);
      });
      search.placeholder = selected.length === 0
        ? (root.getAttribute("data-placeholder") || "输入关键词搜索，点开选择")
        : "继续搜索";
    }

    function renderList(keyword) {
      var text = (keyword || "").trim().toLowerCase();
      matches = options.filter(function (item) {
        return text === "" || item.label.toLowerCase().indexOf(text) !== -1;
      });
      list.innerHTML = "";
      active = -1;
      matches.forEach(function (item, index) {
        var taken = isSelected(item.value);
        var disabled = !taken && selected.length >= max;
        var li = document.createElement("li");
        li.className = "unit-picker-option";
        li.setAttribute("role", "option");
        li.setAttribute("aria-selected", taken ? "true" : "false");
        if (disabled) {
          li.setAttribute("aria-disabled", "true");
        }
        li.setAttribute("data-unit-value", item.value);
        var mark = document.createElement("span");
        mark.className = "unit-picker-mark";
        mark.textContent = taken ? "✓" : "";
        li.appendChild(mark);
        li.appendChild(document.createTextNode(item.label));
        li.addEventListener("click", function () {
          if (disabled) {
            return;
          }
          toggle(item.value);
        });
        li.addEventListener("mousemove", function () {
          setActive(index);
        });
        list.appendChild(li);
      });
      if (empty) {
        empty.hidden = matches.length > 0;
      }
    }

    function setActive(index) {
      active = index;
      Array.prototype.forEach.call(list.children, function (node, position) {
        node.classList.toggle("is-active", position === index);
      });
      if (index >= 0 && list.children[index]) {
        search.setAttribute("aria-activedescendant", "unit-option-" + index);
        list.children[index].id = "unit-option-" + index;
      }
    }

    function open() {
      panel.hidden = false;
      root.classList.add("is-open");
      search.setAttribute("aria-expanded", "true");
      renderList(search.value);
    }

    function close() {
      panel.hidden = true;
      root.classList.remove("is-open");
      search.setAttribute("aria-expanded", "false");
      search.removeAttribute("aria-activedescendant");
    }

    field.addEventListener("click", function () {
      search.focus();
      open();
    });
    search.addEventListener("focus", open);
    search.addEventListener("input", function () {
      open();
      renderList(search.value);
    });
    search.addEventListener("keydown", function (event) {
      if (event.key === "ArrowDown" || event.key === "ArrowUp") {
        event.preventDefault();
        if (panel.hidden) {
          open();
        }
        if (matches.length === 0) {
          return;
        }
        var next = event.key === "ArrowDown" ? active + 1 : active - 1;
        if (next < 0) {
          next = matches.length - 1;
        }
        if (next >= matches.length) {
          next = 0;
        }
        setActive(next);
        return;
      }
      if (event.key === "Enter") {
        if (!panel.hidden && active >= 0 && matches[active]) {
          event.preventDefault();
          toggle(matches[active].value);
        }
        return;
      }
      if (event.key === "Escape") {
        close();
        return;
      }
      // 退格清空输入后，已选不该被误删：删除走标签上的 ×
      if (event.key === "Backspace" && search.value === "" && selected.length > 0) {
        event.preventDefault();
      }
    });
    document.addEventListener("click", function (event) {
      // 点中的选项在 render() 里被重建后已脱离文档，这时 contains() 会误判成「点了外面」
      if (event.target instanceof Node && !event.target.isConnected) {
        return;
      }
      if (!root.contains(event.target)) {
        close();
      }
    });

    render();
    close();
  }
})();
