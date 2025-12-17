<?php
/**
 * PixelHop - Admin SEO & Domain Management
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
        case 'save_seo':
            $settings = [
                'site_name' => $_POST['site_name'] ?? 'PixelHop',
                'site_description' => $_POST['site_description'] ?? '',
                'site_keywords' => $_POST['site_keywords'] ?? '',
                'og_image' => $_POST['og_image'] ?? '',
                'google_analytics' => $_POST['google_analytics'] ?? '',
                'google_verification' => $_POST['google_verification'] ?? '',
            ];

            foreach ($settings as $key => $value) {
                $gatekeeper->updateSetting($key, $value);
            }

            echo json_encode(['success' => true, 'message' => 'SEO settings saved']);
            break;

        case 'save_domain':
            $settings = [
                'primary_domain' => $_POST['primary_domain'] ?? '',
                'cdn_domain' => $_POST['cdn_domain'] ?? '',
                'shortlink_domain' => $_POST['shortlink_domain'] ?? 'hel.ink',
                'ssl_redirect' => (int) ($_POST['ssl_redirect'] ?? 1),
            ];

            foreach ($settings as $key => $value) {
                $gatekeeper->updateSetting($key, $value);
            }

            echo json_encode(['success' => true, 'message' => 'Domain settings saved']);
            break;

        case 'save_popup':
            $settings = [
                'popup_enabled' => (int) ($_POST['popup_enabled'] ?? 0),
                'popup_title' => $_POST['popup_title'] ?? '',
                'popup_message' => $_POST['popup_message'] ?? '',
                'popup_button_text' => $_POST['popup_button_text'] ?? 'Got it',
                'popup_button_url' => $_POST['popup_button_url'] ?? '',
                'popup_type' => $_POST['popup_type'] ?? 'info',
                'popup_show_once' => (int) ($_POST['popup_show_once'] ?? 1),
                'popup_delay_ms' => (int) ($_POST['popup_delay_ms'] ?? 1000),
            ];

            foreach ($settings as $key => $value) {
                $gatekeeper->updateSetting($key, $value);
            }

            echo json_encode(['success' => true, 'message' => 'Popup banner settings saved']);
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Get current settings
$seoSettings = [
    'site_name' => $gatekeeper->getSetting('site_name', 'PixelHop'),
    'site_description' => $gatekeeper->getSetting('site_description', 'Free online image hosting and tools'),
    'site_keywords' => $gatekeeper->getSetting('site_keywords', 'image hosting, image tools, compress, resize'),
    'og_image' => $gatekeeper->getSetting('og_image', ''),
    'google_analytics' => $gatekeeper->getSetting('google_analytics', ''),
    'google_verification' => $gatekeeper->getSetting('google_verification', ''),
];

$domainSettings = [
    'primary_domain' => $gatekeeper->getSetting('primary_domain', 'p.hel.ink'),
    'cdn_domain' => $gatekeeper->getSetting('cdn_domain', ''),
    'shortlink_domain' => $gatekeeper->getSetting('shortlink_domain', 'hel.ink'),
    'ssl_redirect' => $gatekeeper->getSetting('ssl_redirect', 1),
];

$popupSettings = [
    'popup_enabled' => $gatekeeper->getSetting('popup_enabled', 0),
    'popup_title' => $gatekeeper->getSetting('popup_title', ''),
    'popup_message' => $gatekeeper->getSetting('popup_message', ''),
    'popup_button_text' => $gatekeeper->getSetting('popup_button_text', 'Got it'),
    'popup_button_url' => $gatekeeper->getSetting('popup_button_url', ''),
    'popup_type' => $gatekeeper->getSetting('popup_type', 'info'),
    'popup_show_once' => $gatekeeper->getSetting('popup_show_once', 1),
    'popup_delay_ms' => $gatekeeper->getSetting('popup_delay_ms', 1000),
];

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEO & Domain - Admin - PixelHop</title>
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
            padding: 40px 20px;
        }

        .dashboard-container {
            width: 100%;
            max-width: 1100px;
            margin: 0 auto;
            background: rgba(20, 20, 35, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 32px;
        }

        .header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); }
        .logo-section { display: flex; align-items: center; gap: 14px; }
        .logo-icon { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #22d3ee, #a855f7); display: flex; align-items: center; justify-content: center; }
        .nav-links { display: flex; gap: 8px; flex-wrap: wrap; }
        .nav-link { padding: 10px 18px; border-radius: 10px; color: rgba(255, 255, 255, 0.6); text-decoration: none; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
        .nav-link.active { background: rgba(34, 211, 238, 0.15); color: #22d3ee; }

        .tabs { display: flex; gap: 8px; margin-bottom: 24px; border-bottom: 1px solid rgba(255, 255, 255, 0.08); padding-bottom: 16px; }
        .tab-btn { padding: 10px 20px; background: transparent; border: none; color: rgba(255, 255, 255, 0.5); cursor: pointer; border-radius: 8px; font-size: 13px; font-weight: 500; }
        .tab-btn:hover { background: rgba(255, 255, 255, 0.05); }
        .tab-btn.active { background: rgba(34, 211, 238, 0.15); color: #22d3ee; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .form-section { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.06); border-radius: 16px; padding: 24px; margin-bottom: 20px; }
        .section-title { font-size: 14px; font-weight: 600; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-size: 12px; color: rgba(255, 255, 255, 0.6); margin-bottom: 6px; }
        .form-input { width: 100%; padding: 12px 14px; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 8px; color: #fff; font-size: 13px; }
        .form-input:focus { border-color: #22d3ee; outline: none; }
        .form-textarea { min-height: 100px; resize: vertical; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } .nav-links { display: none; } }

        .toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.06); }
        .toggle-label { font-size: 13px; color: #fff; }
        .toggle-hint { font-size: 11px; color: rgba(255, 255, 255, 0.4); }

        .toggle-switch { position: relative; width: 44px; height: 24px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; inset: 0; background: rgba(255, 255, 255, 0.1); border-radius: 24px; transition: 0.3s; }
        .toggle-slider:before { content: ""; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.3s; }
        input:checked + .toggle-slider { background: #22c55e; }
        input:checked + .toggle-slider:before { transform: translateX(20px); }

        .type-select { display: flex; gap: 8px; flex-wrap: wrap; }
        .type-btn { padding: 8px 16px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.1); background: rgba(255, 255, 255, 0.05); color: rgba(255, 255, 255, 0.6); cursor: pointer; font-size: 12px; }
        .type-btn.active { border-color: #22d3ee; color: #22d3ee; background: rgba(34, 211, 238, 0.15); }
        .type-btn[data-type="success"] { border-color: #22c55e; color: #22c55e; }
        .type-btn[data-type="warning"] { border-color: #eab308; color: #eab308; }
        .type-btn[data-type="error"] { border-color: #ef4444; color: #ef4444; }

        .save-bar { display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; }
        .btn { padding: 12px 24px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; border: none; }
        .btn-primary { background: linear-gradient(135deg, #22d3ee, #a855f7); color: #fff; }
        .btn-secondary { background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.15); }

        .preview-popup { background: rgba(255, 255, 255, 0.03); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 12px; padding: 20px; margin-top: 16px; }
        .preview-label { font-size: 11px; color: rgba(255, 255, 255, 0.4); margin-bottom: 10px; }

        .toast { position: fixed; top: 20px; right: 20px; padding: 14px 20px; background: rgba(34, 197, 94, 0.9); color: #fff; border-radius: 10px; font-size: 13px; z-index: 1000; display: none; }

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
        <div class="header">
            <div class="logo-section">
                <div class="logo-icon"><i data-lucide="globe" class="w-6 h-6 text-white"></i></div>
                <div>
                    <h1 class="text-xl font-bold text-white">SEO & Domain</h1>
                    <p class="text-xs text-white/50">Manage SEO, domains and popup banner</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/admin/dashboard.php" class="nav-link"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard</a>
                <a href="/admin/users.php" class="nav-link"><i data-lucide="users" class="w-4 h-4"></i> Users</a>
                <a href="/admin/tools.php" class="nav-link"><i data-lucide="wrench" class="w-4 h-4"></i> Tools</a>
                <a href="/admin/gallery.php" class="nav-link"><i data-lucide="images" class="w-4 h-4"></i> Gallery</a>
                <a href="/admin/abuse.php" class="nav-link"><i data-lucide="shield-alert" class="w-4 h-4"></i> Abuse</a>
                <a href="/admin/settings.php" class="nav-link"><i data-lucide="settings" class="w-4 h-4"></i> Settings</a>
                <a href="/admin/seo.php" class="nav-link active"><i data-lucide="globe" class="w-4 h-4"></i> SEO</a>
            </div>
        </div>

        <div class="tabs">
            <button class="tab-btn active" data-tab="seo"><i data-lucide="search" class="w-4 h-4"></i> SEO</button>
            <button class="tab-btn" data-tab="domain"><i data-lucide="link" class="w-4 h-4"></i> Domains</button>
            <button class="tab-btn" data-tab="popup"><i data-lucide="message-square" class="w-4 h-4"></i> Popup Banner</button>
        </div>

        <!-- SEO Tab -->
        <div class="tab-content active" id="tab-seo">
            <form id="seoForm">
                <div class="form-section">
                    <div class="section-title"><i data-lucide="type" class="w-4 h-4 text-cyan-400"></i> Basic SEO</div>

                    <div class="form-group">
                        <label class="form-label">Site Name</label>
                        <input type="text" name="site_name" class="form-input" value="<?= htmlspecialchars($seoSettings['site_name']) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Site Description</label>
                        <textarea name="site_description" class="form-input form-textarea"><?= htmlspecialchars($seoSettings['site_description']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Keywords (comma separated)</label>
                        <input type="text" name="site_keywords" class="form-input" value="<?= htmlspecialchars($seoSettings['site_keywords']) ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label">OG Image URL</label>
                        <input type="url" name="og_image" class="form-input" value="<?= htmlspecialchars($seoSettings['og_image']) ?>" placeholder="https://...">
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title"><i data-lucide="bar-chart" class="w-4 h-4 text-purple-400"></i> Analytics</div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Google Analytics ID</label>
                            <input type="text" name="google_analytics" class="form-input" value="<?= htmlspecialchars($seoSettings['google_analytics']) ?>" placeholder="G-XXXXXXXXXX">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Google Verification</label>
                            <input type="text" name="google_verification" class="form-input" value="<?= htmlspecialchars($seoSettings['google_verification']) ?>">
                        </div>
                    </div>
                </div>

                <div class="save-bar">
                    <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save SEO Settings</button>
                </div>
            </form>
        </div>

        <!-- Domain Tab -->
        <div class="tab-content" id="tab-domain">
            <form id="domainForm">
                <div class="form-section">
                    <div class="section-title"><i data-lucide="link" class="w-4 h-4 text-cyan-400"></i> Domain Configuration</div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Primary Domain</label>
                            <input type="text" name="primary_domain" class="form-input" value="<?= htmlspecialchars($domainSettings['primary_domain']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">CDN Domain (optional)</label>
                            <input type="text" name="cdn_domain" class="form-input" value="<?= htmlspecialchars($domainSettings['cdn_domain']) ?>" placeholder="cdn.example.com">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Shortlink Domain</label>
                        <input type="text" name="shortlink_domain" class="form-input" value="<?= htmlspecialchars($domainSettings['shortlink_domain']) ?>" placeholder="hel.ink">
                    </div>

                    <div class="toggle-row">
                        <div>
                            <div class="toggle-label">Force HTTPS</div>
                            <div class="toggle-hint">Redirect all HTTP requests to HTTPS</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="ssl_redirect" <?= $domainSettings['ssl_redirect'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="save-bar">
                    <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Domain Settings</button>
                </div>
            </form>
        </div>

        <!-- Popup Banner Tab -->
        <div class="tab-content" id="tab-popup">
            <form id="popupForm">
                <div class="form-section">
                    <div class="section-title"><i data-lucide="message-square" class="w-4 h-4 text-yellow-400"></i> Popup Banner</div>

                    <div class="toggle-row" style="border: none; padding-top: 0;">
                        <div>
                            <div class="toggle-label">Enable Popup Banner</div>
                            <div class="toggle-hint">Show a popup to visitors on the site</div>
                        </div>
                        <label class="toggle-switch">
                            <input type="checkbox" name="popup_enabled" id="popupEnabled" <?= $popupSettings['popup_enabled'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                        </label>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Title</label>
                        <input type="text" name="popup_title" id="popupTitle" class="form-input" value="<?= htmlspecialchars($popupSettings['popup_title']) ?>" placeholder="Welcome to PixelHop!">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Message</label>
                        <textarea name="popup_message" id="popupMessage" class="form-input form-textarea" placeholder="Your announcement or message..."><?= htmlspecialchars($popupSettings['popup_message']) ?></textarea>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Button Text</label>
                            <input type="text" name="popup_button_text" id="popupButton" class="form-input" value="<?= htmlspecialchars($popupSettings['popup_button_text']) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Button URL (optional)</label>
                            <input type="url" name="popup_button_url" class="form-input" value="<?= htmlspecialchars($popupSettings['popup_button_url']) ?>" placeholder="https://...">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Popup Type</label>
                        <div class="type-select">
                            <button type="button" class="type-btn <?= $popupSettings['popup_type'] === 'info' ? 'active' : '' ?>" data-type="info">ℹ️ Info</button>
                            <button type="button" class="type-btn <?= $popupSettings['popup_type'] === 'success' ? 'active' : '' ?>" data-type="success">✅ Success</button>
                            <button type="button" class="type-btn <?= $popupSettings['popup_type'] === 'warning' ? 'active' : '' ?>" data-type="warning">⚠️ Warning</button>
                            <button type="button" class="type-btn <?= $popupSettings['popup_type'] === 'error' ? 'active' : '' ?>" data-type="error">❌ Error</button>
                        </div>
                        <input type="hidden" name="popup_type" id="popupType" value="<?= htmlspecialchars($popupSettings['popup_type']) ?>">
                    </div>

                    <div class="form-row">
                        <div class="toggle-row" style="border: none;">
                            <div>
                                <div class="toggle-label">Show Once Per Session</div>
                                <div class="toggle-hint">Don't annoy users by showing repeatedly</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="popup_show_once" <?= $popupSettings['popup_show_once'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Delay (ms)</label>
                            <input type="number" name="popup_delay_ms" class="form-input" value="<?= (int) $popupSettings['popup_delay_ms'] ?>" min="0" step="100">
                        </div>
                    </div>

                    <div class="preview-popup">
                        <div class="preview-label">PREVIEW</div>
                        <div id="popupPreview" style="background: rgba(34, 211, 238, 0.15); border: 1px solid rgba(34, 211, 238, 0.3); border-radius: 12px; padding: 20px;">
                            <div style="font-weight: 600; color: #fff; margin-bottom: 8px;" id="previewTitle">Welcome to PixelHop!</div>
                            <div style="font-size: 13px; color: rgba(255,255,255,0.7); margin-bottom: 12px;" id="previewMessage">Your announcement or message...</div>
                            <button style="padding: 8px 16px; background: #22d3ee; color: #000; border: none; border-radius: 6px; font-size: 12px;" id="previewBtn">Got it</button>
                        </div>
                    </div>
                </div>

                <div class="save-bar">
                    <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Popup Settings</button>
                </div>
            </form>
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

    <div class="toast" id="toast">Settings saved!</div>

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


        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.onclick = () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
            };
        });


        document.querySelectorAll('.type-btn').forEach(btn => {
            btn.onclick = () => {
                document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('popupType').value = btn.dataset.type;
                updatePreview();
            };
        });


        function updatePreview() {
            const title = document.getElementById('popupTitle').value || 'Title';
            const message = document.getElementById('popupMessage').value || 'Message';
            const btnText = document.getElementById('popupButton').value || 'Got it';
            const type = document.getElementById('popupType').value;

            document.getElementById('previewTitle').textContent = title;
            document.getElementById('previewMessage').textContent = message;
            document.getElementById('previewBtn').textContent = btnText;

            const colors = {
                info: { bg: 'rgba(34, 211, 238, 0.15)', border: 'rgba(34, 211, 238, 0.3)', btn: '#22d3ee' },
                success: { bg: 'rgba(34, 197, 94, 0.15)', border: 'rgba(34, 197, 94, 0.3)', btn: '#22c55e' },
                warning: { bg: 'rgba(234, 179, 8, 0.15)', border: 'rgba(234, 179, 8, 0.3)', btn: '#eab308' },
                error: { bg: 'rgba(239, 68, 68, 0.15)', border: 'rgba(239, 68, 68, 0.3)', btn: '#ef4444' }
            };

            const c = colors[type] || colors.info;
            document.getElementById('popupPreview').style.background = c.bg;
            document.getElementById('popupPreview').style.borderColor = c.border;
            document.getElementById('previewBtn').style.background = c.btn;
        }

        document.getElementById('popupTitle').oninput = updatePreview;
        document.getElementById('popupMessage').oninput = updatePreview;
        document.getElementById('popupButton').oninput = updatePreview;


        async function submitForm(form, action) {
            const fd = new FormData(form);
            fd.append('ajax', '1');
            fd.append('action', action);
            fd.append('csrf_token', csrf);


            form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                if (!cb.checked) {
                    fd.set(cb.name, '0');
                } else {
                    fd.set(cb.name, '1');
                }
            });

            const res = await fetch('/admin/seo.php', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                const toast = document.getElementById('toast');
                toast.textContent = data.message || 'Saved!';
                toast.style.display = 'block';
                setTimeout(() => toast.style.display = 'none', 3000);
            } else {
                alert(data.error || 'Failed to save');
            }
        }

        document.getElementById('seoForm').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'save_seo'); };
        document.getElementById('domainForm').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'save_domain'); };
        document.getElementById('popupForm').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'save_popup'); };

        updatePreview();
    </script>
</body>
</html>
