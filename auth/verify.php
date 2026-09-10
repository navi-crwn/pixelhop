<?php
/**
 * PixelHop - Email Verification Endpoint
 * GET /auth/verify.php?token=xxx
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Mailer.php';

$token = trim($_GET['token'] ?? '');
$error = null;
$success = false;

if (empty($token)) {
    $error = 'Invalid verification link';
} else {
    try {
        $db = Database::getInstance();
        
        // Find user with this token
        $stmt = $db->prepare("
            SELECT id, email, email_verified, email_verification_expires 
            FROM users 
            WHERE email_verification_token = ? 
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $error = 'Invalid or expired verification link';
        } elseif ($user['email_verified']) {
            $error = 'Email already verified';
            $success = true; // Show success UI anyway
        } elseif ($user['email_verification_expires'] && strtotime($user['email_verification_expires']) < time()) {
            $error = 'Verification link has expired. Please request a new one.';
        } else {
            // Verify the email
            $updateStmt = $db->prepare("
                UPDATE users 
                SET email_verified = 1, 
                    email_verification_token = NULL, 
                    email_verification_expires = NULL,
                    email_verified_at = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$user['id']]);
            
            $success = true;
            
            // Send welcome email
            $mailer = new Mailer();
            $mailer->sendWelcomeEmail($user['email']);
            
            // Auto-login user
            $userStmt = $db->prepare("SELECT id, email, role, account_type FROM users WHERE id = ?");
            $userStmt->execute([$user['id']]);
            $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($userData) {
                require_once __DIR__ . '/middleware.php';
                setUserSession($userData);
            }
        }
        
    } catch (Exception $e) {
        error_log('Verification error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

$config = require __DIR__ . '/../config/s3.php';
$siteName = $config['site']['name'] ?? 'PixelHop';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification - <?= htmlspecialchars($siteName) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            if (savedTheme) document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/assets/css/glass.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        [data-theme="light"] body {
            background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 50%, #f0f4f8 100%);
        }
        .card {
            max-width: 450px;
            width: 100%;
            background: rgba(20, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 48px;
            text-align: center;
        }
        [data-theme="light"] .card {
            background: rgba(255, 255, 255, 0.9);
            border-color: rgba(0, 0, 0, 0.1);
        }
        .icon-success {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon-error {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        h1 { color: #fff; font-size: 24px; margin-bottom: 12px; }
        [data-theme="light"] h1 { color: #1a202c; }
        p { color: rgba(255, 255, 255, 0.6); font-size: 15px; margin-bottom: 24px; }
        [data-theme="light"] p { color: rgba(0, 0, 0, 0.6); }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            color: #fff;
            font-weight: 600;
            border-radius: 12px;
            text-decoration: none;
            transition: transform 0.2s;
        }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-weight: 500;
            border-radius: 12px;
            text-decoration: none;
            margin-top: 12px;
        }
        [data-theme="light"] .btn-secondary {
            background: rgba(0, 0, 0, 0.05);
            color: #1a202c;
        }
    </style>
</head>
<body>
    <div class="card">
        <?php if ($success): ?>
            <div class="icon-success">
                <i data-lucide="check" class="w-10 h-10 text-white"></i>
            </div>
            <h1>Email Verified!</h1>
            <p>Your email has been verified successfully. You can now enjoy all features of PixelHop.</p>
            <a href="/dashboard" class="btn-primary">
                <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                Go to Dashboard
            </a>
        <?php else: ?>
            <div class="icon-error">
                <i data-lucide="x" class="w-10 h-10 text-white"></i>
            </div>
            <h1>Verification Failed</h1>
            <p><?= htmlspecialchars($error) ?></p>
            <a href="/login" class="btn-primary">
                <i data-lucide="log-in" class="w-5 h-5"></i>
                Go to Login
            </a>
            <a href="/auth/resend-verification" class="btn-secondary">
                Resend Verification Email
            </a>
        <?php endif; ?>
    </div>
    
    <script>lucide.createIcons();</script>
</body>
</html>
