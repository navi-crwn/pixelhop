<?php
/**
 * PixelHop - Admin Tools Stats
 * Sub-page of Gallery
 */

require_once __DIR__ . '/../includes/bootstrap.php';
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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'toggle_tool':
            $tool = $_POST['tool'] ?? '';
            $enabled = (int) ($_POST['enabled'] ?? 1);
            $allowed = ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg'];
            if (in_array($tool, $allowed)) {
                $gatekeeper->updateSetting("tool_{$tool}_enabled", $enabled);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Invalid tool']);
            }
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Get today's usage stats
$toolStats = [];
$tools = ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg'];
$toolStatsStmt = $db->prepare("SELECT COUNT(*) FROM usage_logs WHERE tool_name = ? AND DATE(created_at) = CURDATE()");
foreach ($tools as $tool) {
    $toolStatsStmt->execute([$tool]);
    $count = $toolStatsStmt->fetchColumn();
    $toolStats[$tool] = (int) $count;
}

$totalToday = array_sum($toolStats);

$toolInfo = [
    'compress' => ['name' => 'Compress', 'icon' => 'file-minus', 'color' => 'cyan', 'type' => 'PHP', 'enabled' => $gatekeeper->getSetting('tool_compress_enabled', 1)],
    'resize' => ['name' => 'Resize', 'icon' => 'scaling', 'color' => 'purple', 'type' => 'PHP', 'enabled' => $gatekeeper->getSetting('tool_resize_enabled', 1)],
    'crop' => ['name' => 'Crop', 'icon' => 'crop', 'color' => 'purple', 'type' => 'PHP', 'enabled' => $gatekeeper->getSetting('tool_crop_enabled', 1)],
    'convert' => ['name' => 'Convert', 'icon' => 'repeat', 'color' => 'green', 'type' => 'PHP', 'enabled' => $gatekeeper->getSetting('tool_convert_enabled', 1)],
    'ocr' => ['name' => 'OCR', 'icon' => 'scan-text', 'color' => 'yellow', 'type' => 'Python', 'enabled' => $gatekeeper->getSetting('tool_ocr_enabled', 1)],
    'rembg' => ['name' => 'Remove BG', 'icon' => 'eraser', 'color' => 'red', 'type' => 'Python', 'enabled' => $gatekeeper->getSetting('tool_rembg_enabled', 1)],
];

$csrfToken = generateCsrfToken();
$currentPage = 'gallery';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tools Stats - Admin - PixelHop</title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/admin/includes/admin-styles.css">
    <script src="/admin/includes/admin-scripts.js"></script>
</head>
<body>
    <div class="admin-wrapper">
        <div class="admin-container">
            <?php include __DIR__ . '/includes/header.php'; ?>
            
            <div class="admin-content">
                <!-- Stats Summary -->
                <div class="grid-3 mb-6">
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="activity" class="w-4 h-4 text-cyan"></i>
                            Today's Usage
                        </div>
                        <div class="stat-value"><?= number_format($totalToday) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="code" class="w-4 h-4 text-purple"></i>
                            PHP Tools
                        </div>
                        <div class="stat-value">4</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="brain" class="w-4 h-4 text-yellow"></i>
                            Python/AI Tools
                        </div>
                        <div class="stat-value">2</div>
                    </div>
                </div>

                <!-- Tools Grid -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i data-lucide="wrench" class="w-5 h-5"></i>
                            Tool Management
                        </div>
                    </div>
                    
                    <div class="tools-grid">
                        <?php foreach ($toolInfo as $key => $tool): ?>
                        <div class="tool-card" data-tool="<?= $key ?>">
                            <div class="tool-header">
                                <div class="tool-icon text-<?= $tool['color'] ?>">
                                    <i data-lucide="<?= $tool['icon'] ?>" class="w-6 h-6"></i>
                                </div>
                                <label class="toggle-switch">
                                    <input type="checkbox" class="tool-toggle" data-tool="<?= $key ?>" <?= $tool['enabled'] ? 'checked' : '' ?>>
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            <div class="tool-name"><?= $tool['name'] ?></div>
                            <div class="tool-meta">
                                <span class="badge badge-<?= $tool['type'] === 'Python' ? 'yellow' : 'cyan' ?>"><?= $tool['type'] ?></span>
                                <span class="tool-count"><?= $toolStats[$key] ?? 0 ?> today</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
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

    <style>
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
            padding: 16px 0;
        }
        .tool-card {
            background: var(--border-color);
            border-radius: 12px;
            padding: 16px;
        }
        .tool-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }
        .tool-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(255,255,255,0.05);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .tool-name {
            font-size: 16px;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }
        .tool-meta {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tool-count {
            font-size: 12px;
            color: var(--text-muted);
        }
        .badge {
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        .badge-cyan { background: rgba(34, 211, 238, 0.15); color: var(--accent-cyan); }
        .badge-yellow { background: rgba(234, 179, 8, 0.15); color: var(--accent-yellow); }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const csrf = '<?= $csrfToken ?>';
            
            document.querySelectorAll('.tool-toggle').forEach(toggle => {
                toggle.onchange = async function() {
                    const tool = this.dataset.tool;
                    const enabled = this.checked ? 1 : 0;
                    
                    const fd = new FormData();
                    fd.append('ajax', '1');
                    fd.append('action', 'toggle_tool');
                    fd.append('csrf_token', csrf);
                    fd.append('tool', tool);
                    fd.append('enabled', enabled);
                    
                    await fetch('/admin/tools.php', { method: 'POST', body: fd });
                };
            });
        });
    </script>
</body>
</html>
