/**
 * ModalService - Custom modal dialogs replacing browser alert/confirm
 * 
 * Provides beautiful, non-blocking confirmation and notification dialogs
 * that match the portal's design system.
 */

class ModalService {
    constructor() {
        this._ensureContainer();
    }

    _ensureContainer() {
        if (!document.getElementById('modal-overlay')) {
            const overlay = document.createElement('div');
            overlay.id = 'modal-overlay';
            overlay.style.cssText = `
        display:none; position:fixed; inset:0; z-index:9999;
        background:rgba(0,0,0,0.7); backdrop-filter:blur(4px);
        align-items:center; justify-content:center;
      `;
            overlay.innerHTML = `<div id="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title"></div>`;
            document.body.appendChild(overlay);
        }
    }

    _show(html) {
        this._ensureContainer();
        const overlay = document.getElementById('modal-overlay');
        const box = document.getElementById('modal-box');
        box.innerHTML = html;
        overlay.style.display = 'flex';
        // Animate in
        box.style.opacity = '0';
        box.style.transform = 'scale(0.92) translateY(12px)';
        box.style.transition = 'all 0.22s cubic-bezier(0.34,1.56,0.64,1)';
        requestAnimationFrame(() => {
            box.style.opacity = '1';
            box.style.transform = 'scale(1) translateY(0)';
        });
    }

    close() {
        this._hide();
    }

    _hide() {
        const overlay = document.getElementById('modal-overlay');
        const box = document.getElementById('modal-box');
        if (!overlay) return;
        box.style.opacity = '0';
        box.style.transform = 'scale(0.95) translateY(8px)';
        setTimeout(() => { overlay.style.display = 'none'; }, 200);
    }

    /**
     * Show a confirmation dialog with OK/Cancel
     * @param {string} message - Message to display
     * @param {Object} options - title, confirmText, cancelText, type
     * @returns {Promise<boolean>} Resolves true (confirmed) or false (cancelled)
     */
    confirm(message, options = {}) {
        const {
            title = 'Confirm Action',
            confirmText = 'Confirm',
            cancelText = 'Cancel',
            type = 'warning'
        } = options;

        const icons = {
            warning: `<svg class="modal-icon warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>`,
            danger: `<svg class="modal-icon danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9.303 3.376c.866 1.5-.217 3.374-1.948 3.374H4.645c-1.73 0-2.813-1.874-1.948-3.374L10.05 3.378c.866-1.5 3.032-1.5 3.898 0L21.303 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>`,
            info: `<svg class="modal-icon info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>`
        };

        const confirmClass = type === 'danger' ? 'modal-btn-danger' : 'modal-btn-confirm';

        return new Promise((resolve) => {
            this._show(`
        <div class="modal-card">
          <div class="modal-header">
            ${icons[type] || icons.warning}
            <h2 id="modal-title" class="modal-title">${title}</h2>
          </div>
          <p class="modal-message">${message}</p>
          <div class="modal-actions">
            <button id="modal-cancel" class="modal-btn modal-btn-cancel">${cancelText}</button>
            <button id="modal-confirm" class="modal-btn ${confirmClass}">${confirmText}</button>
          </div>
        </div>
      `);

            document.getElementById('modal-confirm').addEventListener('click', () => {
                this._hide(); resolve(true);
            });
            document.getElementById('modal-cancel').addEventListener('click', () => {
                this._hide(); resolve(false);
            });
        });
    }

    /**
     * Show an alert/notification dialog (OK only)
     * @param {string} message - Message to display
     * @param {Object} options - title, type (success|warning|danger|info)
     * @returns {Promise<void>}
     */
    alert(message, options = {}) {
        const {
            title = 'Notice',
            type = 'info',
            buttonText = 'OK'
        } = options;

        const colors = {
            success: '#10b981',
            warning: '#f59e0b',
            danger: '#ef4444',
            info: '#6366f1'
        };

        const icons = {
            success: `<svg class="modal-icon success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`,
            warning: `<svg class="modal-icon warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>`,
            danger: `<svg class="modal-icon danger" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01"/></svg>`,
            info: `<svg class="modal-icon info" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>`
        };

        return new Promise((resolve) => {
            this._show(`
        <div class="modal-card">
          <div class="modal-header">
            ${icons[type] || icons.info}
            <h2 id="modal-title" class="modal-title">${title}</h2>
          </div>
          <p class="modal-message">${message}</p>
          <div class="modal-actions">
            <button id="modal-ok" class="modal-btn modal-btn-confirm" style="min-width:120px">${buttonText}</button>
          </div>
        </div>
      `);

            document.getElementById('modal-ok').addEventListener('click', () => {
                this._hide(); resolve();
            });
        });
    }

    /**
     * Show a non-blocking toast notification
     * @param {string} message 
     * @param {string} type - success|error|warning|info
     */
    toast(message, type = 'info') {
        this._ensureContainer();
        const toast = document.createElement('div');
        toast.className = `portal-toast toast-${type}`;

        const icons = {
            success: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`,
            error: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`,
            warning: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>`,
            info: `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`
        };

        toast.innerHTML = `
            ${icons[type] || icons.info}
            <span>${message}</span>
        `;
        document.body.appendChild(toast);

        // Offset toasts if multiple exist
        const activeToasts = document.querySelectorAll('.portal-toast');
        const offset = (activeToasts.length - 1) * 60;
        toast.style.bottom = `${24 + offset}px`;

        requestAnimationFrame(() => toast.classList.add('visible'));

        setTimeout(() => {
            toast.classList.remove('visible');
            setTimeout(() => toast.remove(), 400);
        }, 3500);
    }

    /**
     * Show a security warning toast-style alert
     * @param {string} message 
     */
    securityWarning(message) {
        this.toast(message, 'warning');
    }
}

// Singleton instance
const modalService = new ModalService();
export default modalService;
export { ModalService };
