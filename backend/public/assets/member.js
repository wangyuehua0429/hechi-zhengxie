/* 政协委员提案门户 · 渐进增强脚本（零依赖）
 *
 *  1. 正文与案由的字数提示：上限从模板的 data-count-max 取，与后端校验同源。
 *  2. 新建提案的本地草稿：输入即存 localStorage，页面重新打开时可一键恢复。
 *     草稿按委员分键（模板给 data-draft-key），且只存本人改动过的字段——
 *     预填的姓名、联系电话等未改动的初始值不入库，共用电脑上不串号、少留个人信息。
 *
 * 页面在不加载本文件时功能完整（只是没有字数提示与草稿），因此不上线也不影响提交。
 * 与后台一样受 CSP 约束（script-src 'self'），不引外部脚本。
 */
(function () {
  'use strict';

  const DRAFT_TTL = 24 * 60 * 60 * 1000;
  const DRAFT_KEY = 'hechi:member:proposal-draft:v1';

  /* ---------- 字数提示 ---------- */
  function initCounters() {
    document.querySelectorAll('[data-count-for]').forEach(function (badge) {
      const field = document.getElementById(badge.getAttribute('data-count-for'));
      if (!field) return;
      const max = parseInt(badge.getAttribute('data-count-max'), 10) || 0;

      function render() {
        const len = Array.from(field.value).length;
        badge.textContent = len + ' / ' + max;
        badge.classList.toggle('m-count-over', len > max);
      }

      field.addEventListener('input', render);
      render();
    });
  }

  /* ---------- 本地草稿 ---------- */
  function pad(n) {
    return (n < 10 ? '0' : '') + n;
  }

  function draftFields(form) {
    return Array.prototype.filter.call(form.elements, function (el) {
      if (!el.name || el.name === '_token') return false;
      return el.type !== 'file' && el.type !== 'submit' && el.type !== 'button';
    });
  }

  function initDraft(form) {
    const notice = document.getElementById('mDraftNotice');
    if (!notice) return;

    const key = form.getAttribute('data-draft-key') || DRAFT_KEY;
    const fields = draftFields(form);
    // 服务端预填的初始值：与初始值相同说明委员没动过，不写进草稿
    const initial = {};
    fields.forEach(function (el) { initial[el.name] = el.value; });
    let timer = null;

    function read() {
      try {
        const raw = window.localStorage.getItem(key);
        if (!raw) return null;
        const draft = JSON.parse(raw);
        if (!draft || !draft.fields) return null;
        return Date.now() - (draft.savedAt || 0) > DRAFT_TTL ? null : draft;
      } catch (e) {
        return null;
      }
    }

    function clear() {
      try { window.localStorage.removeItem(key); } catch (e) { /* 隐私模式忽略 */ }
    }

    function save() {
      const data = {};
      let changed = false;
      fields.forEach(function (el) {
        if (el.value === initial[el.name]) return;
        data[el.name] = el.value;
        changed = true;
      });
      try {
        if (!changed) { clear(); return; }
        window.localStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), fields: data }));
      } catch (e) { /* 隐私模式忽略 */ }
    }

    form.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(save, 800);
    });

    // 能走到提交说明浏览器校验已通过；草稿使命结束，避免下次新建时误提示
    form.addEventListener('submit', clear);

    const draft = read();
    if (!draft) return;

    const differs = fields.some(function (el) {
      return Object.prototype.hasOwnProperty.call(draft.fields, el.name)
        && draft.fields[el.name] !== el.value;
    });
    if (!differs) return;

    const stamp = document.getElementById('mDraftTime');
    if (stamp) {
      const when = new Date(draft.savedAt);
      stamp.textContent = '保存于 ' + when.getFullYear() + '-' + pad(when.getMonth() + 1) + '-' + pad(when.getDate())
        + ' ' + pad(when.getHours()) + ':' + pad(when.getMinutes()) + '。';
    }
    notice.hidden = false;

    document.getElementById('mDraftRestore').addEventListener('click', function () {
      fields.forEach(function (el) {
        if (Object.prototype.hasOwnProperty.call(draft.fields, el.name)) {
          el.value = draft.fields[el.name];
          el.dispatchEvent(new Event('input', { bubbles: true }));
        }
      });
      notice.hidden = true;
      form.querySelector('input, select, textarea').focus();
    });

    document.getElementById('mDraftDiscard').addEventListener('click', function () {
      clear();
      notice.hidden = true;
    });
  }

  function init() {
    initCounters();
    const form = document.querySelector('form.m-form-wide');
    if (form) initDraft(form);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}());
