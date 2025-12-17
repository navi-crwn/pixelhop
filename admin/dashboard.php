<?php
/**
 * PixelHop - Admin Dashboard
 * Compact Centered Design inspired by dashdot
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
        case 'get_health':
            echo json_encode($gatekeeper->getServerHealth());
            break;

        case 'purge_temp':
            $result = $gatekeeper->cleanupExpiredTempFiles();
            echo json_encode(['success' => true, 'result' => $result]);
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Get stats
$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalImages = 0;
$totalStorageUsed = 0;
$imagesFile = __DIR__ . '/../data/images.json';
if (file_exists($imagesFile)) {
    $images = json_decode(file_get_contents($imagesFile), true) ?: [];
    $totalImages = count($images);

    foreach ($images as $img) {
        $totalStorageUsed += $img['size'] ?? 0;
    }
}

// Override storage if images.json has data but database shows 0
if ($totalStorageUsed > 0 && ($health['storage']['global_used'] ?? 0) == 0) {
    $health['storage']['global_used'] = $totalStorageUsed;
    $health['storage']['global_used_human'] = formatBytes($totalStorageUsed);
    $health['storage']['percent'] = round(($totalStorageUsed / 268435456000) * 100, 2);
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

$csrfToken = generateCsrfToken();

// Calculate percentages
$cpuPercent = min(100, ($health['cpu']['load_1m'] / 4) * 100);
$memPercent = $health['memory']['percent'];
$diskPercent = $health['disk']['percent'];
$storagePercent = $health['storage']['percent'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PixelHop</title>
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
            max-width: 1200px;
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

        .nav-links {
            display: flex;
            gap: 8px;
        }

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

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .nav-link.active {
            background: rgba(34, 211, 238, 0.15);
            color: #22d3ee;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .nav-links { display: none; }
        }

        @media (max-width: 500px) {
            .stats-grid { grid-template-columns: 1fr; }
            .dashboard-container { padding: 20px; }
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }

        .stat-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .gauge-ring {
            position: relative;
            width: 100px;
            height: 100px;
            margin: 0 auto 12px;
        }

        .gauge-ring svg {
            transform: rotate(-90deg);
            width: 100%;
            height: 100%;
        }

        .gauge-bg {
            fill: none;
            stroke: rgba(255, 255, 255, 0.08);
            stroke-width: 8;
        }

        .gauge-fill {
            fill: none;
            stroke-width: 8;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.8s ease;
        }

        .gauge-center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
        }

        .gauge-value {
            font-size: 22px;
            font-weight: 700;
            color: #fff;
        }

        .gauge-unit {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.5);
        }

        .stat-detail {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.4);
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 768px) {
            .info-grid { grid-template-columns: 1fr; }
        }

        .info-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
        }

        .info-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            font-size: 13px;
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
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
            animation: pulse 2s infinite;
        }

        .status-green { background: #22c55e; box-shadow: 0 0 10px #22c55e; }
        .status-yellow { background: #eab308; box-shadow: 0 0 10px #eab308; }
        .status-red { background: #ef4444; box-shadow: 0 0 10px #ef4444; }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .quick-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 12px 20px;
            border-radius: 12px;
            border: none;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-btn-primary {
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            color: #fff;
        }

        .action-btn-secondary {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
        }

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

        /* Color helpers */
        .text-cyan { color: #22d3ee; }
        .text-purple { color: #a855f7; }
        .text-green { color: #22c55e; }
        .text-yellow { color: #eab308; }
        .text-red { color: #ef4444; }

        .stroke-cyan { stroke: #22d3ee; }
        .stroke-purple { stroke: #a855f7; }
        .stroke-green { stroke: #22c55e; }
        .stroke-yellow { stroke: #eab308; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header">
            <div class="logo-section">
                <div class="logo-icon">
                    <i data-lucide="shield" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">PixelHop Admin</h1>
                    <p class="text-xs text-white/50">Server Monitoring Dashboard</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/admin/dashboard.php" class="nav-link active">
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
                <a href="/admin/settings.php" class="nav-link">
                    <i data-lucide="settings" class="w-4 h-4"></i>
                    Settings
                </a>
                <a href="/admin/seo.php" class="nav-link">
                    <i data-lucide="globe" class="w-4 h-4"></i>
                    SEO
                </a>
                <a href="/" class="nav-link">
                    <i data-lucide="home" class="w-4 h-4"></i>
                    Site
                </a>
            </div>
        </div>

        <!-- Stats Grid - Gauges -->
        <div class="stats-grid">
            <!-- CPU -->
            <div class="stat-card">
                <div class="stat-label">
                    <i data-lucide="cpu" class="w-4 h-4 text-cyan"></i>
                    CPU Load
                </div>
                <div class="gauge-ring">
                    <svg viewBox="0 0 100 100">
                        <circle class="gauge-bg" cx="50" cy="50" r="42"></circle>
                        <circle class="gauge-fill stroke-cyan" cx="50" cy="50" r="42" id="cpu-ring"
                            stroke-dasharray="264"
                            stroke-dashoffset="<?= 264 - ($cpuPercent / 100 * 264) ?>"></circle>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value" id="cpu-value"><?= number_format($health['cpu']['load_1m'], 1) ?></div>
                        <div class="gauge-unit">/ 4 cores</div>
                    </div>
                </div>
                <div class="stat-detail" id="cpu-detail">1min: <?= number_format($health['cpu']['load_1m'], 2) ?> | 5min: <?= number_format($health['cpu']['load_5m'], 2) ?></div>
            </div>

            <!-- Memory -->
            <div class="stat-card">
                <div class="stat-label">
                    <i data-lucide="memory-stick" class="w-4 h-4 text-purple"></i>
                    Memory
                </div>
                <div class="gauge-ring">
                    <svg viewBox="0 0 100 100">
                        <circle class="gauge-bg" cx="50" cy="50" r="42"></circle>
                        <circle class="gauge-fill stroke-purple" cx="50" cy="50" r="42" id="mem-ring"
                            stroke-dasharray="264"
                            stroke-dashoffset="<?= 264 - ($memPercent / 100 * 264) ?>"></circle>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value" id="mem-value"><?= $memPercent ?>%</div>
                        <div class="gauge-unit">used</div>
                    </div>
                </div>
                <div class="stat-detail" id="mem-detail"><?= $health['memory']['used_human'] ?> / <?= $health['memory']['total_human'] ?></div>
            </div>

            <!-- Disk -->
            <div class="stat-card">
                <div class="stat-label">
                    <i data-lucide="hard-drive" class="w-4 h-4 text-green"></i>
                    Disk
                </div>
                <div class="gauge-ring">
                    <svg viewBox="0 0 100 100">
                        <circle class="gauge-bg" cx="50" cy="50" r="42"></circle>
                        <circle class="gauge-fill stroke-green" cx="50" cy="50" r="42" id="disk-ring"
                            stroke-dasharray="264"
                            stroke-dashoffset="<?= 264 - ($diskPercent / 100 * 264) ?>"></circle>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value" id="disk-value"><?= $diskPercent ?>%</div>
                        <div class="gauge-unit">used</div>
                    </div>
                </div>
                <div class="stat-detail" id="disk-detail"><?= $health['disk']['used_human'] ?> / <?= $health['disk']['total_human'] ?></div>
            </div>

            <!-- Storage -->
            <div class="stat-card">
                <div class="stat-label">
                    <i data-lucide="database" class="w-4 h-4 text-yellow"></i>
                    Object Storage
                </div>
                <div class="gauge-ring">
                    <svg viewBox="0 0 100 100">
                        <circle class="gauge-bg" cx="50" cy="50" r="42"></circle>
                        <circle class="gauge-fill stroke-yellow" cx="50" cy="50" r="42" id="storage-ring"
                            stroke-dasharray="264"
                            stroke-dashoffset="<?= 264 - ($storagePercent / 100 * 264) ?>"></circle>
                    </svg>
                    <div class="gauge-center">
                        <div class="gauge-value" id="storage-value"><?= $storagePercent ?>%</div>
                        <div class="gauge-unit">used</div>
                    </div>
                </div>
                <div class="stat-detail" id="storage-detail"><?= $health['storage']['global_used_human'] ?> / 250 GB</div>
            </div>
        </div>

        <!-- Info Grid -->
        <div class="info-grid">
            <!-- Server Info -->
            <div class="info-card">
                <div class="info-header">
                    <i data-lucide="server" class="w-4 h-4 text-cyan"></i>
                    Server Info
                </div>
                <div class="info-row">
                    <span class="info-label">Status</span>
                    <span class="info-value">
                        <span class="status-dot status-<?= $health['cpu']['status'] === 'healthy' ? 'green' : ($health['cpu']['status'] === 'warning' ? 'yellow' : 'red') ?>"></span>
                        <?= ucfirst($health['cpu']['status']) ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Python Processes</span>
                    <span class="info-value"><?= $health['processes']['python_running'] ?> / <?= $health['processes']['max_allowed'] ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Disk Free</span>
                    <span class="info-value"><?= $health['disk']['free_human'] ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Maintenance</span>
                    <span class="info-value"><?= $health['status']['maintenance'] ? '<span class="text-yellow">ON</span>' : '<span class="text-green">OFF</span>' ?></span>
                </div>
            </div>

            <!-- Quick Stats -->
            <div class="info-card">
                <div class="info-header">
                    <i data-lucide="bar-chart-3" class="w-4 h-4 text-purple"></i>
                    Statistics
                </div>
                <div class="info-row">
                    <span class="info-label">Total Users</span>
                    <span class="info-value"><?= number_format($totalUsers) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Images</span>
                    <span class="info-value"><?= number_format($totalImages) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Kill Switch</span>
                    <span class="info-value"><?= $health['status']['kill_switch'] ? '<span class="text-red">ACTIVE</span>' : '<span class="text-green">OFF</span>' ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">PHP Version</span>
                    <span class="info-value"><?= PHP_VERSION ?></span>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="info-card">
                <div class="info-header">
                    <i data-lucide="zap" class="w-4 h-4 text-yellow"></i>
                    Quick Actions
                </div>
                <div class="quick-actions">
                    <button id="btn-refresh" class="action-btn action-btn-primary">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                        Refresh
                    </button>
                    <button id="btn-purge" class="action-btn action-btn-secondary">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                        Purge Temp
                    </button>
                </div>
                <div id="action-result" class="mt-4 text-xs text-center" style="color: rgba(255,255,255,0.5);"></div>
            </div>
        </div>

        <!-- Footer -->
        <div class="footer">
            <div class="footer-text" style="display: flex; align-items: center; gap: 16px;">
                <button onclick="toggleTheme()" class="theme-toggle" title="Toggle theme">
                    <i data-lucide="sun" class="w-4 h-4 theme-icon-light"></i>
                    <i data-lucide="moon" class="w-4 h-4 theme-icon-dark"></i>
                </button>
                <span>© 2025 PixelHop • Admin Panel v2.0</span>
            </div>
            <div class="footer-links">
                <a href="/dashboard.php" class="footer-link">My Account</a>
                <a href="/tools" class="footer-link">Tools</a>
                <a href="/auth/logout.php" class="footer-link text-red">Logout</a>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        const csrf = '<?= $csrfToken ?>';


        function toggleTheme() {

            const current = localStorage.getItem('pixelhop-theme') || 'dark';
            const next = current === 'dark' ? 'light' : 'dark';
            localStorage.setItem('pixelhop-theme', next);

            updateThemeIcon();
        }

        function updateThemeIcon() {

            const savedTheme = localStorage.getItem('pixelhop-theme') || 'dark';
            const isDark = savedTheme !== 'light';
            document.querySelectorAll('.theme-icon-light').forEach(el => el.style.display = isDark ? 'none' : 'block');
            document.querySelectorAll('.theme-icon-dark').forEach(el => el.style.display = isDark ? 'block' : 'none');
        }
        updateThemeIcon();

        async function api(action, data = {}) {
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', action);
            fd.append('csrf_token', csrf);
            for (const k in data) fd.append(k, data[k]);
            const res = await fetch('/admin/dashboard.php', { method: 'POST', body: fd });
            return res.json();
        }

        document.getElementById('btn-refresh').onclick = async () => {
            await refreshStats();
        };


        async function refreshStats() {
            try {
                const h = await api('get_health');


                const cpuPercent = Math.min(100, (h.cpu.load_1m / 4) * 100);
                document.getElementById('cpu-value').textContent = h.cpu.load_1m.toFixed(1);
                document.getElementById('cpu-ring').setAttribute('stroke-dashoffset', 264 - (cpuPercent / 100 * 264));
                document.getElementById('cpu-detail').textContent = '1min: ' + h.cpu.load_1m.toFixed(2) + ' | 5min: ' + h.cpu.load_5m.toFixed(2);


                document.getElementById('mem-value').textContent = h.memory.percent + '%';
                document.getElementById('mem-ring').setAttribute('stroke-dashoffset', 264 - (h.memory.percent / 100 * 264));
                document.getElementById('mem-detail').textContent = h.memory.used_human + ' / ' + h.memory.total_human;


                document.getElementById('disk-value').textContent = h.disk.percent + '%';
                document.getElementById('disk-ring').setAttribute('stroke-dashoffset', 264 - (h.disk.percent / 100 * 264));
                document.getElementById('disk-detail').textContent = h.disk.used_human + ' / ' + h.disk.total_human;


                document.getElementById('storage-value').textContent = h.storage.percent + '%';
                document.getElementById('storage-ring').setAttribute('stroke-dashoffset', 264 - (h.storage.percent / 100 * 264));
                document.getElementById('storage-detail').textContent = h.storage.global_used_human + ' / 250 GB';

                document.getElementById('action-result').textContent = 'Stats refreshed at ' + new Date().toLocaleTimeString();
            } catch (e) {
                console.error('Stats refresh error:', e);
            }
        }


        setInterval(refreshStats, 5000);

        document.getElementById('btn-purge').onclick = async () => {
            const r = await api('purge_temp');
            document.getElementById('action-result').textContent = 'Purged ' + r.result.deleted + ' files (' + r.result.freed_human + ')';
        };
    </script>
</body>
</html>
