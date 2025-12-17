<?php
/**
 * PixelHop - Admin Abuse Management
 * Monitor and manage abuse prevention
 */

session_start();
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../core/AbuseGuard.php';

if (!isAuthenticated() || !isAdmin()) {
    header('Location: /login.php?error=access_denied');
    exit;
}

$db = Database::getInstance();
$abuseGuard = new AbuseGuard();

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Invalid token']);
        exit;
    }

    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'block_ip':
            $ip = $_POST['ip'] ?? '';
            $reason = $_POST['reason'] ?? 'Manually blocked by admin';
            $hours = (int)($_POST['hours'] ?? 24);

            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $result = $abuseGuard->blockIP($ip, $reason, $hours, 'admin');
                echo json_encode(['success' => $result]);
            } else {
                echo json_encode(['error' => 'Invalid IP address']);
            }
            break;

        case 'unblock_ip':
            $ip = $_POST['ip'] ?? '';
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $result = $abuseGuard->unblockIP($ip);
                echo json_encode(['success' => $result]);
            } else {
                echo json_encode(['error' => 'Invalid IP address']);
            }
            break;

        case 'run_watchdog':
            $report = $abuseGuard->runWatchdog();
            echo json_encode(['success' => true, 'report' => $report]);
            break;

        case 'update_setting':
            $key = $_POST['key'] ?? '';
            $value = $_POST['value'] ?? '';

            if (strpos($key, 'abuse_') === 0) {
                $stmt = $db->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?");
                $result = $stmt->execute([$value, $key]);
                echo json_encode(['success' => $result]);
            } else {
                echo json_encode(['error' => 'Invalid setting key']);
            }
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Get data for display
$stats = $abuseGuard->getStats();
$blockedIPs = $abuseGuard->getBlockedIPs(50);
$abuseLogs = $abuseGuard->getAbuseLogs(50);

// Get settings
$settingsStmt = $db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'abuse_%'");
$settings = [];
while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Abuse Management - PixelHop Admin</title>
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
            flex-wrap: wrap;
            gap: 16px;
        }

        .header-left { display: flex; align-items: center; gap: 14px; }

        .title-icon {
            width: 48px; height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            display: flex; align-items: center; justify-content: center;
        }

        .nav-links { display: flex; gap: 8px; flex-wrap: wrap; }
        .nav-link {
            padding: 10px 18px; border-radius: 10px;
            color: rgba(255, 255, 255, 0.6);
            text-decoration: none; font-size: 14px; font-weight: 500;
            display: flex; align-items: center; gap: 8px;
            transition: all 0.2s;
        }
        .nav-link:hover { background: rgba(255, 255, 255, 0.08); color: #fff; }
        .nav-link.active { background: rgba(239, 68, 68, 0.15); color: #ef4444; }

        @media (max-width: 700px) { .nav-links { display: none; } }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        @media (max-width: 800px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }

        .stat-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }

        .stat-value { font-size: 1.75rem; font-weight: 700; color: #fff; }
        .stat-label { font-size: 0.7rem; color: rgba(255, 255, 255, 0.5); margin-top: 4px; }

        .card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        .card-title {
            font-size: 0.9rem;
            font-weight: 600;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-primary { background: linear-gradient(135deg, #22d3ee, #0891b2); color: #fff; }
        .btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; }
        .btn-secondary { background: rgba(255, 255, 255, 0.1); color: #fff; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn:hover { transform: translateY(-1px); opacity: 0.9; }

        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.04);
            font-size: 12px;
        }
        .table th { color: rgba(255, 255, 255, 0.5); font-weight: 500; }
        .table td { color: #fff; }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 10px;
            font-weight: 600;
        }

        .badge-low { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
        .badge-medium { background: rgba(245, 158, 11, 0.2); color: #f59e0b; }
        .badge-high { background: rgba(249, 115, 22, 0.2); color: #f97316; }
        .badge-critical { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-auto { background: rgba(139, 92, 246, 0.2); color: #8b5cf6; }
        .badge-admin { background: rgba(34, 211, 238, 0.2); color: #22d3ee; }

        .input {
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #fff;
            font-size: 13px;
        }
        .input:focus { outline: none; border-color: #22d3ee; }

        .settings-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .setting-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 8px;
        }

        .setting-label { font-size: 12px; color: rgba(255, 255, 255, 0.7); white-space: nowrap; }
        .setting-input { width: 70px; text-align: center; padding: 8px; }

        .toggle {
            position: relative;
            width: 44px;
            height: 24px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .toggle.active { background: #22d3ee; }
        .toggle::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            transition: transform 0.2s;
        }
        .toggle.active::after { transform: translateX(20px); }

        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 900px) { .grid-2 { grid-template-columns: 1fr; } }

        .ip-mono { font-family: monospace; font-size: 11px; }

        .empty-state {
            text-align: center;
            padding: 30px;
            color: rgba(255, 255, 255, 0.4);
        }
        .empty-state i { opacity: 0.3; margin-bottom: 12px; }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header">
            <div class="header-left">
                <div class="title-icon">
                    <i data-lucide="shield-alert" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">Abuse Management</h1>
                    <p class="text-xs text-white/50">Monitor and prevent abuse</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/admin/dashboard.php" class="nav-link"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> Dashboard</a>
                <a href="/admin/users.php" class="nav-link"><i data-lucide="users" class="w-4 h-4"></i> Users</a>
                <a href="/admin/tools.php" class="nav-link"><i data-lucide="wrench" class="w-4 h-4"></i> Tools</a>
                <a href="/admin/gallery.php" class="nav-link"><i data-lucide="images" class="w-4 h-4"></i> Gallery</a>
                <a href="/admin/abuse.php" class="nav-link active"><i data-lucide="shield-alert" class="w-4 h-4"></i> Abuse</a>
                <a href="/admin/settings.php" class="nav-link"><i data-lucide="settings" class="w-4 h-4"></i> Settings</a>
            </div>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value" style="color: #ef4444;"><?= $stats['blocked_ips'] ?></div>
                <div class="stat-label">Blocked IPs</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #f59e0b;"><?= $stats['incidents_today'] ?></div>
                <div class="stat-label">Incidents Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #22d3ee;"><?= $stats['incidents_week'] ?></div>
                <div class="stat-label">Incidents (7 days)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #22c55e;"><?= $stats['by_severity']['critical'] ?? 0 ?></div>
                <div class="stat-label">Critical Alerts</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="zap" class="w-5 h-5 text-yellow-400"></i> Quick Actions</span>
            </div>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <button onclick="runWatchdog()" class="btn btn-primary">
                    <i data-lucide="scan" class="w-4 h-4"></i> Run Watchdog Now
                </button>
                <button onclick="showBlockModal()" class="btn btn-danger">
                    <i data-lucide="ban" class="w-4 h-4"></i> Block IP
                </button>
            </div>
        </div>

        <div class="grid-2">
            <!-- Blocked IPs -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i data-lucide="ban" class="w-5 h-5 text-red-400"></i> Blocked IPs</span>
                    <span class="text-xs text-white/50"><?= count($blockedIPs) ?> blocked</span>
                </div>
                <?php if (empty($blockedIPs)): ?>
                <div class="empty-state">
                    <i data-lucide="shield-check" class="w-12 h-12 mx-auto mb-4 opacity-50"></i>
                    <p>No IPs currently blocked</p>
                </div>
                <?php else: ?>
                <div style="max-height: 400px; overflow-y: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th>Reason</th>
                                <th>Expires</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($blockedIPs as $blocked): ?>
                            <tr>
                                <td class="ip-mono"><?= htmlspecialchars($blocked['ip_address']) ?></td>
                                <td>
                                    <span class="badge badge-<?= $blocked['blocked_by'] ?>">
                                        <?= $blocked['blocked_by'] ?>
                                    </span>
                                    <br><small style="color: rgba(255,255,255,0.5);"><?= htmlspecialchars(substr($blocked['reason'] ?? '', 0, 30)) ?></small>
                                </td>
                                <td style="font-size: 11px;"><?= $blocked['expires_at'] ? date('M j H:i', strtotime($blocked['expires_at'])) : 'Never' ?></td>
                                <td>
                                    <button onclick="unblockIP('<?= htmlspecialchars($blocked['ip_address']) ?>')" class="btn btn-secondary btn-sm">
                                        Unblock
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Top Offenders -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i data-lucide="alert-triangle" class="w-5 h-5 text-orange-400"></i> Top Offenders (7 days)</span>
                </div>
                <?php if (empty($stats['top_offenders'])): ?>
                <div class="empty-state">
                    <i data-lucide="check-circle" class="w-12 h-12 mx-auto mb-4 opacity-50"></i>
                    <p>No abuse incidents recorded</p>
                </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Incidents</th>
                            <th>Last Seen</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['top_offenders'] as $offender): ?>
                        <tr>
                            <td class="ip-mono"><?= htmlspecialchars($offender['ip_address']) ?></td>
                            <td><span class="badge badge-<?= $offender['count'] >= 10 ? 'critical' : ($offender['count'] >= 5 ? 'high' : 'medium') ?>"><?= $offender['count'] ?></span></td>
                            <td style="font-size: 11px;"><?= date('M j H:i', strtotime($offender['last_incident'])) ?></td>
                            <td>
                                <button onclick="blockIPQuick('<?= htmlspecialchars($offender['ip_address']) ?>')" class="btn btn-danger btn-sm">
                                    Block
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Abuse Logs -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="file-text" class="w-5 h-5 text-cyan-400"></i> Recent Abuse Logs</span>
            </div>
            <?php if (empty($abuseLogs)): ?>
            <div class="empty-state">
                <i data-lucide="file-check" class="w-12 h-12 mx-auto mb-4 opacity-50"></i>
                <p>No abuse logs recorded</p>
            </div>
            <?php else: ?>
            <div style="max-height: 400px; overflow-y: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>IP Address</th>
                            <th>Type</th>
                            <th>Severity</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($abuseLogs as $log): ?>
                        <tr>
                            <td style="font-size: 11px; white-space: nowrap;"><?= date('M j H:i', strtotime($log['created_at'])) ?></td>
                            <td class="ip-mono"><?= htmlspecialchars($log['ip_address']) ?></td>
                            <td><?= htmlspecialchars($log['abuse_type']) ?></td>
                            <td><span class="badge badge-<?= $log['severity'] ?>"><?= $log['severity'] ?></span></td>
                            <td style="font-size: 11px; max-width: 300px; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($log['details'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Settings -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i data-lucide="settings" class="w-5 h-5 text-purple-400"></i> Abuse Prevention Settings</span>
            </div>
            <div class="settings-grid">
                <div class="setting-item">
                    <span class="setting-label">Auto-block enabled</span>
                    <div class="toggle <?= ($settings['abuse_auto_block_enabled'] ?? 1) ? 'active' : '' ?>"
                         onclick="toggleSetting(this, 'abuse_auto_block_enabled')"></div>
                </div>
                <div class="setting-item">
                    <span class="setting-label">Guest uploads enabled</span>
                    <div class="toggle <?= ($settings['abuse_guest_upload_enabled'] ?? 1) ? 'active' : '' ?>"
                         onclick="toggleSetting(this, 'abuse_guest_upload_enabled')"></div>
                </div>
                <div class="setting-item">
                    <span class="setting-label">Uploads/hour threshold</span>
                    <input type="number" class="input setting-input" value="<?= $settings['abuse_threshold_uploads_per_hour'] ?? 50 ?>"
                           onchange="updateSetting('abuse_threshold_uploads_per_hour', this.value)">
                </div>
                <div class="setting-item">
                    <span class="setting-label">Uploads/day threshold</span>
                    <input type="number" class="input setting-input" value="<?= $settings['abuse_threshold_uploads_per_day'] ?? 200 ?>"
                           onchange="updateSetting('abuse_threshold_uploads_per_day', this.value)">
                </div>
                <div class="setting-item">
                    <span class="setting-label">Block duration (hours)</span>
                    <input type="number" class="input setting-input" value="<?= $settings['abuse_block_duration_hours'] ?? 24 ?>"
                           onchange="updateSetting('abuse_block_duration_hours', this.value)">
                </div>
                <div class="setting-item">
                    <span class="setting-label">Max guest file size (MB)</span>
                    <input type="number" class="input setting-input" value="<?= $settings['abuse_max_file_size_guest_mb'] ?? 5 ?>"
                           onchange="updateSetting('abuse_max_file_size_guest_mb', this.value)">
                </div>
            </div>
        </div>
    </div>

    <!-- Block IP Modal -->
    <div id="blockModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center;">
        <div style="background: rgba(30,30,50,0.95); border: 1px solid rgba(255,255,255,0.1); border-radius: 20px; padding: 30px; width: 400px; max-width: 90%;">
            <h3 style="font-size: 1.25rem; font-weight: 600; color: #fff; margin-bottom: 20px;">Block IP Address</h3>
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; color: rgba(255,255,255,0.6); margin-bottom: 6px;">IP Address</label>
                <input type="text" id="blockIpInput" class="input" style="width: 100%;" placeholder="e.g., 192.168.1.1">
            </div>
            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 13px; color: rgba(255,255,255,0.6); margin-bottom: 6px;">Reason</label>
                <input type="text" id="blockReasonInput" class="input" style="width: 100%;" placeholder="Reason for blocking">
            </div>
            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 13px; color: rgba(255,255,255,0.6); margin-bottom: 6px;">Duration (hours, 0 = permanent)</label>
                <input type="number" id="blockHoursInput" class="input" style="width: 100%;" value="24">
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button onclick="hideBlockModal()" class="btn btn-secondary">Cancel</button>
                <button onclick="confirmBlock()" class="btn btn-danger">Block IP</button>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const csrfToken = '<?= $csrfToken ?>';

        async function postAction(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('csrf_token', csrfToken);
            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }

            const res = await fetch('/admin/abuse.php', {
                method: 'POST',
                body: formData
            });
            return res.json();
        }

        async function runWatchdog() {
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> Running...';

            const result = await postAction('run_watchdog');

            if (result.success) {
                const report = result.report;
                alert(`Watchdog completed!\n\nScanned: ${report.scanned} IPs\nSuspicious: ${report.suspicious_ips?.length || 0}\nBlocked: ${report.blocked}\nWarnings: ${report.warnings}`);
                location.reload();
            } else {
                alert('Error: ' + (result.error || 'Unknown error'));
            }

            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="scan" class="w-4 h-4"></i> Run Watchdog Now';
            lucide.createIcons();
        }

        function showBlockModal() {
            document.getElementById('blockModal').style.display = 'flex';
        }

        function hideBlockModal() {
            document.getElementById('blockModal').style.display = 'none';
        }

        async function confirmBlock() {
            const ip = document.getElementById('blockIpInput').value.trim();
            const reason = document.getElementById('blockReasonInput').value.trim();
            const hours = parseInt(document.getElementById('blockHoursInput').value) || 24;

            if (!ip) {
                alert('Please enter an IP address');
                return;
            }

            const result = await postAction('block_ip', { ip, reason, hours });

            if (result.success) {
                hideBlockModal();
                location.reload();
            } else {
                alert('Error: ' + (result.error || 'Failed to block IP'));
            }
        }

        async function blockIPQuick(ip) {
            if (!confirm(`Block IP ${ip} for 24 hours?`)) return;

            const result = await postAction('block_ip', { ip, reason: 'Blocked from admin panel', hours: 24 });

            if (result.success) {
                location.reload();
            } else {
                alert('Error: ' + (result.error || 'Failed to block IP'));
            }
        }

        async function unblockIP(ip) {
            if (!confirm(`Unblock IP ${ip}?`)) return;

            const result = await postAction('unblock_ip', { ip });

            if (result.success) {
                location.reload();
            } else {
                alert('Error: ' + (result.error || 'Failed to unblock IP'));
            }
        }

        async function toggleSetting(el, key) {
            const isActive = el.classList.contains('active');
            const newValue = isActive ? 0 : 1;

            const result = await postAction('update_setting', { key, value: newValue });

            if (result.success) {
                el.classList.toggle('active');
            } else {
                alert('Failed to update setting');
            }
        }

        async function updateSetting(key, value) {
            const result = await postAction('update_setting', { key, value });

            if (!result.success) {
                alert('Failed to update setting');
            }
        }

        document.getElementById('blockModal').addEventListener('click', function(e) {
            if (e.target === this) hideBlockModal();
        });
    </script>
</body>
</html>
