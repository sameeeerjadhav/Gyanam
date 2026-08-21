/**
 * Gyanam Portal — Login Page Interactions v3.0
 * Clean, focused UX interactions
 */
document.addEventListener('DOMContentLoaded', () => {

    /* ---- Role Selector ---- */
    const roleBtns = document.querySelectorAll('.role-btn');
    const roleInput = document.getElementById('role');

    roleBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            roleBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            roleInput.value = btn.dataset.role;

            // Focus the username field after selecting role
            const usernameField = document.getElementById('username');
            if (usernameField && !usernameField.value) {
                setTimeout(() => usernameField.focus(), 200);
            }
        });
    });

    /* ---- Toggle Password ---- */
    const toggleBtn = document.getElementById('togglePassword');
    const pwdInput = document.getElementById('password');

    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', () => {
            const isPassword = pwdInput.type === 'password';
            pwdInput.type = isPassword ? 'text' : 'password';
            toggleBtn.innerHTML = isPassword
                ? `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>`
                : `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`;
        });
    }

    /* ---- Form Submit ---- */
    const form = document.getElementById('loginForm');
    const submitBtn = document.getElementById('loginBtn');

    if (form) {
        form.addEventListener('submit', (e) => {
            const username = document.getElementById('username').value.trim();
            const password = pwdInput.value.trim();
            const role = roleInput.value;

            if (!role) {
                e.preventDefault();
                showError('Please select your role to continue.');
                highlightRoles();
                return;
            }
            if (!username) {
                e.preventDefault();
                showError('Please enter your username.');
                document.getElementById('username').focus();
                return;
            }
            if (!password) {
                e.preventDefault();
                showError('Please enter your password.');
                pwdInput.focus();
                return;
            }

            submitBtn.classList.add('loading');
        });
    }

    /* ---- Highlight role buttons when not selected ---- */
    function highlightRoles() {
        roleBtns.forEach(btn => {
            btn.style.animation = 'shakeIn 0.5s ease';
            setTimeout(() => { btn.style.animation = ''; }, 500);
        });
    }

    /* ---- Ripple Effect ---- */
    if (submitBtn) {
        submitBtn.addEventListener('click', function (e) {
            const ripple = document.createElement('span');
            ripple.classList.add('ripple');
            const rect = this.getBoundingClientRect();
            const size = Math.max(rect.width, rect.height);
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
            ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
            this.appendChild(ripple);
            setTimeout(() => ripple.remove(), 700);
        });
    }

    /* ---- Error display helper ---- */
    function showError(msg) {
        let existing = document.querySelector('.alert-error');
        if (existing) existing.remove();

        const alert = document.createElement('div');
        alert.className = 'alert-error';
        alert.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>${msg}`;
        form.insertBefore(alert, form.firstChild);
    }

    /* ---- Auto-dismiss error after 5s ---- */
    const alertEl = document.querySelector('.alert-error');
    if (alertEl) {
        setTimeout(() => {
            alertEl.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            alertEl.style.opacity = '0';
            alertEl.style.transform = 'translateY(-10px)';
            setTimeout(() => alertEl.remove(), 500);
        }, 5000);
    }

    /* ---- Input focus effects ---- */
    document.querySelectorAll('.form-group input').forEach(input => {
        input.addEventListener('focus', () => {
            input.parentElement.classList.add('focused');
        });
        input.addEventListener('blur', () => {
            input.parentElement.classList.remove('focused');
        });
    });
});
