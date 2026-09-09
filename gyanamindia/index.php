<?php
/**
 * Gyanam Portal — Login
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();

if (isLoggedIn()) {
    redirect(getDashboardURL(getUserRole()));
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);

$csrfToken = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gyanam India Educational Services — Secure login for Head Office, DLC, ATC and Training.">
    <title>Sign in — Gyanam India</title>
    <?php include __DIR__ . '/includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="assets/css/global.css">
    <link rel="stylesheet" href="assets/css/login.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>G</text></svg>">
</head>
<body>

<div class="login-wrapper">

    <aside class="login-brand" aria-hidden="false">
        <div class="login-brand-wash"></div>
        <div class="login-brand-inner">
            <img class="login-brand-logo" src="assets/logo.png" alt="Gyanam India Educational Services">
            <h1 class="login-brand-title">Gyanam India</h1>
            <p class="login-brand-tag">Educational Services</p>
            <p class="login-brand-lead">Head office, district centres and ATCs — one secure portal for training operations.</p>
            <ul class="login-brand-points">
                <li>Encrypted access for every role</li>
                <li>Students, fees and share tracking</li>
                <li>Certificates, materials and reports</li>
            </ul>
        </div>
        <p class="login-brand-foot">&copy; <?= date('Y') ?> Gyanam India Educational Services</p>
    </aside>

    <main class="login-panel">
        <div class="login-panel-inner">
            <header class="login-panel-head">
                <p class="login-kicker">Portal sign in</p>
                <h2>Welcome back</h2>
                <p>Select your role, then enter your User ID and password.</p>
            </header>

            <div class="role-selector" role="group" aria-label="Sign-in role">
                <button type="button" class="role-btn" data-role="Admin">
                    <span class="role-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 2a5 5 0 0 1 5 5v3a5 5 0 0 1-10 0V7a5 5 0 0 1 5-5z"/><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/></svg>
                    </span>
                    <span class="role-label">Head Office</span>
                </button>
                <button type="button" class="role-btn" data-role="DLC Office">
                    <span class="role-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                    </span>
                    <span class="role-label">DLC</span>
                </button>
                <button type="button" class="role-btn" data-role="ATC CENTER">
                    <span class="role-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                    </span>
                    <span class="role-label">ATC</span>
                </button>
                <button type="button" class="role-btn" data-role="Training">
                    <span class="role-icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    </span>
                    <span class="role-label">Training</span>
                </button>
            </div>

            <form id="loginForm" class="login-form" action="authenticate.php" method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="role" id="role" value="">

                <?php if ($error): ?>
                    <div class="alert-error" role="alert">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <?= sanitize($error) ?>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="username">User ID</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <input type="text" id="username" name="username" placeholder="Enter your User ID" required autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <span class="input-icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="form-extras">
                    <label class="remember-check">
                        <input type="checkbox" name="remember">
                        <span>Remember me on this device</span>
                    </label>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <span class="btn-text">Sign in</span>
                    <span class="spinner" aria-hidden="true"></span>
                </button>
            </form>

            <nav class="login-links" aria-label="Site links">
                <a href="about-us.php">About</a>
                <a href="pricing.php">Pricing</a>
                <a href="privacy-policy.php">Privacy</a>
                <a href="terms-and-conditions.php">Terms</a>
                <a href="refund-policy.php">Refunds</a>
                <a href="contact-us.php">Contact</a>
            </nav>
        </div>
    </main>

</div>

<script src="assets/js/login.js"></script>
</body>
</html>
