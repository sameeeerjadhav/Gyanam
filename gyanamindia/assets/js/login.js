/**
 * Gyanam Portal — Login interactions
 */
document.addEventListener('DOMContentLoaded', () => {
    const roleBtns = document.querySelectorAll('.role-btn');
    const roleInput = document.getElementById('role');
    const form = document.getElementById('loginForm');
    const submitBtn = document.getElementById('loginBtn');
    const pwdInput = document.getElementById('password');
    const toggleBtn = document.getElementById('togglePassword');

    roleBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            roleBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            roleInput.value = btn.dataset.role;
            const usernameField = document.getElementById('username');
            if (usernameField && !usernameField.value) {
                setTimeout(() => usernameField.focus(), 150);
            }
        });
    });

    if (toggleBtn && pwdInput) {
        toggleBtn.addEventListener('click', () => {
            const show = pwdInput.type === 'password';
            pwdInput.type = show ? 'text' : 'password';
            toggleBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            toggleBtn.innerHTML = show
                ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/></svg>'
                : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        });
    }

    function showError(msg) {
        const existing = document.querySelector('.alert-error');
        if (existing) existing.remove();
        const alert = document.createElement('div');
        alert.className = 'alert-error';
        alert.setAttribute('role', 'alert');
        alert.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>' + msg;
        form.insertBefore(alert, form.firstChild);
    }

    function highlightRoles() {
        roleBtns.forEach(btn => {
            btn.style.animation = 'shakeIn 0.45s ease';
            setTimeout(() => { btn.style.animation = ''; }, 450);
        });
    }

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
                showError('Please enter your User ID.');
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

    const alertEl = document.querySelector('.alert-error');
    if (alertEl) {
        setTimeout(() => {
            alertEl.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            alertEl.style.opacity = '0';
            alertEl.style.transform = 'translateY(-8px)';
            setTimeout(() => alertEl.remove(), 400);
        }, 5000);
    }
});
