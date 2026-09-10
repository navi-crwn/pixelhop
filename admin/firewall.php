<?php
/**
 * PixelHop - Security Firewall Admin Page
 * Sub-page of Security
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/SecurityFirewall.php';

if (!isAuthenticated() || !isAdmin()) {
    header('Location: /login.php?error=access_denied');
    exit;
}

$firewall = new SecurityFirewall();
$stats = $firewall->getStats();
$blockedIPs = $firewall->getBlockedIPs();
$recentEvents = $firewall->getRecentEvents(30);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'unblock_ip' && !empty($_POST['ip'])) {
        $ip = $_POST['ip'];
        if ($firewall->unblockIP($ip)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'Failed to unblock']);
        }
        exit;
    }
    
    if ($action === 'cleanup') {
        $results = $firewall->cleanup();
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }
    
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

$csrfToken = generateCsrfToken();
$currentPage = 'security';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firewall - Admin - PixelHop</title>
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
                <!-- Stats -->
                <div class="grid-4 mb-6">
                    <div class="stat-card">
                        <div class="stat-label"><i data-lucide="activity" class="w-4 h-4 text-cyan"></i> Total Requests (24h)</div>
                        <div class="stat-value"><?= number_format($stats['total_requests'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><i data-lucide="shield-x" class="w-4 h-4 text-red"></i> Blocked</div>
                        <div class="stat-value"><?= number_format($stats['blocked_requests'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><i data-lucide="alert-triangle" class="w-4 h-4 text-yellow"></i> Suspicious</div>
                        <div class="stat-value"><?= number_format($stats['suspicious_events'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label"><i data-lucide="ban" class="w-4 h-4 text-purple"></i> Active Blocks</div>
                        <div class="stat-value"><?= count($blockedIPs) ?></div>
                    </div>
                </div>

                <div class="grid-2">
                    <!-- Blocked IPs -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="shield-x" class="w-5 h-5"></i>
                                Blocked IPs
                            </div>
                        </div>
                        <div class="table-wrapper" style="max-height: 300px; overflow-y: auto;">
                            <table class="table">
                                <thead>
                                    <tr><th>IP</th><th>Reason</th><th>Action</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($blockedIPs, 0, 15) as $ip): ?>
                                    <tr data-ip="<?= htmlspecialchars($ip['ip_address']) ?>">
                                        <td><code style="font-size: 12px;"><?= htmlspecialchars($ip['ip_address']) ?></code></td>
                                        <td style="font-size: 12px;"><?= htmlspecialchars(substr($ip['reason'] ?? '-', 0, 30)) ?></td>
                                        <td>
                                            <button class="btn-unblock" style="font-size: 11px; padding: 4px 8px; background: var(--border-color); border: none; border-radius: 4px; cursor: pointer; color: var(--text-secondary);">
                                                Unblock
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($blockedIPs)): ?>
                                    <tr><td colspan="3" class="text-muted" style="text-align: center;">No blocked IPs</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="zap" class="w-5 h-5"></i>
                                Actions
                            </div>
                        </div>
                        <button id="btn-cleanup" class="btn btn-secondary mb-4" style="width: 100%;">
                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                            Cleanup Old Logs
                        </button>
                        <div id="cleanup-result" class="text-muted" style="font-size: 12px; text-align: center;"></div>
                    </div>
                </div>

                <!-- Recent Events -->
                <div class="card mt-6">
                    <div class="card-header">
                        <div class="card-title">
                            <i data-lucide="list" class="w-5 h-5"></i>
                            Recent Security Events
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>IP</th>
                                    <th>Type</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentEvents as $event): ?>
                                <tr>
                                    <td style="font-size: 12px;"><?= date('M j H:i', strtotime($event['created_at'])) ?></td>
                                    <td><code style="font-size: 11px;"><?= htmlspecialchars($event['ip_address']) ?></code></td>
                                    <td>
                                        <span class="badge-sm <?= $event['event_type'] === 'blocked' ? 'badge-red' : 'badge-yellow' ?>">
                                            <?= htmlspecialchars($event['event_type']) ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 12px;"><?= htmlspecialchars(substr($event['details'] ?? '-', 0, 50)) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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
        code { background: var(--border-color); padding: 2px 6px; border-radius: 4px; }
        .badge-sm { padding: 2px 6px; border-radius: 4px; font-size: 11px; }
        .badge-red { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); }
        .badge-yellow { background: rgba(234, 179, 8, 0.15); color: var(--accent-yellow); }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const csrf = '<?= $csrfToken ?>';
            
            document.querySelectorAll('.btn-unblock').forEach(btn => {
                btn.onclick = async function() {
                    const row = this.closest('tr');
                    const ip = row.dataset.ip;
                    
                    const fd = new FormData();
                    fd.append('action', 'unblock_ip');
                    fd.append('csrf_token', csrf);
                    fd.append('ip', ip);
                    
                    const res = await fetch('/admin/firewall.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) row.remove();
                };
            });
            
            document.getElementById('btn-cleanup').onclick = async () => {
                const fd = new FormData();
                fd.append('action', 'cleanup');
                fd.append('csrf_token', csrf);
                
                const res = await fetch('/admin/firewall.php', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    const r = data.results;
                    document.getElementById('cleanup-result').innerHTML = 
                        '<span class="text-green">Cleaned: ' + r.ip_requests + ' requests, ' + r.security_events + ' events</span>';
                }
            };
        });
    </script>
</body>
</html>
