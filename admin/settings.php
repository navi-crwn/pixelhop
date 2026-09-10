<?php
/**
 * PixelHop - Admin Settings
 * Combined Settings + SEO management
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
$currentPage = 'settings';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Admin - PixelHop</title>
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
                <div class="grid-2">
                    <!-- System Controls -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="shield" class="w-5 h-5"></i>
                                System Controls
                            </div>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-info">
                                <span class="setting-label">Maintenance Mode</span>
                                <span class="setting-desc">Disable tools for non-admin users</span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="maintenance_mode" <?= $settings['maintenance_mode'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="setting-row">
                            <div class="setting-info">
                                <span class="setting-label">Kill Switch</span>
                                <span class="setting-desc">Block ALL AI tools (emergency)</span>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" id="kill_switch_active" <?= $settings['kill_switch_active'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <!-- Server Limits -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="server" class="w-5 h-5"></i>
                                Server Limits
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Max Concurrent Processes</label>
                            <div class="number-input-inline">
                                <button type="button" onclick="adjustValue('max_concurrent_processes', -1, 1, 10)">−</button>
                                <input type="number" id="max_concurrent_processes" value="<?= $settings['max_concurrent_processes'] ?>" min="1" max="10" readonly>
                                <button type="button" onclick="adjustValue('max_concurrent_processes', 1, 1, 10)">+</button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">CPU Load Threshold</label>
                            <div class="number-input-inline">
                                <button type="button" onclick="adjustValue('cpu_load_threshold', -0.5, 1, 10)">−</button>
                                <input type="number" id="cpu_load_threshold" value="<?= $settings['cpu_load_threshold'] ?>" min="1" max="10" step="0.5" readonly>
                                <button type="button" onclick="adjustValue('cpu_load_threshold', 0.5, 1, 10)">+</button>
                            </div>
                        </div>
                    </div>

                    <!-- Daily OCR Limits -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="scan-text" class="w-5 h-5"></i>
                                Daily OCR Limits
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Free Users (per day)</label>
                            <div class="number-input-inline">
                                <button type="button" onclick="adjustValue('daily_ocr_limit_free', -1, 0, 100)">−</button>
                                <input type="number" id="daily_ocr_limit_free" value="<?= $settings['daily_ocr_limit_free'] ?>" min="0" readonly>
                                <button type="button" onclick="adjustValue('daily_ocr_limit_free', 1, 0, 100)">+</button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Premium Users (per day)</label>
                            <div class="number-input-inline">
                                <button type="button" onclick="adjustValue('daily_ocr_limit_premium', -5, 0, 500)">−</button>
                                <input type="number" id="daily_ocr_limit_premium" value="<?= $settings['daily_ocr_limit_premium'] ?>" min="0" readonly>
                                <button type="button" onclick="adjustValue('daily_ocr_limit_premium', 5, 0, 500)">+</button>
                            </div>
                        </div>
                    </div>

                    <!-- Daily Remove BG Limits -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="eraser" class="w-5 h-5"></i>
                                Daily Remove BG Limits
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Free Users (per day)</label>
                            <div class="number-input-inline">
                                <button type="button" onclick="adjustValue('daily_removebg_limit_free', -1, 0, 100)">−</button>
                                <input type="number" id="daily_removebg_limit_free" value="<?= $settings['daily_removebg_limit_free'] ?>" min="0" readonly>
                                <button type="button" onclick="adjustValue('daily_removebg_limit_free', 1, 0, 100)">+</button>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Premium Users (per day)</label>
                            <div class="number-input-inline">
                                <button type="button" onclick="adjustValue('daily_removebg_limit_premium', -5, 0, 500)">−</button>
                                <input type="number" id="daily_removebg_limit_premium" value="<?= $settings['daily_removebg_limit_premium'] ?>" min="0" readonly>
                                <button type="button" onclick="adjustValue('daily_removebg_limit_premium', 5, 0, 500)">+</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Save Button -->
                <div class="mt-6">
                    <button id="btn-save" class="btn btn-primary" style="width: 100%;">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        Save All Settings
                    </button>
                    <div id="save-result" class="text-muted mt-4" style="text-align: center; font-size: 13px;"></div>
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
        .setting-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        .setting-row:last-child { border-bottom: none; }
        .setting-info { flex: 1; }
        .setting-label { display: block; font-size: 14px; font-weight: 500; color: var(--text-primary); }
        .setting-desc { display: block; font-size: 12px; color: var(--text-muted); margin-top: 2px; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const csrf = '<?= $csrfToken ?>';
            
            // Adjust value function for number inputs
            window.adjustValue = function(id, delta, min, max) {
                const input = document.getElementById(id);
                let value = parseFloat(input.value) || 0;
                value += delta;
                if (value < min) value = min;
                if (value > max) value = max;
                // Round to 1 decimal for float values
                if (delta % 1 !== 0) {
                    value = Math.round(value * 10) / 10;
                }
                input.value = value;
            };
            
            document.getElementById('btn-save').onclick = async () => {
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
                
                try {
                    const res = await fetch('/admin/settings.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    
                    if (data.success) {
                        document.getElementById('save-result').innerHTML = '<span class="text-green">✓ Settings saved successfully!</span>';
                    } else {
                        document.getElementById('save-result').innerHTML = '<span class="text-red">✗ Failed to save settings</span>';
                    }
                } catch (e) {
                    document.getElementById('save-result').innerHTML = '<span class="text-red">✗ Error: ' + e.message + '</span>';
                }
            };
        });
    </script>
</body>
</html>
