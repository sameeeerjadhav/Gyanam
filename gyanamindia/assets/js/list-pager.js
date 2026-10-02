/**
 * Client-side list pager for ATC pages that already render the full list.
 * Search and status filters stay in the page; call pager.refresh(true) after they change.
 */
(function () {
    var cssDone = false;

    function ensureCss() {
        if (cssDone) return;
        cssDone = true;
        var style = document.createElement('style');
        style.id = 'gyanam-list-pager-css';
        style.textContent = [
            '.gy-list-pager{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin:.85rem 0 0;padding:.9rem 1.1rem;background:#fff;border:1.5px solid #e5e7eb;border-radius:12px;box-sizing:border-box}',
            '.gy-list-pager-info{font-size:.8rem;color:#6b7280;font-weight:600;line-height:1.4}',
            '.gy-list-pager-info strong{color:#1f2937;font-weight:800}',
            '.gy-list-pager-controls{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-left:auto}',
            '.gy-list-pager .pager-btn{display:inline-flex;align-items:center;justify-content:center;min-width:36px;height:36px;padding:0 .75rem;border-radius:9px;border:1.5px solid #e5e7eb;background:#fff;color:#374151;font-size:.78rem;font-weight:700;font-family:inherit;line-height:1;box-sizing:border-box;cursor:pointer}',
            '.gy-list-pager .pager-btn:hover{border-color:#a5b4fc;background:#eef2ff;color:#3730a3}',
            '.gy-list-pager .pager-btn.active{background:linear-gradient(135deg,#4361ee,#3730a3);border-color:#3730a3;color:#fff;box-shadow:0 3px 10px rgba(67,97,238,.25);cursor:default}',
            '.gy-list-pager .pager-btn:disabled{opacity:.42;cursor:not-allowed;background:#f8fafc;color:#9ca3af}',
            '.gy-list-pager .pager-ellipsis{color:#9ca3af;font-weight:700;padding:0 .15rem}',
            '@media (max-width:640px){.gy-list-pager{justify-content:center}.gy-list-pager-info{width:100%;text-align:center}.gy-list-pager-controls{margin-left:0;justify-content:center}}'
        ].join('');
        document.head.appendChild(style);
    }

    function rowsOf(selector) {
        return Array.prototype.filter.call(document.querySelectorAll(selector), function (row) {
            return !row.querySelector('td[colspan]');
        });
    }

    window.initListPager = function (cfg) {
        ensureCss();
        var pageSize = cfg.pageSize || 20;
        var label = cfg.label || 'records';
        var page = 1;
        var mount = typeof cfg.mount === 'string' ? document.querySelector(cfg.mount) : cfg.mount;
        if (!mount) {
            return { refresh: function () {}, go: function () {} };
        }

        var bar = document.createElement('div');
        bar.className = 'gy-list-pager';
        bar.setAttribute('role', 'navigation');
        bar.setAttribute('aria-label', 'Pagination');
        bar.style.display = 'none';
        mount.insertAdjacentElement('afterend', bar);

        function matched() {
            return rowsOf(cfg.rows).filter(function (row) {
                return cfg.match ? !!cfg.match(row) : true;
            });
        }

        function setRow(row, on) {
            row.style.display = on ? '' : 'none';
            if (!cfg.companion) return;
            var extra = cfg.companion(row);
            if (!extra) return;
            extra.style.display = on ? '' : 'none';
        }

        function button(text, opts) {
            var el = document.createElement('button');
            el.type = 'button';
            el.className = 'pager-btn' + (opts.active ? ' active' : '');
            el.textContent = text;
            if (opts.disabled) el.disabled = true;
            if (opts.current) el.setAttribute('aria-current', 'page');
            if (opts.go) {
                el.addEventListener('click', function () { go(opts.go); });
            }
            return el;
        }

        function renderBar(total, pages) {
            bar.innerHTML = '';
            if (total <= pageSize) {
                bar.style.display = 'none';
                return;
            }
            bar.style.display = '';
            var from = (page - 1) * pageSize + 1;
            var to = Math.min(total, page * pageSize);
            var info = document.createElement('div');
            info.className = 'gy-list-pager-info';
            info.innerHTML = 'Showing <strong>' + from + '–' + to + '</strong> of <strong>' + total.toLocaleString() + '</strong> ' + label;
            var controls = document.createElement('div');
            controls.className = 'gy-list-pager-controls';
            controls.appendChild(button('‹ Prev', { disabled: page <= 1, go: page - 1 }));

            var windowSize = 2;
            var start = Math.max(1, page - windowSize);
            var end = Math.min(pages, page + windowSize);
            if (start > 1) {
                controls.appendChild(button('1', { go: 1 }));
                if (start > 2) {
                    var dots = document.createElement('span');
                    dots.className = 'pager-ellipsis';
                    dots.textContent = '…';
                    controls.appendChild(dots);
                }
            }
            for (var i = start; i <= end; i++) {
                controls.appendChild(button(String(i), { active: i === page, current: i === page, go: i === page ? null : i }));
            }
            if (end < pages) {
                if (end < pages - 1) {
                    var dots2 = document.createElement('span');
                    dots2.className = 'pager-ellipsis';
                    dots2.textContent = '…';
                    controls.appendChild(dots2);
                }
                controls.appendChild(button(String(pages), { go: pages }));
            }
            controls.appendChild(button('Next ›', { disabled: page >= pages, go: page + 1 }));
            bar.appendChild(info);
            bar.appendChild(controls);
        }

        function apply() {
            var all = rowsOf(cfg.rows);
            var hit = matched();
            var pages = Math.max(1, Math.ceil(hit.length / pageSize));
            if (page > pages) page = pages;
            if (page < 1) page = 1;
            var start = (page - 1) * pageSize;
            var visible = new Set(hit.slice(start, start + pageSize));
            all.forEach(function (row) { setRow(row, visible.has(row)); });
            renderBar(hit.length, pages);
            if (typeof cfg.onApply === 'function') cfg.onApply(hit.length);
        }

        function go(next) {
            page = next;
            apply();
        }

        apply();
        return {
            refresh: function (reset) {
                if (reset) page = 1;
                apply();
            },
            go: go
        };
    };
})();
