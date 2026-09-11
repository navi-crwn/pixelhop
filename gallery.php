<?php
/**
 * PixelHop - User Gallery
 * View and manage uploaded images
 */

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/auth/middleware.php';
require_once __DIR__ . '/includes/Database.php';

if (!isAuthenticated()) {
    header('Location: /login.php');
    exit;
}

$currentUser = getCurrentUser();
$isAdmin = isAdmin();
$currentUserId = $currentUser['id'] ?? null;
$csrfToken = generateCsrfToken();

require_once __DIR__ . '/includes/ImageRepository.php';
$repo = new ImageRepository();

// Handle AJAX delete requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    // Load storage manager for deletion
    require_once __DIR__ . '/includes/R2StorageManager.php';
    $s3Config = require __DIR__ . '/config/s3.php';
    $storageManager = new R2StorageManager($s3Config);
    
    if ($action === 'delete_images') {
        $imageIds = json_decode($_POST['image_ids'] ?? '[]', true);
        if (!is_array($imageIds) || empty($imageIds)) {
            echo json_encode(['success' => false, 'error' => 'No images specified']);
            exit;
        }
        
        $allImages = $repo->readAll();
        if (empty($allImages)) {
            echo json_encode(['success' => false, 'error' => 'No images found']);
            exit;
        }
        
        $deleted = 0;
        $errors = [];
        
        foreach ($imageIds as $imageId) {
            if (!isset($allImages[$imageId])) {
                $errors[] = "$imageId not found";
                continue;
            }
            
            // Only allow users to delete their own images
            if (!$isAdmin && ($allImages[$imageId]['user_id'] ?? null) != $currentUserId) {
                $errors[] = "$imageId: permission denied";
                continue;
            }
            
            $imgData = $allImages[$imageId];
            
            // Delete from storage (R2 and Contabo)
            if (!empty($imgData['s3_keys'])) {
                $deleteResult = $storageManager->deleteImage($imgData['s3_keys'], $imgData['size'] ?? 0);
                if (!$deleteResult['success']) {
                    $errors[] = "$imageId: storage error";
                }
            }
            
            $repo->delete($imageId);
            $deleted++;
        }
        
        echo json_encode([
            'success' => true, 
            'deleted' => $deleted,
            'errors' => $errors
        ]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$offset = ($page - 1) * $perPage;

// Sort
$sort = $_GET['sort'] ?? 'newest';

$userImages = [];
$allImages = $repo->readAll();
foreach ($allImages as $id => $img) {
    if (isset($img['user_id']) && $img['user_id'] == $currentUserId) {
        $img['id'] = $id;
        $userImages[] = $img;
    }
}

// Sort images
usort($userImages, function($a, $b) use ($sort) {
    switch ($sort) {
        case 'oldest':
            return ($a['created_at'] ?? 0) - ($b['created_at'] ?? 0);
        case 'largest':
            return ($b['size'] ?? 0) - ($a['size'] ?? 0);
        case 'smallest':
            return ($a['size'] ?? 0) - ($b['size'] ?? 0);
        case 'name':
            return strcasecmp($a['filename'] ?? '', $b['filename'] ?? '');
        case 'views':
            return ($b['view_count'] ?? 0) - ($a['view_count'] ?? 0);
        default: // newest
            return ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0);
    }
});

$totalImages = count($userImages);
$totalPages = ceil($totalImages / $perPage);
$pagedImages = array_slice($userImages, $offset, $perPage);

$totalSize = 0;
$totalViews = 0;
foreach ($userImages as $img) {
    $totalSize += $img['size'] ?? 0;
    $totalViews += $img['view_count'] ?? 0;
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function getProxyUrl($s3Key) {
    return 'https://p.hel.ink/i/' . $s3Key;
}

/**
 * Proxy URL (/i/) untuk satu varian gambar.
 *
 * s3_keys bersifat otoritatif (key = YYYY/MM/DD/id_size.ext). Untuk record
 * lama yang hanya punya URL bucket mentah, key dipulihkan dari path URL
 * (segmen setelah domain) supaya tetap bisa diproksi. URL mentah S3 TIDAK
 * pernah dikembalikan karena bucket sudah privat dan akan 403.
 * Mengembalikan string kosong bila key tidak bisa ditentukan.
 */
function proxyUrlForVariant(array $img, string $size): string {
    if (!empty($img['s3_keys'][$size])) {
        return getProxyUrl($img['s3_keys'][$size]);
    }

    $raw = $img['urls'][$size] ?? '';
    if ($raw !== '') {
        $path = (string) parse_url($raw, PHP_URL_PATH);
        if (preg_match('~(\d{4}/\d{2}/\d{2}/[^/?#]+)$~', $path, $m)) {
            return getProxyUrl($m[1]);
        }
    }

    return '';
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery - PixelHop</title>
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
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            flex-wrap: wrap;
            gap: 16px;
        }
        .title-section { display: flex; align-items: center; gap: 14px; }
        .title-icon {
            width: 48px; height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            display: flex; align-items: center; justify-content: center;
        }
        .nav-links { display: flex; gap: 8px; flex-wrap: wrap; }
        .nav-link {
            padding: 10px 18px; border-radius: 10px;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none; font-size: 14px; font-weight: 500;
            transition: all 0.2s;
            display: flex; align-items: center; gap: 8px;
        }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
        .nav-link.active { background: rgba(34, 211, 238, 0.15); color: #22d3ee; }
        .toolbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; margin-bottom: 20px; flex-wrap: wrap;
        }
        .toolbar-left, .toolbar-right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .btn {
            padding: 10px 16px; border-radius: 10px; border: none;
            font-size: 13px; font-weight: 500; cursor: pointer;
            display: flex; align-items: center; gap: 8px; transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #22d3ee, #a855f7); color: #fff;
        }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 15px rgba(34, 211, 238, 0.3); }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .btn-secondary:hover { background: rgba(255, 255, 255, 0.12); color: #fff; }
        .btn-danger {
            background: rgba(239, 68, 68, 0.15); color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        .btn-danger:hover { background: rgba(239, 68, 68, 0.25); }
        .btn-danger:disabled { opacity: 0.5; cursor: not-allowed; }
        .select-input {
            padding: 10px 14px; border-radius: 10px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff; font-size: 13px; cursor: pointer;
        }
        .selection-info {
            font-size: 13px; color: rgba(255, 255, 255, 0.6);
            padding: 8px 12px; background: rgba(34, 211, 238, 0.1);
            border-radius: 8px; display: none;
        }
        .selection-info.active { display: block; color: #22d3ee; }
        .stats-row { display: flex; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        .stat-box {
            padding: 16px 24px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px; text-align: center; min-width: 120px;
        }
        .stat-number { font-size: 24px; font-weight: 700; color: #fff; }
        .stat-label { font-size: 12px; color: rgba(255, 255, 255, 0.5); }
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
        }
        .gallery-item {
            position: relative; aspect-ratio: 1;
            border-radius: 14px; overflow: hidden;
            background: rgba(255, 255, 255, 0.05);
            cursor: pointer; transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        .gallery-item:hover { transform: scale(1.02); box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4); }
        .gallery-item.selected { border-color: #22d3ee; box-shadow: 0 0 0 3px rgba(34, 211, 238, 0.3); }
        .gallery-item img { width: 100%; height: 100%; object-fit: cover; }
        .gallery-checkbox {
            position: absolute; top: 10px; left: 10px;
            width: 24px; height: 24px; border-radius: 6px;
            background: rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(255, 255, 255, 0.3);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; transition: all 0.2s; z-index: 10;
        }
        .gallery-item:hover .gallery-checkbox,
        .gallery-item.selected .gallery-checkbox { opacity: 1; }
        .gallery-item.selected .gallery-checkbox { background: #22d3ee; border-color: #22d3ee; }
        .gallery-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8) 0%, transparent 50%);
            opacity: 0; transition: opacity 0.3s ease;
            display: flex; flex-direction: column; justify-content: flex-end;
            padding: 12px;
        }
        .gallery-item:hover .gallery-overlay { opacity: 1; }
        .gallery-info { font-size: 11px; color: rgba(255, 255, 255, 0.8); }
        .gallery-filename {
            font-size: 12px; font-weight: 500; color: #fff;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            margin-bottom: 4px;
        }
        .gallery-actions { display: flex; gap: 6px; margin-top: 8px; }
        .gallery-btn {
            padding: 6px 10px; border-radius: 6px; border: none;
            font-size: 11px; font-weight: 500; cursor: pointer;
            background: rgba(255, 255, 255, 0.2); color: #fff;
            text-decoration: none; transition: all 0.2s;
        }
        .gallery-btn:hover { background: rgba(255, 255, 255, 0.3); }
        .gallery-btn-danger { background: rgba(239, 68, 68, 0.3); }
        .gallery-btn-danger:hover { background: rgba(239, 68, 68, 0.5); }
        .pagination {
            display: flex; align-items: center; justify-content: center;
            gap: 12px; margin-top: 24px; padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .pagination a, .pagination span { padding: 8px 14px; border-radius: 8px; font-size: 13px; text-decoration: none; }
        .pagination a { background: rgba(255, 255, 255, 0.08); color: rgba(255, 255, 255, 0.8); }
        .pagination a:hover { background: rgba(255, 255, 255, 0.12); }
        .pagination .info { color: rgba(255, 255, 255, 0.5); }
        .empty-state { text-align: center; padding: 60px 20px; color: rgba(255, 255, 255, 0.4); }
        .empty-icon { width: 64px; height: 64px; margin: 0 auto 16px; opacity: 0.5; }
        .upload-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 24px; border-radius: 12px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            color: #fff; text-decoration: none;
            font-size: 14px; font-weight: 500; margin-top: 16px; transition: all 0.2s;
        }
        .upload-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 30px rgba(34, 211, 238, 0.3); }
        .footer-bar {
            margin-top: 24px; padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 12px;
        }
        .footer-text { font-size: 12px; color: rgba(255, 255, 255, 0.4); display: flex; align-items: center; gap: 12px; }
        .footer-links { display: flex; gap: 16px; }
        .footer-links a { font-size: 12px; color: rgba(255, 255, 255, 0.5); text-decoration: none; }
        .footer-links a:hover { color: #22d3ee; }
        .theme-toggle-btn {
            width: 32px; height: 32px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.6); cursor: pointer; transition: all 0.2s;
        }
        .theme-toggle-btn:hover { background: rgba(255, 255, 255, 0.1); color: #22d3ee; }
        #theme-icon-light { display: none; }
        #theme-icon-dark { display: block; }
        #theme-icon-light-header { display: none; }
        #theme-icon-dark-header { display: block; }
        [data-theme="light"] #theme-icon-light { display: block; }
        [data-theme="light"] #theme-icon-dark { display: none; }
        [data-theme="light"] #theme-icon-light-header { display: block; }
        [data-theme="light"] #theme-icon-dark-header { display: none; }
        [data-theme="light"] .theme-toggle-btn { background: rgba(0, 0, 0, 0.05); border-color: rgba(0, 0, 0, 0.1); color: rgba(0, 0, 0, 0.6); }
        [data-theme="light"] .theme-toggle-btn:hover { background: rgba(0, 0, 0, 0.1); color: #0891b2; }
        [data-theme="light"] body { background: linear-gradient(135deg, #f0f4f8 0%, #e2e8f0 50%, #f0f4f8 100%); }
        [data-theme="light"] .dashboard-container { background: rgba(255, 255, 255, 0.9); border-color: rgba(0, 0, 0, 0.1); }
        [data-theme="light"] .text-white, [data-theme="light"] h1, [data-theme="light"] h2 { color: #1a202c !important; }
        [data-theme="light"] .stat-number { color: #1a202c; }
        [data-theme="light"] .stat-label, [data-theme="light"] .footer-text { color: rgba(0, 0, 0, 0.5); }
        [data-theme="light"] .nav-link { color: rgba(0, 0, 0, 0.6); }
        [data-theme="light"] .nav-link:hover { background: rgba(0, 0, 0, 0.05); color: #1a202c; }
        [data-theme="light"] .nav-link.active { background: rgba(34, 211, 238, 0.15); color: #0891b2; }
        [data-theme="light"] .btn-secondary { background: rgba(0, 0, 0, 0.05); color: rgba(0, 0, 0, 0.7); border-color: rgba(0, 0, 0, 0.1); }
        [data-theme="light"] .select-input { background: rgba(0, 0, 0, 0.03); border-color: rgba(0, 0, 0, 0.1); color: #1a202c; }
        [data-theme="light"] .stat-box, [data-theme="light"] .gallery-item { background: rgba(0, 0, 0, 0.03); border-color: rgba(0, 0, 0, 0.08); }
        .toast {
            position: fixed; bottom: 24px; right: 24px;
            padding: 14px 20px; border-radius: 10px; font-size: 14px;
            display: flex; align-items: center; gap: 10px;
            transform: translateY(100px); opacity: 0; transition: all 0.3s ease; z-index: 1000;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast-success { background: rgba(34, 197, 94, 0.9); color: #fff; }
        .toast-error { background: rgba(239, 68, 68, 0.9); color: #fff; }
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .toolbar-left, .toolbar-right { justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
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
                <a href="/dashboard" class="nav-link"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard</a>
                <a href="/gallery" class="nav-link active"><i data-lucide="images" class="w-4 h-4"></i> Gallery</a>
                <a href="/member/tools" class="nav-link"><i data-lucide="wrench" class="w-4 h-4"></i> Tools</a>
                <a href="/member/settings" class="nav-link"><i data-lucide="settings" class="w-4 h-4"></i> Settings</a>
                <button onclick="toggleTheme()" class="theme-toggle-btn" title="Toggle theme">
                    <i data-lucide="sun" class="w-4 h-4" id="theme-icon-light-header"></i>
                    <i data-lucide="moon" class="w-4 h-4" id="theme-icon-dark-header"></i>
                </button>
                <a href="/auth/logout" class="nav-link" style="color: #ef4444;"><i data-lucide="log-out" class="w-4 h-4"></i></a>
            </div>
        </div>

        <?php if (empty($userImages)): ?>
        <div class="empty-state">
            <i data-lucide="image-off" class="empty-icon"></i>
            <h2 class="text-lg font-semibold text-white mb-2">No images yet</h2>
            <p class="text-sm">Start uploading images to build your gallery</p>
            <a href="/member/upload" class="upload-btn"><i data-lucide="upload" class="w-4 h-4"></i> Upload Image</a>
        </div>
        <?php else: ?>
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-number"><?= $totalImages ?></div>
                <div class="stat-label">Total Images</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?= formatBytes($totalSize) ?></div>
                <div class="stat-label">Total Size</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?= number_format($totalViews) ?></div>
                <div class="stat-label">Total Views</div>
            </div>
        </div>

        <div class="toolbar">
            <div class="toolbar-left">
                <button id="selectAllBtn" class="btn btn-secondary"><i data-lucide="check-square" class="w-4 h-4"></i> Select All</button>
                <button id="deselectAllBtn" class="btn btn-secondary" style="display: none;"><i data-lucide="square" class="w-4 h-4"></i> Deselect All</button>
                <button id="deleteSelectedBtn" class="btn btn-danger" disabled><i data-lucide="trash-2" class="w-4 h-4"></i> Delete Selected</button>
                <span id="selectionInfo" class="selection-info">0 selected</span>
            </div>
            <div class="toolbar-right">
                <select class="select-input" id="sortSelect" onchange="changeSort(this.value)">
                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                    <option value="largest" <?= $sort === 'largest' ? 'selected' : '' ?>>Largest First</option>
                    <option value="smallest" <?= $sort === 'smallest' ? 'selected' : '' ?>>Smallest First</option>
                    <option value="views" <?= $sort === 'views' ? 'selected' : '' ?>>Most Views</option>
                    <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>By Name</option>
                </select>
                <a href="/member/upload" class="btn btn-primary"><i data-lucide="upload" class="w-4 h-4"></i> Upload</a>
            </div>
        </div>

        <div class="gallery-grid" id="galleryGrid">
            <?php foreach ($pagedImages as $img): 
                $thumbUrl = proxyUrlForVariant($img, 'thumb') ?: proxyUrlForVariant($img, 'medium') ?: proxyUrlForVariant($img, 'original');
                $originalUrl = proxyUrlForVariant($img, 'original');
            ?>
            <div class="gallery-item" data-id="<?= htmlspecialchars($img['id']) ?>">
                <div class="gallery-checkbox"><i data-lucide="check" class="w-4 h-4 text-white"></i></div>
                <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy" onerror="this.src='/assets/img/placeholder.png'">
                <div class="gallery-overlay">
                    <div class="gallery-filename"><?= htmlspecialchars($img['filename'] ?? $img['id']) ?></div>
                    <div class="gallery-info">
                        <?= strtoupper($img['extension'] ?? 'unknown') ?> • <?= formatBytes($img['size'] ?? 0) ?>
                        <br><i data-lucide="eye" style="width:12px;height:12px;display:inline;vertical-align:middle;"></i> <?= number_format($img['view_count'] ?? 0) ?> views • <?= date('M j, Y', $img['created_at'] ?? 0) ?>
                    </div>
                    <div class="gallery-actions">
                        <a href="/<?= htmlspecialchars($img['id']) ?>" class="gallery-btn" target="_blank">View</a>
                        <?php if ($originalUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($originalUrl) ?>" class="gallery-btn" download>Download</a>
                        <?php endif; ?>
                        <button class="gallery-btn gallery-btn-danger btn-delete-single" data-id="<?= htmlspecialchars($img['id']) ?>">Delete</button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>&sort=<?= $sort ?>">← Previous</a><?php endif; ?>
            <span class="info">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1 ?>&sort=<?= $sort ?>">Next →</a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <div class="footer-bar">
            <div class="footer-text">
                <button onclick="toggleTheme()" class="theme-toggle-btn" title="Toggle theme">
                    <i data-lucide="sun" class="w-4 h-4" id="theme-icon-light"></i>
                    <i data-lucide="moon" class="w-4 h-4" id="theme-icon-dark"></i>
                </button>
                <?= $totalImages ?> images uploaded
            </div>
            <div class="footer-links">
                <a href="/member/tools">Tools</a>
                <a href="/help">Help</a>
                <a href="/auth/logout">Logout</a>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        lucide.createIcons();
        const csrfToken = '<?= $csrfToken ?>';
        let selectedItems = new Set();

        function toggleTheme() {
            const html = document.documentElement;
            const next = (html.getAttribute('data-theme') || 'dark') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', next);
            localStorage.setItem('pixelhop-theme', next);
        }

        function changeSort(value) { window.location.href = '?sort=' + value; }

        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.className = 'toast toast-' + type + ' show';
            toast.innerHTML = '<i data-lucide="' + (type === 'success' ? 'check-circle' : 'alert-circle') + '" class="w-5 h-5"></i>' + message;
            lucide.createIcons();
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        function updateSelectionUI() {
            const count = selectedItems.size;
            document.getElementById('selectionInfo').textContent = count + ' selected';
            document.getElementById('selectionInfo').classList.toggle('active', count > 0);
            document.getElementById('deleteSelectedBtn').disabled = count === 0;
            const totalItems = document.querySelectorAll('.gallery-item').length;
            document.getElementById('selectAllBtn').style.display = count === totalItems ? 'none' : 'flex';
            document.getElementById('deselectAllBtn').style.display = count > 0 ? 'flex' : 'none';
        }

        document.querySelectorAll('.gallery-item').forEach(item => {
            item.addEventListener('click', (e) => {
                if (e.target.closest('.gallery-actions') || e.target.closest('a')) return;
                const id = item.dataset.id;
                if (selectedItems.has(id)) { selectedItems.delete(id); item.classList.remove('selected'); }
                else { selectedItems.add(id); item.classList.add('selected'); }
                updateSelectionUI();
            });
        });

        document.getElementById('selectAllBtn').addEventListener('click', () => {
            document.querySelectorAll('.gallery-item').forEach(item => {
                selectedItems.add(item.dataset.id);
                item.classList.add('selected');
            });
            updateSelectionUI();
        });

        document.getElementById('deselectAllBtn').addEventListener('click', () => {
            selectedItems.clear();
            document.querySelectorAll('.gallery-item').forEach(item => item.classList.remove('selected'));
            updateSelectionUI();
        });

        document.getElementById('deleteSelectedBtn').addEventListener('click', async () => {
            if (selectedItems.size === 0) return;
            if (!confirm(`Delete ${selectedItems.size} image(s)? This cannot be undone.`)) return;
            
            const btn = document.getElementById('deleteSelectedBtn');
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Deleting...';
            
            try {
                const fd = new FormData();
                fd.append('ajax', '1');
                fd.append('action', 'delete_images');
                fd.append('csrf_token', csrfToken);
                fd.append('image_ids', JSON.stringify([...selectedItems]));
                
                const res = await fetch('/gallery.php', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.success) {
                    selectedItems.forEach(id => {
                        const item = document.querySelector(`.gallery-item[data-id="${id}"]`);
                        if (item) item.remove();
                    });
                    selectedItems.clear();
                    updateSelectionUI();
                    showToast(`${data.deleted} image(s) deleted`);
                } else {
                    showToast(data.error || 'Failed to delete', 'error');
                }
            } catch (e) { showToast('Error: ' + e.message, 'error'); }
            
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="trash-2" class="w-4 h-4"></i> Delete Selected';
            lucide.createIcons();
        });

        document.querySelectorAll('.btn-delete-single').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                if (!confirm('Delete this image?')) return;
                const id = btn.dataset.id;
                btn.innerHTML = '<i data-lucide="loader" class="w-3 h-3 animate-spin"></i>';
                
                try {
                    const fd = new FormData();
                    fd.append('ajax', '1');
                    fd.append('action', 'delete_images');
                    fd.append('csrf_token', csrfToken);
                    fd.append('image_ids', JSON.stringify([id]));
                    
                    const res = await fetch('/gallery.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    
                    if (data.success) {
                        document.querySelector(`.gallery-item[data-id="${id}"]`)?.remove();
                        selectedItems.delete(id);
                        updateSelectionUI();
                        showToast('Image deleted');
                    } else { showToast(data.error || 'Failed', 'error'); }
                } catch (e) { showToast('Error: ' + e.message, 'error'); }
            });
        });
    </script>
</body>
</html>
