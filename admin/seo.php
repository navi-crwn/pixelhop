<?php
/**
 * PixelHop - SEO & Domain Admin Page
 * Sub-page of Settings
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

// Handle AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_seo') {
        $settings = [
            'site_name' => $_POST['site_name'] ?? 'PixelHop',
            'site_description' => $_POST['site_description'] ?? '',
            'site_keywords' => $_POST['site_keywords'] ?? '',
            'og_image' => $_POST['og_image'] ?? '',
            'google_analytics' => $_POST['google_analytics'] ?? '',
            'google_verification' => $_POST['google_verification'] ?? '',
        ];
        foreach ($settings as $k => $v) $gatekeeper->updateSetting($k, $v);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'save_domain') {
        $settings = [
            'primary_domain' => $_POST['primary_domain'] ?? '',
            'cdn_domain' => $_POST['cdn_domain'] ?? '',
            'shortlink_domain' => $_POST['shortlink_domain'] ?? 'hel.ink',
            'ssl_redirect' => (int)($_POST['ssl_redirect'] ?? 1),
        ];
        foreach ($settings as $k => $v) $gatekeeper->updateSetting($k, $v);
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'save_popup') {
        $settings = [
            'popup_enabled' => (int)($_POST['popup_enabled'] ?? 0),
            'popup_title' => $_POST['popup_title'] ?? '',
            'popup_message' => $_POST['popup_message'] ?? '',
            'popup_button_text' => $_POST['popup_button_text'] ?? 'Got it',
            'popup_button_url' => $_POST['popup_button_url'] ?? '',
            'popup_type' => $_POST['popup_type'] ?? 'info',
        ];
        foreach ($settings as $k => $v) $gatekeeper->updateSetting($k, $v);
        echo json_encode(['success' => true]);
        exit;
    }
    
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// Get settings
$seo = [
    'site_name' => $gatekeeper->getSetting('site_name', 'PixelHop'),
    'site_description' => $gatekeeper->getSetting('site_description', ''),
    'site_keywords' => $gatekeeper->getSetting('site_keywords', ''),
    'og_image' => $gatekeeper->getSetting('og_image', ''),
    'google_analytics' => $gatekeeper->getSetting('google_analytics', ''),
    'google_verification' => $gatekeeper->getSetting('google_verification', ''),
];

$domain = [
    'primary_domain' => $gatekeeper->getSetting('primary_domain', 'p.hel.ink'),
    'cdn_domain' => $gatekeeper->getSetting('cdn_domain', ''),
    'shortlink_domain' => $gatekeeper->getSetting('shortlink_domain', 'hel.ink'),
    'ssl_redirect' => $gatekeeper->getSetting('ssl_redirect', 1),
];

$popup = [
    'popup_enabled' => $gatekeeper->getSetting('popup_enabled', 0),
    'popup_title' => $gatekeeper->getSetting('popup_title', ''),
    'popup_message' => $gatekeeper->getSetting('popup_message', ''),
    'popup_button_text' => $gatekeeper->getSetting('popup_button_text', 'Got it'),
    'popup_button_url' => $gatekeeper->getSetting('popup_button_url', ''),
    'popup_type' => $gatekeeper->getSetting('popup_type', 'info'),
];

$csrfToken = generateCsrfToken();
$currentPage = 'settings';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SEO & Domain - Admin - PixelHop</title>
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
                <!-- Tabs -->
                <div class="tabs mb-6">
                    <button class="tab active" data-tab="seo"><i data-lucide="search" class="w-4 h-4"></i> SEO</button>
                    <button class="tab" data-tab="domain"><i data-lucide="link" class="w-4 h-4"></i> Domains</button>
                    <button class="tab" data-tab="popup"><i data-lucide="message-square" class="w-4 h-4"></i> Popup</button>
                </div>

                <!-- SEO Tab -->
                <div class="tab-content active" id="tab-seo">
                    <form id="seoForm" class="card">
                        <div class="card-header">
                            <div class="card-title"><i data-lucide="search" class="w-5 h-5"></i> SEO Settings</div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Site Name</label>
                            <input type="text" name="site_name" class="form-input" value="<?= htmlspecialchars($seo['site_name']) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Site Description</label>
                            <textarea name="site_description" class="form-input" rows="3"><?= htmlspecialchars($seo['site_description']) ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Keywords (comma separated)</label>
                            <input type="text" name="site_keywords" class="form-input" value="<?= htmlspecialchars($seo['site_keywords']) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">OG Image URL</label>
                            <input type="url" name="og_image" class="form-input" value="<?= htmlspecialchars($seo['og_image']) ?>" placeholder="https://...">
                        </div>
                        
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Google Analytics ID</label>
                                <input type="text" name="google_analytics" class="form-input" value="<?= htmlspecialchars($seo['google_analytics']) ?>" placeholder="G-XXXXXXXXXX">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Google Verification</label>
                                <input type="text" name="google_verification" class="form-input" value="<?= htmlspecialchars($seo['google_verification']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save SEO</button>
                        </div>
                    </form>
                </div>

                <!-- Domain Tab -->
                <div class="tab-content" id="tab-domain">
                    <form id="domainForm" class="card">
                        <div class="card-header">
                            <div class="card-title"><i data-lucide="link" class="w-5 h-5"></i> Domain Settings</div>
                        </div>
                        
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Primary Domain</label>
                                <input type="text" name="primary_domain" class="form-input" value="<?= htmlspecialchars($domain['primary_domain']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">CDN Domain</label>
                                <input type="text" name="cdn_domain" class="form-input" value="<?= htmlspecialchars($domain['cdn_domain']) ?>" placeholder="cdn.example.com">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Shortlink Domain</label>
                            <input type="text" name="shortlink_domain" class="form-input" value="<?= htmlspecialchars($domain['shortlink_domain']) ?>">
                        </div>
                        
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Force SSL Redirect</div>
                                <div class="toggle-hint">Redirect all HTTP to HTTPS</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="ssl_redirect" <?= $domain['ssl_redirect'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Domain</button>
                        </div>
                    </form>
                </div>

                <!-- Popup Tab -->
                <div class="tab-content" id="tab-popup">
                    <form id="popupForm" class="card">
                        <div class="card-header">
                            <div class="card-title"><i data-lucide="message-square" class="w-5 h-5"></i> Popup Banner</div>
                        </div>
                        
                        <div class="toggle-row mb-4">
                            <div>
                                <div class="toggle-label">Enable Popup</div>
                                <div class="toggle-hint">Show popup banner on homepage</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" name="popup_enabled" <?= $popup['popup_enabled'] ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Popup Title</label>
                            <input type="text" name="popup_title" class="form-input" value="<?= htmlspecialchars($popup['popup_title']) ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Message</label>
                            <textarea name="popup_message" class="form-input" rows="3"><?= htmlspecialchars($popup['popup_message']) ?></textarea>
                        </div>
                        
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Button Text</label>
                                <input type="text" name="popup_button_text" class="form-input" value="<?= htmlspecialchars($popup['popup_button_text']) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Button URL (optional)</label>
                                <input type="text" name="popup_button_url" class="form-input" value="<?= htmlspecialchars($popup['popup_button_url']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Popup Type</label>
                            <div class="type-select">
                                <?php foreach (['info', 'success', 'warning', 'error'] as $type): ?>
                                <button type="button" class="type-btn <?= $popup['popup_type'] === $type ? 'active' : '' ?>" data-type="<?= $type ?>"><?= ucfirst($type) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="popup_type" value="<?= $popup['popup_type'] ?>">
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary"><i data-lucide="save" class="w-4 h-4"></i> Save Popup</button>
                        </div>
                    </form>
                </div>
            </div>

            <footer class="admin-footer">
                <span>© 2025 PixelHop • Admin Panel v2.0</span>
                <div class="footer-links">
                    <a href="/dashboard">My Account</a>
                    <a href="/auth/logout" class="text-red">Logout</a>
                </div>
            </footer>
        </div>
    </div>

    <style>
        .tabs { display: flex; gap: 8px; border-bottom: 1px solid var(--border-color); padding-bottom: 16px; }
        .tab { padding: 10px 20px; background: transparent; border: none; color: var(--text-secondary); cursor: pointer; border-radius: 8px; font-size: 13px; display: flex; align-items: center; gap: 8px; }
        .tab:hover { background: var(--bg-tertiary); }
        .tab.active { background: rgba(34, 211, 238, 0.15); color: var(--accent-cyan); }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border-color); }
        .toggle-label { font-size: 14px; }
        .toggle-hint { font-size: 11px; color: var(--text-secondary); }
        
        .toggle-switch { position: relative; width: 44px; height: 24px; cursor: pointer; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; inset: 0; background: var(--border-color); border-radius: 24px; transition: 0.3s; }
        .toggle-slider:before { content: ""; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.3s; }
        input:checked + .toggle-slider { background: var(--accent-green); }
        input:checked + .toggle-slider:before { transform: translateX(20px); }
        
        .type-select { display: flex; gap: 8px; margin-top: 8px; }
        .type-btn { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--border-color); background: var(--bg-tertiary); color: var(--text-secondary); cursor: pointer; font-size: 12px; }
        .type-btn.active { border-color: var(--accent-cyan); color: var(--accent-cyan); }
        .type-btn[data-type="success"].active { border-color: var(--accent-green); color: var(--accent-green); }
        .type-btn[data-type="warning"].active { border-color: var(--accent-yellow); color: var(--accent-yellow); }
        .type-btn[data-type="error"].active { border-color: var(--accent-red); color: var(--accent-red); }
        
        .form-actions { margin-top: 20px; display: flex; justify-content: flex-end; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const csrf = '<?= $csrfToken ?>';
            
            // Tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.onclick = () => {
                    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    tab.classList.add('active');
                    document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
                };
            });
            
            // Type buttons
            document.querySelectorAll('.type-btn').forEach(btn => {
                btn.onclick = () => {
                    document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    document.querySelector('input[name="popup_type"]').value = btn.dataset.type;
                };
            });
            
            // Forms
            async function submitForm(form, action) {
                const fd = new FormData(form);
                fd.append('ajax', '1');
                fd.append('csrf_token', csrf);
                fd.append('action', action);
                
                // Handle checkboxes
                form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                    fd.set(cb.name, cb.checked ? '1' : '0');
                });
                
                const res = await fetch('/admin/seo.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    showToast('Settings saved!');
                }
            }
            
            document.getElementById('seoForm').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'save_seo'); };
            document.getElementById('domainForm').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'save_domain'); };
            document.getElementById('popupForm').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'save_popup'); };
            
            function showToast(msg) {
                const t = document.createElement('div');
                t.className = 'toast';
                t.textContent = msg;
                document.body.appendChild(t);
                setTimeout(() => t.classList.add('show'), 10);
                setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2000);
            }
        });
    </script>
    
    <style>
        .toast { position: fixed; top: 20px; right: 20px; padding: 14px 20px; background: var(--accent-green); color: #fff; border-radius: 10px; font-size: 13px; z-index: 1000; opacity: 0; transform: translateY(-10px); transition: all 0.3s; }
        .toast.show { opacity: 1; transform: translateY(0); }
    </style>
</body>
</html>
