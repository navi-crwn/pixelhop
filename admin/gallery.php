<?php
/**
 * PixelHop - Admin Gallery
 * View all uploaded images with complete metadata
 */

session_start();
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../core/Gatekeeper.php';

if (!isAuthenticated() || !isAdmin()) {
    header('Location: /login.php?error=access_denied');
    exit;
}

$db = Database::getInstance();
$gatekeeper = new Gatekeeper();
$currentUser = getCurrentUser();

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Search/Filter
$search = $_GET['search'] ?? '';
$filterUser = $_GET['user'] ?? '';
$filterType = $_GET['type'] ?? '';

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
foreach ($allImages as $id => $img) {
    $img['id'] = $id;


    $userId = $img['user_id'] ?? null;
    if ($userId && isset($usersMap[$userId])) {
        $img['user_email'] = $usersMap[$userId]['email'];
        $img['user_type'] = $usersMap[$userId]['account_type'];
        $img['is_guest'] = false;
    } else {
        $img['user_email'] = 'Guest';
        $img['user_type'] = 'guest';
        $img['is_guest'] = true;
    }


    if ($filterType === 'guest' && !$img['is_guest']) continue;
    if ($filterType === 'member' && $img['is_guest']) continue;
    if ($filterUser && $userId != $filterUser) continue;
    if ($search) {
        $searchLower = strtolower($search);
        $matchFilename = stripos($img['filename'] ?? '', $search) !== false;
        $matchId = stripos($id, $search) !== false;
        $matchIp = stripos($img['ip'] ?? '', $search) !== false;
        $matchEmail = stripos($img['user_email'] ?? '', $search) !== false;
        if (!$matchFilename && !$matchId && !$matchIp && !$matchEmail) continue;
    }

    $processedImages[] = $img;
}

// Sort by date (newest first)
usort($processedImages, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));

$totalImages = count($processedImages);
$totalPages = ceil($totalImages / $perPage);
$images = array_slice($processedImages, $offset, $perPage);

// Calculate stats
$totalSize = array_sum(array_column($processedImages, 'size'));
$guestCount = count(array_filter($processedImages, fn($i) => $i['is_guest']));
$memberCount = $totalImages - $guestCount;

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function formatDate($timestamp) {
    return date('Y-m-d H:i', $timestamp);
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Gallery - PixelHop</title>
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
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: rgba(20, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .title-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .nav-links {
            display: flex;
            gap: 8px;
        }

        .nav-link {
            padding: 10px 16px;
            border-radius: 8px;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .nav-link:hover, .nav-link.active {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: rgba(20, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #fff;
        }

        .stat-label {
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.5);
            margin-top: 4px;
        }

        .filters {
            background: rgba(20, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 20px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-input {
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 13px;
            flex: 1;
            min-width: 200px;
        }

        .filter-input:focus {
            outline: none;
            border-color: #8b5cf6;
        }

        .filter-select {
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 13px;
        }

        .filter-btn {
            padding: 10px 20px;
            border-radius: 8px;
            background: linear-gradient(135deg, #8b5cf6, #6d28d9);
            color: #fff;
            border: none;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .image-card {
            background: rgba(20, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            overflow: hidden;
            transition: transform 0.2s, border-color 0.2s;
        }

        .image-card:hover {
            transform: translateY(-4px);
            border-color: rgba(139, 92, 246, 0.3);
        }

        .image-preview {
            width: 100%;
            height: 160px;
            object-fit: cover;
            background: rgba(0, 0, 0, 0.3);
        }

        .image-info {
            padding: 16px;
        }

        .image-id {
            font-family: monospace;
            font-size: 14px;
            color: #8b5cf6;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            font-size: 12px;
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: rgba(255, 255, 255, 0.5);
        }

        .info-value {
            color: #fff;
            font-weight: 500;
            text-align: right;
            max-width: 60%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-guest {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .badge-free {
            background: rgba(34, 211, 238, 0.2);
            color: #22d3ee;
        }

        .badge-premium {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
        }

        .image-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .action-btn {
            flex: 1;
            padding: 8px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            cursor: pointer;
            border: none;
        }

        .btn-view {
            background: rgba(139, 92, 246, 0.2);
            color: #8b5cf6;
        }

        .btn-delete {
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }

        .page-link {
            padding: 10px 16px;
            border-radius: 8px;
            background: rgba(20, 20, 35, 0.85);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            font-size: 13px;
        }

        .page-link:hover, .page-link.active {
            background: rgba(139, 92, 246, 0.2);
            color: #8b5cf6;
            border-color: rgba(139, 92, 246, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 60px;
            color: rgba(255, 255, 255, 0.5);
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            .nav-links {
                width: 100%;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <div class="title-icon">
                    <i data-lucide="images" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">Admin Gallery</h1>
                    <p class="text-xs text-white/50">View and manage all uploaded images</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/admin/dashboard.php" class="nav-link"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard</a>
                <a href="/admin/users.php" class="nav-link"><i data-lucide="users" class="w-4 h-4"></i> Users</a>
                <a href="/admin/tools.php" class="nav-link"><i data-lucide="wrench" class="w-4 h-4"></i> Tools</a>
                <a href="/admin/gallery.php" class="nav-link active"><i data-lucide="images" class="w-4 h-4"></i> Gallery</a>
                <a href="/admin/abuse.php" class="nav-link"><i data-lucide="shield-alert" class="w-4 h-4"></i> Abuse</a>
                <a href="/admin/settings.php" class="nav-link"><i data-lucide="settings" class="w-4 h-4"></i> Settings</a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($totalImages) ?></div>
                <div class="stat-label">Total Images</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= formatBytes($totalSize) ?></div>
                <div class="stat-label">Total Storage</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($memberCount) ?></div>
                <div class="stat-label">Member Uploads</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($guestCount) ?></div>
                <div class="stat-label">Guest Uploads</div>
            </div>
        </div>

        <!-- Filters -->
        <form class="filters" method="GET">
            <input type="text" name="search" class="filter-input" placeholder="Search by filename, ID, IP, or email..." value="<?= htmlspecialchars($search) ?>">
            <select name="type" class="filter-select">
                <option value="">All Users</option>
                <option value="member" <?= $filterType === 'member' ? 'selected' : '' ?>>Members Only</option>
                <option value="guest" <?= $filterType === 'guest' ? 'selected' : '' ?>>Guests Only</option>
            </select>
            <button type="submit" class="filter-btn">
                <i data-lucide="search" class="w-4 h-4 inline"></i> Filter
            </button>
            <?php if ($search || $filterType || $filterUser): ?>
            <a href="/admin/gallery.php" class="filter-btn" style="background: rgba(255,255,255,0.1);">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Gallery Grid -->
        <?php if (empty($images)): ?>
        <div class="empty-state">
            <i data-lucide="image-off" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
            <h3 class="text-lg font-semibold text-white mb-2">No images found</h3>
            <p>Try adjusting your search or filter criteria.</p>
        </div>
        <?php else: ?>
        <div class="gallery-grid">
            <?php foreach ($images as $img): ?>
            <div class="image-card">
                <img
                    src="<?= htmlspecialchars($img['urls']['thumb'] ?? $img['urls']['medium'] ?? '') ?>"
                    alt="<?= htmlspecialchars($img['filename'] ?? '') ?>"
                    class="image-preview"
                    loading="lazy"
                    onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><rect fill=%22%23333%22 width=%22100%22 height=%22100%22/><text x=%2250%22 y=%2250%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23666%22 font-size=%2212%22>No Preview</text></svg>'"
                >
                <div class="image-info">
                    <div class="image-id">
                        <a href="/<?= htmlspecialchars($img['id']) ?>" target="_blank" style="color: inherit; text-decoration: none;">
                            <?= htmlspecialchars($img['id']) ?>
                        </a>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Filename</span>
                        <span class="info-value" title="<?= htmlspecialchars($img['filename'] ?? 'Unknown') ?>">
                            <?= htmlspecialchars($img['filename'] ?? 'Unknown') ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">User</span>
                        <span class="info-value">
                            <span class="badge badge-<?= $img['is_guest'] ? 'guest' : ($img['user_type'] === 'premium' ? 'premium' : 'free') ?>">
                                <?= $img['is_guest'] ? 'Guest' : ($img['user_type'] === 'premium' ? 'Premium' : 'Free') ?>
                            </span>
                            <?php if (!$img['is_guest']): ?>
                            <br><small style="color: rgba(255,255,255,0.5);"><?= htmlspecialchars($img['user_email']) ?></small>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Size</span>
                        <span class="info-value"><?= formatBytes($img['size'] ?? 0) ?></span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Dimensions</span>
                        <span class="info-value"><?= ($img['width'] ?? '?') ?>×<?= ($img['height'] ?? '?') ?></span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">IP Address</span>
                        <span class="info-value" style="font-family: monospace; font-size: 11px;">
                            <?= htmlspecialchars($img['ip'] ?? 'Unknown') ?>
                        </span>
                    </div>

                    <div class="info-row">
                        <span class="info-label">Uploaded</span>
                        <span class="info-value"><?= isset($img['created_at']) ? formatDate($img['created_at']) : 'Unknown' ?></span>
                    </div>

                    <div class="image-actions">
                        <a href="/<?= htmlspecialchars($img['id']) ?>" target="_blank" class="action-btn btn-view">
                            <i data-lucide="external-link" class="w-3 h-3"></i> View
                        </a>
                        <a href="<?= htmlspecialchars($img['urls']['original'] ?? '#') ?>" target="_blank" class="action-btn btn-view">
                            <i data-lucide="download" class="w-3 h-3"></i> Original
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($filterType) ?>" class="page-link">
                <i data-lucide="chevron-left" class="w-4 h-4 inline"></i> Prev
            </a>
            <?php endif; ?>

            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);

            if ($startPage > 1): ?>
            <a href="?page=1&search=<?= urlencode($search) ?>&type=<?= urlencode($filterType) ?>" class="page-link">1</a>
            <?php if ($startPage > 2): ?><span class="page-link" style="border: none;">...</span><?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($filterType) ?>"
               class="page-link <?= $i === $page ? 'active' : '' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>

            <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?><span class="page-link" style="border: none;">...</span><?php endif; ?>
            <a href="?page=<?= $totalPages ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($filterType) ?>" class="page-link"><?= $totalPages ?></a>
            <?php endif; ?>

            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&type=<?= urlencode($filterType) ?>" class="page-link">
                Next <i data-lucide="chevron-right" class="w-4 h-4 inline"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
