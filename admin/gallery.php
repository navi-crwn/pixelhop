<?php
/**
 * PixelHop - Admin Gallery
 * Advanced image management for administrators
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';

if (!isAuthenticated() || !isAdmin()) {
    header('Location: /login.php?error=access_denied');
    exit;
}

$db = Database::getInstance();
$currentUser = getCurrentUser();
$csrfToken = generateCsrfToken();
$currentPage = 'gallery';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    $imagesFile = __DIR__ . '/../data/images.json';
    
    // Load storage manager for deletion
    require_once __DIR__ . '/../includes/R2StorageManager.php';
    $s3Config = require __DIR__ . '/../config/s3.php';
    $storageManager = new R2StorageManager($s3Config);
    
    if ($action === 'delete_images') {
        $imageIds = json_decode($_POST['image_ids'] ?? '[]', true);
        if (!is_array($imageIds) || empty($imageIds)) {
            echo json_encode(['success' => false, 'error' => 'No images specified']);
            exit;
        }
        
        if (!file_exists($imagesFile)) {
            echo json_encode(['success' => false, 'error' => 'No images found']);
            exit;
        }
        
        $images = json_decode(file_get_contents($imagesFile), true) ?: [];
        $deleted = 0;
        $freedSpace = 0;
        $storageErrors = [];
        
        foreach ($imageIds as $imageId) {
            if (isset($images[$imageId])) {
                $imgData = $images[$imageId];
                $freedSpace += $imgData['size'] ?? 0;
                
                // Delete from storage (R2 and Contabo)
                if (!empty($imgData['s3_keys'])) {
                    $deleteResult = $storageManager->deleteImage($imgData['s3_keys'], $imgData['size'] ?? 0);
                    if (!$deleteResult['success']) {
                        $storageErrors[] = $imageId . ': ' . ($deleteResult['error'] ?? 'storage delete failed');
                    }
                }
                
                unset($images[$imageId]);
                $deleted++;
            }
        }
        
        file_put_contents($imagesFile, json_encode($images, JSON_PRETTY_PRINT), LOCK_EX);
        
        echo json_encode([
            'success' => true, 
            'deleted' => $deleted,
            'freed_space' => $freedSpace,
            'storage_errors' => $storageErrors
        ]);
        exit;
    }
    
    if ($action === 'delete_by_user') {
        $userId = $_POST['user_id'] ?? '';
        if (empty($userId)) {
            echo json_encode(['success' => false, 'error' => 'No user specified']);
            exit;
        }
        
        if (!file_exists($imagesFile)) {
            echo json_encode(['success' => false, 'error' => 'No images found']);
            exit;
        }
        
        $images = json_decode(file_get_contents($imagesFile), true) ?: [];
        $deleted = 0;
        $freedSpace = 0;
        $storageErrors = [];
        
        foreach ($images as $id => $img) {
            $imgUserId = $img['user_id'] ?? null;
            $isGuest = ($userId === 'guest' && empty($imgUserId));
            $isMatch = ($imgUserId == $userId);
            
            if ($isGuest || $isMatch) {
                $freedSpace += $img['size'] ?? 0;
                
                // Delete from storage
                if (!empty($img['s3_keys'])) {
                    $deleteResult = $storageManager->deleteImage($img['s3_keys'], $img['size'] ?? 0);
                    if (!$deleteResult['success']) {
                        $storageErrors[] = $id;
                    }
                }
                
                unset($images[$id]);
                $deleted++;
            }
        }
        
        file_put_contents($imagesFile, json_encode($images, JSON_PRETTY_PRINT), LOCK_EX);
        
        echo json_encode([
            'success' => true, 
            'deleted' => $deleted,
            'freed_space' => $freedSpace,
            'storage_errors' => count($storageErrors)
        ]);
        exit;
    }
    
    if ($action === 'export_data') {
        $imageIds = json_decode($_POST['image_ids'] ?? '[]', true);
        
        if (!file_exists($imagesFile)) {
            echo json_encode(['success' => false, 'error' => 'No images found']);
            exit;
        }
        
        $images = json_decode(file_get_contents($imagesFile), true) ?: [];
        $exportData = [];
        
        foreach ($images as $id => $img) {
            if (empty($imageIds) || in_array($id, $imageIds)) {
                $exportData[] = [
                    'id' => $id,
                    'filename' => $img['filename'] ?? '',
                    'size' => $img['size'] ?? 0,
                    'mime_type' => $img['mime_type'] ?? '',
                    'width' => $img['width'] ?? 0,
                    'height' => $img['height'] ?? 0,
                    'user_id' => $img['user_id'] ?? null,
                    'created_at' => date('Y-m-d H:i:s', $img['created_at'] ?? 0),
                    'url' => $img['urls']['original'] ?? '',
                ];
            }
        }
        
        echo json_encode(['success' => true, 'data' => $exportData]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

// Filters
$search = $_GET['search'] ?? '';
$filterUser = $_GET['user'] ?? '';
$filterDate = $_GET['date'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

// Load images from JSON
$imagesFile = __DIR__ . '/../data/images.json';
$allImages = [];
if (file_exists($imagesFile)) {
    $allImages = json_decode(file_get_contents($imagesFile), true) ?: [];
}

// Get all users for lookup
$usersStmt = $db->query("SELECT id, email, account_type FROM users");
$usersMap = [];
while ($row = $usersStmt->fetch(PDO::FETCH_ASSOC)) {
    $usersMap[$row['id']] = $row;
}

// Process and filter images
$processedImages = [];
$userStats = ['guest' => 0];

foreach ($allImages as $id => $img) {
    $img['id'] = $id;
    
    $userId = $img['user_id'] ?? null;
    if ($userId && isset($usersMap[$userId])) {
        $img['user_email'] = $usersMap[$userId]['email'];
        $img['is_guest'] = false;
        $userStats[$userId] = ($userStats[$userId] ?? 0) + 1;
    } else {
        $img['user_email'] = 'Guest';
        $img['is_guest'] = true;
        $userStats['guest']++;
    }
    
    // Apply filters
    if ($search) {
        $matchFilename = stripos($img['filename'] ?? '', $search) !== false;
        $matchId = stripos($id, $search) !== false;
        $matchEmail = stripos($img['user_email'] ?? '', $search) !== false;
        if (!$matchFilename && !$matchId && !$matchEmail) continue;
    }
    
    if ($filterUser) {
        if ($filterUser === 'guest' && !$img['is_guest']) continue;
        if ($filterUser !== 'guest' && ($img['user_id'] ?? '') != $filterUser) continue;
    }
    
    if ($filterDate) {
        $imgDate = date('Y-m-d', $img['created_at'] ?? 0);
        if ($imgDate !== $filterDate) continue;
    }
    
    $processedImages[] = $img;
}

// Sort images
usort($processedImages, function($a, $b) use ($sort) {
    switch ($sort) {
        case 'oldest': return ($a['created_at'] ?? 0) - ($b['created_at'] ?? 0);
        case 'largest': return ($b['size'] ?? 0) - ($a['size'] ?? 0);
        case 'smallest': return ($a['size'] ?? 0) - ($b['size'] ?? 0);
        case 'views': return ($b['view_count'] ?? 0) - ($a['view_count'] ?? 0);
        case 'name': return strcasecmp($a['filename'] ?? '', $b['filename'] ?? '');
        default: return ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0);
    }
});

$totalFiltered = count($processedImages);
$totalPages = ceil($totalFiltered / $perPage);
$images = array_slice($processedImages, $offset, $perPage);

// Calculate stats
$totalSize = array_sum(array_column($allImages, 'size'));
$totalViews = array_sum(array_column($allImages, 'view_count'));
$guestCount = count(array_filter($allImages, fn($i) => empty($i['user_id'])));
$memberCount = count($allImages) - $guestCount;

// Get unique dates for filter
$uniqueDates = [];
foreach ($allImages as $img) {
    $date = date('Y-m-d', $img['created_at'] ?? 0);
    $uniqueDates[$date] = ($uniqueDates[$date] ?? 0) + 1;
}
krsort($uniqueDates);
$uniqueDates = array_slice($uniqueDates, 0, 30, true);

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function getProxyUrl($s3Key) {
    return 'https://p.hel.ink/i/' . $s3Key;
}

$currentPage = 'gallery';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery - Admin - PixelHop</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/admin/includes/admin-styles.css">
    <script src="/admin/includes/admin-scripts.js"></script>
    <style>
        .gallery-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .toolbar-left, .toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .filter-label {
            font-size: 12px;
            color: var(--text-muted);
        }
        .filter-select {
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 13px;
            cursor: pointer;
        }
        .filter-select option {
            background: var(--bg-color);
            color: var(--text-primary);
        }
        .form-input {
            padding: 8px 12px;
            border-radius: 8px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 13px;
        }
        .form-input::placeholder {
            color: var(--text-muted);
        }
        .selection-bar {
            display: none;
            align-items: center;
            gap: 16px;
            padding: 12px 16px;
            background: rgba(34, 211, 238, 0.1);
            border: 1px solid rgba(34, 211, 238, 0.2);
            border-radius: 10px;
            margin-bottom: 16px;
        }
        .selection-bar.active { display: flex; }
        .selection-count {
            font-size: 14px;
            font-weight: 500;
            color: var(--accent-cyan);
        }
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
            padding: 16px 0;
        }
        .gallery-item {
            position: relative;
            background: var(--card-bg);
            border: 2px solid transparent;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.2s;
            cursor: pointer;
        }
        .gallery-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }
        .gallery-item.selected {
            border-color: var(--accent-cyan);
            box-shadow: 0 0 0 3px rgba(34, 211, 238, 0.2);
        }
        .gallery-checkbox {
            position: absolute;
            top: 8px;
            left: 8px;
            width: 22px;
            height: 22px;
            border-radius: 6px;
            background: rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(255, 255, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.2s;
            z-index: 10;
        }
        .gallery-item:hover .gallery-checkbox,
        .gallery-item.selected .gallery-checkbox { opacity: 1; }
        .gallery-item.selected .gallery-checkbox {
            background: var(--accent-cyan);
            border-color: var(--accent-cyan);
        }
        .gallery-thumb {
            aspect-ratio: 1;
            overflow: hidden;
            background: rgba(0,0,0,0.2);
        }
        .gallery-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }
        .gallery-item:hover .gallery-thumb img {
            transform: scale(1.05);
        }
        .gallery-info {
            padding: 10px 12px;
        }
        .gallery-id {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary);
        }
        .gallery-filename {
            font-size: 11px;
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }
        .gallery-meta {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .gallery-user {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            margin-top: 6px;
            padding-top: 6px;
            border-top: 1px solid var(--border-color);
        }
        .gallery-user.guest { color: var(--accent-yellow); }
        .gallery-user.member { color: var(--accent-green); }
        .gallery-actions {
            display: flex;
            gap: 4px;
            padding: 8px 12px;
            border-top: 1px solid var(--border-color);
        }
        .btn-icon {
            flex: 1;
            padding: 6px;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-icon:hover { background: var(--border-color); color: var(--text-primary); }
        .btn-delete:hover { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); }
        .no-results {
            grid-column: 1 / -1;
            text-align: center;
            padding: 60px 20px;
        }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 16px;
            border-top: 1px solid var(--border-color);
        }
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            padding: 14px 20px;
            border-radius: 10px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 1000;
        }
        .toast.show { transform: translateY(0); opacity: 1; }
        .toast-success { background: rgba(34, 197, 94, 0.9); color: #fff; }
        .toast-error { background: rgba(239, 68, 68, 0.9); color: #fff; }
        
        /* Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            max-width: 500px;
            width: 90%;
            padding: 24px;
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
        }
        .modal-body { margin-bottom: 20px; }
        .modal-footer { display: flex; gap: 10px; justify-content: flex-end; }
        
        /* Compact stats row */
        .stats-row-compact {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .stat-compact {
            flex: 1;
            min-width: 120px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
        }
        .stat-compact .stat-num {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .stat-compact .stat-lbl {
            font-size: 12px;
            color: var(--text-muted);
        }
        
        @media (max-width: 768px) {
            .gallery-toolbar { flex-direction: column; align-items: stretch; }
            .toolbar-left, .toolbar-right { flex-wrap: wrap; }
            .stat-compact { min-width: 100px; }
        }
    </style>
</head>
<body>
    <div class="admin-wrapper">
        <div class="admin-container">
            <?php include __DIR__ . '/includes/header.php'; ?>
            
            <div class="admin-content">
                <!-- Stats Grid - Compact 5 columns -->
                <div class="stats-row-compact mb-4">
                    <div class="stat-compact">
                        <i data-lucide="images" class="w-4 h-4 text-cyan"></i>
                        <span class="stat-num"><?= number_format(count($allImages)) ?></span>
                        <span class="stat-lbl">Images</span>
                    </div>
                    <div class="stat-compact">
                        <i data-lucide="hard-drive" class="w-4 h-4 text-purple"></i>
                        <span class="stat-num"><?= formatBytes($totalSize) ?></span>
                        <span class="stat-lbl">Size</span>
                    </div>
                    <div class="stat-compact">
                        <i data-lucide="eye" class="w-4 h-4 text-pink"></i>
                        <span class="stat-num"><?= number_format($totalViews) ?></span>
                        <span class="stat-lbl">Views</span>
                    </div>
                    <div class="stat-compact">
                        <i data-lucide="user" class="w-4 h-4 text-green"></i>
                        <span class="stat-num"><?= number_format($memberCount) ?></span>
                        <span class="stat-lbl">Members</span>
                    </div>
                    <div class="stat-compact">
                        <i data-lucide="user-x" class="w-4 h-4 text-yellow"></i>
                        <span class="stat-num"><?= number_format($guestCount) ?></span>
                        <span class="stat-lbl">Guests</span>
                    </div>
                </div>

                <!-- Filters & Search -->
                <div class="card mb-4">
                    <form method="GET" class="gallery-toolbar">
                        <div class="toolbar-left">
                            <input type="text" name="search" class="form-input" placeholder="Search ID, filename, user..." 
                                   value="<?= htmlspecialchars($search) ?>" style="min-width: 200px;">
                            
                            <div class="filter-group">
                                <select name="user" class="filter-select">
                                    <option value="">All Users</option>
                                    <option value="guest" <?= $filterUser === 'guest' ? 'selected' : '' ?>>Guests Only</option>
                                    <?php foreach ($usersMap as $uid => $u): ?>
                                    <option value="<?= $uid ?>" <?= $filterUser == $uid ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($u['email']) ?> (<?= $userStats[$uid] ?? 0 ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <select name="date" class="filter-select">
                                    <option value="">All Dates</option>
                                    <?php foreach ($uniqueDates as $date => $count): ?>
                                    <option value="<?= $date ?>" <?= $filterDate === $date ? 'selected' : '' ?>>
                                        <?= $date ?> (<?= $count ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <select name="sort" class="filter-select">
                                    <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest</option>
                                    <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest</option>
                                    <option value="largest" <?= $sort === 'largest' ? 'selected' : '' ?>>Largest</option>
                                    <option value="smallest" <?= $sort === 'smallest' ? 'selected' : '' ?>>Smallest</option>
                                    <option value="views" <?= $sort === 'views' ? 'selected' : '' ?>>Most Views</option>
                                </select>
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <button type="submit" class="btn btn-primary">
                                <i data-lucide="search" class="w-4 h-4"></i> Filter
                            </button>
                            <?php if ($search || $filterUser || $filterDate): ?>
                            <a href="/admin/gallery" class="btn btn-secondary">Clear</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- Selection Bar -->
                <div id="selectionBar" class="selection-bar">
                    <span id="selectionCount" class="selection-count">0 selected</span>
                    <button id="selectAllBtn" class="btn btn-secondary btn-sm">
                        <i data-lucide="check-square" class="w-4 h-4"></i> Select All
                    </button>
                    <button id="deselectAllBtn" class="btn btn-secondary btn-sm">
                        <i data-lucide="square" class="w-4 h-4"></i> Deselect All
                    </button>
                    <button id="deleteSelectedBtn" class="btn btn-danger btn-sm">
                        <i data-lucide="trash-2" class="w-4 h-4"></i> Delete Selected
                    </button>
                    <button id="exportSelectedBtn" class="btn btn-secondary btn-sm">
                        <i data-lucide="download" class="w-4 h-4"></i> Export Data
                    </button>
                </div>

                <!-- Gallery Grid -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i data-lucide="grid-3x3" class="w-5 h-5"></i>
                            Images (<?= $totalFiltered ?> found • Page <?= $page ?>/<?= max(1, $totalPages) ?>)
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <button id="bulkDeleteGuestBtn" class="btn btn-danger btn-sm" title="Delete all guest uploads">
                                <i data-lucide="user-x" class="w-4 h-4"></i> Delete Guest Uploads
                            </button>
                            <button id="exportAllBtn" class="btn btn-secondary btn-sm">
                                <i data-lucide="file-json" class="w-4 h-4"></i> Export All
                            </button>
                        </div>
                    </div>
                    
                    <div class="gallery-grid" id="galleryGrid">
                        <?php foreach ($images as $img): 
                            $thumbUrl = isset($img['s3_keys']['thumb']) ? getProxyUrl($img['s3_keys']['thumb']) : ($img['urls']['thumb'] ?? $img['urls']['medium'] ?? '');
                        ?>
                        <div class="gallery-item" data-id="<?= htmlspecialchars($img['id']) ?>" data-user="<?= $img['is_guest'] ? 'guest' : $img['user_id'] ?>">
                            <div class="gallery-checkbox">
                                <i data-lucide="check" class="w-3 h-3 text-white"></i>
                            </div>
                            <div class="gallery-thumb">
                                <img src="<?= htmlspecialchars($thumbUrl) ?>" alt="" loading="lazy" onerror="this.src='/assets/img/placeholder.png'">
                            </div>
                            <div class="gallery-info">
                                <div class="gallery-id"><?= htmlspecialchars($img['id']) ?></div>
                                <div class="gallery-filename"><?= htmlspecialchars($img['filename'] ?? 'unknown') ?></div>
                                <div class="gallery-meta">
                                    <?= formatBytes($img['size'] ?? 0) ?> • <?= ($img['width'] ?? '?') ?>×<?= ($img['height'] ?? '?') ?>
                                    <br><i data-lucide="eye" style="width:11px;height:11px;display:inline;vertical-align:middle;"></i> <?= number_format($img['view_count'] ?? 0) ?> • <?= date('M j, Y H:i', $img['created_at'] ?? 0) ?>
                                </div>
                                <div class="gallery-user <?= $img['is_guest'] ? 'guest' : 'member' ?>">
                                    <i data-lucide="<?= $img['is_guest'] ? 'user-x' : 'user' ?>" class="w-3 h-3"></i>
                                    <?= htmlspecialchars($img['is_guest'] ? 'Guest' : $img['user_email']) ?>
                                </div>
                            </div>
                            <div class="gallery-actions">
                                <a href="/<?= htmlspecialchars($img['id']) ?>" target="_blank" class="btn-icon" title="View">
                                    <i data-lucide="external-link" class="w-4 h-4"></i>
                                </a>
                                <a href="<?= htmlspecialchars($img['urls']['original'] ?? '') ?>" download class="btn-icon" title="Download">
                                    <i data-lucide="download" class="w-4 h-4"></i>
                                </a>
                                <button class="btn-icon btn-delete" data-id="<?= htmlspecialchars($img['id']) ?>" title="Delete">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($images)): ?>
                        <div class="no-results">
                            <i data-lucide="image-off" class="w-12 h-12 text-muted"></i>
                            <p class="text-muted mt-4">No images found</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php 
                        $queryParams = http_build_query(array_filter([
                            'search' => $search,
                            'user' => $filterUser,
                            'date' => $filterDate,
                            'sort' => $sort
                        ]));
                        ?>
                        <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?><?= $queryParams ? '&'.$queryParams : '' ?>" class="btn btn-secondary">← Previous</a>
                        <?php endif; ?>
                        <span class="text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?><?= $queryParams ? '&'.$queryParams : '' ?>" class="btn btn-secondary">Next →</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <footer class="admin-footer">
                <span>© 2025 PixelHop • Admin Panel v2.0</span>
                <div class="footer-links">
                    <a href="/dashboard">My Account</a>
                    <a href="/member/tools">Tools</a>
                    <a href="/auth/logout" class="text-red">Logout</a>
                </div>
            </footer>
        </div>
    </div>

    <!-- Confirm Modal -->
    <div id="confirmModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <span class="modal-title" id="modalTitle">Confirm Action</span>
                <button class="modal-close" onclick="closeModal()">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="modal-body" id="modalBody">
                Are you sure?
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button class="btn btn-danger" id="modalConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="toast" class="toast"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const csrfToken = '<?= $csrfToken ?>';
            let selectedItems = new Set();
            let modalCallback = null;
            
            function showToast(message, type = 'success') {
                const toast = document.getElementById('toast');
                toast.className = 'toast toast-' + type + ' show';
                toast.innerHTML = '<i data-lucide="' + (type === 'success' ? 'check-circle' : 'alert-circle') + '" class="w-5 h-5"></i>' + message;
                lucide.createIcons();
                setTimeout(() => toast.classList.remove('show'), 3000);
            }
            
            window.closeModal = function() {
                document.getElementById('confirmModal').classList.remove('active');
                modalCallback = null;
            };
            
            function showModal(title, body, onConfirm) {
                document.getElementById('modalTitle').textContent = title;
                document.getElementById('modalBody').innerHTML = body;
                document.getElementById('confirmModal').classList.add('active');
                modalCallback = onConfirm;
            }
            
            document.getElementById('modalConfirmBtn').addEventListener('click', () => {
                if (modalCallback) modalCallback();
                closeModal();
            });
            
            function updateSelectionUI() {
                const count = selectedItems.size;
                const bar = document.getElementById('selectionBar');
                bar.classList.toggle('active', count > 0);
                document.getElementById('selectionCount').textContent = count + ' selected';
            }
            
            // Click on gallery item to toggle selection
            document.querySelectorAll('.gallery-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    if (e.target.closest('.gallery-actions') || e.target.closest('a') || e.target.closest('button')) return;
                    
                    const id = item.dataset.id;
                    if (selectedItems.has(id)) {
                        selectedItems.delete(id);
                        item.classList.remove('selected');
                    } else {
                        selectedItems.add(id);
                        item.classList.add('selected');
                    }
                    updateSelectionUI();
                });
            });
            
            // Select All
            document.getElementById('selectAllBtn').addEventListener('click', () => {
                document.querySelectorAll('.gallery-item').forEach(item => {
                    selectedItems.add(item.dataset.id);
                    item.classList.add('selected');
                });
                updateSelectionUI();
            });
            
            // Deselect All
            document.getElementById('deselectAllBtn').addEventListener('click', () => {
                selectedItems.clear();
                document.querySelectorAll('.gallery-item').forEach(item => item.classList.remove('selected'));
                updateSelectionUI();
            });
            
            // Delete Selected
            document.getElementById('deleteSelectedBtn').addEventListener('click', () => {
                if (selectedItems.size === 0) return;
                
                showModal(
                    'Delete ' + selectedItems.size + ' Images?',
                    '<p>This will permanently delete the selected images. This action cannot be undone.</p>',
                    async () => {
                        try {
                            const fd = new FormData();
                            fd.append('ajax', '1');
                            fd.append('action', 'delete_images');
                            fd.append('csrf_token', csrfToken);
                            fd.append('image_ids', JSON.stringify([...selectedItems]));
                            
                            const res = await fetch('/admin/gallery.php', { method: 'POST', body: fd });
                            const data = await res.json();
                            
                            if (data.success) {
                                selectedItems.forEach(id => {
                                    document.querySelector(`.gallery-item[data-id="${id}"]`)?.remove();
                                });
                                selectedItems.clear();
                                updateSelectionUI();
                                showToast(`${data.deleted} images deleted`);
                            } else {
                                showToast(data.error || 'Failed', 'error');
                            }
                        } catch (e) {
                            showToast('Error: ' + e.message, 'error');
                        }
                    }
                );
            });
            
            // Single delete
            document.querySelectorAll('.btn-delete').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const id = btn.dataset.id;
                    if (!confirm('Delete this image?')) return;
                    
                    try {
                        const fd = new FormData();
                        fd.append('ajax', '1');
                        fd.append('action', 'delete_images');
                        fd.append('csrf_token', csrfToken);
                        fd.append('image_ids', JSON.stringify([id]));
                        
                        const res = await fetch('/admin/gallery.php', { method: 'POST', body: fd });
                        const data = await res.json();
                        
                        if (data.success) {
                            document.querySelector(`.gallery-item[data-id="${id}"]`)?.remove();
                            showToast('Image deleted');
                        } else {
                            showToast(data.error || 'Failed', 'error');
                        }
                    } catch (e) {
                        showToast('Error: ' + e.message, 'error');
                    }
                });
            });
            
            // Bulk delete guest uploads
            document.getElementById('bulkDeleteGuestBtn').addEventListener('click', () => {
                showModal(
                    'Delete All Guest Uploads?',
                    '<p class="text-red"><strong>Warning:</strong> This will permanently delete ALL images uploaded by guests (<?= $guestCount ?> images).</p><p>This action cannot be undone!</p>',
                    async () => {
                        try {
                            const fd = new FormData();
                            fd.append('ajax', '1');
                            fd.append('action', 'delete_by_user');
                            fd.append('csrf_token', csrfToken);
                            fd.append('user_id', 'guest');
                            
                            const res = await fetch('/admin/gallery.php', { method: 'POST', body: fd });
                            const data = await res.json();
                            
                            if (data.success) {
                                showToast(`${data.deleted} guest images deleted`);
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                showToast(data.error || 'Failed', 'error');
                            }
                        } catch (e) {
                            showToast('Error: ' + e.message, 'error');
                        }
                    }
                );
            });
            
            // Export selected
            document.getElementById('exportSelectedBtn').addEventListener('click', async () => {
                if (selectedItems.size === 0) return;
                
                try {
                    const fd = new FormData();
                    fd.append('ajax', '1');
                    fd.append('action', 'export_data');
                    fd.append('csrf_token', csrfToken);
                    fd.append('image_ids', JSON.stringify([...selectedItems]));
                    
                    const res = await fetch('/admin/gallery.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    
                    if (data.success) {
                        const blob = new Blob([JSON.stringify(data.data, null, 2)], { type: 'application/json' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'pixelhop_export_' + Date.now() + '.json';
                        a.click();
                        showToast('Exported ' + data.data.length + ' images');
                    } else {
                        showToast(data.error || 'Export failed', 'error');
                    }
                } catch (e) {
                    showToast('Error: ' + e.message, 'error');
                }
            });
            
            // Export all
            document.getElementById('exportAllBtn').addEventListener('click', async () => {
                try {
                    const fd = new FormData();
                    fd.append('ajax', '1');
                    fd.append('action', 'export_data');
                    fd.append('csrf_token', csrfToken);
                    fd.append('image_ids', '[]');
                    
                    const res = await fetch('/admin/gallery.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    
                    if (data.success) {
                        const blob = new Blob([JSON.stringify(data.data, null, 2)], { type: 'application/json' });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = 'pixelhop_all_images_' + Date.now() + '.json';
                        a.click();
                        showToast('Exported ' + data.data.length + ' images');
                    } else {
                        showToast(data.error || 'Export failed', 'error');
                    }
                } catch (e) {
                    showToast('Error: ' + e.message, 'error');
                }
            });
        });
    </script>
</body>
</html>
