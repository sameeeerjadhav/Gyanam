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
});
