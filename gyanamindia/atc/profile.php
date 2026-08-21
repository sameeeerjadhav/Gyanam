<?php
/**
 * Gyanam Portal — ATC Center Profile Page
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin(['ATC CENTER']);

$pdo    = getDBConnection();
$userId = getUserId();
$atcId  = $_SESSION['atc_id'] ?? null;

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Also fetch ATC center details (for logo)
$atcCenter = null;
if ($atcId) {
    $stmt2 = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ?");
    $stmt2->execute([$atcId]);
    $atcCenter = $stmt2->fetch(PDO::FETCH_ASSOC);
}

$success = $_SESSION['profile_success'] ?? '';
$error   = $_SESSION['profile_error'] ?? '';
unset($_SESSION['profile_success'], $_SESSION['profile_error']);

$roleBadgeClass = 'atc';
$roleLabel = 'ATC Login';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — ATC Login | Gyanam India</title>
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
                    <p>Manage your account information</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content profile-page">

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
                <div class="profile-avatar-lg"><?= strtoupper(substr($user['name'] ?? $user['username'], 0, 1)) ?></div>
                <div class="profile-header-info">
                    <h2><?= sanitize($user['name'] ?? $user['username']) ?></h2>
                    <span class="profile-role-badge <?= $roleBadgeClass ?>"><?= $roleLabel ?></span>
                    <div class="profile-meta">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Member since <?= formatDate($user['created_at'], 'd M Y') ?>
                    </div>
                </div>
            </div>

            <form action="../update_profile.php" method="POST" class="profile-form-card">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Personal Information
                </h3>
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
                        <input type="text" name="name" value="<?= sanitize($user['name']) ?>" required>
                    </div>
                    <div class="form-field">
                        <label>Email Address</label>
                        <input type="email" name="email" value="<?= sanitize($user['email']) ?>" placeholder="Enter your email">
                    </div>
                    <div class="form-field">
                        <label>Mobile Number</label>
                        <input type="tel" name="mobile" value="<?= sanitize($user['mobile']) ?>" placeholder="Enter your mobile">
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
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Change Password
                </h3>
                <input type="hidden" name="action" value="change_password">
                <div class="profile-form-grid">
                    <div class="form-field full-width">
                        <label>Current Password *</label>
                        <input type="password" name="current_password" required placeholder="Enter current password">
                    </div>
                    <div class="form-field">
                        <label>New Password *</label>
                        <input type="password" name="new_password" required placeholder="Min 6 characters" minlength="6">
                    </div>
                    <div class="form-field">
                        <label>Confirm New Password *</label>
                        <input type="password" name="confirm_password" required placeholder="Re-enter new password">
                    </div>
                </div>
                <div class="profile-form-actions">
                    <button type="submit" class="btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Update Password
                    </button>
                </div>
            </form>

            <!-- ── Center Logo Upload ── -->
            <div class="profile-form-card logo-upload-section">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    Center Logo
                </h3>
                <p class="logo-hint">Your logo will appear on <strong>Fee Receipts</strong> and <strong>Hall Tickets</strong>. Upload a clear PNG or JPG (max 2 MB, recommended: square).</p>

                <div class="logo-current-wrap">
                    <?php
                        $logoPath = $atcCenter['logo'] ?? '';
                        $logoUrl  = !empty($logoPath) ? '../' . ltrim($logoPath, '/') : '';
                    ?>
                    <?php if ($logoUrl): ?>
                        <div class="logo-preview-box">
                            <img id="logoPreview" src="<?= htmlspecialchars($logoUrl) ?>" alt="Center Logo">
                            <div class="logo-preview-label">Current Logo</div>
                        </div>
                    <?php else: ?>
                        <div class="logo-placeholder" id="logoPlaceholderBox">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
                            </svg>
                            <span>No logo uploaded yet</span>
                            <img id="logoPreview" alt="Preview" style="display:none;max-height:120px;max-width:200px;border-radius:8px;object-fit:contain;">
                        </div>
                    <?php endif; ?>
                </div>

                <form action="../update_profile.php" method="POST" enctype="multipart/form-data" class="logo-form">
                    <input type="hidden" name="action" value="upload_logo">
                    <div class="logo-upload-area" onclick="document.getElementById('logoFile').click()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                        <span id="uploadAreaText">Click to choose logo file</span>
                        <small>PNG, JPG, JPEG, SVG — max 2 MB</small>
                    </div>
                    <input type="file" id="logoFile" name="logo" accept=".png,.jpg,.jpeg,.svg,.webp" style="display:none" onchange="previewLogo(this)">
                    <div class="profile-form-actions">
                        <button type="submit" class="btn-primary" id="uploadLogoBtn" disabled>
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                            Upload Logo
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script>
function previewLogo(input) {
    const file = input.files[0];
    if (!file) return;
    const preview = document.getElementById('logoPreview');
    const placeholder = document.getElementById('logoPlaceholderBox');
    const btn = document.getElementById('uploadLogoBtn');
    const label = document.getElementById('uploadAreaText');

    // Live preview
    const reader = new FileReader();
    reader.onload = function(e) {
        preview.src = e.target.result;
        preview.style.display = 'block';
        if (placeholder) placeholder.querySelector('svg').style.display = 'none';
        if (placeholder) placeholder.querySelector('span').style.display = 'none';
    };
    reader.readAsDataURL(file);

    label.textContent = file.name;
    btn.disabled = false;
}
</script>
<style>
.logo-upload-section { border-top: 3px solid #4361ee; }
.logo-hint { font-size: .85rem; color: #6b7280; margin: -.5rem 0 1.25rem; line-height: 1.6; }
.logo-current-wrap { margin-bottom: 1.25rem; }
.logo-preview-box { display:inline-block; padding:.75rem; border:2px solid #e5e7eb; border-radius:12px; background:#f9fafb; text-align:center; }
.logo-preview-box img { max-height:120px; max-width:220px; object-fit:contain; border-radius:8px; display:block; }
.logo-preview-label { font-size:.72rem; color:#6b7280; margin-top:.5rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
.logo-placeholder { display:flex; flex-direction:column; align-items:center; gap:.5rem; padding:1.5rem 2rem; border:2px dashed #d1d5db; border-radius:12px; color:#9ca3af; font-size:.875rem; background:#f9fafb; }
.logo-placeholder svg { width:40px; height:40px; }
.logo-upload-area {
    border: 2px dashed #c7d2fe; border-radius: 12px; padding: 1.75rem;
    text-align: center; cursor: pointer; transition: all .18s ease;
    background: #f5f7ff; display: flex; flex-direction: column; align-items: center; gap: .5rem;
}
.logo-upload-area:hover { border-color: #4361ee; background: #eef2ff; }
.logo-upload-area svg { width:32px; height:32px; stroke:#4361ee; }
.logo-upload-area span { font-weight:600; color:#4361ee; font-size:.9rem; }
.logo-upload-area small { color:#9ca3af; font-size:.78rem; }
.logo-form { display:flex; flex-direction:column; gap:1rem; }
</style>
</body>
</html>
