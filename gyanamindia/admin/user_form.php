<?php
/**
 * Admin — Add New User (full page, replaces add modal)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

$msg = '';
$msgType = '';
$form = [
    'username' => '',
    'name' => '',
    'role' => '',
    'status' => 'Active',
    'dlc_id' => '',
    'atc_id' => '',
    'mobile' => '',
    'email' => '',
    'date_of_birth' => '',
    'create_training' => '0',
    'training_username' => '',
    'training_password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($form as $k => $_) {
        if (isset($_POST[$k])) {
            $form[$k] = is_string($_POST[$k]) ? trim($_POST[$k]) : $_POST[$k];
        }
    }
    $form['create_training'] = !empty($_POST['create_training']) ? '1' : '0';
    $password = (string)($_POST['password'] ?? '');

    try {
        if ($form['username'] === '' || $form['name'] === '' || $form['role'] === '') {
            throw new Exception('Username, full name, and role are required.');
        }
        if (strlen($password) < 6) {
            throw new Exception('Password must be at least 6 characters.');
        }
        if ($form['role'] === 'DLC Office' && $form['dlc_id'] === '') {
            throw new Exception('Please select a DLC Office.');
        }
        if ($form['role'] === 'ATC CENTER' && ($form['dlc_id'] === '' || $form['atc_id'] === '')) {
            throw new Exception('Please select DLC Office and ATC Center.');
        }
        if ($form['mobile'] !== '' && !preg_match('/^[0-9]{10}$/', $form['mobile'])) {
            throw new Exception('Mobile must be a 10-digit number.');
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$form['username']]);
        if ($stmt->fetch()) {
            throw new Exception('Username already exists.');
        }
        if ($form['email'] !== '') {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$form['email']]);
            if ($stmt->fetch()) {
                throw new Exception('Email already exists.');
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (username, password, role, name, email, mobile, date_of_birth, dlc_id, atc_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $form['username'],
            $password,
            $form['role'],
            $form['name'],
            $form['email'] !== '' ? $form['email'] : null,
            $form['mobile'] !== '' ? $form['mobile'] : null,
            $form['date_of_birth'] !== '' ? $form['date_of_birth'] : null,
            $form['dlc_id'] !== '' ? (int)$form['dlc_id'] : null,
            $form['atc_id'] !== '' ? (int)$form['atc_id'] : null,
            in_array($form['status'], ['Active', 'Inactive'], true) ? $form['status'] : 'Active',
        ]);

        if (function_exists('syncPortalUserToExam') && in_array($form['role'], ['ATC CENTER', 'DLC Office'], true)) {
            $centreId = null;
            if ($form['role'] === 'ATC CENTER' && $form['atc_id'] !== '') {
                $acStmt = $pdo->prepare("SELECT atc_code FROM atc_centers WHERE id = ?");
                $acStmt->execute([(int)$form['atc_id']]);
                $centreId = $acStmt->fetchColumn() ?: (date('Y') . str_pad((string)$form['atc_id'], 5, '0', STR_PAD_LEFT));
            } elseif ($form['role'] === 'DLC Office' && $form['dlc_id'] !== '') {
                $centreId = 'DLC' . $form['dlc_id'];
            }
            syncPortalUserToExam($form['username'], $form['name'], $form['email'] ?: null, $password, $form['role'], $centreId);
        }

        if ($form['role'] === 'ATC CENTER' && $form['create_training'] === '1' && $form['atc_id'] !== '') {
            $tUser = trim((string)$form['training_username']);
            $tPass = trim((string)$form['training_password']);
            if ($tUser !== '' && $tPass !== '') {
                $chk = $pdo->prepare("SELECT id FROM users WHERE role='Training' AND atc_id=?");
                $chk->execute([(int)$form['atc_id']]);
                if (!$chk->fetch()) {
                    $tName = $form['name'] . ' (Training)';
                    $pdo->prepare("INSERT INTO users (username, password, role, name, email, mobile, atc_id, status) VALUES (?, ?, 'Training', ?, ?, ?, ?, 'Active')")
                        ->execute([
                            $tUser,
                            $tPass,
                            $tName,
                            $form['email'] !== '' ? $form['email'] : null,
                            $form['mobile'] !== '' ? $form['mobile'] : null,
                            (int)$form['atc_id'],
                        ]);
                }
            }
        }

        header('Location: users.php?created=1');
        exit;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $msgType = 'error';
    }
}

$dlcOffices = $pdo->query("SELECT id, name FROM dlc_offices WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$atcCenters = $pdo->query("SELECT id, name, dlc_id FROM atc_centers WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add New User — Admin | Gyanam India</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<style>
:root {
    --font:'Sora',sans-serif;
    --bg:#f0f2f7; --surface:#fff; --border:#e4e8f0; --text:#0f1523; --muted:#8896a5; --sec:#4a5568;
    --indigo:#4f6ef7; --indigo-dark:#3a57e8; --indigo-soft:#eef1fe; --rose:#f43f5e; --rose-soft:#fff1f3;
}
.uf-page { padding:1.5rem 2rem; width:100%; }
.uf-alert { padding:.85rem 1rem; border-radius:10px; margin-bottom:1rem; font-size:.88rem; font-weight:600; }
.uf-alert.err { background:#fef2f2; border:1.5px solid #fecaca; color:#991b1b; }
.uf-card {
    background:var(--surface); border:1.5px solid var(--border); border-radius:16px;
    padding:1.35rem 1.5rem; margin-bottom:1.15rem;
}
.uf-card h3 {
    display:flex; align-items:center; gap:.45rem; margin:0 0 1rem; padding-bottom:.75rem;
    border-bottom:1px solid var(--border); font-size:.78rem; font-weight:800; letter-spacing:.06em;
    text-transform:uppercase; color:var(--sec);
}
.uf-card h3 svg { width:15px; height:15px; stroke:var(--indigo); }
.uf-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem 1.15rem; }
.uf-field { display:flex; flex-direction:column; gap:.35rem; }
.uf-field.span-2 { grid-column:1 / -1; }
.uf-label { font-size:.82rem; font-weight:700; color:var(--sec); }
.uf-req { color:var(--rose); }
.uf-hint { font-size:.75rem; color:var(--muted); font-weight:500; }
.uf-input, .uf-select {
    width:100%; height:42px; border:1.5px solid var(--border); border-radius:10px;
    padding:0 .9rem; font-family:var(--font); font-size:.9rem; font-weight:500; background:#f8f9fc; color:var(--text);
}
.uf-select { cursor:pointer; }
.uf-input:focus, .uf-select:focus { outline:none; border-color:var(--indigo); background:#fff; box-shadow:0 0 0 3px rgba(79,110,247,.1); }
.uf-actions { display:flex; gap:.75rem; justify-content:flex-end; flex-wrap:wrap; margin-top:.25rem; }
.uf-btn {
    display:inline-flex; align-items:center; gap:.4rem; height:42px; padding:0 1.25rem; border-radius:10px;
    font-family:var(--font); font-weight:800; font-size:.88rem; cursor:pointer; text-decoration:none; border:none;
}
.uf-btn-ghost { background:#fff; border:1.5px solid var(--border); color:var(--sec); }
.uf-btn-primary { background:linear-gradient(135deg,var(--indigo),var(--indigo-dark)); color:#fff; box-shadow:0 4px 14px rgba(79,110,247,.3); }
.uf-train {
    background:linear-gradient(135deg,#f5f3ff,#eef1fd); border:1.5px solid #ddd6fe; border-radius:12px; padding:1.1rem 1.2rem;
}
.uf-train-top { display:flex; align-items:center; justify-content:space-between; gap:.75rem; margin-bottom:.75rem; }
.uf-train-title { display:flex; align-items:center; gap:.45rem; font-size:.82rem; font-weight:800; color:#5b21b6; }
@media (max-width:720px) { .uf-grid { grid-template-columns:1fr; } .uf-field.span-2 { grid-column:1; } }
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="page-content uf-page">

        <div class="page-header" style="margin-bottom:1.25rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap">
            <div>
                <h2>Add New User</h2>
                <p>Create an Admin, DLC, or ATC login for the portal</p>
            </div>
            <a class="uf-btn uf-btn-ghost" href="users.php">← Back to Users</a>
        </div>

        <?php if ($msg): ?>
        <div class="uf-alert err"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>

        <form method="post" id="userAddForm" autocomplete="off">
            <div class="uf-card">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Account Information
                </h3>
                <div class="uf-grid">
                    <div class="uf-field">
                        <label class="uf-label" for="username">Username <span class="uf-req">*</span></label>
                        <input class="uf-input" type="text" id="username" name="username" required maxlength="50"
                               value="<?= htmlspecialchars($form['username']) ?>" placeholder="e.g. john_doe">
                        <span class="uf-hint">Unique identifier used for login</span>
                    </div>
                    <div class="uf-field">
                        <label class="uf-label" for="password">Password <span class="uf-req">*</span></label>
                        <input class="uf-input" type="password" id="password" name="password" required minlength="6"
                               placeholder="Min. 6 characters" autocomplete="new-password">
                        <span class="uf-hint">Minimum 6 characters required</span>
                    </div>
                    <div class="uf-field span-2">
                        <label class="uf-label" for="name">Full Name <span class="uf-req">*</span></label>
                        <input class="uf-input" type="text" id="name" name="name" required maxlength="100"
                               value="<?= htmlspecialchars($form['name']) ?>" placeholder="Enter full display name">
                        <span class="uf-hint">Displayed across the portal</span>
                    </div>
                </div>
            </div>

            <div class="uf-card">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
                    Role &amp; Organization
                </h3>
                <div class="uf-grid">
                    <div class="uf-field">
                        <label class="uf-label" for="role">User Role <span class="uf-req">*</span></label>
                        <select class="uf-select" id="role" name="role" required onchange="handleRoleChange()">
                            <option value="">Select a role…</option>
                            <option value="Admin" <?= $form['role'] === 'Admin' ? 'selected' : '' ?>>Admin</option>
                            <option value="DLC Office" <?= $form['role'] === 'DLC Office' ? 'selected' : '' ?>>DLC Office</option>
                            <option value="ATC CENTER" <?= $form['role'] === 'ATC CENTER' ? 'selected' : '' ?>>ATC Center</option>
                        </select>
                        <span class="uf-hint">Determines access &amp; permissions</span>
                    </div>
                    <div class="uf-field">
                        <label class="uf-label" for="status">Account Status</label>
                        <select class="uf-select" id="status" name="status">
                            <option value="Active" <?= $form['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                            <option value="Inactive" <?= $form['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                        <span class="uf-hint">Inactive users cannot log in</span>
                    </div>
                    <div class="uf-field" id="dlcField" style="display:none;">
                        <label class="uf-label" for="dlc_id">DLC Office <span class="uf-req" id="dlcRequired">*</span></label>
                        <select class="uf-select" id="dlc_id" name="dlc_id" onchange="handleDLCChange()">
                            <option value="">Select DLC Office…</option>
                            <?php foreach ($dlcOffices as $dlc): ?>
                            <option value="<?= (int)$dlc['id'] ?>" <?= (string)$form['dlc_id'] === (string)$dlc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dlc['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="uf-field" id="atcField" style="display:none;">
                        <label class="uf-label" for="atc_id">ATC Center <span class="uf-req" id="atcRequired">*</span></label>
                        <select class="uf-select" id="atc_id" name="atc_id">
                            <option value="">Select ATC Center…</option>
                            <?php foreach ($atcCenters as $atc): ?>
                            <option value="<?= (int)$atc['id'] ?>" data-dlc="<?= (int)$atc['dlc_id'] ?>"
                                <?= (string)$form['atc_id'] === (string)$atc['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($atc['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="uf-field span-2" id="trainingSection" style="display:none;">
                        <div class="uf-train">
                            <div class="uf-train-top">
                                <div class="uf-train-title">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" style="width:18px;height:18px"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                    Training Login
                                </div>
                                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.78rem;font-weight:700;color:#6d28d9;margin:0;">
                                    <input type="checkbox" id="createTraining" name="create_training" value="1"
                                           <?= $form['create_training'] === '1' ? 'checked' : '' ?>
                                           style="width:auto;accent-color:#7c3aed;">
                                    Enable
                                </label>
                            </div>
                            <div id="trainingFields" style="display:<?= $form['create_training'] === '1' ? 'block' : 'none' ?>;">
                                <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-size:.78rem;font-weight:600;color:#6d28d9;margin:0 0 .75rem;">
                                    <input type="checkbox" id="sameCredentials" style="width:auto;accent-color:#7c3aed;" onchange="handleSameCredentials()">
                                    <span>Use same credentials as ATC login</span>
                                </label>
                                <div class="uf-grid">
                                    <div class="uf-field">
                                        <label class="uf-label" for="training_username">Training Username</label>
                                        <input class="uf-input" type="text" id="training_username" name="training_username"
                                               value="<?= htmlspecialchars($form['training_username']) ?>" placeholder="training_username">
                                    </div>
                                    <div class="uf-field">
                                        <label class="uf-label" for="training_password">Training Password</label>
                                        <input class="uf-input" type="password" id="training_password" name="training_password"
                                               value="<?= htmlspecialchars($form['training_password']) ?>" placeholder="Set password" autocomplete="new-password">
                                    </div>
                                </div>
                                <div style="margin-top:.5rem;font-size:.72rem;color:#7c3aed;font-weight:500;">
                                    Training users can only view videos assigned to this ATC center
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="uf-card">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    Contact Information
                </h3>
                <div class="uf-grid">
                    <div class="uf-field">
                        <label class="uf-label" for="mobile">Mobile Number</label>
                        <input class="uf-input" type="tel" id="mobile" name="mobile" maxlength="10" pattern="[0-9]{10}"
                               value="<?= htmlspecialchars($form['mobile']) ?>" placeholder="9876543210">
                        <span class="uf-hint">10-digit number, no spaces</span>
                    </div>
                    <div class="uf-field">
                        <label class="uf-label" for="email">Email Address</label>
                        <input class="uf-input" type="email" id="email" name="email" maxlength="100"
                               value="<?= htmlspecialchars($form['email']) ?>" placeholder="user@example.com">
                        <span class="uf-hint">Used for notifications &amp; recovery</span>
                    </div>
                    <div class="uf-field">
                        <label class="uf-label" for="date_of_birth">Date of Birth</label>
                        <input class="uf-input" type="date" id="date_of_birth" name="date_of_birth"
                               value="<?= htmlspecialchars($form['date_of_birth']) ?>">
                        <span class="uf-hint">Used for birthday alerts on dashboard</span>
                    </div>
                </div>
            </div>

            <div class="uf-actions">
                <a class="uf-btn uf-btn-ghost" href="users.php">Cancel</a>
                <button type="submit" class="uf-btn uf-btn-primary">Add User</button>
            </div>
        </form>
    </div>
</main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script>
function handleRoleChange() {
    var role = document.getElementById('role').value;
    var dlcField = document.getElementById('dlcField');
    var atcField = document.getElementById('atcField');
    var dlcSelect = document.getElementById('dlc_id');
    var atcSelect = document.getElementById('atc_id');
    var trainingSection = document.getElementById('trainingSection');

    dlcSelect.required = false;
    atcSelect.required = false;
    dlcField.style.display = 'none';
    atcField.style.display = 'none';
    trainingSection.style.display = 'none';

    if (role === 'DLC Office') {
        dlcField.style.display = 'flex';
        dlcSelect.required = true;
    } else if (role === 'ATC CENTER') {
        dlcField.style.display = 'flex';
        atcField.style.display = 'flex';
        dlcSelect.required = true;
        atcSelect.required = true;
        trainingSection.style.display = 'block';
    } else {
        document.getElementById('createTraining').checked = false;
        document.getElementById('trainingFields').style.display = 'none';
    }
    handleDLCChange();
}

function handleDLCChange() {
    var dlcId = document.getElementById('dlc_id').value;
    var atcSel = document.getElementById('atc_id');
    Array.prototype.forEach.call(atcSel.querySelectorAll('option'), function (opt) {
        opt.style.display = (!opt.value || !dlcId || opt.dataset.dlc === dlcId) ? '' : 'none';
    });
    var cur = atcSel.options[atcSel.selectedIndex];
    if (cur && cur.dataset.dlc && cur.dataset.dlc !== dlcId) atcSel.value = '';
}

function handleSameCredentials() {
    var same = document.getElementById('sameCredentials').checked;
    var tUser = document.getElementById('training_username');
    var tPass = document.getElementById('training_password');
    if (same) {
        tUser.value = document.getElementById('username').value;
        tPass.value = document.getElementById('password').value;
        tUser.readOnly = true;
        tPass.readOnly = true;
        tUser.style.opacity = '.6';
        tPass.style.opacity = '.6';
    } else {
        tUser.readOnly = false;
        tPass.readOnly = false;
        tUser.style.opacity = '1';
        tPass.style.opacity = '1';
    }
}

document.getElementById('createTraining').addEventListener('change', function () {
    document.getElementById('trainingFields').style.display = this.checked ? 'block' : 'none';
});
document.getElementById('username').addEventListener('input', function () {
    if (document.getElementById('sameCredentials').checked) {
        document.getElementById('training_username').value = this.value;
    }
});
document.getElementById('password').addEventListener('input', function () {
    if (document.getElementById('sameCredentials').checked) {
        document.getElementById('training_password').value = this.value;
    }
});

handleRoleChange();
</script>
</body>
</html>
