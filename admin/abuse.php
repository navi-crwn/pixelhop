<?php
/**
 * PixelHop - Admin Security (Abuse Management)
 * Combined: Abuse Reports + Firewall + Storage
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../core/AbuseGuard.php';
require_once __DIR__ . '/../includes/JsonStore.php';
require_once __DIR__ . '/../includes/ImageRepository.php';

if (!isAuthenticated() || !isAdmin()) {
    header('Location: /login.php?error=access_denied');
    exit;
}

$db = Database::getInstance();
$abuseGuard = new AbuseGuard();
$imageRepo = new ImageRepository();

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

        case 'dismiss_report':
            $reportId = $_POST['report_id'] ?? '';
            $reportsFile = __DIR__ . '/../data/abuse_reports.json';
            $reports = file_exists($reportsFile) ? json_decode(file_get_contents($reportsFile), true) ?: [] : [];
            
            foreach ($reports as &$r) {
                if ($r['id'] === $reportId) {
                    $r['status'] = 'dismissed';
                    $r['reviewed_at'] = time();
                    $r['reviewed_by'] = $currentUser['email'] ?? 'admin';
                    break;
                }
            }
            unset($r);
            
            file_put_contents($reportsFile, json_encode($reports, JSON_PRETTY_PRINT), LOCK_EX);
            echo json_encode(['success' => true]);
            break;

        case 'delete_reported_image':
            $reportId = $_POST['report_id'] ?? '';
            $reportsFile = __DIR__ . '/../data/abuse_reports.json';
            $reports = file_exists($reportsFile) ? json_decode(file_get_contents($reportsFile), true) ?: [] : [];
            
            $imageId = null;
            foreach ($reports as &$r) {
                if ($r['id'] === $reportId) {
                    $imageId = $r['image_id'];
                    $r['status'] = 'resolved';
                    $r['action_taken'] = 'deleted';
                    $r['reviewed_at'] = time();
                    $r['reviewed_by'] = $currentUser['email'] ?? 'admin';
                    break;
                }
            }
            unset($r);
            
            if ($imageId) {
                // Delete the image
                require_once __DIR__ . '/../includes/R2StorageManager.php';
                $s3Config = require __DIR__ . '/../config/s3.php';
                $storageManager = new R2StorageManager($s3Config);
                
                $imgData = $imageRepo->find($imageId);
                if ($imgData !== null) {
                    if (!empty($imgData['s3_keys'])) {
                        $storageManager->deleteImage($imgData['s3_keys'], $imgData['size'] ?? 0);
                    }
                    $imageRepo->delete($imageId);
                }
                
                file_put_contents($reportsFile, json_encode($reports, JSON_PRETTY_PRINT), LOCK_EX);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Report not found']);
            }
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Get data for display
$stats = $abuseGuard->getStats();
$blockedIPs = $abuseGuard->getBlockedIPs(20);
$abuseLogs = $abuseGuard->getAbuseLogs(20);

// Load user reports
$reportsFile = __DIR__ . '/../data/abuse_reports.json';
$userReports = [];
if (file_exists($reportsFile)) {
    $userReports = json_decode(file_get_contents($reportsFile), true) ?: [];
    // Sort by created_at descending
    usort($userReports, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));
}
$pendingReports = count(array_filter($userReports, fn($r) => ($r['status'] ?? '') === 'pending'));

$csrfToken = generateCsrfToken();
$currentPage = 'security';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security - Admin - PixelHop</title>
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
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="shield-x" class="w-4 h-4 text-red"></i>
                            Blocked IPs
                        </div>
                        <div class="stat-value"><?= number_format($stats['blocked_ips'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="alert-triangle" class="w-4 h-4 text-yellow"></i>
                            Today Events
                        </div>
                        <div class="stat-value"><?= number_format($stats['today_events'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="activity" class="w-4 h-4 text-cyan"></i>
                            Rate Limited
                        </div>
                        <div class="stat-value"><?= number_format($stats['rate_limited_today'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="ban" class="w-4 h-4 text-purple"></i>
                            Auto Blocked
                        </div>
                        <div class="stat-value"><?= number_format($stats['auto_blocked'] ?? 0) ?></div>
                    </div>
                </div>

                <div class="grid-2">
                    <!-- Block IP Form -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="shield-plus" class="w-5 h-5"></i>
                                Block IP Address
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">IP Address</label>
                            <input type="text" class="form-input" id="block-ip" placeholder="192.168.1.1">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reason</label>
                            <input type="text" class="form-input" id="block-reason" placeholder="Spam, abuse, etc.">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Duration (hours)</label>
                            <select class="form-input" id="block-hours">
                                <option value="1">1 hour</option>
                                <option value="24" selected>24 hours</option>
                                <option value="168">7 days</option>
                                <option value="720">30 days</option>
                                <option value="8760">1 year</option>
                            </select>
                        </div>
                        <button id="btn-block" class="btn btn-danger" style="width: 100%;">
                            <i data-lucide="ban" class="w-4 h-4"></i>
                            Block IP
                        </button>
                        <div id="block-result" class="text-muted mt-4" style="text-align: center; font-size: 13px;"></div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="zap" class="w-5 h-5"></i>
                                Quick Actions
                            </div>
                        </div>
                        <div class="flex gap-2 mb-4" style="flex-wrap: wrap;">
                            <button id="btn-watchdog" class="btn btn-secondary" style="flex: 1; min-width: 150px;">
                                <i data-lucide="search" class="w-4 h-4"></i>
                                Run Watchdog
                            </button>
                            <a href="/admin/firewall" class="btn btn-secondary" style="flex: 1; min-width: 150px;">
                                <i data-lucide="flame" class="w-4 h-4"></i>
                                Firewall Logs
                            </a>
                        </div>
                        <div class="flex gap-2" style="flex-wrap: wrap;">
                            <a href="/admin/storage" class="btn btn-secondary" style="flex: 1; min-width: 150px;">
                                <i data-lucide="database" class="w-4 h-4"></i>
                                Storage Stats
                            </a>
                            <a href="/admin/tools" class="btn btn-secondary" style="flex: 1; min-width: 150px;">
                                <i data-lucide="wrench" class="w-4 h-4"></i>
                                Tools Stats
                            </a>
                        </div>
                        <div id="watchdog-result" class="text-muted mt-4" style="text-align: center; font-size: 13px;"></div>
                    </div>
                </div>

                <!-- Blocked IPs Table -->
                <div class="card mt-6">
                    <div class="card-header">
                        <div class="card-title">
                            <i data-lucide="list" class="w-5 h-5"></i>
                            Recently Blocked IPs
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Reason</th>
                                    <th>Expires</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="blocked-list">
                                <?php foreach ($blockedIPs as $ip): ?>
                                <tr data-ip="<?= htmlspecialchars($ip['ip_address']) ?>">
                                    <td><code><?= htmlspecialchars($ip['ip_address']) ?></code></td>
                                    <td><?= htmlspecialchars(substr($ip['reason'] ?? '-', 0, 40)) ?></td>
                                    <td><?= $ip['expires_at'] ? date('M j, H:i', strtotime($ip['expires_at'])) : 'Permanent' ?></td>
                                    <td>
                                        <button class="btn btn-secondary btn-unblock" style="padding: 6px 12px; font-size: 12px;">
                                            Unblock
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($blockedIPs)): ?>
                                <tr><td colspan="4" class="text-muted" style="text-align: center;">No blocked IPs</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- User Reports Table -->
                <div class="card mt-6">
                    <div class="card-header">
                        <div class="card-title">
                            <i data-lucide="flag" class="w-5 h-5"></i>
                            User Reports
                            <?php if ($pendingReports > 0): ?>
                            <span style="background: #ef4444; color: white; padding: 2px 8px; border-radius: 12px; font-size: 12px; margin-left: 8px;"><?= $pendingReports ?> pending</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Image</th>
                                    <th>Reason</th>
                                    <th>Details</th>
                                    <th>Reporter IP</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="reports-list">
                                <?php foreach (array_slice($userReports, 0, 20) as $report): ?>
                                <tr data-report-id="<?= htmlspecialchars($report['id']) ?>">
                                    <td>
                                        <a href="/<?= htmlspecialchars($report['image_id']) ?>" target="_blank" class="text-cyan">
                                            <?= htmlspecialchars($report['image_id']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $report['reason'] === 'illegal' || $report['reason'] === 'nsfw' ? 'red' : 'yellow' ?>">
                                            <?= ucfirst(htmlspecialchars($report['reason'])) ?>
                                        </span>
                                    </td>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= htmlspecialchars($report['details'] ?? '') ?>">
                                        <?= htmlspecialchars(substr($report['details'] ?? '-', 0, 50)) ?>
                                    </td>
                                    <td><code><?= htmlspecialchars(substr($report['ip'], 0, 15)) ?></code></td>
                                    <td>
                                        <?php 
                                        $statusColors = ['pending' => 'yellow', 'reviewed' => 'cyan', 'resolved' => 'green', 'dismissed' => 'gray'];
                                        $statusColor = $statusColors[$report['status']] ?? 'gray';
                                        ?>
                                        <span class="badge badge-<?= $statusColor ?>"><?= ucfirst($report['status']) ?></span>
                                    </td>
                                    <td><?= date('M j, H:i', $report['created_at']) ?></td>
                                    <td>
                                        <div class="flex gap-1">
                                            <button class="btn btn-secondary btn-report-action" data-action="dismiss" style="padding: 4px 8px; font-size: 11px;">
                                                Dismiss
                                            </button>
                                            <button class="btn btn-danger btn-report-action" data-action="delete" style="padding: 4px 8px; font-size: 11px;">
                                                Delete Image
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($userReports)): ?>
                                <tr><td colspan="7" class="text-muted" style="text-align: center;">No user reports</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
        code {
            background: var(--border-color);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
        }
        .badge {
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .badge-red { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .badge-yellow { background: rgba(251, 191, 36, 0.2); color: #fbbf24; }
        .badge-green { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
        .badge-cyan { background: rgba(34, 211, 238, 0.2); color: #22d3ee; }
        .badge-gray { background: rgba(156, 163, 175, 0.2); color: #9ca3af; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const csrf = '<?= $csrfToken ?>';
            
            async function api(action, data = {}) {
                const fd = new FormData();
                fd.append('action', action);
                fd.append('csrf_token', csrf);
                for (const k in data) fd.append(k, data[k]);
                const res = await fetch('/admin/abuse.php', { method: 'POST', body: fd });
                return res.json();
            }
            
            document.getElementById('btn-block').onclick = async () => {
                const ip = document.getElementById('block-ip').value.trim();
                const reason = document.getElementById('block-reason').value.trim() || 'Manually blocked';
                const hours = document.getElementById('block-hours').value;
                
                if (!ip) {
                    document.getElementById('block-result').innerHTML = '<span class="text-red">Please enter an IP address</span>';
                    return;
                }
                
                const r = await api('block_ip', { ip, reason, hours });
                if (r.success) {
                    document.getElementById('block-result').innerHTML = '<span class="text-green">✓ IP blocked successfully</span>';
                    document.getElementById('block-ip').value = '';
                    setTimeout(() => location.reload(), 1000);
                } else {
                    document.getElementById('block-result').innerHTML = '<span class="text-red">✗ ' + (r.error || 'Failed') + '</span>';
                }
            };
            
            document.getElementById('btn-watchdog').onclick = async () => {
                const r = await api('run_watchdog');
                if (r.success) {
                    const report = r.report;
                    document.getElementById('watchdog-result').innerHTML = 
                        '<span class="text-green">Watchdog completed: ' + report.blocked + ' blocked, ' + report.warnings + ' warnings</span>';
                }
            };
            
            document.querySelectorAll('.btn-unblock').forEach(btn => {
                btn.onclick = async function() {
                    const row = this.closest('tr');
                    const ip = row.dataset.ip;
                    const r = await api('unblock_ip', { ip });
                    if (r.success) {
                        row.remove();
                    }
                };
            });

            // Report action handlers
            document.querySelectorAll('.btn-report-action').forEach(btn => {
                btn.onclick = async function() {
                    const row = this.closest('tr');
                    const reportId = row.dataset.reportId;
                    const action = this.dataset.action;
                    
                    if (action === 'delete' && !confirm('Are you sure you want to delete this image? This cannot be undone.')) {
                        return;
                    }
                    
                    const apiAction = action === 'delete' ? 'delete_reported_image' : 'dismiss_report';
                    const r = await api(apiAction, { report_id: reportId });
                    
                    if (r.success) {
                        row.style.opacity = '0.3';
                        row.style.pointerEvents = 'none';
                        const statusCell = row.querySelector('td:nth-child(5) .badge');
                        if (statusCell) {
                            statusCell.textContent = action === 'delete' ? 'Resolved' : 'Dismissed';
                            statusCell.className = 'badge badge-' + (action === 'delete' ? 'green' : 'gray');
                        }
                        // Disable all buttons in this row
                        row.querySelectorAll('button').forEach(b => b.disabled = true);
                    } else {
                        alert(r.error || 'Action failed');
                    }
                };
            });
        });
    </script>
</body>
</html>
