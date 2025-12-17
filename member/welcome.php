<?php
/**
 * PixelHop - Welcome Page for New Users
 */

session_start();
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$currentUser = getCurrentUser();
unset($_SESSION['show_welcome']);

$csrfToken = generateCsrfToken();
$config = require __DIR__ . '/../config/s3.php';
$siteName = $config['site']['name'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - <?= htmlspecialchars($siteName) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/assets/css/glass.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #0a0a0f 0%, #1a1a2e 50%, #0a0a0f 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .welcome-card { max-width: 600px; width: 100%; background: rgba(20, 20, 35, 0.85); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.08); border-radius: 24px; padding: 48px; text-align: center; }
        .confetti { position: fixed; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none; overflow: hidden; }
        .confetti span { position: absolute; top: -20px; width: 10px; height: 10px; border-radius: 50%; animation: fall 5s linear infinite; }
        @keyframes fall { to { transform: translateY(100vh) rotate(720deg); } }
        .feature-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin: 32px 0; }
        @media (max-width: 500px) { .feature-grid { grid-template-columns: 1fr; } }
        .feature-item { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 12px; padding: 16px; text-align: left; }
        .feature-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin-bottom: 10px; }
        .btn-primary { display: inline-flex; align-items: center; gap: 8px; padding: 16px 32px; background: linear-gradient(135deg, #22d3ee, #a855f7); color: #fff; font-weight: 600; border-radius: 12px; text-decoration: none; transition: transform 0.2s; }
        .btn-primary:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class="confetti" id="confetti"></div>

    <div class="welcome-card">
        <div class="w-20 h-20 mx-auto mb-6 bg-gradient-to-br from-cyan-400 to-purple-500 rounded-2xl flex items-center justify-center">
            <i data-lucide="party-popper" class="w-10 h-10 text-white"></i>
        </div>

        <h1 class="text-3xl font-bold text-white mb-2">Welcome to <?= htmlspecialchars($siteName) ?>!</h1>
        <p class="text-white/60 mb-8">Your account has been created successfully. Here's what you can do:</p>

        <div class="feature-grid">
            <div class="feature-item">
                <div class="feature-icon" style="background: rgba(34, 211, 238, 0.15);">
                    <i data-lucide="upload-cloud" class="w-5 h-5 text-cyan-400"></i>
                </div>
                <div class="text-sm font-semibold text-white">Upload Images</div>
                <div class="text-xs text-white/50">Store and share your images securely</div>
            </div>
            <div class="feature-item">
                <div class="feature-icon" style="background: rgba(168, 85, 247, 0.15);">
                    <i data-lucide="wand-2" class="w-5 h-5 text-purple-400"></i>
                </div>
                <div class="text-sm font-semibold text-white">Image Tools</div>
                <div class="text-xs text-white/50">Compress, resize, crop, convert</div>
            </div>
            <div class="feature-item">
                <div class="feature-icon" style="background: rgba(236, 72, 153, 0.15);">
                    <i data-lucide="scan-text" class="w-5 h-5 text-pink-400"></i>
                </div>
                <div class="text-sm font-semibold text-white">OCR Text Extraction</div>
                <div class="text-xs text-white/50">Extract text from images with AI</div>
            </div>
            <div class="feature-item">
                <div class="feature-icon" style="background: rgba(34, 197, 94, 0.15);">
                    <i data-lucide="eraser" class="w-5 h-5 text-green-400"></i>
                </div>
                <div class="text-sm font-semibold text-white">Background Removal</div>
                <div class="text-xs text-white/50">AI-powered background removal</div>
            </div>
        </div>

        <a href="/dashboard.php" class="btn-primary">
            <i data-lucide="rocket" class="w-5 h-5"></i>
            Go to Dashboard
        </a>

        <p class="text-xs text-white/30 mt-6">
            Need help? Check out our <a href="/docs.php" class="text-cyan-400 hover:underline">documentation</a>
        </p>
    </div>

    <script>
        lucide.createIcons();


        const colors = ['#22d3ee', '#a855f7', '#ec4899', '#22c55e', '#eab308'];
        const confetti = document.getElementById('confetti');

        for (let i = 0; i < 50; i++) {
            const span = document.createElement('span');
            span.style.left = Math.random() * 100 + '%';
            span.style.background = colors[Math.floor(Math.random() * colors.length)];
            span.style.animationDelay = Math.random() * 3 + 's';
            span.style.animationDuration = (3 + Math.random() * 2) + 's';
            confetti.appendChild(span);
        }


        setTimeout(() => confetti.style.display = 'none', 8000);
    </script>
</body>
</html>
