/**
 * Gyanam — shared live table filter (search + optional attribute filters).
 *
 * Client mode (default) — filter rows already on the page:
 *   GyanamLiveFilter({ input, tbody, rowSelector, filters, ... });
 *
 * Server mode — debounce then GET-reload (for paginated / heavy lists):
 *   GyanamLiveFilter({
 *     mode: 'server',
 *     input: '#searchInput',
 *     button: '#searchBtn',
 *     form: '#filterForm',          // optional GET form
 *     searchParam: 'search',
 *     debounceMs: 400,
 *     minChars: 0,                  // reload even when cleared
 *     params: { status: 'Active' }, // extra static params
 *     keepParams: ['status','course','fees'], // preserve from current URL / form
 *     reloadSelects: ['#course','#fees'],     // change → immediate reload
 *     loadingClass: 'is-searching',
 *   });
 */
(function (global) {
  'use strict';

  function el(ref) {
    if (!ref) return null;
    return typeof ref === 'string' ? document.querySelector(ref) : ref;
  }

  function navigateWithParams(opts, inputValue) {
    try {
      const url = new URL(window.location.href);
      const searchParam = opts.searchParam === false ? null : (opts.searchParam || 'search');
      const form = opts.form ? el(opts.form) : (el(opts.input) && el(opts.input).form);

      // Start from current query, then overlay form fields + opts
      if (form) {
        Array.from(form.elements || []).forEach(function (field) {
          if (!field.name || field.disabled) return;
          if (field.type === 'submit' || field.type === 'button' || field.type === 'file') return;
          if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
          if (searchParam && field.name === searchParam) return; // set below
          const v = (field.value || '').trim();
          const allVal = opts.allValue != null ? opts.allValue : 'all';
          if (!v || v === allVal) url.searchParams.delete(field.name);
          else url.searchParams.set(field.name, v);
        });
      }

      (opts.keepParams || []).forEach(function (key) {
        const fromUrl = new URL(window.location.href).searchParams.get(key);
        if (fromUrl != null && fromUrl !== '' && !url.searchParams.has(key)) {
          url.searchParams.set(key, fromUrl);
        }
      });

      if (opts.params && typeof opts.params === 'object') {
        Object.keys(opts.params).forEach(function (k) {
          const v = opts.params[k];
          if (v == null || v === '' || v === 'all') url.searchParams.delete(k);
          else url.searchParams.set(k, String(v));
        });
      }

      if (searchParam) {
        const q = (inputValue != null ? inputValue : ((el(opts.input) && el(opts.input).value) || '')).trim();
        if (q) url.searchParams.set(searchParam, q);
        else url.searchParams.delete(searchParam);
      }

      url.searchParams.delete('page');
      const next = url.pathname + url.search;
      const cur = window.location.pathname + window.location.search;
      if (next === cur) return false;
      window.location.href = next;
      return true;
    } catch (e) {
      const form = opts.form ? el(opts.form) : (el(opts.input) && el(opts.input).form);
      if (form) {
        form.submit();
        return true;
      }
      return false;
    }
  }

  function setLoading(opts, on) {
    const input = el(opts.input);
    const cls = opts.loadingClass || 'is-searching';
    if (input) input.classList.toggle(cls, !!on);
    if (opts.loadingEl) {
      const le = el(opts.loadingEl);
      if (le) le.hidden = !on;
    }
    document.documentElement.classList.toggle('gy-live-searching', !!on);
  }

  function GyanamLiveFilter(opts) {
    opts = opts || {};
    if (opts.mode === 'server') {
      return GyanamServerLiveFilter(opts);
    }
    return GyanamClientLiveFilter(opts);
  }

  function GyanamServerLiveFilter(opts) {
    const input = el(opts.input);
    const button = el(opts.button);
    const form = opts.form ? el(opts.form) : (input && input.form);
    const debounceMs = opts.debounceMs != null ? opts.debounceMs : 400;
    const minChars = opts.minChars != null ? opts.minChars : 0;
    let timer = null;
    let lastSent = input ? String(input.value || '').trim() : '';

    function go(force) {
      const q = input ? String(input.value || '').trim() : '';
      if (!force && q === lastSent) return;
      if (q.length > 0 && q.length < minChars) return;
      lastSent = q;
      setLoading(opts, true);
      if (!navigateWithParams(opts, q)) setLoading(opts, false);
    }

    function schedule() {
      clearTimeout(timer);
      timer = setTimeout(function () { go(false); }, debounceMs);
    }

    if (input) {
      input.setAttribute('autocomplete', input.getAttribute('autocomplete') || 'off');
      input.addEventListener('input', schedule);
      input.addEventListener('search', function () { clearTimeout(timer); go(true); });
    }
    if (button) {
      button.addEventListener('click', function (e) {
        e.preventDefault();
        clearTimeout(timer);
        go(true);
      });
    }
    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearTimeout(timer);
        go(true);
      });
    }

    (opts.reloadSelects || []).forEach(function (ref) {
      const sel = el(ref);
      if (!sel) return;
      sel.addEventListener('change', function () {
        clearTimeout(timer);
        go(true);
      });
    });

    return { apply: function () { go(true); }, navigate: go };
  }

  function GyanamClientLiveFilter(opts) {
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

  if (!document.getElementById('gyanam-live-filter-style')) {
    const style = document.createElement('style');
    style.id = 'gyanam-live-filter-style';
    style.textContent = [
      '.live-row-hidden{display:none!important}',
      'input.is-searching,input.gy-live-searching{opacity:.72}',
      'html.gy-live-searching{cursor:progress}',
    ].join('');
    document.head.appendChild(style);
  }

  global.GyanamLiveFilter = GyanamLiveFilter;
})(window);
