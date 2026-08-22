<?php
/**
 * Gyanam Portal — Admin: Add / Edit ATC Center (dedicated page)
 * Add:  atc_form.php
 * Edit: atc_form.php?id=123
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

// Ensure franchise payment columns exist
try {
    $flag = __DIR__ . '/../config/.schema_atc_franchise_pay_ok';
    if (!is_file($flag)) {
        try { $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS franchise_payment_mode VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS franchise_paid_date DATE DEFAULT NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE atc_centers ADD COLUMN IF NOT EXISTS franchise_fees DECIMAL(12,2) DEFAULT NULL"); } catch (Exception $e) {}
        @file_put_contents($flag, date('c') . "\n");
    }
} catch (Exception $e) {}

$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $editId > 0;
$atc = null;
$trainingUser = null;
$error = '';
$flash = '';

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $atc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$atc) {
        header('Location: atc_centers.php?err=' . urlencode('ATC not found'));
        exit;
    }
    try {
        $tStmt = $pdo->prepare("SELECT id, username, password FROM users WHERE role='Training' AND atc_id=? LIMIT 1");
        $tStmt->execute([$editId]);
        $trainingUser = $tStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {}
}

$dlcOffices = $pdo->query("SELECT id, name FROM dlc_offices WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$states = [
    'Maharashtra','Gujarat','Karnataka','Delhi','Rajasthan','Uttar Pradesh','Madhya Pradesh',
    'Tamil Nadu','West Bengal','Telangana','Andhra Pradesh','Kerala','Punjab','Haryana',
    'Bihar','Odisha','Jharkhand','Chhattisgarh','Assam','Other',
];
$centerTypes = [
    'Abacus','Vedic Maths','IT','Abacus + IT','Abacus + Vedic Maths',
    'Vedic Maths + IT','Abacus + Vedic Maths + IT',
];

$v = function ($key, $default = '') use ($atc) {
    if ($atc && array_key_exists($key, $atc) && $atc[$key] !== null) {
        return (string)$atc[$key];
    }
    return (string)$default;
};

$pageTitle = $isEdit ? 'Edit ATC Center' : 'Add New ATC Center';
$today = date('Y-m-d');
$defaultExpiry = date('Y-m-d', strtotime('+1 year'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Admin | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<?php if (file_exists(__DIR__.'/../assets/css/notifications.css')): ?>
<link rel="stylesheet" href="../assets/css/notifications.css">
<?php endif; ?>
<style>
:root { --font: 'Sora', system-ui, sans-serif; }
.page-wrap { max-width: none; width: 100%; margin: 0; }
.form-card {
    background:#fff; border:1.5px solid var(--border-color,#e5e7eb); border-radius:16px;
    overflow:hidden; box-shadow:0 4px 20px rgba(15,23,42,.04); width:100%;
}
.form-card-head {
    padding:1.1rem 1.5rem; border-bottom:1px solid #eef2f7;
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
    background:linear-gradient(135deg,#fff 0%,#f8fafc 100%);
}
.form-card-head h3 { margin:0; font-size:1.05rem; font-weight:800; color:var(--text-primary,#111) }
.form-card-head p { margin:.2rem 0 0; font-size:.8rem; color:var(--text-secondary,#6b7280); font-weight:500 }
.form-body { padding:1.35rem 1.5rem 1.6rem }
.atc-form-section { margin-bottom:1.5rem }
.atc-form-section-title {
    display:flex; align-items:center; gap:.5rem; font-size:.78rem; font-weight:800;
    text-transform:uppercase; letter-spacing:.06em; color:#64748b; margin-bottom:.85rem;
}
.atc-form-section-title svg { width:16px; height:16px }
.atc-form-grid {
    display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.85rem 1.1rem;
}
.atc-form-grid .full { grid-column:1 / -1 }
.atc-form-field { display:flex; flex-direction:column; gap:.28rem }
.atc-form-field label { font-size:.78rem; font-weight:700; color:#374151 }
.atc-form-field label .req { color:#ef4444 }
.atc-form-field input,
.atc-form-field select,
.atc-form-field textarea {
    height:42px; padding:0 .85rem; border:1.5px solid #e5e7eb; border-radius:10px;
    font-family:inherit; font-size:.875rem; outline:none; background:#fff; transition:border-color .15s;
    width:100%; box-sizing:border-box;
}
.atc-form-field textarea { height:auto; padding:.7rem .85rem; resize:vertical }
.atc-form-field input:focus,
.atc-form-field select:focus,
.atc-form-field textarea:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.12) }
.form-actions {
    display:flex; gap:.75rem; justify-content:flex-end; flex-wrap:wrap;
    padding:1rem 1.5rem; border-top:1px solid #eef2f7; background:#fafbfc;
}
.btn-cancel {
    height:42px; padding:0 1.15rem; border-radius:10px; border:1.5px solid #e5e7eb;
    background:#fff; font-weight:700; font-size:.85rem; cursor:pointer; text-decoration:none;
    color:#475569; display:inline-flex; align-items:center; font-family:inherit;
}
.btn-save {
    height:42px; padding:0 1.35rem; border-radius:10px; border:none;
    background:linear-gradient(135deg,#4361ee,#3b82f6); color:#fff; font-weight:800;
    font-size:.85rem; cursor:pointer; display:inline-flex; align-items:center; gap:.4rem;
    font-family:inherit; box-shadow:0 3px 12px rgba(67,97,238,.28);
}
.btn-save:disabled { opacity:.6; cursor:not-allowed }
.hint-box {
    padding:.75rem 1rem; border-radius:12px; margin-bottom:1rem;
    font-size:.8rem; font-weight:500; border:1px solid #c7d2fe;
    background:linear-gradient(135deg,#eff6ff,#ede9fe); color:#4338ca;
}
.alert {
    padding:.85rem 1.1rem; border-radius:12px; margin-bottom:1rem;
    font-size:.875rem; font-weight:600;
}
.alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca }
.alert.success { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0 }
.back-link {
    display:inline-flex; align-items:center; gap:.35rem; font-size:.82rem; font-weight:700;
    color:#4361ee; text-decoration:none; margin-bottom:.85rem;
}
@media (max-width:1100px) {
    .atc-form-grid { grid-template-columns:repeat(2,minmax(0,1fr)) }
}
@media (max-width:720px) {
    .atc-form-grid { grid-template-columns:1fr }
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn" type="button">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="header-greeting">
                <h2><?= htmlspecialchars($pageTitle) ?></h2>
                <p><?= $isEdit ? 'Update center details, fees & login' : 'Create a new Authorized Training Center' ?></p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__.'/../includes/notification_bell.php')) include __DIR__.'/../includes/notification_bell.php'; ?>
            <?php include __DIR__.'/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">
        <div class="page-wrap">
            <a class="back-link" href="atc_centers.php">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                Back to ATC Logins
            </a>

            <div id="formAlert" class="alert" style="display:none"></div>

            <div class="form-card">
                <div class="form-card-head">
                    <div>
                        <h3><?= htmlspecialchars($pageTitle) ?></h3>
                        <p><?= $isEdit
                            ? 'ATC Code: <strong style="font-family:ui-monospace,monospace">' . htmlspecialchars($v('atc_code') ?: (date('Y') . str_pad((string)$editId, 5, '0', STR_PAD_LEFT))) . '</strong>'
                            : 'Fill in center, location, contact and franchise payment details' ?></p>
                    </div>
                </div>

                <form id="atcForm" novalidate>
                    <input type="hidden" name="action" value="<?= $isEdit ? 'edit' : 'add' ?>">
                    <?php if ($isEdit): ?>
                    <input type="hidden" name="id" value="<?= (int)$editId ?>">
                    <?php endif; ?>

                    <div class="form-body">
                        <!-- Center Information -->
                        <div class="atc-form-section">
                            <div class="atc-form-section-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                Center Information
                            </div>
                            <div class="atc-form-grid">
                                <div class="atc-form-field full">
                                    <label for="f_name">Center Name <span class="req">*</span></label>
                                    <input type="text" id="f_name" name="name" required maxlength="150"
                                        value="<?= htmlspecialchars($v('name')) ?>"
                                        placeholder="e.g., Pune Authorized Training Center">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_center_type">Center Type <span class="req">*</span></label>
                                    <select id="f_center_type" name="center_type" required>
                                        <option value="">-- Select Type --</option>
                                        <?php foreach ($centerTypes as $ct): ?>
                                        <option value="<?= htmlspecialchars($ct) ?>" <?= $v('center_type') === $ct ? 'selected' : '' ?>><?= htmlspecialchars($ct) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_dlc_id">Assign to DLC Login <span class="req">*</span></label>
                                    <select id="f_dlc_id" name="dlc_id" required>
                                        <option value="">-- Select DLC --</option>
                                        <?php foreach ($dlcOffices as $dlc): ?>
                                        <option value="<?= (int)$dlc['id'] ?>" <?= (string)$v('dlc_id') === (string)$dlc['id'] ? 'selected' : '' ?>><?= htmlspecialchars($dlc['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_date_created">Date Created</label>
                                    <input type="date" id="f_date_created" name="date_created" max="<?= $today ?>"
                                        value="<?= htmlspecialchars($v('date_created', $isEdit ? '' : $today)) ?>">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_authorization_expires_at">Authorization Expiry Date</label>
                                    <input type="date" id="f_authorization_expires_at" name="authorization_expires_at"
                                        value="<?= htmlspecialchars($v('authorization_expires_at', $isEdit ? '' : $defaultExpiry)) ?>">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_franchise_payment_mode">Payment Mode</label>
                                    <select id="f_franchise_payment_mode" name="franchise_payment_mode">
                                        <option value="">-- Select Mode --</option>
                                        <?php foreach (['Cash','UPI','Cheque'] as $pm): ?>
                                        <option value="<?= $pm ?>" <?= $v('franchise_payment_mode') === $pm ? 'selected' : '' ?>><?= $pm ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_franchise_fees">Franchise Fees / Amount Paid (&#8377;)</label>
                                    <input type="number" id="f_franchise_fees" name="franchise_fees" min="0" step="0.01"
                                        value="<?= htmlspecialchars($v('franchise_fees')) ?>"
                                        placeholder="e.g. 25000">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_franchise_paid_date">Amount Paid Date</label>
                                    <input type="date" id="f_franchise_paid_date" name="franchise_paid_date" max="<?= $today ?>"
                                        value="<?= htmlspecialchars($v('franchise_paid_date')) ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Location -->
                        <div class="atc-form-section">
                            <div class="atc-form-section-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                Location Details
                            </div>
                            <div class="atc-form-grid">
                                <div class="atc-form-field full">
                                    <label for="f_address">Full Address <span class="req">*</span></label>
                                    <textarea id="f_address" name="address" rows="2" required
                                        placeholder="Complete address with landmark, street, area"><?= htmlspecialchars($v('address')) ?></textarea>
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_district">District <span class="req">*</span></label>
                                    <input type="text" id="f_district" name="district" required maxlength="100"
                                        value="<?= htmlspecialchars($v('district')) ?>" placeholder="e.g., Pune">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_taluka">Taluka <span class="req">*</span></label>
                                    <input type="text" id="f_taluka" name="taluka" required maxlength="100"
                                        value="<?= htmlspecialchars($v('taluka')) ?>" placeholder="e.g., Haveli">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_city">City</label>
                                    <input type="text" id="f_city" name="city" maxlength="100"
                                        value="<?= htmlspecialchars($v('city')) ?>" placeholder="e.g., Pune City">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_state">State</label>
                                    <select id="f_state" name="state">
                                        <?php $curState = $v('state', 'Maharashtra'); foreach ($states as $st): ?>
                                        <option value="<?= htmlspecialchars($st) ?>" <?= $curState === $st ? 'selected' : '' ?>><?= htmlspecialchars($st) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_pin_code">PIN Code <span class="req">*</span></label>
                                    <input type="text" id="f_pin_code" name="pin_code" required maxlength="10" pattern="[0-9]{6}"
                                        value="<?= htmlspecialchars($v('pin_code')) ?>" placeholder="6-digit PIN">
                                </div>
                            </div>
                        </div>

                        <!-- Contact -->
                        <div class="atc-form-section">
                            <div class="atc-form-section-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                Contact Information
                            </div>
                            <div class="atc-form-grid">
                                <div class="atc-form-field">
                                    <label for="f_contact_person">Contact Person</label>
                                    <input type="text" id="f_contact_person" name="contact_person" maxlength="100"
                                        value="<?= htmlspecialchars($v('contact_person')) ?>" placeholder="Full name">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_mobile">Mobile Number</label>
                                    <input type="tel" id="f_mobile" name="mobile" maxlength="10" pattern="[0-9]{10}"
                                        value="<?= htmlspecialchars($v('mobile')) ?>" placeholder="9876543210">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_alternate_mobile">Alternate Mobile <span style="font-size:.72rem;color:#9ca3af;font-weight:500">(optional)</span></label>
                                    <input type="tel" id="f_alternate_mobile" name="alternate_mobile" maxlength="15"
                                        value="<?= htmlspecialchars($v('alternate_mobile')) ?>" placeholder="e.g., 9876543211">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_email">Email Address</label>
                                    <input type="email" id="f_email" name="email" maxlength="100"
                                        value="<?= htmlspecialchars($v('email')) ?>" placeholder="center@example.com">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_dob">Contact Person Birthday</label>
                                    <input type="date" id="f_dob" name="dob" value="<?= htmlspecialchars($v('dob')) ?>">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_status">Status</label>
                                    <select id="f_status" name="status">
                                        <option value="Active" <?= $v('status', 'Active') === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option value="Inactive" <?= $v('status') === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Login -->
                        <div class="atc-form-section">
                            <div class="atc-form-section-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                Login Account Credentials
                            </div>
                            <div class="hint-box" id="loginAccountHint">
                                <?php if ($isEdit): ?>
                                Username is fixed to <strong>ATC Code</strong>. You can view or reset the password below.
                                <?php else: ?>
                                Username is always the <strong>ATC Code</strong>. Temporary password is <strong>password</strong> — ATC should change it after first login.
                                <?php endif; ?>
                            </div>
                            <div class="atc-form-grid">
                                <div class="atc-form-field">
                                    <label for="f_login_username">Username (ATC Code)</label>
                                    <input type="text" id="f_login_username" name="login_username" maxlength="100" autocomplete="off" readonly
                                        style="background:#f8fafc;cursor:not-allowed"
                                        value="<?= htmlspecialchars($isEdit ? ($v('atc_code') ?: $v('login_username')) : '') ?>"
                                        placeholder="<?= $isEdit ? '' : 'Auto = ATC Code after save' ?>">
                                </div>
                                <div class="atc-form-field">
                                    <label for="f_login_password">Password</label>
                                    <input type="text" id="f_login_password" name="login_password" maxlength="100" autocomplete="off"
                                        <?= $isEdit ? '' : 'readonly style="background:#f8fafc;cursor:not-allowed"' ?>
                                        value="<?= htmlspecialchars($isEdit ? $v('login_password') : 'password') ?>"
                                        placeholder="<?= $isEdit ? 'Current password (editable to reset)' : 'password' ?>">
                                    <?php if (!$isEdit): ?>
                                    <div style="font-size:.72rem;color:#64748b;margin-top:.25rem">Temporary password: <strong>password</strong></div>
                                    <?php else: ?>
                                    <div style="font-size:.72rem;color:#64748b;margin-top:.25rem">Leave as-is to keep current password</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Training -->
                        <div class="atc-form-section">
                            <div class="atc-form-section-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                Training Video Login
                                <?php if ($trainingUser): ?>
                                <span style="font-size:.65rem;font-weight:800;padding:.15rem .5rem;border-radius:99px;margin-left:.35rem;background:#d1fae5;color:#065f46;border:1px solid #a7f3d0">✓ Active</span>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;gap:.75rem;flex-wrap:wrap">
                                <div style="font-size:.78rem;color:#6d28d9;font-weight:500">Create a separate login to access training videos for this ATC</div>
                                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.78rem;font-weight:700;color:#6d28d9;margin:0">
                                    <input type="checkbox" id="f_create_training" name="create_training" value="1"
                                        <?= $trainingUser ? 'checked' : '' ?>
                                        style="width:auto;accent-color:#7c3aed" onchange="toggleTrainingFields()">
                                    Enable
                                </label>
                            </div>
                            <div id="trainingFieldsATC" style="<?= $trainingUser ? '' : 'display:none' ?>">
                                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.78rem;font-weight:600;color:#6d28d9;margin:0 0 .75rem">
                                    <input type="checkbox" id="f_same_training_creds" name="same_training_creds" value="1"
                                        style="width:auto;accent-color:#7c3aed" onchange="handleTrainingSameCreds()">
                                    <span>Use same credentials as ATC login</span>
                                </label>
                                <div class="atc-form-grid">
                                    <div class="atc-form-field">
                                        <label for="f_training_username">Training Username</label>
                                        <input type="text" id="f_training_username" name="training_username" autocomplete="off"
                                            value="<?= htmlspecialchars($trainingUser['username'] ?? '') ?>"
                                            placeholder="e.g., training_pune">
                                    </div>
                                    <div class="atc-form-field">
                                        <label for="f_training_password">Training Password</label>
                                        <input type="text" id="f_training_password" name="training_password" autocomplete="off"
                                            value="<?= htmlspecialchars($trainingUser['password'] ?? '') ?>"
                                            placeholder="<?= $trainingUser ? 'Leave blank to keep current' : 'e.g., Train@123' ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="atc_centers.php" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-save" id="atcSaveBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <span id="atcSaveBtnText"><?= $isEdit ? 'Update ATC Center' : 'Save ATC Center' ?></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
function toggleTrainingFields() {
    document.getElementById('trainingFieldsATC').style.display =
        document.getElementById('f_create_training').checked ? 'block' : 'none';
}
function handleTrainingSameCreds() {
    const same = document.getElementById('f_same_training_creds').checked;
    const tU = document.getElementById('f_training_username');
    const tP = document.getElementById('f_training_password');
    if (same) {
        tU.value = document.getElementById('f_login_username').value || '(same as ATC Code)';
        tP.value = document.getElementById('f_login_password').value || 'password';
        tU.readOnly = true; tP.readOnly = true;
        tU.style.opacity = '.6'; tP.style.opacity = '.6';
    } else {
        tU.readOnly = false; tP.readOnly = false;
        tU.style.opacity = '1'; tP.style.opacity = '1';
    }
}

function showAlert(msg, type) {
    const el = document.getElementById('formAlert');
    el.style.display = 'block';
    el.className = 'alert ' + (type === 'success' ? 'success' : 'error');
    el.textContent = msg;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

document.getElementById('atcForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const btn = document.getElementById('atcSaveBtn');
    const label = document.getElementById('atcSaveBtnText');
    const orig = label.textContent;
    btn.disabled = true;
    label.textContent = 'Saving...';
    try {
        const fd = new FormData(this);
        const res = await fetch('atc_centers.php', { method: 'POST', body: new URLSearchParams(fd) });
        const data = await res.json();
        if (data.success) {
            showAlert(data.message || 'Saved successfully', 'success');
            setTimeout(() => { location.href = 'atc_centers.php?ok=1'; }, 700);
        } else {
            showAlert(data.message || 'Could not save ATC', 'error');
            btn.disabled = false;
            label.textContent = orig;
        }
    } catch (err) {
        showAlert('Server error. Please try again.', 'error');
        btn.disabled = false;
        label.textContent = orig;
    }
});
</script>
</body>
</html>
