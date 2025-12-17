<?php
/**
 * PixelHop - User Account Settings
 */

session_start();
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$currentUser = getCurrentUser();
$isAdmin = isAdmin();
$db = Database::getInstance();

// Refresh user data
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$currentUser['id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'change_password':
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (empty($currentPassword)) {
                echo json_encode(['success' => false, 'error' => 'Current password required']);
                exit;
            }

            if (!password_verify($currentPassword, $user['password_hash'])) {
                echo json_encode(['success' => false, 'error' => 'Current password is incorrect']);
                exit;
            }

            if (strlen($newPassword) < 8) {
                echo json_encode(['success' => false, 'error' => 'New password must be at least 8 characters']);
                exit;
            }

            if ($newPassword !== $confirmPassword) {
                echo json_encode(['success' => false, 'error' => 'Passwords do not match']);
                exit;
            }

            $hash = password_hash($newPassword, PASSWORD_ARGON2ID, [
                'memory_cost' => 65536,
                'time_cost' => 4,
                'threads' => 1,
            ]);

            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $user['id']]);

            echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
            break;

        case 'change_email':
            $newEmail = trim($_POST['new_email'] ?? '');
            $password = $_POST['password'] ?? '';

            if (!password_verify($password, $user['password_hash'])) {
                echo json_encode(['success' => false, 'error' => 'Password is incorrect']);
                exit;
            }

            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'error' => 'Invalid email format']);
                exit;
            }


            $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$newEmail, $user['id']]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Email is already in use']);
                exit;
            }


            $stmt = $db->prepare("UPDATE users SET email = ?, email_verified = 0, email_verified_at = NULL, verification_token = ? WHERE id = ?");
            $verificationToken = bin2hex(random_bytes(32));
            $stmt->execute([$newEmail, $verificationToken, $user['id']]);

            $_SESSION['user_email'] = $newEmail;



            echo json_encode(['success' => true, 'message' => 'Email updated. A verification email has been sent to your new address.']);
            break;

        case 'request_delete':

            if ($isAdmin) {
                echo json_encode(['success' => false, 'error' => 'Admin accounts cannot be deleted']);
                exit;
            }

            $password = $_POST['password'] ?? '';

            if (!password_verify($password, $user['password_hash'])) {
                echo json_encode(['success' => false, 'error' => 'Password is incorrect']);
                exit;
            }

            $deleteToken = bin2hex(random_bytes(32));
            $deleteTime = date('Y-m-d H:i:s', time() + (48 * 3600));

            $stmt = $db->prepare("UPDATE users SET delete_requested_at = ?, delete_token = ? WHERE id = ?");
            $stmt->execute([$deleteTime, $deleteToken, $user['id']]);



            echo json_encode(['success' => true, 'message' => 'Account deletion scheduled. Your account will be deleted in 48 hours. You can cancel this in your settings.', 'delete_at' => $deleteTime]);
            break;

        case 'cancel_delete':
            $stmt = $db->prepare("UPDATE users SET delete_requested_at = NULL, delete_token = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);

            echo json_encode(['success' => true, 'message' => 'Account deletion cancelled']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

$csrfToken = generateCsrfToken();
$hasPendingDeletion = !empty($user['delete_requested_at']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - PixelHop</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/assets/css/glass.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%); min-height: 100vh; padding: 20px; }
        .container { max-width: 700px; margin: 0 auto; }
        .card { background: rgba(20, 20, 35, 0.85); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 16px; padding: 24px; margin-bottom: 20px; }
        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; }
        .title-section { display: flex; align-items: center; gap: 14px; }
        .title-icon { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #22d3ee, #a855f7); display: flex; align-items: center; justify-content: center; }
        .nav-link { padding: 10px 18px; border-radius: 10px; color: rgba(255, 255, 255, 0.6); text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }

        .section-title { font-size: 14px; font-weight: 600; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 12px; color: rgba(255, 255, 255, 0.6); margin-bottom: 6px; }
        .form-input { width: 100%; padding: 12px 14px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #fff; font-size: 13px; }
        .form-input:focus { border-color: #22d3ee; outline: none; }

        .btn { padding: 12px 24px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; border: none; }
        .btn-primary { background: linear-gradient(135deg, #22d3ee, #a855f7); color: #fff; }
        .btn-danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.3); }
        .btn-secondary { background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.15); }

        .danger-zone { border-color: rgba(239, 68, 68, 0.3); }
        .warning-box { background: rgba(234, 179, 8, 0.15); border: 1px solid rgba(234, 179, 8, 0.3); border-radius: 10px; padding: 16px; margin-bottom: 16px; }

        .toast { position: fixed; top: 20px; right: 20px; padding: 14px 20px; border-radius: 10px; font-size: 13px; z-index: 1000; display: none; }
        .toast-success { background: rgba(34, 197, 94, 0.9); color: #fff; }
        .toast-error { background: rgba(239, 68, 68, 0.9); color: #fff; }

        .info-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
        .info-label { font-size: 13px; color: rgba(255, 255, 255, 0.5); }
        .info-value { font-size: 13px; color: #fff; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="title-section">
                <div class="title-icon"><i data-lucide="settings" class="w-6 h-6 text-white"></i></div>
                <div>
                    <h1 class="text-xl font-bold text-white">Account Settings</h1>
                    <p class="text-xs text-white/50">Manage your account</p>
                </div>
            </div>
            <a href="/dashboard.php" class="nav-link"><i data-lucide="arrow-left" class="w-4 h-4"></i> Back</a>
        </div>

        <!-- Account Info -->
        <div class="card">
            <div class="section-title"><i data-lucide="user" class="w-4 h-4 text-cyan-400"></i> Account Information</div>
            <div class="info-row">
                <span class="info-label">Email</span>
                <span class="info-value"><?= htmlspecialchars($user['email']) ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Account Type</span>
                <span class="info-value"><?= ucfirst($user['account_type'] ?? 'free') ?></span>
            </div>
            <div class="info-row">
                <span class="info-label">Member Since</span>
                <span class="info-value"><?= date('M j, Y', strtotime($user['created_at'])) ?></span>
            </div>
            <div class="info-row" style="border: none;">
                <span class="info-label">Email Verified</span>
                <span class="info-value"><?= $user['email_verified_at'] ? '✓ Verified' : '✗ Not verified' ?></span>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card">
            <div class="section-title"><i data-lucide="key" class="w-4 h-4 text-purple-400"></i> Change Password</div>
            <form id="passwordForm">
                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-input" required minlength="8">
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-input" required>
                </div>
                <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Update Password</button>
            </form>
        </div>

        <!-- Change Email -->
        <div class="card">
            <div class="section-title"><i data-lucide="mail" class="w-4 h-4 text-pink-400"></i> Change Email</div>
            <form id="emailForm">
                <div class="form-group">
                    <label class="form-label">New Email</label>
                    <input type="email" name="new_email" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="password" class="form-input" required>
                </div>
                <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Update Email</button>
            </form>
        </div>

        <?php if (!$isAdmin): ?>
        <!-- Danger Zone -->
        <div class="card danger-zone">
            <div class="section-title" style="color: #ef4444;"><i data-lucide="alert-triangle" class="w-4 h-4"></i> Danger Zone</div>

            <?php if ($hasPendingDeletion): ?>
            <div class="warning-box">
                <div class="text-sm text-yellow-400 font-medium mb-2">⚠️ Account deletion scheduled</div>
                <div class="text-xs text-white/60">Your account will be permanently deleted on <?= date('M j, Y \a\t H:i', strtotime($user['delete_requested_at'])) ?>.</div>
            </div>
            <button type="button" class="btn btn-secondary" id="cancelDeleteBtn">
                <i data-lucide="x" class="w-4 h-4"></i> Cancel Deletion
            </button>
            <?php else: ?>
            <p class="text-sm text-white/50 mb-4">Once you delete your account, there is no going back. You have 48 hours to cancel after requesting deletion.</p>
            <form id="deleteForm">
                <div class="form-group">
                    <label class="form-label">Confirm Password to Delete Account</label>
                    <input type="password" name="password" class="form-input" required>
                </div>
                <button type="submit" class="btn btn-danger"><i data-lucide="trash-2" class="w-4 h-4"></i> Request Account Deletion</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        lucide.createIcons();
        const csrf = '<?= $csrfToken ?>';

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast toast-' + type;
            toast.style.display = 'block';
            setTimeout(() => toast.style.display = 'none', 4000);
        }

        async function submitAction(action, data) {
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', action);
            fd.append('csrf_token', csrf);
            for (const [key, val] of Object.entries(data)) {
                fd.append(key, val);
            }

            const res = await fetch('/member/settings.php', { method: 'POST', body: fd });
            return res.json();
        }

        document.getElementById('passwordForm').onsubmit = async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const result = await submitAction('change_password', Object.fromEntries(fd));
            if (result.success) {
                showToast(result.message, 'success');
                e.target.reset();
            } else {
                showToast(result.error, 'error');
            }
        };

        document.getElementById('emailForm').onsubmit = async e => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const result = await submitAction('change_email', Object.fromEntries(fd));
            if (result.success) {
                showToast(result.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(result.error, 'error');
            }
        };

        <?php if (!$isAdmin): ?>
        <?php if (!$hasPendingDeletion): ?>
        document.getElementById('deleteForm').onsubmit = async e => {
            e.preventDefault();
            if (!confirm('Are you sure you want to delete your account? This action can be cancelled within 48 hours.')) return;
            const fd = new FormData(e.target);
            const result = await submitAction('request_delete', Object.fromEntries(fd));
            if (result.success) {
                showToast(result.message, 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                showToast(result.error, 'error');
            }
        };
        <?php else: ?>
        document.getElementById('cancelDeleteBtn').onclick = async () => {
            const result = await submitAction('cancel_delete', {});
            if (result.success) {
                showToast(result.message, 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(result.error, 'error');
            }
        };
        <?php endif; ?>
        <?php endif; ?>
    </script>
</body>
</html>
