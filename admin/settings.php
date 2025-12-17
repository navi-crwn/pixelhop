<?php
/**
 * PixelHop - Admin Settings
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

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'update_setting':
            $key = $_POST['key'] ?? '';
            $value = $_POST['value'] ?? '';
            $allowed = [
                'max_concurrent_processes',
                'cpu_load_threshold',
                'maintenance_mode',
                'kill_switch_active',
                'daily_ocr_limit_free',
                'daily_ocr_limit_premium',
                'daily_removebg_limit_free',
                'daily_removebg_limit_premium',
                'max_upload_size_free',
                'max_upload_size_premium',
                'storage_limit_free',
                'storage_limit_premium'
            ];
            if (in_array($key, $allowed)) {
                $gatekeeper->updateSetting($key, $value);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Invalid setting key']);
            }
            break;

        case 'save_all':
            $settings = json_decode($_POST['settings'] ?? '{}', true);
            $saved = 0;
            foreach ($settings as $key => $value) {
                $gatekeeper->updateSetting($key, $value);
                $saved++;
            }
            echo json_encode(['success' => true, 'saved' => $saved]);
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Get current settings
$settings = [
    'max_concurrent_processes' => $gatekeeper->getSetting('max_concurrent_processes'),
    'cpu_load_threshold' => $gatekeeper->getSetting('cpu_load_threshold'),
    'maintenance_mode' => $gatekeeper->getSetting('maintenance_mode'),
    'kill_switch_active' => $gatekeeper->getSetting('kill_switch_active'),
    'daily_ocr_limit_free' => $gatekeeper->getSetting('daily_ocr_limit_free'),
    'daily_ocr_limit_premium' => $gatekeeper->getSetting('daily_ocr_limit_premium'),
    'daily_removebg_limit_free' => $gatekeeper->getSetting('daily_removebg_limit_free'),
    'daily_removebg_limit_premium' => $gatekeeper->getSetting('daily_removebg_limit_premium'),
];

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin - PixelHop</title>
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
            max-width: 900px;
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
            flex-wrap: wrap;
            gap: 16px;
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

        .nav-links {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .nav-link {
            padding: 8px 14px;
            border-radius: 10px;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
        .nav-link.active { background: rgba(34, 211, 238, 0.15); color: #22d3ee; }

        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        @media (max-width: 700px) {
            .settings-grid { grid-template-columns: 1fr; }
            .nav-links { display: none; }
        }

        .setting-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
        }

        .setting-card.full-width {
            grid-column: span 2;
        }

        @media (max-width: 700px) {
            .setting-card.full-width { grid-column: span 1; }
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
        }

        .setting-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
        }

        .setting-row:last-child { border-bottom: none; }

        .setting-label {
            font-size: 13px;
            color: rgba(255, 255, 255, 0.7);
        }

        .setting-desc {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
            margin-top: 2px;
        }

        .setting-input {
            width: 100px;
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 13px;
            text-align: center;
        }

        .setting-input:focus {
            outline: none;
            border-color: #22d3ee;
        }

        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            width: 52px;
            height: 28px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 28px;
            transition: 0.3s;
        }

        .toggle-slider:before {
            content: "";
            position: absolute;
            height: 22px;
            width: 22px;
            left: 3px;
            bottom: 3px;
            background: white;
            border-radius: 50%;
            transition: 0.3s;
        }

        input:checked + .toggle-slider {
            background: #22d3ee;
        }

        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        .toggle-danger input:checked + .toggle-slider {
            background: #ef4444;
        }

        .toggle-warning input:checked + .toggle-slider {
            background: #eab308;
        }

        .save-btn {
            width: 100%;
            padding: 14px;
            border-radius: 12px;
            border: none;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }

        .save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(34, 211, 238, 0.3);
        }

        .save-result {
            text-align: center;
            margin-top: 12px;
            font-size: 13px;
            color: #22c55e;
        }

        .text-cyan { color: #22d3ee; }
        .text-purple { color: #a855f7; }
        .text-yellow { color: #eab308; }
        .text-red { color: #ef4444; }

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
                    <i data-lucide="settings" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">Settings</h1>
                    <p class="text-xs text-white/50">System configuration</p>
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
                <a href="/admin/tools.php" class="nav-link">
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
                <a href="/admin/settings.php" class="nav-link active">
                    <i data-lucide="settings" class="w-4 h-4"></i>
                    Settings
                </a>
                <a href="/admin/seo.php" class="nav-link">
                    <i data-lucide="globe" class="w-4 h-4"></i>
                    SEO
                </a>
            </div>
        </div>

        <div class="settings-grid">
            <!-- System Controls -->
            <div class="setting-card">
                <div class="card-header">
                    <i data-lucide="shield" class="w-5 h-5 text-cyan"></i>
                    System Controls
                </div>

                <div class="setting-row">
                    <div>
                        <div class="setting-label">Maintenance Mode</div>
                        <div class="setting-desc">Disable tools for non-admin users</div>
                    </div>
                    <label class="toggle-switch toggle-warning">
                        <input type="checkbox" id="maintenance_mode" <?= $settings['maintenance_mode'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>

                <div class="setting-row">
                    <div>
                        <div class="setting-label">Kill Switch</div>
                        <div class="setting-desc">Block ALL AI tools (emergency)</div>
                    </div>
                    <label class="toggle-switch toggle-danger">
                        <input type="checkbox" id="kill_switch_active" <?= $settings['kill_switch_active'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>

            <!-- Server Limits -->
            <div class="setting-card">
                <div class="card-header">
                    <i data-lucide="server" class="w-5 h-5 text-purple"></i>
                    Server Limits
                </div>

                <div class="setting-row">
                    <div>
                        <div class="setting-label">Max Concurrent Processes</div>
                        <div class="setting-desc">Python processes limit</div>
                    </div>
                    <input type="number" class="setting-input" id="max_concurrent_processes"
                           value="<?= $settings['max_concurrent_processes'] ?>" min="1" max="4">
                </div>

                <div class="setting-row">
                    <div>
                        <div class="setting-label">CPU Load Threshold</div>
                        <div class="setting-desc">Block heavy tools above this</div>
                    </div>
                    <input type="number" class="setting-input" id="cpu_load_threshold"
                           value="<?= $settings['cpu_load_threshold'] ?>" min="1" max="4" step="0.5">
                </div>
            </div>

            <!-- OCR Limits -->
            <div class="setting-card">
                <div class="card-header">
                    <i data-lucide="scan-text" class="w-5 h-5 text-yellow"></i>
                    Daily OCR Limits
                </div>

                <div class="setting-row">
                    <div>
                        <div class="setting-label">Free Users</div>
                        <div class="setting-desc">OCR per day for free tier</div>
                    </div>
                    <input type="number" class="setting-input" id="daily_ocr_limit_free"
                           value="<?= $settings['daily_ocr_limit_free'] ?>" min="0">
                </div>

                <div class="setting-row">
                    <div>
                        <div class="setting-label">Premium Users</div>
                        <div class="setting-desc">OCR per day for premium</div>
                    </div>
                    <input type="number" class="setting-input" id="daily_ocr_limit_premium"
                           value="<?= $settings['daily_ocr_limit_premium'] ?>" min="0">
                </div>
            </div>

            <!-- Remove BG Limits -->
            <div class="setting-card">
                <div class="card-header">
                    <i data-lucide="eraser" class="w-5 h-5 text-red"></i>
                    Daily Remove BG Limits
                </div>

                <div class="setting-row">
                    <div>
                        <div class="setting-label">Free Users</div>
                        <div class="setting-desc">RemBG per day for free tier</div>
                    </div>
                    <input type="number" class="setting-input" id="daily_removebg_limit_free"
                           value="<?= $settings['daily_removebg_limit_free'] ?>" min="0">
                </div>

                <div class="setting-row">
                    <div>
                        <div class="setting-label">Premium Users</div>
                        <div class="setting-desc">RemBG per day for premium</div>
                    </div>
                    <input type="number" class="setting-input" id="daily_removebg_limit_premium"
                           value="<?= $settings['daily_removebg_limit_premium'] ?>" min="0">
                </div>
            </div>
        </div>

        <button class="save-btn" id="save-btn">
            <i data-lucide="save" class="w-5 h-5"></i>
            Save All Settings
        </button>
        <div class="save-result" id="save-result"></div>

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

        document.getElementById('save-btn').onclick = async () => {
            const settings = {
                maintenance_mode: document.getElementById('maintenance_mode').checked ? 1 : 0,
                kill_switch_active: document.getElementById('kill_switch_active').checked ? 1 : 0,
                max_concurrent_processes: document.getElementById('max_concurrent_processes').value,
                cpu_load_threshold: document.getElementById('cpu_load_threshold').value,
                daily_ocr_limit_free: document.getElementById('daily_ocr_limit_free').value,
                daily_ocr_limit_premium: document.getElementById('daily_ocr_limit_premium').value,
                daily_removebg_limit_free: document.getElementById('daily_removebg_limit_free').value,
                daily_removebg_limit_premium: document.getElementById('daily_removebg_limit_premium').value,
            };

            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', 'save_all');
            fd.append('csrf_token', csrf);
            fd.append('settings', JSON.stringify(settings));

            const res = await fetch('/admin/settings.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                document.getElementById('save-result').textContent = '✓ Settings saved successfully!';
                setTimeout(() => {
                    document.getElementById('save-result').textContent = '';
                }, 3000);
            } else {
                document.getElementById('save-result').textContent = '✗ ' + (data.error || 'Failed to save');
                document.getElementById('save-result').style.color = '#ef4444';
            }
        };
    </script>
</body>
</html>
