/**
 * Gyanam Portal — Dashboard Interactions
 */
document.addEventListener('DOMContentLoaded', () => {

    /* ---- Sidebar Toggle (Mobile) ---- */
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.querySelector('.sidebar-overlay');
    const hamburger = document.getElementById('hamburgerBtn');

    if (hamburger) {
        hamburger.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        });
    }
    if (overlay) {
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });
    }

    /* ---- Sidebar Collapse (Desktop) ---- */
    const collapseBtn = document.getElementById('sidebarCollapseBtn');
    if (sidebar && collapseBtn) {
        // Restore saved state
        if (localStorage.getItem('sidebar-collapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }

        collapseBtn.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebar-collapsed', isCollapsed);
        });
    }

    /* ---- Count-Up Animation for Stat Values ---- */
    const statValues = document.querySelectorAll('.stat-value[data-count]');
    const animateCount = (el) => {
        const target = parseInt(el.dataset.count, 10);
        const duration = 1200;
        const startTime = performance.now();

        const update = (now) => {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            // Ease out cubic
            const eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = Math.round(eased * target).toLocaleString();
            if (progress < 1) requestAnimationFrame(update);
        };
        requestAnimationFrame(update);
    };

    // Use IntersectionObserver for lazy animation
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    animateCount(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.3 });

        statValues.forEach(el => observer.observe(el));
    } else {
        statValues.forEach(animateCount);
    }

    /* ---- Active Nav Link ---- */
    const currentPath = window.location.pathname;
    document.querySelectorAll('.nav-link').forEach(link => {
        if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href').replace('..', ''))) {
            link.classList.add('active');
        }
    });

    /* ---- Profile Dropdown ---- */
    const profileDropdown = document.getElementById('profileDropdown');
    const profileTrigger = document.getElementById('profileTrigger');

    if (profileTrigger && profileDropdown) {
        profileTrigger.addEventListener('click', (e) => {
            e.stopPropagation();
            profileDropdown.classList.toggle('open');
        });

        // Close on outside click
        document.addEventListener('click', (e) => {
            if (!profileDropdown.contains(e.target)) {
                profileDropdown.classList.remove('open');
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                profileDropdown.classList.remove('open');
            }
        });
    }

    /* ---- Logout Confirmation (Custom Modal) ---- */
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const logoutURL = logoutBtn.getAttribute('href');

            // Create modal
            const overlay = document.createElement('div');
            overlay.className = 'confirm-overlay';
            overlay.innerHTML = `
                <div class="confirm-modal">
                    <div class="confirm-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                    </div>
                    <h3>Log Out</h3>
                    <p>Are you sure you want to log out of your account?</p>
                    <div class="confirm-actions">
                        <button class="confirm-btn cancel" id="confirmCancel">Cancel</button>
                        <button class="confirm-btn danger" id="confirmLogout">Yes, Log Out</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);

            // Animate in
            requestAnimationFrame(() => overlay.classList.add('active'));

            // Cancel
            const cancel = () => {
                overlay.classList.remove('active');
                setTimeout(() => overlay.remove(), 250);
            };

            overlay.querySelector('#confirmCancel').addEventListener('click', cancel);
            overlay.addEventListener('click', (ev) => { if (ev.target === overlay) cancel(); });
            document.addEventListener('keydown', function esc(ev) {
                if (ev.key === 'Escape') { cancel(); document.removeEventListener('keydown', esc); }
            });

            // Confirm logout
            overlay.querySelector('#confirmLogout').addEventListener('click', () => {
                window.location.href = logoutURL;
            });
        });
    }

    /* ---- Cross-page toast + background upload pill (training videos, etc.) ---- */
    (function initGyanamCrossPageToast() {
        const KEY_TOAST = 'gyanam_admin_toast';
        const KEY_STATUS = 'gyanam_upload_status';
        const CHANNEL = 'gyanam-admin';
        let lastToastTs = 0;

        function ensureToastStyles() {
            if (document.getElementById('gyanam-global-toast-css')) return;
            const s = document.createElement('style');
            s.id = 'gyanam-global-toast-css';
            s.textContent = `
              .gyanam-global-toast{position:fixed;bottom:1.25rem;right:1.25rem;z-index:99999;display:flex;align-items:center;gap:.65rem;
                padding:.85rem 1.1rem;border-radius:14px;background:#0f172a;color:#fff;font:700 .85rem/1.35 Sora,system-ui,sans-serif;
                box-shadow:0 16px 40px rgba(15,23,42,.35);max-width:min(420px,92vw);animation:gyanamToastIn .28s ease}
              .gyanam-global-toast.ok{background:linear-gradient(135deg,#065f46,#047857)}
              .gyanam-global-toast.err{background:linear-gradient(135deg,#9f1239,#e11d48)}
              .gyanam-upload-pill{position:fixed;bottom:1.25rem;left:1.25rem;z-index:99998;display:none;align-items:center;gap:.7rem;
                padding:.7rem 1rem;border-radius:999px;background:#111827;color:#f8fafc;font:700 .78rem/1.2 Sora,system-ui,sans-serif;
                box-shadow:0 12px 30px rgba(0,0,0,.28);cursor:pointer;max-width:min(360px,90vw)}
              .gyanam-upload-pill .bar{flex:1;min-width:70px;height:6px;border-radius:99px;background:rgba(255,255,255,.15);overflow:hidden}
              .gyanam-upload-pill .fill{height:100%;width:0;background:linear-gradient(90deg,#818cf8,#22d3ee);border-radius:99px}
              @keyframes gyanamToastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
            `;
            document.head.appendChild(s);
        }

        function showToast(message, success) {
            if (!message) return;
            ensureToastStyles();
            document.querySelectorAll('.gyanam-global-toast').forEach(n => n.remove());
            const d = document.createElement('div');
            d.className = 'gyanam-global-toast ' + (success ? 'ok' : 'err');
            d.innerHTML = '<span>' + (success ? '✅' : '❌') + '</span><span></span>';
            d.lastChild.textContent = message;
            document.body.appendChild(d);
            setTimeout(() => d.remove(), 5500);
        }

        function handleToastPayload(raw) {
            let data = raw;
            if (typeof raw === 'string') {
                try { data = JSON.parse(raw); } catch (e) { return; }
            }
            if (!data || !data.ts || data.ts <= lastToastTs) return;
            lastToastTs = data.ts;
            showToast(data.message || 'Done', !!data.success);
            try { localStorage.removeItem(KEY_TOAST); } catch (e) {}
        }

        function ensurePill() {
            let pill = document.getElementById('gyanamUploadPill');
            if (pill) return pill;
            ensureToastStyles();
            pill = document.createElement('div');
            pill.id = 'gyanamUploadPill';
            pill.className = 'gyanam-upload-pill';
            pill.innerHTML = '<span id="gyanamUploadPillText">Uploading…</span><span class="bar"><span class="fill" id="gyanamUploadPillFill"></span></span>';
            pill.title = 'Open background upload window';
            pill.addEventListener('click', () => {
                try {
                    const w = window.open('training_upload_bg.php', 'gyanamTrainingUpload');
                    if (w) w.focus();
                } catch (e) {}
            });
            document.body.appendChild(pill);
            return pill;
        }

        function updatePill(data) {
            if (!data || !data.active) {
                const pill = document.getElementById('gyanamUploadPill');
                if (pill) pill.style.display = 'none';
                return;
            }
            const pill = ensurePill();
            pill.style.display = 'flex';
            const pct = Math.max(0, Math.min(100, data.pct | 0));
            const fill = document.getElementById('gyanamUploadPillFill');
            const text = document.getElementById('gyanamUploadPillText');
            if (fill) fill.style.width = pct + '%';
            if (text) text.textContent = (data.title ? (String(data.title).slice(0, 28) + ' · ') : '') + pct + '%';
        }

        function readStatus() {
            try {
                const raw = localStorage.getItem(KEY_STATUS);
                if (!raw) { updatePill({ active: false }); return; }
                const data = JSON.parse(raw);
                // stale > 2 hours
                if (!data.ts || Date.now() - data.ts > 7200000) {
                    localStorage.removeItem(KEY_STATUS);
                    updatePill({ active: false });
                    return;
                }
                updatePill(data);
            } catch (e) {}
        }

        // Catch toast written while this page was loading / in another tab
        try {
            const pending = localStorage.getItem(KEY_TOAST);
            if (pending) handleToastPayload(pending);
        } catch (e) {}

        window.addEventListener('storage', (e) => {
            if (e.key === KEY_TOAST && e.newValue) handleToastPayload(e.newValue);
            if (e.key === KEY_STATUS) {
                if (!e.newValue) updatePill({ active: false });
                else {
                    try { updatePill(JSON.parse(e.newValue)); } catch (err) {}
                }
            }
        });

        try {
            const bc = new BroadcastChannel(CHANNEL);
            bc.addEventListener('message', (ev) => {
                if (!ev.data) return;
                if (ev.data.type === 'toast') handleToastPayload(ev.data.data);
                if (ev.data.type === 'upload_status') updatePill(ev.data.data || { active: false });
            });
        } catch (e) {}

        readStatus();
        setInterval(readStatus, 2000);
    })();
});
