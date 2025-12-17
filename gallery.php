<?php
/**
 * PixelHop - User Gallery
 * View and manage uploaded images
 */

session_start();
require_once __DIR__ . '/auth/middleware.php';
require_once __DIR__ . '/includes/Database.php';

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$currentUser = getCurrentUser();
$isAdmin = isAdmin();
$currentUserId = $currentUser['id'] ?? null;

$imagesFile = __DIR__ . '/data/images.json';
$userImages = [];
if (file_exists($imagesFile)) {
    $allImages = json_decode(file_get_contents($imagesFile), true) ?: [];
    foreach (array_reverse($allImages, true) as $id => $img) {
        if (isset($img['user_id']) && $img['user_id'] == $currentUserId) {
            $userImages[$id] = $img;
        }
    }
}

$totalImages = count($userImages);
$totalSize = 0;
foreach ($userImages as $img) {
    $totalSize += $img['size'] ?? 0;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery - PixelHop</title>
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
            max-width: 1100px;
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

        .stats-row {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-box {
            padding: 16px 24px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            text-align: center;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #fff;
        }

        .stat-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }

        @media (max-width: 900px) {
            .gallery-grid { grid-template-columns: repeat(3, 1fr); }
            .nav-links { display: none; }
        }

        @media (max-width: 600px) {
            .gallery-grid { grid-template-columns: repeat(2, 1fr); }
        }

        .gallery-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 14px;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.05);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .gallery-item:hover {
            transform: scale(1.02);
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4);
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .gallery-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, transparent 50%);
            opacity: 0;
            transition: opacity 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 12px;
        }

        .gallery-item:hover .gallery-overlay {
            opacity: 1;
        }

        .gallery-info {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.8);
        }

        .gallery-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }

        .gallery-btn {
            padding: 6px 10px;
            border-radius: 6px;
            border: none;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            transition: all 0.2s;
        }

        .gallery-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: rgba(255, 255, 255, 0.4);
        }

        .empty-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            opacity: 0.5;
        }

        .upload-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: 12px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            color: #fff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            margin-top: 16px;
            transition: all 0.2s;
        }

        .upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(34, 211, 238, 0.3);
        }

        .text-cyan { color: #22d3ee; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header">
            <div class="title-section">
                <div class="title-icon">
                    <i data-lucide="images" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">My Gallery</h1>
                    <p class="text-xs text-white/50"><?= $totalImages ?> images • <?= formatBytes($totalSize) ?></p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/dashboard.php" class="nav-link">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="/gallery.php" class="nav-link active">
                    <i data-lucide="images" class="w-4 h-4"></i>
                    Gallery
                </a>
                <a href="/member/tools.php" class="nav-link">
                    <i data-lucide="wrench" class="w-4 h-4"></i>
                    Tools
                </a>
                <a href="/member/upload.php" class="nav-link">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    Upload
                </a>
            </div>
        </div>

        <?php if (empty($userImages)): ?>
        <!-- Empty State -->
        <div class="empty-state">
            <i data-lucide="image-off" class="empty-icon"></i>
            <h2 class="text-lg font-semibold text-white mb-2">No images yet</h2>
            <p class="text-sm">Start uploading images to build your gallery</p>
            <a href="/member/upload.php" class="upload-btn">
                <i data-lucide="upload" class="w-4 h-4"></i>
                Upload Image
            </a>
        </div>
        <?php else: ?>
        <!-- Gallery Grid -->
        <div class="gallery-grid">
            <?php foreach ($userImages as $img): ?>
            <div class="gallery-item" data-id="<?= htmlspecialchars($img['id']) ?>">
                <img src="<?= htmlspecialchars($img['urls']['thumb'] ?? $img['urls']['medium'] ?? $img['urls']['original'] ?? '') ?>" alt="" onerror="this.style.display='none'">
                <div class="gallery-overlay">
                    <div class="gallery-info">
                        <?= strtoupper($img['extension'] ?? 'unknown') ?> • <?= formatBytes($img['size'] ?? 0) ?>
                    </div>
                    <div class="gallery-actions">
                        <a href="/<?= htmlspecialchars($img['id']) ?>" class="gallery-btn" target="_blank">View</a>
                        <a href="<?= htmlspecialchars($img['urls']['original'] ?? '') ?>" class="gallery-btn" download>Download</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
