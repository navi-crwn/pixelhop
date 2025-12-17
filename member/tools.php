<?php
/**
 * PixelHop - Member Tools
 * Image processing tools with quota display
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
$isPremium = ($currentUser['account_type'] ?? 'free') === 'premium';

// Get quotas from database
$db = Database::getInstance();

// Get daily limits from settings
$limitStmt = $db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('daily_ocr_limit_free', 'daily_ocr_limit_premium', 'daily_removebg_limit_free', 'daily_removebg_limit_premium')");
$limits = [];
while ($row = $limitStmt->fetch(PDO::FETCH_ASSOC)) {
    $limits[$row['setting_key']] = (int)$row['setting_value'];
}

$ocrLimit = $isPremium ? ($limits['daily_ocr_limit_premium'] ?? 50) : ($limits['daily_ocr_limit_free'] ?? 5);
$rembgLimit = $isPremium ? ($limits['daily_removebg_limit_premium'] ?? 30) : ($limits['daily_removebg_limit_free'] ?? 3);

// Get today's usage
$today = date('Y-m-d');
$userId = $currentUser['id'];

$ocrUsageStmt = $db->prepare("SELECT COUNT(*) FROM usage_logs WHERE user_id = ? AND tool_name = 'ocr' AND DATE(created_at) = ?");
$ocrUsageStmt->execute([$userId, $today]);
$ocrUsed = (int)$ocrUsageStmt->fetchColumn();

$rembgUsageStmt = $db->prepare("SELECT COUNT(*) FROM usage_logs WHERE user_id = ? AND tool_name = 'rembg' AND DATE(created_at) = ?");
$rembgUsageStmt->execute([$userId, $today]);
$rembgUsed = (int)$rembgUsageStmt->fetchColumn();

$tools = [
    [
        'id' => 'compress',
        'name' => 'Compress',
        'desc' => 'Reduce file size while maintaining quality',
        'icon' => 'file-archive',
        'color' => '#22d3ee',
        'premium' => false,
        'quota' => null
    ],
    [
        'id' => 'resize',
        'name' => 'Resize',
        'desc' => 'Change image dimensions',
        'icon' => 'maximize-2',
        'color' => '#a855f7',
        'premium' => false,
        'quota' => null
    ],
    [
        'id' => 'crop',
        'name' => 'Crop',
        'desc' => 'Cut and trim images',
        'icon' => 'crop',
        'color' => '#f472b6',
        'premium' => false,
        'quota' => null
    ],
    [
        'id' => 'convert',
        'name' => 'Convert',
        'desc' => 'Change image format',
        'icon' => 'repeat',
        'color' => '#4ade80',
        'premium' => false,
        'quota' => null
    ],
    [
        'id' => 'ocr',
        'name' => 'OCR',
        'desc' => 'Extract text from images',
        'icon' => 'scan-text',
        'color' => '#f59e0b',
        'premium' => false,
        'quota' => [
            'used' => $ocrUsed,
            'limit' => $ocrLimit,
            'remaining' => max(0, $ocrLimit - $ocrUsed)
        ]
    ],
    [
        'id' => 'rembg',
        'name' => 'Remove BG',
        'desc' => 'Remove image background using AI',
        'icon' => 'eraser',
        'color' => '#ef4444',
        'premium' => false,
        'quota' => [
            'used' => $rembgUsed,
            'limit' => $rembgLimit,
            'remaining' => max(0, $rembgLimit - $rembgUsed)
        ]
    ]
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Image Tools - PixelHop</title>
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

        .title-section {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .title-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .account-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-free {
            background: rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.6);
        }

        .badge-premium {
            background: linear-gradient(135deg, #f59e0b, #f97316);
            color: #000;
        }

        .badge-admin {
            background: linear-gradient(135deg, #22d3ee, #a855f7);
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

        @media (max-width: 768px) {
            .nav-links { display: none; }
        }

        .quota-summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        @media (max-width: 600px) {
            .quota-summary { grid-template-columns: 1fr; }
        }

        .quota-card {
            padding: 20px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 14px;
        }

        .quota-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .quota-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quota-bar {
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
        }

        .quota-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .quota-text {
            display: flex;
            justify-content: space-between;
            margin-top: 8px;
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
        }

        .tools-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }

        @media (max-width: 900px) {
            .tools-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 500px) {
            .tools-grid { grid-template-columns: 1fr; }
        }

        .tool-card {
            padding: 24px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: block;
        }

        .tool-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255, 255, 255, 0.15);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        }

        .tool-card.disabled {
            opacity: 0.5;
            pointer-events: none;
        }

        .tool-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }

        .tool-name {
            font-size: 16px;
            font-weight: 600;
            color: #fff;
            margin-bottom: 6px;
        }

        .tool-desc {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.5);
            line-height: 1.4;
        }

        .tool-quota {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 12px;
        }

        .remaining-good { color: #4ade80; }
        .remaining-low { color: #f59e0b; }
        .remaining-none { color: #ef4444; }

        .premium-note {
            margin-top: 24px;
            padding: 16px 20px;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.1), rgba(249, 115, 22, 0.1));
            border: 1px solid rgba(245, 158, 11, 0.2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .premium-note i {
            color: #f59e0b;
        }

        .upgrade-btn {
            margin-left: auto;
            padding: 8px 16px;
            background: linear-gradient(135deg, #f59e0b, #f97316);
            color: #000;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .quota-alert {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #ef4444;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php if (isset($_GET['error']) && $_GET['error'] === 'quota_exceeded'): ?>
        <div class="quota-alert">
            <i data-lucide="alert-triangle" class="w-5 h-5"></i>
            <div>
                <strong>Quota Exceeded!</strong> You have reached your daily limit for <?= htmlspecialchars($_GET['tool'] ?? 'this tool') ?>.
                <?php if (!$isPremium): ?>Upgrade to Premium for higher limits!<?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="header">
            <div class="title-section">
                <div class="title-icon">
                    <i data-lucide="wrench" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">Image Tools</h1>
                    <p class="text-xs text-white/50">
                        <span class="account-badge <?= $isAdmin ? 'badge-admin' : ($isPremium ? 'badge-premium' : 'badge-free') ?>">
                            <?= $isAdmin ? 'Admin' : ($isPremium ? 'Premium' : 'Free') ?>
                        </span>
                    </p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/dashboard.php" class="nav-link">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="/gallery.php" class="nav-link">
                    <i data-lucide="images" class="w-4 h-4"></i>
                    Gallery
                </a>
                <a href="/member/tools.php" class="nav-link active">
                    <i data-lucide="wrench" class="w-4 h-4"></i>
                    Tools
                </a>
                <a href="/member/upload.php" class="nav-link">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    Upload
                </a>
            </div>
        </div>

        <!-- Quota Summary -->
        <div class="quota-summary">
            <div class="quota-card">
                <div class="quota-header">
                    <div class="quota-icon" style="background: rgba(245, 158, 11, 0.15);">
                        <i data-lucide="scan-text" class="w-5 h-5" style="color: #f59e0b;"></i>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-white">OCR Quota</div>
                        <div class="text-xs text-white/50">Text extraction</div>
                    </div>
                </div>
                <div class="quota-bar">
                    <div class="quota-fill" style="width: <?= min(100, ($ocrUsed / $ocrLimit) * 100) ?>%; background: #f59e0b;"></div>
                </div>
                <div class="quota-text">
                    <span><?= $ocrUsed ?> / <?= $ocrLimit ?> used today</span>
                    <span class="<?= ($ocrLimit - $ocrUsed) > 3 ? 'remaining-good' : (($ocrLimit - $ocrUsed) > 0 ? 'remaining-low' : 'remaining-none') ?>">
                        <?= max(0, $ocrLimit - $ocrUsed) ?> remaining
                    </span>
                </div>
            </div>

            <div class="quota-card">
                <div class="quota-header">
                    <div class="quota-icon" style="background: rgba(239, 68, 68, 0.15);">
                        <i data-lucide="eraser" class="w-5 h-5" style="color: #ef4444;"></i>
                    </div>
                    <div>
                        <div class="text-sm font-semibold text-white">Remove BG Quota</div>
                        <div class="text-xs text-white/50">AI background removal</div>
                    </div>
                </div>
                <div class="quota-bar">
                    <div class="quota-fill" style="width: <?= min(100, ($rembgUsed / $rembgLimit) * 100) ?>%; background: #ef4444;"></div>
                </div>
                <div class="quota-text">
                    <span><?= $rembgUsed ?> / <?= $rembgLimit ?> used today</span>
                    <span class="<?= ($rembgLimit - $rembgUsed) > 3 ? 'remaining-good' : (($rembgLimit - $rembgUsed) > 0 ? 'remaining-low' : 'remaining-none') ?>">
                        <?= max(0, $rembgLimit - $rembgUsed) ?> remaining
                    </span>
                </div>
            </div>
        </div>

        <!-- Tools Grid -->
        <div class="tools-grid">
            <?php foreach ($tools as $tool): ?>
            <?php
                $isDisabled = false;
                if ($tool['quota'] !== null && $tool['quota']['remaining'] <= 0) {
                    $isDisabled = true;
                }
            ?>
            <a href="/member/<?= $tool['id'] ?>.php" class="tool-card <?= $isDisabled ? 'disabled' : '' ?>">
                <div class="tool-icon" style="background: <?= $tool['color'] ?>20;">
                    <i data-lucide="<?= $tool['icon'] ?>" class="w-6 h-6" style="color: <?= $tool['color'] ?>;"></i>
                </div>
                <div class="tool-name"><?= $tool['name'] ?></div>
                <div class="tool-desc"><?= $tool['desc'] ?></div>
                <?php if ($tool['quota'] !== null): ?>
                <div class="tool-quota">
                    <span class="<?= $tool['quota']['remaining'] > 3 ? 'remaining-good' : ($tool['quota']['remaining'] > 0 ? 'remaining-low' : 'remaining-none') ?>">
                        <?php if ($tool['quota']['remaining'] > 0): ?>
                            <?= $tool['quota']['remaining'] ?> uses left today
                        <?php else: ?>
                            Quota exhausted - resets at midnight
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (!$isPremium && !$isAdmin): ?>
        <!-- Upgrade Note -->
        <div class="premium-note">
            <i data-lucide="sparkles" class="w-5 h-5"></i>
            <div>
                <div class="text-sm font-semibold text-white">Need more quota?</div>
                <div class="text-xs text-white/60">Premium users get 50 OCR and 30 Remove BG uses daily</div>
            </div>
            <button onclick="showComingSoon()" class="upgrade-btn">Upgrade</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Coming Soon Modal -->
    <div id="comingSoonModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:rgba(30,30,50,0.95); border:1px solid rgba(255,255,255,0.1); border-radius:20px; padding:40px; text-align:center; max-width:400px; backdrop-filter:blur(20px);">
            <i data-lucide="rocket" class="w-16 h-16 mx-auto mb-4 text-cyan-400"></i>
            <h3 style="font-size:1.5rem; font-weight:600; color:#fff; margin-bottom:12px;">Coming Soon!</h3>
            <p style="color:rgba(255,255,255,0.6); margin-bottom:24px;">Premium plans will be available soon. Stay tuned for exclusive features and higher limits!</p>
            <button onclick="hideComingSoon()" style="padding:12px 32px; background:linear-gradient(135deg,#22d3ee,#0891b2); color:#fff; border:none; border-radius:10px; font-weight:600; cursor:pointer;">Got it!</button>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function showComingSoon() {
            const modal = document.getElementById('comingSoonModal');
            modal.style.display = 'flex';
            lucide.createIcons();
        }

        function hideComingSoon() {
            document.getElementById('comingSoonModal').style.display = 'none';
        }

        document.getElementById('comingSoonModal').addEventListener('click', function(e) {
            if (e.target === this) hideComingSoon();
        });
    </script>
</body>
</html>
