<?php
/**
 * PixelHop - Admin Dashboard
 * Clean, responsive design with 5-menu navigation
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
$cpuPercent = min(100, ($health['cpu']['load_1m'] / 4) * 100);
$memPercent = $health['memory']['percent'];

// Temp folder stats (10GB allocation)
$tempStats = $health['temp'] ?? ['used_human' => '0 B', 'allocated_human' => '10 GB', 'percent' => 0, 'file_count' => 0];

// Storage provider stats
$r2Stats = $health['storage']['providers']['r2'] ?? ['used_human' => '0 B', 'limit_human' => '9.5 GB', 'percent' => 0, 'file_count' => 0];
$contaboStats = $health['storage']['providers']['contabo'] ?? ['used_human' => '0 B', 'limit_human' => '250 GB', 'percent' => 0, 'file_count' => 0];
$currentPage = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - PixelHop</title>
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
                <!-- Stats Grid -->
                <div class="grid-4 mb-6">
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
                        <div class="stat-unit" id="cpu-detail">1min: <?= number_format($health['cpu']['load_1m'], 2) ?> | 5min: <?= number_format($health['cpu']['load_5m'], 2) ?></div>
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
                        <div class="stat-unit" id="mem-detail"><?= $health['memory']['used_human'] ?> / <?= $health['memory']['total_human'] ?></div>
                    </div>

                    <!-- Storage Overview -->
                    <div class="stat-card storage-card" style="grid-column: span 2;">
                        <div class="stat-label">
                            <i data-lucide="database" class="w-4 h-4 text-green"></i>
                            Storage Overview
                        </div>
                        <div class="storage-bars">
                            <!-- R2 Storage -->
                            <div class="storage-item">
                                <div class="storage-header">
                                    <span class="storage-name">
                                        <i data-lucide="cloud" class="w-3 h-3"></i>
                                        R2 (Thumbnails)
                                    </span>
                                    <span class="storage-detail" id="r2-detail"><?= $r2Stats['used_human'] ?> / <?= $r2Stats['limit_human'] ?></span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill bg-cyan" id="r2-bar" style="width: <?= max(1, min(100, $r2Stats['percent'])) ?>%"></div>
                                </div>
                                <div class="storage-meta"><?= number_format($r2Stats['file_count']) ?> files • <?= $r2Stats['percent'] ?>%</div>
                            </div>
                            
                            <!-- Contabo Storage -->
                            <div class="storage-item">
                                <div class="storage-header">
                                    <span class="storage-name">
                                        <i data-lucide="hard-drive" class="w-3 h-3"></i>
                                        Contabo S3 (Originals)
                                    </span>
                                    <span class="storage-detail" id="contabo-detail"><?= $contaboStats['used_human'] ?> / <?= $contaboStats['limit_human'] ?></span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill bg-purple" id="contabo-bar" style="width: <?= max(1, min(100, $contaboStats['percent'])) ?>%"></div>
                                </div>
                                <div class="storage-meta"><?= number_format($contaboStats['file_count']) ?> files • <?= $contaboStats['percent'] ?>%</div>
                            </div>
                            
                            <!-- Temp Storage (10GB allocation) -->
                            <div class="storage-item">
                                <div class="storage-header">
                                    <span class="storage-name">
                                        <i data-lucide="folder-open" class="w-3 h-3"></i>
                                        Temp Storage (Local)
                                    </span>
                                    <span class="storage-detail" id="temp-detail"><?= $tempStats['used_human'] ?> / <?= $tempStats['allocated_human'] ?></span>
                                </div>
                                <div class="progress-bar">
                                    <div class="progress-fill bg-green" id="temp-bar" style="width: <?= max(1, min(100, $tempStats['percent'])) ?>%"></div>
                                </div>
                                <div class="storage-meta"><?= number_format($tempStats['file_count']) ?> files • <?= $tempStats['percent'] ?>%</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Cards -->
                <div class="grid-3">
                    <!-- Server Info -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="server" class="w-5 h-5"></i>
                                Server Info
                            </div>
                        </div>
                        <div class="info-list">
                            <div class="info-row">
                                <span>Status</span>
                                <span class="text-green">● Healthy</span>
                            </div>
                            <div class="info-row">
                                <span>Python Processes</span>
                                <span><?= $health['python']['running'] ?? 0 ?> / <?= $gatekeeper->getSetting('max_concurrent_processes') ?></span>
                            </div>
                            <div class="info-row">
                                <span>R2 Status</span>
                                <span class="<?= ($r2Stats['percent'] ?? 0) > 80 ? 'text-yellow' : 'text-green' ?>"><?= ($r2Stats['percent'] ?? 0) > 80 ? '● Warning' : '● OK' ?></span>
                            </div>
                            <div class="info-row">
                                <span>Maintenance</span>
                                <span class="<?= $health['status']['maintenance'] ? 'text-yellow' : 'text-green' ?>"><?= $health['status']['maintenance'] ? 'ON' : 'OFF' ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
                                Statistics
                            </div>
                        </div>
                        <div class="info-list">
                            <div class="info-row">
                                <span>Total Users</span>
                                <span><?= number_format($totalUsers) ?></span>
                            </div>
                            <div class="info-row">
                                <span>Total Images</span>
                                <span><?= number_format($totalImages) ?></span>
                            </div>
                            <div class="info-row">
                                <span>Kill Switch</span>
                                <span class="<?= $health['status']['kill_switch'] ? 'text-red' : 'text-green' ?>"><?= $health['status']['kill_switch'] ? 'ACTIVE' : 'OFF' ?></span>
                            </div>
                            <div class="info-row">
                                <span>PHP Version</span>
                                <span><?= PHP_VERSION ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="zap" class="w-5 h-5"></i>
                                Quick Actions
                            </div>
                        </div>
                        <div class="flex gap-2 mb-4">
                            <button id="btn-refresh" class="btn btn-primary" style="flex: 1;">
                                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                                Refresh
                            </button>
                            <button id="btn-purge" class="btn btn-secondary" style="flex: 1;">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                Purge Temp
                            </button>
                        </div>
                        <div id="action-result" class="text-muted" style="font-size: 12px; text-align: center;"></div>
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
        .info-list { display: flex; flex-direction: column; gap: 12px; }
        .info-row { display: flex; justify-content: space-between; align-items: center; font-size: 14px; }
        .info-row span:first-child { color: var(--text-muted); }
        .info-row span:last-child { color: var(--text-primary); font-weight: 500; }
        
        /* Storage Card with Progress Bars */
        .storage-card { 
            grid-column: span 1;
            display: flex;
            flex-direction: column;
        }
        .storage-bars { 
            display: flex; 
            flex-direction: column; 
            gap: 20px; 
            margin-top: 16px;
            flex: 1;
            justify-content: center;
        }
        .storage-item { 
            display: flex; 
            flex-direction: column; 
            gap: 8px; 
        }
        .storage-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center;
            flex-wrap: wrap;
            gap: 4px;
        }
        .storage-name { 
            display: flex; 
            align-items: center; 
            gap: 8px; 
            font-size: 13px; 
            font-weight: 500; 
            color: var(--text-primary); 
        }
        .storage-name i { 
            opacity: 0.8;
            width: 14px;
            height: 14px;
        }
        .storage-detail { 
            font-size: 11px; 
            color: var(--text-muted); 
            font-family: 'SF Mono', Monaco, monospace;
            white-space: nowrap;
        }
        .storage-meta { 
            font-size: 11px; 
            color: var(--text-muted); 
            opacity: 0.8;
        }
        
        .progress-bar { 
            height: 10px; 
            background: rgba(255,255,255,0.1); 
            border-radius: 5px; 
            overflow: hidden;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.2);
        }
        .progress-fill { 
            height: 100%; 
            border-radius: 5px; 
            transition: width 0.3s ease;
            min-width: 4px;
        }
        .bg-cyan { background: linear-gradient(90deg, #06b6d4, #22d3ee); box-shadow: 0 0 8px rgba(6, 182, 212, 0.4); }
        .bg-purple { background: linear-gradient(90deg, #8b5cf6, #a855f7); box-shadow: 0 0 8px rgba(139, 92, 246, 0.4); }
        .bg-green { background: linear-gradient(90deg, #10b981, #34d399); box-shadow: 0 0 8px rgba(16, 185, 129, 0.4); }
        
        /* Responsive */
        @media (max-width: 900px) {
            .storage-card[style*="span 2"] { grid-column: span 1 !important; }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const csrf = '<?= $csrfToken ?>';
            
            async function api(action, data = {}) {
                const fd = new FormData();
                fd.append('ajax', '1');
                fd.append('action', action);
                fd.append('csrf_token', csrf);
                for (const k in data) fd.append(k, data[k]);
                const res = await fetch('/admin/dashboard.php', { method: 'POST', body: fd });
                return res.json();
            }

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
                    
                    // Storage providers
                    if (h.storage && h.storage.providers) {
                        const r2 = h.storage.providers.r2 || {};
                        const contabo = h.storage.providers.contabo || {};
                        
                        document.getElementById('r2-detail').textContent = (r2.used_human || '0 B') + ' / ' + (r2.limit_human || '9.5 GB');
                        document.getElementById('r2-bar').style.width = Math.max(1, Math.min(100, r2.percent || 0)) + '%';
                        
                        document.getElementById('contabo-detail').textContent = (contabo.used_human || '0 B') + ' / ' + (contabo.limit_human || '250 GB');
                        document.getElementById('contabo-bar').style.width = Math.max(1, Math.min(100, contabo.percent || 0)) + '%';
                    }
                    
                    // Temp storage
                    if (h.temp) {
                        document.getElementById('temp-detail').textContent = (h.temp.used_human || '0 B') + ' / ' + (h.temp.allocated_human || '10 GB');
                        document.getElementById('temp-bar').style.width = Math.max(1, Math.min(100, h.temp.percent || 0)) + '%';
                    }
                    
                    document.getElementById('action-result').textContent = 'Refreshed at ' + new Date().toLocaleTimeString();
                } catch (e) {
                    console.error('Stats refresh error:', e);
                }
            }
            
            document.getElementById('btn-refresh').onclick = refreshStats;
            
            document.getElementById('btn-purge').onclick = async () => {
                const r = await api('purge_temp');
                document.getElementById('action-result').textContent = 'Purged ' + r.result.deleted + ' files (' + r.result.freed_human + ')';
                refreshStats(); // Refresh after purge
            };
            
            setInterval(refreshStats, 5000);
        });
    </script>
</body>
</html>
