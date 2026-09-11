/**
 * Gyanam — shared live table filter (search + optional attribute filters).
 * Usage:
 *   GyanamLiveFilter({
 *     input: '#searchInput',
 *     button: '#searchBtn',
 *     tbody: 'table.data-table tbody',
 *     rowSelector: 'tr.live-row',
 *     countEl: '#listCount',
 *     countFormat: (n) => n + ' shown',
 *     searchParam: 'search', // URL query key (false to skip)
 *     emptyColspan: 8,
 *     emptyHtml: '<div class="empty-title">No matches</div>',
 *     filters: [
 *       { select: '#statusFilter', attr: 'data-status', param: 'status', allValue: 'all' }
 *     ],
 *     reloadSelects: ['#dlcFilter'], // full page reload on change
 *   });
 */
(function (global) {
  'use strict';

  function el(ref) {
    if (!ref) return null;
    return typeof ref === 'string' ? document.querySelector(ref) : ref;
  }

  function GyanamLiveFilter(opts) {
    opts = opts || {};
    const input = el(opts.input);
    const button = el(opts.button);
    const tbody = el(opts.tbody);
    const scope = el(opts.scope) || tbody;
    if (!scope) return null;

    const rowSelector = opts.rowSelector || 'tr.live-row';
    const countEl = el(opts.countEl);
    const countFormat = opts.countFormat || function (n) { return n + ' shown'; };
    const searchParam = opts.searchParam === false ? null : (opts.searchParam || 'search');
    const emptyColspan = opts.emptyColspan || 8;
    const emptyHtml = opts.emptyHtml || '<div style="text-align:center;padding:1.5rem;color:#94a3b8;font-weight:600">No matches found</div>';
    const filters = Array.isArray(opts.filters) ? opts.filters : [];
    const debounceMs = opts.debounceMs != null ? opts.debounceMs : 120;
    const hideClass = opts.hideClass || 'live-row-hidden';

    const rows = function () {
      return Array.from(scope.querySelectorAll(rowSelector));
    };

    let emptyRow = null;
    if (tbody && opts.emptyColspan !== false) {
      emptyRow = tbody.querySelector('tr.live-filter-empty');
      if (!emptyRow) {
        emptyRow = document.createElement('tr');
        emptyRow.className = 'live-filter-empty';
        emptyRow.innerHTML = '<td colspan="' + emptyColspan + '">' + emptyHtml + '</td>';
        emptyRow.hidden = true;
        tbody.appendChild(emptyRow);
      }
    }

    function syncUrl() {
      if (!searchParam && !filters.some(function (f) { return f.param; })) return;
      try {
        const url = new URL(window.location.href);
        if (searchParam) {
          const q = (input && input.value || '').trim();
          if (q) url.searchParams.set(searchParam, q);
          else url.searchParams.delete(searchParam);
        }
        filters.forEach(function (f) {
          if (!f.param) return;
          const sel = el(f.select);
          if (!sel) return;
          const allVal = f.allValue != null ? f.allValue : 'all';
          const v = sel.value;
          if (!v || v === allVal) url.searchParams.delete(f.param);
          else url.searchParams.set(f.param, v);
        });
        url.searchParams.delete('page');
        history.replaceState(null, '', url.pathname + url.search);
      } catch (e) { /* ignore */ }
    }

    function apply() {
      const q = ((input && input.value) || '').trim().toLowerCase();
      let visible = 0;
      rows().forEach(function (tr) {
        const hay = (tr.getAttribute('data-search') || '').toLowerCase();
        let show = !q || hay.indexOf(q) !== -1;
        if (show) {
          for (let i = 0; i < filters.length; i++) {
            const f = filters[i];
            const sel = el(f.select);
            if (!sel) continue;
            const allVal = f.allValue != null ? f.allValue : 'all';
            const want = sel.value;
            if (!want || want === allVal) continue;
            const got = (tr.getAttribute(f.attr) || '');
            if (String(got).toLowerCase() !== String(want).toLowerCase()) {
              show = false;
              break;
            }
          }
        }
        tr.classList.toggle(hideClass, !show);
        if (show) visible += 1;
      });
      if (countEl) countEl.textContent = countFormat(visible);
      if (emptyRow) emptyRow.hidden = rows().length === 0 || visible > 0;
      syncUrl();
      if (typeof opts.onApply === 'function') opts.onApply(visible);
    }

    let timer = null;
    function schedule() {
      clearTimeout(timer);
      timer = setTimeout(apply, debounceMs);
    }

    if (input) {
      input.addEventListener('input', schedule);
      input.addEventListener('search', apply);
    }
    if (button) {
      button.addEventListener('click', function () {
        apply();
        if (input) input.focus();
      });
    }

    filters.forEach(function (f) {
      const sel = el(f.select);
      if (!sel) return;
      sel.addEventListener('change', apply);
    });

    (opts.reloadSelects || []).forEach(function (ref) {
      const sel = el(ref);
      if (!sel) return;
      sel.addEventListener('change', function () {
        try {
          const url = new URL(window.location.href);
          const name = sel.getAttribute('name') || sel.id;
          if (name) {
            if (!sel.value || sel.value === 'all') url.searchParams.delete(name);
            else url.searchParams.set(name, sel.value);
          }
          if (searchParam && input) {
            const q = (input.value || '').trim();
            if (q) url.searchParams.set(searchParam, q);
            else url.searchParams.delete(searchParam);
          }
          url.searchParams.delete('page');
          window.location.href = url.pathname + url.search;
        } catch (e) {
          if (sel.form) sel.form.submit();
        }
      });
    });

    // Prevent GET form submit on Enter for search forms
    const form = opts.form ? el(opts.form) : (input && input.form);
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        apply();
      });
    }

    if ((input && (input.value || '').trim()) || filters.some(function (f) {
      const sel = el(f.select);
      const allVal = f.allValue != null ? f.allValue : 'all';
      return sel && sel.value && sel.value !== allVal;
    })) {
      apply();
    }

    return { apply: apply };
  }

  // Hide helper class (pages can also define their own)
  if (!document.getElementById('gyanam-live-filter-style')) {
    const style = document.createElement('style');
    style.id = 'gyanam-live-filter-style';
    style.textContent = '.live-row-hidden{display:none!important}';
    document.head.appendChild(style);
  }

  global.GyanamLiveFilter = GyanamLiveFilter;
})(window);
