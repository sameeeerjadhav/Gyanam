<?php
/**
 * TEMPORARY FILE — Create admin user + Generate API token.
 * 
 * ⚠️ DELETE THIS FILE IMMEDIATELY AFTER USE ⚠️
 */

require __DIR__ . '/gyanam-backend/vendor/autoload.php';
$app = require_once __DIR__ . '/gyanam-backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Try to find existing admin user
$user = User::where('role', 'admin')->first();

// If no admin exists, create one
if (!$user) {
    $user = User::create([
        'username'  => 'admin',
        'name'      => 'Gyanam Admin',
        'email'     => 'admin@gyanam.edu',
        'password'  => Hash::make('admin123'),
        'role'      => 'admin',
        'centre_id' => null,
    ]);
    $created = true;
} else {
    $created = false;
}

// Delete old integration tokens
$user->tokens()->where('name', 'integration-token')->delete();

// Create new token
$token = $user->createToken('integration-token', ['role:admin'])->plainTextToken;
?>
<!DOCTYPE html>
<html>
<head><title>Token Generated</title></head>
<body style="font-family:monospace;padding:2rem;background:#1e293b;color:#e2e8f0">
    <h2 style="color:#22c55e">✅ Success!</h2>
    
    <?php if ($created): ?>
    <p style="color:#fbbf24">⚡ Admin user was created (username: <strong>admin</strong>, password: <strong>admin123</strong>)</p>
    <?php else: ?>
    <p>Using existing admin: <strong><?= htmlspecialchars($user->username) ?></strong> (ID: <?= $user->id ?>)</p>
    <?php endif; ?>
    
    <h3 style="margin-top:2rem">Your API token:</h3>
    <div style="background:#0f172a;border:2px solid #22c55e;border-radius:8px;padding:1rem;word-break:break-all;font-size:1.1rem;color:#fbbf24">
        <?= htmlspecialchars($token) ?>
    </div>
    
    <h3 style="margin-top:2rem;color:#f59e0b">📋 Next steps:</h3>
    <ol style="line-height:2">
        <li>Copy the token above</li>
        <li>Open <code>gyanamindia/config/db.php</code> on Hostinger</li>
        <li>Replace the <code>EXAM_API_TOKEN</code> value with this token</li>
        <li><strong style="color:#ef4444">DELETE this file from the server NOW!</strong></li>
    </ol>
</body>
</html>
