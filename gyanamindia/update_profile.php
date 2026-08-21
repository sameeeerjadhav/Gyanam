<?php
/**
 * Gyanam Portal - Profile Update Handler
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin(['Admin', 'DLC Office', 'ATC CENTER']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/index.php');
}

$pdo = getDBConnection();
$userId = getUserId();
$userRole = getUserRole() ?? '';
$action = trim($_POST['action'] ?? '');

function getProfilePageByRole(string $role): string
{
    $map = [
        'Admin' => '/admin/profile.php',
        'DLC Office' => '/dlc/profile.php',
        'ATC CENTER' => '/atc/profile.php',
    ];

    return $map[$role] ?? '/index.php';
}

function verifyUserPassword(string $plainPassword, string $storedPassword): bool
{
    $passwordInfo = password_get_info($storedPassword);
    $isHashed = isset($passwordInfo['algo']) && $passwordInfo['algo'] !== null && $passwordInfo['algo'] !== 0;

    if ($isHashed) {
        return password_verify($plainPassword, $storedPassword);
    }

    // Legacy plaintext support for old seeded users.
    return hash_equals($storedPassword, $plainPassword);
}

$redirectUrl = getProfilePageByRole($userRole);

try {
    $stmt = $pdo->prepare('SELECT id, password, status FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        $_SESSION['profile_error'] = 'User not found.';
        redirect($redirectUrl);
    }

    if (($user['status'] ?? 'Active') !== 'Active') {
        $_SESSION['profile_error'] = 'Account is inactive. Contact administrator.';
        redirect($redirectUrl);
    }

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');

        if ($name === '') {
            $_SESSION['profile_error'] = 'Full name is required.';
            redirect($redirectUrl);
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['profile_error'] = 'Please enter a valid email address.';
            redirect($redirectUrl);
        }

        $updateStmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, mobile = ? WHERE id = ?');
        $updateStmt->execute([$name, $email !== '' ? $email : null, $mobile !== '' ? $mobile : null, $userId]);

        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_mobile'] = $mobile;
        $_SESSION['profile_success'] = 'Profile information updated successfully.';
        redirect($redirectUrl);
    }

    if ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $_SESSION['profile_error'] = 'All password fields are required.';
            redirect($redirectUrl);
        }

        if (strlen($newPassword) < 6) {
            $_SESSION['profile_error'] = 'New password must be at least 6 characters.';
            redirect($redirectUrl);
        }

        if ($newPassword !== $confirmPassword) {
            $_SESSION['profile_error'] = 'New password and confirm password do not match.';
            redirect($redirectUrl);
        }

        if (!verifyUserPassword($currentPassword, (string)$user['password'])) {
            $_SESSION['profile_error'] = 'Current password is incorrect.';
            redirect($redirectUrl);
        }

        if (verifyUserPassword($newPassword, (string)$user['password'])) {
            $_SESSION['profile_error'] = 'New password must be different from current password.';
            redirect($redirectUrl);
        }

        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updatePasswordStmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $updatePasswordStmt->execute([$newPasswordHash, $userId]);

        // Keep ATC center password in sync so Admin can still view it
        if (strtoupper(trim((string)$userRole)) === 'ATC CENTER') {
            $atcIdForPass = intval($_SESSION['atc_id'] ?? ($user['atc_id'] ?? 0));
            if ($atcIdForPass > 0) {
                try {
                    $pdo->prepare('UPDATE atc_centers SET login_password = ? WHERE id = ?')
                        ->execute([$newPassword, $atcIdForPass]);
                } catch (Exception $e) { /* non-fatal */ }
            }
        }

        $_SESSION['profile_success'] = 'Password updated successfully.';
        redirect($redirectUrl);
    }

    if ($action === 'upload_logo') {
        if (strtoupper(trim((string)$userRole)) !== 'ATC CENTER') {
            $_SESSION['profile_error'] = 'Only ATC profile can upload center logo.';
            redirect($redirectUrl);
        }

        $atcId = intval($_SESSION['atc_id'] ?? 0);
        if ($atcId <= 0) {
            $_SESSION['profile_error'] = 'ATC account not linked properly.';
            redirect($redirectUrl);
        }

        if (!isset($_FILES['logo']) || !is_array($_FILES['logo'])) {
            $_SESSION['profile_error'] = 'Please choose a logo file.';
            redirect($redirectUrl);
        }

        $logoFile = $_FILES['logo'];
        $uploadError = intval($logoFile['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'Logo file is too large for server upload limit.',
                UPLOAD_ERR_FORM_SIZE => 'Logo file exceeds allowed form upload size.',
                UPLOAD_ERR_PARTIAL => 'Logo upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_FILE => 'Please choose a logo file.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder is missing.',
                UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
                UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension.',
            ];
            $_SESSION['profile_error'] = $uploadErrors[$uploadError] ?? 'File upload failed. Please try again.';
            redirect($redirectUrl);
        }

        $maxSize = 2 * 1024 * 1024;
        if (intval($logoFile['size'] ?? 0) <= 0 || intval($logoFile['size'] ?? 0) > $maxSize) {
            $_SESSION['profile_error'] = 'Logo must be up to 2 MB.';
            redirect($redirectUrl);
        }

        $originalName = (string)($logoFile['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowedExtensions = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
        if (!in_array($extension, $allowedExtensions, true)) {
            $_SESSION['profile_error'] = 'Allowed logo formats: PNG, JPG, JPEG, WEBP, SVG.';
            redirect($redirectUrl);
        }

        $uploadDir = __DIR__ . '/uploads/atc_logos/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $_SESSION['profile_error'] = 'Unable to create upload directory.';
            redirect($redirectUrl);
        }

        $fileName = 'atc_' . $atcId . '_' . time() . '_' . mt_rand(1000, 999999) . '.' . $extension;
        $destination = $uploadDir . $fileName;

        if (!move_uploaded_file($logoFile['tmp_name'], $destination)) {
            $_SESSION['profile_error'] = 'Unable to save uploaded logo. Check server permissions.';
            redirect($redirectUrl);
        }

        $hasLogoColumn = $pdo->query("SHOW COLUMNS FROM atc_centers LIKE 'logo'")->rowCount() > 0;
        if (!$hasLogoColumn) {
            try {
                $pdo->exec("ALTER TABLE atc_centers ADD COLUMN logo VARCHAR(255) NULL AFTER address");
            } catch (Throwable $e) {
                $_SESSION['profile_error'] = 'Logo column is missing in database. Please run migration for atc_centers.logo.';
                redirect($redirectUrl);
            }
        }

        $relativePath = 'uploads/atc_logos/' . $fileName;
        $stmt = $pdo->prepare('UPDATE atc_centers SET logo = ? WHERE id = ?');
        $stmt->execute([$relativePath, $atcId]);

        $_SESSION['profile_success'] = 'Center logo uploaded successfully.';
        redirect($redirectUrl);
    }

    $_SESSION['profile_error'] = 'Invalid action.';
    redirect($redirectUrl);
} catch (PDOException $e) {
    error_log('Profile Update DB Error: ' . $e->getMessage());
    $_SESSION['profile_error'] = 'Database error while updating profile.';
    redirect($redirectUrl);
} catch (Throwable $e) {
    error_log('Profile Update Error: ' . $e->getMessage());
    $_SESSION['profile_error'] = 'Something went wrong. Please try again.';
    redirect($redirectUrl);
}
