<?php
/**
 * Gyanam Portal — Admin Profile Page
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/app_settings.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userId = getUserId();

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$mailSettings = getMailSettings($pdo);
$mailConfigured = trim($mailSettings['mail_username'] ?? '') !== '' && trim($mailSettings['mail_password'] ?? '') !== '';
$mailEnabled = ($mailSettings['mail_enabled'] ?? '1') !== '0';

$success = $_SESSION['profile_success'] ?? '';
$error   = $_SESSION['profile_error'] ?? '';
unset($_SESSION['profile_success'], $_SESSION['profile_error']);

$roleBadgeClass = 'admin';
$roleLabel = 'Head Office';
$displayName = sanitize($user['name'] ?? $user['username'] ?? 'Admin');
$initial = strtoupper(substr($user['name'] ?? $user['username'] ?? 'G', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — Head Office | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/profile.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
</head>
<body>
<div class="dashboard-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>My Profile</h2>
                    <p>Account details &amp; outbound email settings</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content profile-page profile-page--admin">

            <?php if ($success): ?>
                <div class="alert-success">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?= sanitize($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert-danger">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?= sanitize($error) ?>
                </div>
            <?php endif; ?>

            <div class="profile-header-card">
                <div class="profile-avatar-lg"><?= $initial ?></div>
                <div class="profile-header-info">
                    <h2><?= $displayName ?></h2>
                    <span class="profile-role-badge <?= $roleBadgeClass ?>"><?= $roleLabel ?></span>
                    <div class="profile-meta">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Member since <?= formatDate($user['created_at'], 'd M Y') ?>
                    </div>
                </div>
                <div class="profile-header-aside">
                    <div class="profile-stat-chip <?= $mailConfigured ? 'is-ok' : 'is-warn' ?>">
                        <span class="dot"></span>
                        <?= $mailConfigured ? 'Email ready' : 'Email setup needed' ?>
                    </div>
                </div>
            </div>

            <form action="../update_profile.php" method="POST" class="profile-form-card">
                <div class="card-head">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Personal Information
                    </h3>
                </div>
                <input type="hidden" name="action" value="update_profile">

                <div class="profile-form-grid">
                    <div class="form-field">
                        <label>Username</label>
                        <input type="text" value="<?= sanitize($user['username']) ?>" readonly>
                    </div>
                    <div class="form-field">
                        <label>Role</label>
                        <input type="text" value="<?= $roleLabel ?>" readonly>
                    </div>
                    <div class="form-field">
                        <label>Full Name *</label>
                        <input type="text" name="name" value="<?= sanitize($user['name']) ?>" required placeholder="Enter your full name">
                    </div>
                    <div class="form-field">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?= sanitize($user['email']) ?>" placeholder="Enter your email">
                    </div>
                    <div class="form-field">
                        <label>Mobile Number</label>
                        <input type="tel" name="mobile" value="<?= sanitize($user['mobile']) ?>" placeholder="Enter your mobile number">
                    </div>
                    <div class="form-field">
                        <label>Status</label>
                        <input type="text" value="<?= sanitize($user['status']) ?>" readonly>
                    </div>
                </div>

                <div class="profile-form-actions">
                    <button type="submit" class="btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save Changes
                    </button>
                </div>
            </form>

            <form action="../update_profile.php" method="POST" class="profile-form-card password-section">
                <div class="card-head">
                    <h3>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Change Password
                    </h3>
                </div>
                <input type="hidden" name="action" value="change_password">

                <div class="profile-form-grid">
                    <div class="form-field full-width">
                        <label>Current Password *</label>
                        <input type="password" name="current_password" required placeholder="Enter current password" autocomplete="current-password">
                    </div>
                    <div class="form-field">
                        <label>New Password *</label>
                        <input type="password" name="new_password" required placeholder="Minimum 6 characters" minlength="6" autocomplete="new-password">
                    </div>
                    <div class="form-field">
                        <label>Confirm New Password *</label>
                        <input type="password" name="confirm_password" required placeholder="Re-enter new password" autocomplete="new-password">
                    </div>
                </div>

                <div class="profile-form-actions">
                    <button type="submit" class="btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Update Password
                    </button>
                </div>
            </form>

            <form action="../update_profile.php" method="POST" class="profile-form-card mail-settings-card">
                <div class="card-head">
                    <div>
                        <h3>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            Email (SMTP) Settings
                        </h3>
                        <p class="card-sub">Powers ATC welcome / onboarding emails from the portal.</p>
                    </div>
                    <span class="status-pill <?= $mailConfigured ? 'ok' : 'warn' ?>">
                        <span class="dot"></span>
                        <?= $mailConfigured ? ($mailEnabled ? 'Configured' : 'Saved · Disabled') : 'Not configured' ?>
                    </span>
                </div>
                <input type="hidden" name="action" value="update_mail_settings">

                <div class="info-note">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <div>
                        Use your Hostinger mailbox (for example <strong>gyanamindia@labxco.in</strong>).
                        Leave password blank to keep the currently saved password.
                    </div>
                </div>

                <div class="mail-section-label">Connection</div>
                <div class="profile-form-grid mail-grid">
                    <div class="form-field">
                        <label>Enable outbound email</label>
                        <select name="mail_enabled">
                            <option value="1" <?= $mailEnabled ? 'selected' : '' ?>>Enabled</option>
                            <option value="0" <?= !$mailEnabled ? 'selected' : '' ?>>Disabled</option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label>SMTP Host</label>
                        <input type="text" name="mail_host" value="<?= sanitize($mailSettings['mail_host'] ?? 'smtp.hostinger.com') ?>" placeholder="smtp.hostinger.com">
                    </div>
                    <div class="form-field">
                        <label>SMTP Port</label>
                        <input type="number" name="mail_port" value="<?= sanitize($mailSettings['mail_port'] ?? '465') ?>" placeholder="465">
                    </div>
                    <div class="form-field">
                        <label>Encryption</label>
                        <select name="mail_encryption">
                            <?php $enc = $mailSettings['mail_encryption'] ?? 'ssl'; ?>
                            <option value="ssl" <?= $enc === 'ssl' ? 'selected' : '' ?>>SSL (port 465)</option>
                            <option value="tls" <?= $enc === 'tls' ? 'selected' : '' ?>>TLS (port 587)</option>
                        </select>
                    </div>
                </div>

                <div class="mail-section-label">Mailbox credentials</div>
                <div class="profile-form-grid mail-grid">
                    <div class="form-field">
                        <label>Mailbox Email (SMTP Username) *</label>
                        <input type="email" name="mail_username" value="<?= sanitize($mailSettings['mail_username'] ?? '') ?>" placeholder="gyanamindia@labxco.in" required>
                    </div>
                    <div class="form-field">
                        <label>Mailbox Password <?= $mailConfigured ? '' : '*' ?></label>
                        <input type="password" name="mail_password" value="" placeholder="<?= $mailConfigured ? '••••••••  (leave blank to keep)' : 'Hostinger mailbox password' ?>" <?= $mailConfigured ? '' : 'required' ?> autocomplete="new-password">
                    </div>
                </div>

                <div class="mail-section-label">Sender identity</div>
                <div class="profile-form-grid mail-grid">
                    <div class="form-field">
                        <label>From Email *</label>
                        <input type="email" name="mail_from_email" value="<?= sanitize($mailSettings['mail_from_email'] ?: ($mailSettings['mail_username'] ?? '')) ?>" placeholder="gyanamindia@labxco.in" required>
                    </div>
                    <div class="form-field">
                        <label>From Name</label>
                        <input type="text" name="mail_from_name" value="<?= sanitize($mailSettings['mail_from_name'] ?? 'Gyanam India Educational Services') ?>" placeholder="Gyanam India Educational Services">
                    </div>
                    <div class="form-field full-width">
                        <label>Reply-To Email</label>
                        <input type="email" name="mail_reply_to" value="<?= sanitize($mailSettings['mail_reply_to'] ?: ($mailSettings['mail_from_email'] ?? '')) ?>" placeholder="gyanamindia@labxco.in">
                    </div>
                </div>

                <div class="profile-form-actions">
                    <button type="submit" class="btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        Save Email Settings
                    </button>
                </div>
            </form>

        </div>
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
</body>
</html>
