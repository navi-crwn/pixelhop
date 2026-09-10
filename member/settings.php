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

            if (strlen($newPassword) > 128) {
                echo json_encode(['success' => false, 'error' => 'New password is too long']);
                exit;
            }

            // Match the complexity policy enforced at registration
            if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
                echo json_encode(['success' => false, 'error' => 'Password must include an uppercase letter, a lowercase letter, and a number']);
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
            if ($stmt->execute([$hash, $user['id']])) {
                // Matikan semua sesi lain milik user ini.
                incrementSessionVersion($user['id']);

                // Sesi yang sedang dipakai tetap valid: samakan key sesi
                // dengan nilai DB terbaru agar tidak ikut ter-logout.
                $_SESSION['session_version'] = (int) ($_SESSION['session_version'] ?? 1) + 1;
            }

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
            if (strtolower($newEmail) === strtolower($user['email'])) {
                echo json_encode(['success' => false, 'error' => 'This is already your email address']);
                exit;
            }
            $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$newEmail, $user['id']]);
            if ($check->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Email is already in use']);
                exit;
            }

            // Use the same verification columns the rest of the app relies on
            // (register.php / verify.php use email_verification_token + _expires)
            $verificationToken = bin2hex(random_bytes(32));
            $verificationExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));
            $stmt = $db->prepare("UPDATE users SET email = ?, email_verified = 0, email_verified_at = NULL, email_verification_token = ?, email_verification_expires = ? WHERE id = ?");
            $stmt->execute([$newEmail, $verificationToken, $verificationExpires, $user['id']]);

            // Actually send the verification email
            require_once __DIR__ . '/../includes/Mailer.php';
            $mailer = new Mailer();
            $emailSent = $mailer->sendVerificationEmail($newEmail, $verificationToken);
            if (!$emailSent) {
                error_log('settings change_email: failed to send verification email to ' . $newEmail);
            }

            $_SESSION['user_email'] = $newEmail;

            echo json_encode(['success' => true, 'message' => 'Email updated. A verification email has been sent to your new address. Please verify to continue using your account.']);
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
        * { box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%); 
            min-height: 100vh; 
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .dashboard-container {
            width: 100%;
            max-width: 1000px;
            background: rgba(20, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 25px 80px rgba(0, 0, 0, 0.5);
            margin: 0 auto;
        }
        
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .user-section { display: flex; align-items: center; gap: 14px; }
        .user-avatar {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .user-info h1 { font-size: 18px; font-weight: 700; color: #fff; margin: 0; }
        .user-info p { font-size: 13px; color: rgba(255,255,255,0.5); margin: 2px 0 0; }
        
        .nav-links { display: flex; gap: 8px; }
        .nav-link {
            padding: 10px 18px;
            border-radius: 10px;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
        .nav-link.active { background: rgba(34, 211, 238, 0.15); color: #22d3ee; }
        
        /* Info Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            padding: 16px 12px;
            text-align: center;
        }
        .stat-label { font-size: 11px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
        .stat-value { font-size: 14px; font-weight: 600; color: #fff; }
        
        /* Two Column Forms */
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        
        .card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
        }
        .section-title { font-size: 14px; font-weight: 600; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        
        .form-group { margin-bottom: 14px; }
        .form-label { display: block; font-size: 12px; color: rgba(255, 255, 255, 0.6); margin-bottom: 6px; }
        .form-input { 
            width: 100%; 
            padding: 12px 14px; 
            background: rgba(255, 255, 255, 0.05); 
            border: 1px solid rgba(255, 255, 255, 0.1); 
            border-radius: 10px; 
            color: #fff; 
            font-size: 14px; 
        }
        .form-input:focus { border-color: #22d3ee; outline: none; }

        .btn { 
            padding: 12px 20px; 
            border-radius: 10px; 
            font-size: 13px; 
            font-weight: 600; 
            cursor: pointer; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            border: none; 
        }
        .btn-primary { background: linear-gradient(135deg, #22d3ee, #a855f7); color: #fff; }
        .btn-danger { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        .btn-secondary { background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.15); }

        .danger-card { border-color: rgba(239, 68, 68, 0.2); }
        .warning-box { background: rgba(234, 179, 8, 0.15); border: 1px solid rgba(234, 179, 8, 0.3); border-radius: 10px; padding: 14px; margin-bottom: 14px; }

        .toast { position: fixed; top: 20px; right: 20px; padding: 14px 20px; border-radius: 10px; font-size: 13px; z-index: 1000; display: none; }
        .toast-success { background: rgba(34, 197, 94, 0.9); color: #fff; }
        .toast-error { background: rgba(239, 68, 68, 0.9); color: #fff; }
        
        @media (max-width: 600px) {
            .two-col { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .nav-links { display: none; }
        }

        /* Light theme */
        [data-theme="light"] body { background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 50%, #f0f4f8 100%); }
        [data-theme="light"] .dashboard-container { background: rgba(255, 255, 255, 0.9); border-color: rgba(0, 0, 0, 0.1); box-shadow: 0 25px 80px rgba(0, 0, 0, 0.1); }
        [data-theme="light"] .text-white, [data-theme="light"] h1, [data-theme="light"] h2, [data-theme="light"] .section-title, [data-theme="light"] label,
        [data-theme="light"] .user-info h1, [data-theme="light"] .stat-value, [data-theme="light"] .form-input { color: #1a202c !important; }
        [data-theme="light"] .header { border-color: rgba(0, 0, 0, 0.08); }
        [data-theme="light"] .nav-link { color: rgba(0, 0, 0, 0.6); }
        [data-theme="light"] .nav-link:hover { background: rgba(0, 0, 0, 0.05); color: #1a202c; }
        [data-theme="light"] .nav-link.active { background: rgba(34, 211, 238, 0.15); color: #0891b2; }
        [data-theme="light"] .text-white\/50, [data-theme="light"] .user-info p, [data-theme="light"] .stat-label, 
        [data-theme="light"] .form-label { color: rgba(0, 0, 0, 0.5) !important; }
        [data-theme="light"] .settings-card, [data-theme="light"] .stat-card, [data-theme="light"] .card { background: rgba(0, 0, 0, 0.03); border-color: rgba(0, 0, 0, 0.08); }
        [data-theme="light"] .settings-input, [data-theme="light"] .settings-select, [data-theme="light"] .form-input { 
            background: rgba(0, 0, 0, 0.03); border-color: rgba(0, 0, 0, 0.1); color: #1a202c; 
        }
        [data-theme="light"] .btn-secondary { background: rgba(0, 0, 0, 0.05); color: rgba(0, 0, 0, 0.7); border-color: rgba(0, 0, 0, 0.1); }
        [data-theme="light"] .warning-box { background: rgba(234, 179, 8, 0.1); }
        
        /* Theme Toggle */
        .theme-toggle-btn { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); color: rgba(255, 255, 255, 0.6); cursor: pointer; transition: all 0.2s; }
        .theme-toggle-btn:hover { background: rgba(255, 255, 255, 0.1); color: #22d3ee; }
        [data-theme="light"] .theme-toggle-btn { background: rgba(0, 0, 0, 0.05); border-color: rgba(0, 0, 0, 0.1); color: rgba(0, 0, 0, 0.6); }
        [data-theme="light"] .theme-toggle-btn:hover { background: rgba(0, 0, 0, 0.1); color: #0891b2; }
        #theme-icon-light { display: none; }
        #theme-icon-dark { display: block; }
        [data-theme="light"] #theme-icon-light { display: block; }
        [data-theme="light"] #theme-icon-dark { display: none; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="header">
            <div class="user-section">
                <div class="user-avatar"><i data-lucide="settings" class="w-6 h-6 text-white"></i></div>
                <div class="user-info">
                    <h1>Account Settings</h1>
                    <p><?= htmlspecialchars($user['email']) ?></p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/dashboard" class="nav-link"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard</a>
                <a href="/gallery" class="nav-link"><i data-lucide="images" class="w-4 h-4"></i> Gallery</a>
                <a href="/member/tools" class="nav-link"><i data-lucide="wrench" class="w-4 h-4"></i> Tools</a>
                <a href="/member/settings" class="nav-link active"><i data-lucide="settings" class="w-4 h-4"></i> Settings</a>
                <button class="theme-toggle-btn" onclick="toggleTheme()" title="Toggle Theme">
                    <i data-lucide="sun" id="theme-icon-light" class="w-4 h-4"></i>
                    <i data-lucide="moon" id="theme-icon-dark" class="w-4 h-4"></i>
                </button>
                <a href="/auth/logout" class="nav-link" style="color: #ef4444;"><i data-lucide="log-out" class="w-4 h-4"></i></a>
            </div>
        </div>
        
        <!-- Account Info -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Account</div>
                <div class="stat-value"><?= ucfirst($user['account_type'] ?? 'free') ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Member Since</div>
                <div class="stat-value"><?= date('M j, Y', strtotime($user['created_at'])) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Email</div>
                <div class="stat-value" style="color: <?= $user['email_verified_at'] ? '#22c55e' : '#ef4444' ?>">
                    <?= $user['email_verified_at'] ? '✓ Verified' : '✗ Unverified' ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Role</div>
                <div class="stat-value"><?= $isAdmin ? 'Admin' : 'User' ?></div>
            </div>
        </div>

        <!-- Two Column: Password & Email -->
        <div class="two-col">
            <div class="card">
                <div class="section-title"><i data-lucide="key" class="w-4 h-4" style="color: #a855f7;"></i> Change Password</div>
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
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-input" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Update</button>
                </form>
            </div>

            <div class="card">
                <div class="section-title"><i data-lucide="mail" class="w-4 h-4" style="color: #ec4899;"></i> Change Email</div>
                <form id="emailForm">
                    <div class="form-group">
                        <label class="form-label">New Email</label>
                        <input type="email" name="new_email" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" name="password" class="form-input" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Update</button>
                </form>
            </div>
        </div>

        <?php if (!$isAdmin): ?>
        <div class="card danger-card">
            <div class="section-title" style="color: #ef4444;"><i data-lucide="alert-triangle" class="w-4 h-4"></i> Danger Zone</div>
            <?php if ($hasPendingDeletion): ?>
            <div class="warning-box">
                <div style="font-weight: 600; color: #eab308; margin-bottom: 4px;">⚠️ Account deletion scheduled</div>
                <div style="font-size: 13px; color: rgba(255,255,255,0.6);">Your account will be deleted on <?= date('M j, Y \a\t H:i', strtotime($user['delete_requested_at'])) ?></div>
            </div>
            <button type="button" class="btn btn-secondary" id="cancelDeleteBtn"><i data-lucide="x" class="w-4 h-4"></i> Cancel Deletion</button>
            <?php else: ?>
            <p style="font-size: 13px; color: rgba(255,255,255,0.5); margin-bottom: 16px;">Once you delete your account, there is no going back. You have 48 hours to cancel.</p>
            <form id="deleteForm" style="display: flex; gap: 12px; align-items: flex-end;">
                <div class="form-group" style="flex: 1; margin: 0;">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="password" class="form-input" required>
                </div>
                <button type="submit" class="btn btn-danger"><i data-lucide="trash-2" class="w-4 h-4"></i> Delete Account</button>
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
        
        // Theme Toggle
        function toggleTheme() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme') || 'dark';
            const next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('pixelhop-theme', next);
            lucide.createIcons();
        }
    </script>
</body>
</html>
