<?php
/**
 * PixelHop - Admin Tools Management
 * Compact Centered Design
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
$health = $gatekeeper->getServerHealth();

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

        case 'get_stats':
            $stats = [];
            $tools = ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg'];
            foreach ($tools as $tool) {
                $count = $db->query("SELECT COUNT(*) FROM usage_logs WHERE tool_name = '$tool' AND DATE(created_at) = CURDATE()")->fetchColumn();
                $stats[$tool] = (int) $count;
            }
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Get today's usage stats
$toolStats = [];
$tools = ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg'];
foreach ($tools as $tool) {
    $count = $db->query("SELECT COUNT(*) FROM usage_logs WHERE tool_name = '$tool' AND DATE(created_at) = CURDATE()")->fetchColumn();
    $toolStats[$tool] = (int) $count;
}

// Recent tool usage
$recentLogs = $db->query("SELECT ul.*, u.email FROM usage_logs ul LEFT JOIN users u ON ul.user_id = u.id ORDER BY ul.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();

// Tool info with enabled status from database
$toolInfo = [
    'compress' => ['name' => 'Compress', 'icon' => 'file-minus', 'color' => '#22d3ee', 'type' => 'PHP', 'enabled' => $gatekeeper->getSetting('tool_compress_enabled', 1)],
    'resize' => ['name' => 'Resize', 'icon' => 'scaling', 'color' => '#a855f7', 'type' => 'PHP', 'enabled' => $gatekeeper->getSetting('tool_resize_enabled', 1)],
    'crop' => ['name' => 'Crop', 'icon' => 'crop', 'color' => '#ec4899', 'type' => 'PHP', 'enabled' => $gatekeeper->getSetting('tool_crop_enabled', 1)],
    'convert' => ['name' => 'Convert', 'icon' => 'repeat', 'color' => '#22c55e', 'type' => 'PHP', 'enabled' => $gatekeeper->getSetting('tool_convert_enabled', 1)],
    'ocr' => ['name' => 'OCR', 'icon' => 'scan-text', 'color' => '#eab308', 'type' => 'Python', 'enabled' => $gatekeeper->getSetting('tool_ocr_enabled', 1)],
    'rembg' => ['name' => 'Remove BG', 'icon' => 'eraser', 'color' => '#ef4444', 'type' => 'Python', 'enabled' => $gatekeeper->getSetting('tool_rembg_enabled', 1)],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tools - Admin - PixelHop</title>
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

        .logo-section {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .logo-icon {
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

        .tools-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 900px) {
            .tools-grid { grid-template-columns: repeat(2, 1fr); }
            .nav-links { display: none; }
        }

        @media (max-width: 500px) {
            .tools-grid { grid-template-columns: 1fr; }
        }

        .tool-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
        }

        .tool-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .tool-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .tool-name {
            font-size: 15px;
            font-weight: 600;
            color: #fff;
        }

        .tool-type {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .tool-stats {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            margin-bottom: 12px;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 700;
            color: #fff;
        }

        .stat-label {
            font-size: 10px;
            color: rgba(255, 255, 255, 0.4);
            text-transform: uppercase;
        }

        .toggle-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .toggle-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.6);
        }

        .toggle-switch {
            position: relative;
            width: 44px;
            height: 24px;
        }

        .toggle-switch input { opacity: 0; width: 0; height: 0; }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            transition: 0.3s;
        }

        .toggle-slider:before {
            content: "";
            position: absolute;
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
        }

        input:checked + .toggle-slider { background: #22c55e; }
        input:checked + .toggle-slider:before { transform: translateX(20px); }

        .content-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 10px 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            font-size: 12px;
        }
        th {
            font-size: 10px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.4);
            font-weight: 600;
        }
        td { color: #fff; }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 500;
        }
        .badge-green { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
        .badge-red { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-blue { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }

        .text-cyan { color: #22d3ee; }

        .footer {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .footer-text {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .footer-links {
            display: flex;
            gap: 16px;
        }

        .footer-link {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-link:hover {
            color: #22d3ee;
        }

        .theme-toggle {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.6);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .theme-toggle:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header">
            <div class="logo-section">
                <div class="logo-icon">
                    <i data-lucide="wrench" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">Tools Management</h1>
                    <p class="text-xs text-white/50">Control and monitor image tools</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/admin/dashboard.php" class="nav-link">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="/admin/users.php" class="nav-link">
                    <i data-lucide="users" class="w-4 h-4"></i>
                    Users
                </a>
                <a href="/admin/tools.php" class="nav-link active">
                    <i data-lucide="wrench" class="w-4 h-4"></i>
                    Tools
                </a>
                <a href="/admin/gallery.php" class="nav-link">
                    <i data-lucide="images" class="w-4 h-4"></i>
                    Gallery
                </a>
                <a href="/admin/abuse.php" class="nav-link">
                    <i data-lucide="shield-alert" class="w-4 h-4"></i>
                    Abuse
                </a>
                <a href="/admin/settings.php" class="nav-link">
                    <i data-lucide="settings" class="w-4 h-4"></i>
                    Settings
                </a>
                <a href="/admin/seo.php" class="nav-link">
                    <i data-lucide="globe" class="w-4 h-4"></i>
                    SEO
                </a>
            </div>
        </div>

        <!-- Tools Grid -->
        <div class="tools-grid">
            <?php foreach ($toolInfo as $key => $tool): ?>
            <div class="tool-card">
                <div class="tool-header">
                    <div class="tool-icon" style="background: <?= $tool['color'] ?>20;">
                        <i data-lucide="<?= $tool['icon'] ?>" class="w-5 h-5" style="color: <?= $tool['color'] ?>;"></i>
                    </div>
                    <div>
                        <div class="tool-name"><?= $tool['name'] ?></div>
                        <div class="tool-type"><?= $tool['type'] ?> Engine</div>
                    </div>
                </div>
                <div class="tool-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $toolStats[$key] ?></div>
                        <div class="stat-label">Today</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value" style="color: <?= $tool['color'] ?>;">●</div>
                        <div class="stat-label">Status</div>
                    </div>
                </div>
                <div class="toggle-row">
                    <span class="toggle-label">Enabled</span>
                    <label class="toggle-switch">
                        <input type="checkbox" <?= $tool['enabled'] ? 'checked' : '' ?> data-tool="<?= $key ?>">
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Recent Activity -->
        <div class="content-card">
            <div class="card-header">
                <i data-lucide="activity" class="w-4 h-4 text-cyan"></i>
                Recent Tool Usage
            </div>
            <table>
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Tool</th>
                        <th>Size</th>
                        <th>Time</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td><?= htmlspecialchars($log['email'] ?? 'Guest') ?></td>
                        <td><span class="badge badge-blue"><?= $log['tool_name'] ?></span></td>
                        <td><?= round($log['file_size'] / 1024, 1) ?> KB</td>
                        <td><?= $log['processing_time_ms'] ?>ms</td>
                        <td><span class="badge <?= $log['status'] === 'success' ? 'badge-green' : 'badge-red' ?>"><?= $log['status'] ?></span></td>
                        <td style="color: rgba(255,255,255,0.5);"><?= date('M j, H:i', strtotime($log['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentLogs)): ?>
                    <tr><td colspan="6" class="text-center py-6" style="color: rgba(255,255,255,0.4);">No activity yet</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="footer-text">
                <button onclick="toggleTheme()" class="theme-toggle" title="Toggle theme">
                    <i data-lucide="sun" class="w-4 h-4 theme-icon-light"></i>
                    <i data-lucide="moon" class="w-4 h-4 theme-icon-dark"></i>
                </button>
                © 2025 PixelHop • Admin Panel v2.0
            </div>
            <div class="footer-links">
                <a href="/admin/dashboard.php" class="footer-link">Dashboard</a>
                <a href="/tools" class="footer-link">Tools</a>
                <a href="/auth/logout.php" class="footer-link" style="color: #ef4444;">Logout</a>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        const csrf = '<?= $csrfToken ?>';


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

        document.querySelectorAll('[data-tool]').forEach(toggle => {
            toggle.onchange = async () => {
                const tool = toggle.dataset.tool;
                const enabled = toggle.checked ? 1 : 0;

                const fd = new FormData();
                fd.append('ajax', '1');
                fd.append('action', 'toggle_tool');
                fd.append('csrf_token', csrf);
                fd.append('tool', tool);
                fd.append('enabled', enabled);

                await fetch('/admin/tools.php', { method: 'POST', body: fd });
            };
        });
    </script>
</body>
</html>
