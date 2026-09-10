<?php
/**
 * PixelHop - Verification Pending Page
 * Shown after registration to prompt user to check email
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
$pendingEmail = $_SESSION['pending_verification_email'] ?? '';

$config = require __DIR__ . '/../config/s3.php';
$siteName = $config['site']['name'] ?? 'PixelHop';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email - <?= htmlspecialchars($siteName) ?></title>
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
            max-width: 500px;
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
        .icon-email {
            width: 80px;
            height: 80px;
            margin: 0 auto 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        h1 { color: #fff; font-size: 24px; margin-bottom: 12px; }
        [data-theme="light"] h1 { color: #1a202c; }
        p { color: rgba(255, 255, 255, 0.6); font-size: 15px; margin-bottom: 16px; line-height: 1.6; }
        [data-theme="light"] p { color: rgba(0, 0, 0, 0.6); }
        .email-providers {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin: 24px 0;
            flex-wrap: wrap;
        }
        .email-btn {
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: #fff;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        [data-theme="light"] .email-btn {
            background: rgba(0, 0, 0, 0.03);
            border-color: rgba(0, 0, 0, 0.1);
            color: #1a202c;
        }
        .email-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }
        .divider {
            display: flex;
            align-items: center;
            gap: 16px;
            margin: 24px 0;
            color: rgba(255, 255, 255, 0.3);
            font-size: 13px;
        }
        [data-theme="light"] .divider { color: rgba(0, 0, 0, 0.3); }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(255, 255, 255, 0.1);
        }
        [data-theme="light"] .divider::before,
        [data-theme="light"] .divider::after {
            background: rgba(0, 0, 0, 0.1);
        }
        .resend-section {
            padding: 16px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            margin-top: 24px;
        }
        [data-theme="light"] .resend-section {
            background: rgba(0, 0, 0, 0.03);
        }
        .resend-btn {
            color: #22d3ee;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }
        .resend-btn:disabled {
            color: rgba(255, 255, 255, 0.3);
            cursor: not-allowed;
        }
        .timer { color: rgba(255, 255, 255, 0.4); font-size: 13px; }
        [data-theme="light"] .timer { color: rgba(0, 0, 0, 0.4); }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
            color: rgba(255, 255, 255, 0.5);
            font-size: 14px;
            text-decoration: none;
        }
        [data-theme="light"] .back-link { color: rgba(0, 0, 0, 0.5); }
        .back-link:hover { color: #22d3ee; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-email">
            <i data-lucide="mail-check" class="w-10 h-10 text-white"></i>
        </div>
        
        <h1>Check your email</h1>
        <p>
            We've sent a verification link to your email address. 
            Click the link to verify your account and start using PixelHop.
        </p>
        
        <div class="email-providers">
            <a href="https://mail.google.com" target="_blank" class="email-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M22 6L12 13L2 6V4L12 11L22 4V6Z" fill="#EA4335"/>
                    <path d="M22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6L12 13L22 6Z" fill="#EA4335"/>
                </svg>
                Open Gmail
            </a>
            <a href="https://outlook.live.com" target="_blank" class="email-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2L2 7V17L12 22L22 17V7L12 2Z" fill="#0078D4"/>
                </svg>
                Open Outlook
            </a>
            <a href="https://mail.yahoo.com" target="_blank" class="email-btn">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2L2 7V17L12 22L22 17V7L12 2Z" fill="#6001D2"/>
                </svg>
                Open Yahoo
            </a>
        </div>
        
        <div class="divider">or</div>
        
        <div class="resend-section">
            <p style="margin: 0 0 12px 0; font-size: 13px;">Didn't receive the email?</p>
            <button class="resend-btn" id="resendBtn" onclick="resendEmail()">
                Resend verification email
            </button>
            <div class="timer" id="timer" style="display: none;"></div>
        </div>
        
        <a href="/login" class="back-link">
            <i data-lucide="arrow-left" class="w-4 h-4"></i>
            Back to login
        </a>
    </div>
    
    <script>
        lucide.createIcons();
        
        let cooldown = 0;
        
        async function resendEmail() {
            if (cooldown > 0) return;
            
            const btn = document.getElementById('resendBtn');
            const timer = document.getElementById('timer');
            
            btn.disabled = true;
            btn.textContent = 'Sending...';
            
            try {
                const response = await fetch('/auth/resend-verification.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: <?= json_encode($csrfToken) ?>,
                        email: <?= json_encode($pendingEmail) ?>
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    btn.textContent = 'Email sent!';
                    startCooldown();
                } else {
                    btn.textContent = result.message || 'Failed to send';
                    setTimeout(() => {
                        btn.textContent = 'Resend verification email';
                        btn.disabled = false;
                    }, 3000);
                }
            } catch (e) {
                btn.textContent = 'Error occurred';
                setTimeout(() => {
                    btn.textContent = 'Resend verification email';
                    btn.disabled = false;
                }, 3000);
            }
        }
        
        function startCooldown() {
            cooldown = 60;
            const btn = document.getElementById('resendBtn');
            const timer = document.getElementById('timer');
            
            timer.style.display = 'block';
            
            const interval = setInterval(() => {
                cooldown--;
                timer.textContent = `Wait ${cooldown}s before resending`;
                
                if (cooldown <= 0) {
                    clearInterval(interval);
                    btn.textContent = 'Resend verification email';
                    btn.disabled = false;
                    timer.style.display = 'none';
                }
            }, 1000);
        }
    </script>
</body>
</html>
