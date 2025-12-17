<?php
/**
 * PixelHop - User Dashboard
 * Compact Centered Design
 */

session_start();
require_once __DIR__ . '/auth/middleware.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/core/Gatekeeper.php';

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$db = Database::getInstance();
$gatekeeper = new Gatekeeper();
$currentUser = getCurrentUser();
$isAdmin = isAdmin();

// Get user's quota and usage - Admin has unlimited access
$storageUsed = $currentUser['storage_used'] ?? 0;
if ($isAdmin) {
    $storageLimit = PHP_INT_MAX;
    $storagePercent = 0;
    $ocrLimit = PHP_INT_MAX;
    $rembgLimit = PHP_INT_MAX;
} else {
    $storageLimit = ($currentUser['account_type'] ?? 'free') === 'premium' ? 5 * 1024 * 1024 * 1024 : 500 * 1024 * 1024;
    $storagePercent = $storageLimit > 0 ? round(($storageUsed / $storageLimit) * 100, 1) : 0;
    $ocrLimit = $gatekeeper->getSetting(($currentUser['account_type'] ?? 'free') === 'premium' ? 'daily_ocr_limit_premium' : 'daily_ocr_limit_free');
    $rembgLimit = $gatekeeper->getSetting(($currentUser['account_type'] ?? 'free') === 'premium' ? 'daily_removebg_limit_premium' : 'daily_removebg_limit_free');
}

$ocrUsed = $currentUser['daily_ocr_count'] ?? 0;
$rembgUsed = $currentUser['daily_removebg_count'] ?? 0;

// Get user's recent uploads - filter by user_id
$imagesFile = __DIR__ . '/data/images.json';
$userImages = [];
if (file_exists($imagesFile)) {
    $allImages = json_decode(file_get_contents($imagesFile), true) ?: [];

    $myImages = array_filter($allImages, function($img) use ($currentUser) {
        return isset($img['user_id']) && $img['user_id'] == $currentUser['id'];
    });

    usort($myImages, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));
    $userImages = array_slice($myImages, 0, 6);
}

// Get recent tool usage
$userId = $currentUser['id'];
$recentLogs = $db->query("SELECT * FROM usage_logs WHERE user_id = $userId ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - PixelHop</title>
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
        }

        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .user-section {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .user-avatar {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            font-weight: 700;
            color: #fff;
        }

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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 700px) {
            .stats-grid { grid-template-columns: 1fr; }
            .nav-links { display: none; }
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }

        .stat-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .progress-ring {
            position: relative;
            width: 90px;
            height: 90px;
            margin: 0 auto 12px;
        }

        .progress-ring svg {
            transform: rotate(-90deg);
            width: 100%;
            height: 100%;
        }

        .progress-bg {
            fill: none;
            stroke: rgba(255, 255, 255, 0.08);
            stroke-width: 8;
        }

        .progress-fill {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.8s ease;
        }

        .progress-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .progress-value {
            font-size: 18px;
            font-weight: 700;
            color: #fff;
        }

        .progress-unit {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.5);
        }

        .stat-detail {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 700px) {
            .content-grid { grid-template-columns: 1fr; }
        }

        .content-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
        }

        .card-link {
            font-size: 12px;
            color: #22d3ee;
            text-decoration: none;
        }

        .card-link:hover {
            text-decoration: underline;
        }

        .tool-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
        }

        .tool-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            color: #fff;
            text-decoration: none;
            transition: all 0.2s;
        }

        .tool-btn:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateY(-2px);
        }

        .tool-btn i {
            width: 24px;
            height: 24px;
        }

        .tool-btn span {
            font-size: 11px;
            font-weight: 500;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .gallery-item {
            aspect-ratio: 1;
            border-radius: 10px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.05);
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-empty {
            grid-column: span 3;
            text-align: center;
            padding: 30px;
            color: rgba(255, 255, 255, 0.4);
            font-size: 13px;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .activity-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 10px;
        }

        .activity-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .activity-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .activity-text {
            font-size: 13px;
            color: #fff;
        }

        .activity-time {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 500;
        }
        .badge-green { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
        .badge-yellow { background: rgba(234, 179, 8, 0.2); color: #eab308; }
        .badge-red { background: rgba(239, 68, 68, 0.2); color: #ef4444; }

        .text-cyan { color: #22d3ee; }
        .text-purple { color: #a855f7; }
        .text-green { color: #22c55e; }
        .text-yellow { color: #eab308; }
        .text-pink { color: #ec4899; }

        .stroke-cyan { stroke: #22d3ee; }
        .stroke-purple { stroke: #a855f7; }
        .stroke-yellow { stroke: #eab308; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header">
            <div class="user-section">
                <div class="user-avatar">
                    <?= strtoupper(substr($currentUser['email'], 0, 1)) ?>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-white"><?= htmlspecialchars(explode('@', $currentUser['email'])[0]) ?></h1>
                    <p class="text-xs">
                        <?php if ($isAdmin): ?>
                        <span class="badge badge-red">Admin</span>
                        <?php else: ?>
                        <span class="badge <?= ($currentUser['account_type'] ?? 'free') === 'premium' ? 'badge-yellow' : 'badge-green' ?>">
                            <?= ucfirst($currentUser['account_type'] ?? 'free') ?>
                        </span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/dashboard.php" class="nav-link active">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="/gallery.php" class="nav-link">
                    <i data-lucide="images" class="w-4 h-4"></i>
                    Gallery
                </a>
                <a href="/member/tools.php" class="nav-link">
                    <i data-lucide="wrench" class="w-4 h-4"></i>
                    Tools
                </a>
                <?php if ($isAdmin): ?>
                <a href="/admin/dashboard.php" class="nav-link">
                    <i data-lucide="shield" class="w-4 h-4"></i>
                    Admin
                </a>
                <?php endif; ?>
                <a href="/member/settings.php" class="nav-link">
                    <i data-lucide="settings" class="w-4 h-4"></i>
                    Settings
                </a>
                <a href="/" class="nav-link">
                    <i data-lucide="home" class="w-4 h-4"></i>
                    Home
                </a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <!-- Storage -->
            <div class="stat-card">
                <div class="stat-label">
                    <i data-lucide="hard-drive" class="w-4 h-4 text-cyan"></i>
                    Storage
                </div>
                <div class="progress-ring">
                    <svg viewBox="0 0 90 90">
                        <circle class="progress-bg" cx="45" cy="45" r="38"></circle>
                        <circle class="progress-fill stroke-cyan" cx="45" cy="45" r="38"
                            stroke-dasharray="239"
                            stroke-dashoffset="<?= 239 - (min($storagePercent, 100) / 100 * 239) ?>"></circle>
                    </svg>
                    <div class="progress-center">
                        <div class="progress-value"><?= $storagePercent ?>%</div>
                        <div class="progress-unit">used</div>
                    </div>
                </div>
                <div class="stat-detail">
                    <?php if ($isAdmin): ?>
                        <span style="color: #22d3ee;">∞ Unlimited</span>
                    <?php else: ?>
                        <?= formatBytes($storageUsed) ?> / <?= formatBytes($storageLimit) ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- OCR Quota -->
            <div class="stat-card">
                <div class="stat-label">
                    <i data-lucide="scan-text" class="w-4 h-4 text-yellow"></i>
                    OCR Today
                </div>
                <div class="progress-ring">
                    <svg viewBox="0 0 90 90">
                        <circle class="progress-bg" cx="45" cy="45" r="38"></circle>
                        <?php if ($isAdmin): ?>
                        <circle class="progress-fill stroke-yellow" cx="45" cy="45" r="38"
                            stroke-dasharray="239" stroke-dashoffset="0"></circle>
                        <?php else: ?>
                        <circle class="progress-fill stroke-yellow" cx="45" cy="45" r="38"
                            stroke-dasharray="239"
                            stroke-dashoffset="<?= 239 - (min($ocrLimit > 0 ? ($ocrUsed / $ocrLimit) * 100 : 0, 100) / 100 * 239) ?>"></circle>
                        <?php endif; ?>
                    </svg>
                    <div class="progress-center">
                        <?php if ($isAdmin): ?>
                        <div class="progress-value">∞</div>
                        <div class="progress-unit">unlimited</div>
                        <?php else: ?>
                        <div class="progress-value"><?= $ocrUsed ?></div>
                        <div class="progress-unit">/ <?= $ocrLimit ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="stat-detail">
                    <?php if ($isAdmin): ?>
                        <span style="color: #eab308;">∞ Unlimited</span>
                    <?php else: ?>
                        <?= $ocrLimit - $ocrUsed ?> remaining
                    <?php endif; ?>
                </div>
            </div>

            <!-- RemBG Quota -->
            <div class="stat-card">
                <div class="stat-label">
                    <i data-lucide="eraser" class="w-4 h-4 text-purple"></i>
                    RemBG Today
                </div>
                <div class="progress-ring">
                    <svg viewBox="0 0 90 90">
                        <circle class="progress-bg" cx="45" cy="45" r="38"></circle>
                        <?php if ($isAdmin): ?>
                        <circle class="progress-fill stroke-purple" cx="45" cy="45" r="38"
                            stroke-dasharray="239" stroke-dashoffset="0"></circle>
                        <?php else: ?>
                        <circle class="progress-fill stroke-purple" cx="45" cy="45" r="38"
                            stroke-dasharray="239"
                            stroke-dashoffset="<?= 239 - (min($rembgLimit > 0 ? ($rembgUsed / $rembgLimit) * 100 : 0, 100) / 100 * 239) ?>"></circle>
                        <?php endif; ?>
                    </svg>
                    <div class="progress-center">
                        <?php if ($isAdmin): ?>
                        <div class="progress-value">∞</div>
                        <div class="progress-unit">unlimited</div>
                        <?php else: ?>
                        <div class="progress-value"><?= $rembgUsed ?></div>
                        <div class="progress-unit">/ <?= $rembgLimit ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="stat-detail">
                    <?php if ($isAdmin): ?>
                        <span style="color: #a855f7;">∞ Unlimited</span>
                    <?php else: ?>
                        <?= $rembgLimit - $rembgUsed ?> remaining
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Content Grid -->
        <div class="content-grid">
            <!-- Quick Tools -->
            <div class="content-card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="zap" class="w-4 h-4 text-yellow"></i>
                        Quick Tools
                    </div>
                    <a href="/member/tools.php" class="card-link">View All →</a>
                </div>
                <div class="tool-grid">
                    <a href="/member/upload.php" class="tool-btn">
                        <i data-lucide="upload" class="text-cyan"></i>
                        <span>Upload</span>
                    </a>
                    <a href="/member/compress.php" class="tool-btn">
                        <i data-lucide="file-minus" class="text-green"></i>
                        <span>Compress</span>
                    </a>
                    <a href="/member/resize.php" class="tool-btn">
                        <i data-lucide="scaling" class="text-purple"></i>
                        <span>Resize</span>
                    </a>
                    <a href="/member/ocr.php" class="tool-btn">
                        <i data-lucide="scan-text" class="text-yellow"></i>
                        <span>OCR</span>
                    </a>
                    <a href="/member/rembg.php" class="tool-btn">
                        <i data-lucide="eraser" class="text-pink"></i>
                        <span>Remove BG</span>
                    </a>
                    <a href="/member/convert.php" class="tool-btn">
                        <i data-lucide="repeat" class="text-cyan"></i>
                        <span>Convert</span>
                    </a>
                </div>
            </div>

            <!-- Recent Uploads -->
            <div class="content-card">
                <div class="card-header">
                    <div class="card-title">
                        <i data-lucide="images" class="w-4 h-4 text-purple"></i>
                        Recent Uploads
                    </div>
                    <a href="/gallery.php" class="card-link">View Gallery →</a>
                </div>
                <div class="gallery-grid">
                    <?php if (empty($userImages)): ?>
                    <div class="gallery-empty">
                        <i data-lucide="image-off" class="w-8 h-8 mx-auto mb-2 opacity-50"></i>
                        <p>No uploads yet</p>
                    </div>
                    <?php else: ?>
                        <?php foreach (array_slice($userImages, 0, 6) as $img): ?>
                        <div class="gallery-item">
                            <img src="<?= htmlspecialchars($img['urls']['thumb'] ?? $img['urls']['medium'] ?? $img['urls']['original'] ?? '') ?>" alt="" onerror="this.style.display='none'">
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-6 pt-5 border-t flex justify-between items-center" style="border-color: rgba(255,255,255,0.08);">
            <div class="text-xs flex items-center gap-3" style="color: rgba(255,255,255,0.4);">
                <button onclick="toggleTheme()" class="w-8 h-8 rounded-lg flex items-center justify-center transition hover:bg-white/10" style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color: rgba(255,255,255,0.6);" title="Toggle theme">
                    <i data-lucide="sun" class="w-4 h-4 theme-icon-light"></i>
                    <i data-lucide="moon" class="w-4 h-4 theme-icon-dark"></i>
                </button>
                Member since <?= date('M Y', strtotime($currentUser['created_at'])) ?>
            </div>
            <div class="flex gap-4">
                <a href="/tools" class="text-xs hover:text-cyan-400 transition" style="color: rgba(255,255,255,0.5);">Tools</a>
                <a href="/help" class="text-xs hover:text-cyan-400 transition" style="color: rgba(255,255,255,0.5);">Help</a>
                <a href="/auth/logout.php" class="text-xs hover:text-red-400 transition" style="color: rgba(255,255,255,0.5);">Logout</a>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();


        function toggleTheme() {
            const html = document.documentElement;
            const current = html.getAttribute('data-theme') || 'dark';
            const next = current === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('pixelhop-theme', next);
            updateThemeIcon();
        }

        function updateThemeIcon() {
            const isDark = document.documentElement.getAttribute('data-theme') !== 'light';
            document.querySelectorAll('.theme-icon-light').forEach(el => el.style.display = isDark ? 'none' : 'block');
            document.querySelectorAll('.theme-icon-dark').forEach(el => el.style.display = isDark ? 'block' : 'none');
        }
        updateThemeIcon();
    </script>
</body>
</html>
