<?php
/**
 * Gyanam Portal — DLC: ATC Centers Management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['DLC Office']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$dlcId = $_SESSION['dlc_id'] ?? null;

// Handle AJAX requests (DLC can only view ATC details and manage ATC login users)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'get':
                $stmt = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ? AND dlc_id = ?");
                $stmt->execute([$_POST['id'], $dlcId]);
                $atc = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $atc]);
                exit;
                
            case 'create_user':
                // Check if user already exists for this ATC
                $stmt = $pdo->prepare("SELECT id FROM users WHERE atc_id = ? AND role = 'ATC CENTER'");
                $stmt->execute([$_POST['atc_id']]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'User already exists for this ATC center']);
                    exit;
                }
                
                // Check if username already exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$_POST['username']]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Username already exists. Please choose another.']);
                    exit;
                }
                
                // Create new user
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, role, name, email, mobile, atc_id, status)
                    VALUES (?, ?, 'ATC CENTER', ?, ?, ?, ?, 'Active')
                ");
                $stmt->execute([
                    $_POST['username'],
                    $_POST['password'],
                    $_POST['name'],
                    $_POST['email'],
                    $_POST['mobile'],
                    $_POST['atc_id']
                ]);
                
                echo json_encode(['success' => true, 'message' => 'User created successfully']);
                exit;
                
            case 'get_atc_user':
                $stmt = $pdo->prepare("
                    SELECT id, username, name, email, mobile, status 
                    FROM users 
                    WHERE atc_id = ? AND role = 'ATC CENTER'
                ");
                $stmt->execute([$_POST['atc_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $user]);
                exit;

            default:
                echo json_encode(['success' => false, 'message' => 'Action not permitted for DLC login']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch ATC centers for this DLC
if ($dlcId) {
    $stmt = $pdo->prepare("SELECT * FROM atc_centers WHERE dlc_id = ? ORDER BY created_at DESC");
    $stmt->execute([$dlcId]);
} else {
    $stmt = $pdo->query("SELECT * FROM atc_centers ORDER BY created_at DESC");
}
$atcCenters = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATC Centers — DLC Login | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
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
                    <h2>ATC Centers</h2>
                    <p>View Authorized Training Centers in your region</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="page-toolbar">
                <h3>
                    All ATC Centers
                    <span class="badge-count"><?= count($atcCenters) ?></span>
                </h3>
                <div style="display: flex; gap: 0.75rem; align-items: center;">
                    <div class="search-bar">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="searchInput" placeholder="Search ATC centers...">
                    </div>
                    <!-- ATC creation is managed by Head Office only -->
                </div>
            </div>

            <div class="table-card">
                <table class="data-table" id="atcTable">
                    <thead>
                        <tr>
                            <th>Center Name</th>
                            <th>District</th>
                            <th>Contact Person</th>
                            <th>Contact Info</th>
                            <th>Courses</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($atcCenters)): ?>
                            <tr>
                                <td colspan="7" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                    <p>No ATC centers assigned to your region yet. Contact Head Office.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($atcCenters as $atc): ?>
                                <tr>
                                    <td>
                                        <div class="cell-name"><?= htmlspecialchars($atc['name']) ?></div>
                                        <div class="cell-sub"><?= htmlspecialchars($atc['state']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($atc['district']) ?></td>
                                    <td><?= htmlspecialchars($atc['contact_person'] ?: '—') ?></td>
                                    <td>
                                        <?php if ($atc['mobile']): ?>
                                            <div><?= htmlspecialchars($atc['mobile']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($atc['email']): ?>
                                            <div class="cell-sub"><?= htmlspecialchars($atc['email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><div class="cell-sub"><?= htmlspecialchars($atc['courses'] ?: '—') ?></div></td>
                                    <td>
                                        <span class="cell-badge <?= strtolower($atc['status']) ?>">
                                            <?= $atc['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="cell-actions">
                                            <button class="btn-icon" onclick="createUser(<?= $atc['id'] ?>, '<?= htmlspecialchars($atc['name'], ENT_QUOTES) ?>')" title="Create/View User">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                                            </button>
                                            <!-- Edit and Delete is restricted to Head Office only -->
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Create User Modal -->
<div class="modal-overlay" id="userModal">
    <div class="modal-card">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                <span id="userModalTitle">Create User Account</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeUserModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <form id="userForm" novalidate>
            <input type="hidden" id="userAtcId" name="atc_id">
            <input type="hidden" name="action" value="create_user">
            
            <div class="modal-body">
                
                <!-- ATC Info Display -->
                <div class="info-banner">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                    <div>
                        <strong>ATC Center:</strong>
                        <span id="atcCenterName"></span>
                    </div>
                </div>
                
                <!-- Existing User Display -->
                <div id="existingUserInfo" style="display: none;">
                    <div class="alert-info">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        <div>
                            <strong>User Already Exists</strong>
                            <p>A user account has already been created for this ATC center.</p>
                            <div class="user-details">
                                <div><strong>Username:</strong> <span id="existingUsername"></span></div>
                                <div><strong>Name:</strong> <span id="existingName"></span></div>
                                <div><strong>Email:</strong> <span id="existingEmail"></span></div>
                                <div><strong>Status:</strong> <span id="existingStatus" class="cell-badge"></span></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- User Form -->
                <div id="userFormFields">
                    <!-- Login Credentials Section -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Login Credentials
                        </div>
                        
                        <div class="profile-form-grid">
                            <!-- Username -->
                            <div class="form-field">
                                <label for="user_username">
                                    Username <span class="required">*</span>
                                </label>
                                <div class="input-with-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <input 
                                        type="text" 
                                        id="user_username" 
                                        name="username" 
                                        placeholder="e.g., pune_atc"
                                        required
                                        maxlength="50"
                                        autocomplete="off"
                                    >
                                </div>
                                <small class="field-hint">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                    Unique username for login
                                </small>
                            </div>
                            
                            <!-- Password -->
                            <div class="form-field">
                                <label for="user_password">
                                    Password <span class="required">*</span>
                                </label>
                                <div class="input-with-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                    <input 
                                        type="text" 
                                        id="user_password" 
                                        name="password" 
                                        placeholder="Enter password"
                                        required
                                        maxlength="50"
                                        autocomplete="off"
                                    >
                                </div>
                                <small class="field-hint">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                    Minimum 6 characters
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            Personal Information
                        </div>
                        
                        <div class="profile-form-grid">
                            <!-- Full Name -->
                            <div class="form-field full-width">
                                <label for="user_name">
                                    Full Name <span class="required">*</span>
                                </label>
                                <div class="input-with-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <input 
                                        type="text" 
                                        id="user_name" 
                                        name="name" 
                                        placeholder="Full name of the user"
                                        required
                                        maxlength="100"
                                        autocomplete="off"
                                    >
                                </div>
                            </div>
                            
                            <!-- Email -->
                            <div class="form-field">
                                <label for="user_email">Email Address</label>
                                <div class="input-with-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                    <input 
                                        type="email" 
                                        id="user_email" 
                                        name="email"
                                        placeholder="user@example.com"
                                        maxlength="100"
                                        autocomplete="off"
                                    >
                                </div>
                            </div>
                            
                            <!-- Mobile -->
                            <div class="form-field">
                                <label for="user_mobile">Mobile Number</label>
                                <div class="input-with-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                                    <input 
                                        type="tel" 
                                        id="user_mobile" 
                                        name="mobile" 
                                        placeholder="9876543210"
                                        pattern="[0-9]{10}"
                                        maxlength="10"
                                        autocomplete="off"
                                    >
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeUserModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Close
                </button>
                <button type="submit" class="btn-primary" id="userSubmitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/>
                    </svg>
                    <span id="userSubmitBtnText">Create User</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<style>
/* Copy all the enhanced modal styles from admin/dlc_offices.php */
.modal-overlay {
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-card {
    max-width: 700px;
    animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-header {
    background: linear-gradient(135deg, var(--primary-50), var(--blue-50));
    padding: 1.5rem 1.75rem;
}

.modal-header h3 {
    font-size: 1.15rem;
    color: var(--primary-700);
}

.modal-header h3 svg {
    width: 22px;
    height: 22px;
    color: var(--primary-600);
}

.modal-close {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-md);
}

.modal-body {
    padding: 2rem 1.75rem;
    max-height: calc(90vh - 180px);
    overflow-y: auto;
}

.form-section {
    margin-bottom: 1.75rem;
}

.form-section-title {
    font-size: 0.8rem;
    font-weight: 800;
    color: var(--text-primary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--gray-200);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-section-title svg {
    width: 16px;
    height: 16px;
    color: var(--primary-500);
}

.form-field {
    margin-bottom: 1.25rem;
}

.form-field label {
    display: block;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.form-field label .required {
    color: var(--danger-500);
    font-weight: 800;
    margin-left: 2px;
}

.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
    padding: 0.7rem 0.9rem;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.88rem;
    font-family: inherit;
    color: var(--text-primary);
    background: var(--bg-surface);
    transition: all 0.2s ease;
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    outline: none;
    border-color: var(--primary-500);
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    background: #fff;
}

.form-field input::placeholder,
.form-field textarea::placeholder {
    color: var(--gray-400);
    font-weight: 400;
}

.form-field textarea {
    resize: vertical;
    min-height: 80px;
}

.field-hint {
    display: block;
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 0.35rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.field-hint svg {
    width: 12px;
    height: 12px;
    flex-shrink: 0;
}

.input-with-icon {
    position: relative;
}

.input-with-icon svg {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    color: var(--text-muted);
    pointer-events: none;
}

.input-with-icon input {
    padding-left: 2.5rem;
}

input[type="tel"] {
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.status-select-wrapper {
    position: relative;
}

.status-select-wrapper::before {
    content: '';
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--success-500);
    pointer-events: none;
}

.status-select-wrapper select {
    padding-left: 2rem;
}

.modal-footer {
    padding: 1.25rem 1.75rem;
    background: var(--gray-50);
    border-top: 1.5px solid var(--border-color);
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: var(--radius-md);
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: #fff;
    font-size: 0.88rem;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    box-shadow: 0 4px 12px rgba(67, 97, 238, 0.25);
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(67, 97, 238, 0.35);
}

.btn-primary:active:not(:disabled) {
    transform: translateY(0);
}

.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--bg-surface);
    color: var(--text-primary);
    font-size: 0.88rem;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-secondary:hover {
    background: var(--gray-100);
    border-color: var(--gray-300);
    transform: translateY(-1px);
}

.info-banner {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1rem 1.25rem;
    background: linear-gradient(135deg, var(--primary-50), var(--blue-50));
    border: 1.5px solid var(--primary-200);
    border-radius: var(--radius-lg);
    margin-bottom: 1.5rem;
}

.info-banner svg {
    width: 24px;
    height: 24px;
    color: var(--primary-600);
    flex-shrink: 0;
}

.info-banner strong {
    color: var(--primary-700);
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.info-banner span {
    color: var(--text-primary);
    font-weight: 600;
    font-size: 0.95rem;
}

.alert-info {
    display: flex;
    gap: 0.75rem;
    padding: 1.25rem;
    background: var(--blue-50);
    border: 1.5px solid var(--blue-200);
    border-radius: var(--radius-lg);
    margin-bottom: 1.5rem;
}

.alert-info svg {
    width: 22px;
    height: 22px;
    color: var(--blue-600);
    flex-shrink: 0;
    margin-top: 2px;
}

.alert-info strong {
    display: block;
    color: var(--blue-700);
    font-size: 0.9rem;
    margin-bottom: 0.35rem;
}

.alert-info p {
    color: var(--text-muted);
    font-size: 0.82rem;
    margin-bottom: 0.75rem;
}

.user-details {
    display: grid;
    gap: 0.5rem;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--blue-200);
}

.user-details > div {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.82rem;
}

.user-details strong {
    color: var(--text-primary);
    font-weight: 700;
    min-width: 80px;
}

.user-details span {
    color: var(--text-muted);
}

.user-details .cell-badge {
    margin-left: 0;
}

@media (max-width: 640px) {
    .modal-card {
        max-width: 100%;
        margin: 0;
        border-radius: 0;
        max-height: 100vh;
    }
    
    .modal-body {
        max-height: calc(100vh - 180px);
    }
    
    .profile-form-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>
<script>
// Search functionality
document.getElementById('searchInput').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#atcTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

// Open add modal
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add New ATC Center';
    document.getElementById('submitBtnText').textContent = 'Add ATC Center';
    document.getElementById('formAction').value = 'add';
    document.getElementById('atcForm').reset();
    document.getElementById('atcId').value = '';
    document.getElementById('state').value = 'Maharashtra';
    document.getElementById('status').value = 'Active';
    document.getElementById('atcModal').classList.add('active');
    
    setTimeout(() => {
        document.getElementById('name').focus();
    }, 100);
}

// Edit ATC
async function editATC(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'get');
        formData.append('id', id);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            const atc = result.data;
            document.getElementById('modalTitle').textContent = 'Edit ATC Center';
            document.getElementById('submitBtnText').textContent = 'Update ATC Center';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('atcId').value = atc.id;
            document.getElementById('name').value = atc.name;
            document.getElementById('district').value = atc.district;
            document.getElementById('state').value = atc.state;
            document.getElementById('contact_person').value = atc.contact_person || '';
            document.getElementById('mobile').value = atc.mobile || '';
            document.getElementById('email').value = atc.email || '';
            document.getElementById('address').value = atc.address || '';
            document.getElementById('courses').value = atc.courses || '';
            document.getElementById('status').value = atc.status;
            document.getElementById('atcModal').classList.add('active');
            
            setTimeout(() => {
                document.getElementById('name').focus();
            }, 100);
        }
    } catch (error) {
        alert('Error loading ATC data. Please try again.');
    }
}

// Delete ATC
async function deleteATC(id, name) {
    const confirmed = confirm(
        `⚠️ Delete ATC Center\n\n` +
        `Are you sure you want to delete "${name}"?\n\n` +
        `This will:\n` +
        `• Remove the ATC center permanently\n` +
        `• Delete all associated data\n` +
        `• This action cannot be undone`
    );
    
    if (!confirmed) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('ATC Center deleted successfully', 'success');
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            alert(result.message || 'Error deleting ATC center');
        }
    } catch (error) {
        alert('Error deleting ATC center. Please try again.');
    }
}

// Close modal
function closeModal() {
    document.getElementById('atcModal').classList.remove('active');
}

// ATC Form submission
document.getElementById('atcForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const mobile = document.getElementById('mobile').value;
    if (mobile && !/^[0-9]{10}$/.test(mobile)) {
        alert('Please enter a valid 10-digit mobile number');
        document.getElementById('mobile').focus();
        return;
    }
    
    const email = document.getElementById('email').value;
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('Please enter a valid email address');
        document.getElementById('email').focus();
        return;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    const submitBtnText = document.getElementById('submitBtnText');
    const originalText = submitBtnText.textContent;
    
    submitBtn.disabled = true;
    submitBtnText.textContent = 'Saving...';
    
    const formData = new FormData(this);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            const action = formData.get('action');
            const message = action === 'add' ? 'ATC Center added successfully!' : 'ATC Center updated successfully!';
            
            showNotification(message, 'success');
            
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            alert(result.message || 'Error saving ATC center');
            submitBtn.disabled = false;
            submitBtnText.textContent = originalText;
        }
    } catch (error) {
        alert('Error saving ATC center. Please try again.');
        submitBtn.disabled = false;
        submitBtnText.textContent = originalText;
    }
});

// Create User Modal Functions
async function createUser(atcId, atcName) {
    document.getElementById('userAtcId').value = atcId;
    document.getElementById('atcCenterName').textContent = atcName;
    
    try {
        const formData = new FormData();
        formData.append('action', 'get_atc_user');
        formData.append('atc_id', atcId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.data) {
            document.getElementById('existingUserInfo').style.display = 'block';
            document.getElementById('userFormFields').style.display = 'none';
            document.getElementById('userSubmitBtn').style.display = 'none';
            
            document.getElementById('existingUsername').textContent = result.data.username;
            document.getElementById('existingName').textContent = result.data.name || '—';
            document.getElementById('existingEmail').textContent = result.data.email || '—';
            
            const statusBadge = document.getElementById('existingStatus');
            statusBadge.textContent = result.data.status;
            statusBadge.className = 'cell-badge ' + result.data.status.toLowerCase();
        } else {
            document.getElementById('existingUserInfo').style.display = 'none';
            document.getElementById('userFormFields').style.display = 'block';
            document.getElementById('userSubmitBtn').style.display = 'inline-flex';
            document.getElementById('userForm').reset();
            document.getElementById('userAtcId').value = atcId;
        }
        
        document.getElementById('userModal').classList.add('active');
        
        if (document.getElementById('userFormFields').style.display !== 'none') {
            setTimeout(() => {
                document.getElementById('user_username').focus();
            }, 100);
        }
    } catch (error) {
        alert('Error loading user data. Please try again.');
    }
}

function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
}

// User Form Submission
document.getElementById('userForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const password = document.getElementById('user_password').value;
    if (password.length < 6) {
        alert('Password must be at least 6 characters long');
        document.getElementById('user_password').focus();
        return;
    }
    
    const mobile = document.getElementById('user_mobile').value;
    if (mobile && !/^[0-9]{10}$/.test(mobile)) {
        alert('Please enter a valid 10-digit mobile number');
        document.getElementById('user_mobile').focus();
        return;
    }
    
    const email = document.getElementById('user_email').value;
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        alert('Please enter a valid email address');
        document.getElementById('user_email').focus();
        return;
    }
    
    const submitBtn = document.getElementById('userSubmitBtn');
    const submitBtnText = document.getElementById('userSubmitBtnText');
    const originalText = submitBtnText.textContent;
    
    submitBtn.disabled = true;
    submitBtnText.textContent = 'Creating...';
    
    const formData = new FormData(this);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('User account created successfully!', 'success');
            
            setTimeout(() => {
                closeUserModal();
                location.reload();
            }, 1000);
        } else {
            alert(result.message || 'Error creating user account');
            submitBtn.disabled = false;
            submitBtnText.textContent = originalText;
        }
    } catch (error) {
        alert('Error creating user account. Please try again.');
        submitBtn.disabled = false;
        submitBtnText.textContent = originalText;
    }
});

// Close modals on overlay click
document.getElementById('atcModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

document.getElementById('userModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeUserModal();
    }
});

// Show notification helper
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        font-weight: 600;
        animation: slideIn 0.3s ease;
    `;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 2500);
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);
</script>
</body>
</html>
