<?php
/**
 * PixelHop - Storage Management Admin Page
 * Sub-page of Security (R2 + Contabo hybrid storage)
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../auth/middleware.php';

if (!isAuthenticated() || !isAdmin()) {
    header('Location: /login.php?error=access_denied');
    exit;
}

require_once __DIR__ . '/../includes/R2StorageManager.php';
require_once __DIR__ . '/../includes/R2RateLimiter.php';

$config = require __DIR__ . '/../config/s3.php';
$storage = new R2StorageManager($config);
$status = $storage->getStorageStatus();
$freeTierInfo = R2StorageManager::getFreeTierInfo();

$rateLimiter = new R2RateLimiter();
$rateStats = $rateLimiter->getUsageStats();
$rateLimits = R2RateLimiter::getFreeTierLimits();

$currentPage = 'security';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Storage - Admin - PixelHop</title>
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
                <!-- R2 Warning -->
                <?php if ($status['r2']['usage']['is_warning'] ?? false): ?>
                <div class="alert-box" style="background: rgba(245, 158, 11, 0.15); border: 1px solid var(--accent-yellow); border-radius: 12px; padding: 16px; margin-bottom: 24px;">
                    <strong style="color: var(--accent-yellow);">⚠️ R2 Warning:</strong> 
                    Using <?= $status['r2']['usage']['percentage'] ?>% of free tier. Thumbnails will fallback to Contabo soon.
                </div>
                <?php endif; ?>

                <!-- Storage Cards -->
                <div class="grid-2 mb-6">
                    <!-- R2 Card -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="cloud" class="w-5 h-5 text-cyan"></i>
                                Cloudflare R2
                                <span class="badge <?= ($status['r2']['enabled'] ?? false) ? 'badge-green' : 'badge-gray' ?>">
                                    <?= ($status['r2']['enabled'] ?? false) ? 'Enabled' : 'Off' ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($status['r2']['enabled'] ?? false): 
                            $usage = $status['r2']['usage'];
                            $pct = $usage['percentage'] ?? 0;
                            $barColor = $pct >= 90 ? 'var(--accent-red)' : ($pct >= 70 ? 'var(--accent-yellow)' : 'var(--accent-green)');
                        ?>
                        <div class="storage-bar">
                            <div class="bar-bg">
                                <div class="bar-fill" style="width: <?= min(100, $pct) ?>%; background: <?= $barColor ?>;"></div>
                            </div>
                            <div class="bar-label"><?= $pct ?>% used</div>
                        </div>
                        
                        <div class="storage-stats">
                            <div class="stat-row"><span>Used</span><span><?= $usage['used_human'] ?? '0 B' ?></span></div>
                            <div class="stat-row"><span>Available</span><span><?= $usage['available_human'] ?? '10 GB' ?></span></div>
                            <div class="stat-row"><span>Files</span><span><?= number_format($usage['file_count'] ?? 0) ?></span></div>
                            <div class="stat-row"><span>Egress</span><span style="color: var(--accent-green);">FREE ✨</span></div>
                        </div>
                        <?php else: ?>
                        <p class="text-muted">R2 not configured. All images stored in Contabo.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Contabo Card -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <i data-lucide="server" class="w-5 h-5 text-purple"></i>
                                Contabo S3
                                <span class="badge badge-green">Active</span>
                            </div>
                        </div>
                        
                        <div class="storage-stats">
                            <div class="stat-row"><span>Status</span><span style="color: var(--accent-green);">Operational</span></div>
                            <div class="stat-row"><span>Storage</span><span>Unlimited</span></div>
                            <div class="stat-row"><span>Region</span><span>Singapore (sin1)</span></div>
                            <div class="stat-row"><span>Used For</span><span>Original & Large</span></div>
                        </div>
                    </div>
                </div>

                <!-- Storage Strategy -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i data-lucide="route" class="w-5 h-5"></i>
                            Storage Strategy (Auto-Routing)
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Size</th>
                                    <th>Dimensions</th>
                                    <th>Provider</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Thumbnail</strong></td>
                                    <td>150 × 150</td>
                                    <td><span class="badge badge-cyan"><?= ucfirst($status['strategy']['thumb'] ?? 'r2') ?></span></td>
                                    <td>Most accessed, small</td>
                                </tr>
                                <tr>
                                    <td><strong>Medium</strong></td>
                                    <td>600 × 600</td>
                                    <td><span class="badge badge-cyan"><?= ucfirst($status['strategy']['medium'] ?? 'r2') ?></span></td>
                                    <td>Frequently accessed</td>
                                </tr>
                                <tr>
                                    <td><strong>Large</strong></td>
                                    <td>1200 × 1200</td>
                                    <td><span class="badge badge-purple"><?= ucfirst($status['strategy']['large'] ?? 'contabo') ?></span></td>
                                    <td>Less accessed</td>
                                </tr>
                                <tr>
                                    <td><strong>Original</strong></td>
                                    <td>Full size</td>
                                    <td><span class="badge badge-purple"><?= ucfirst($status['strategy']['original'] ?? 'contabo') ?></span></td>
                                    <td>Archive storage</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Rate Limits -->
                <div class="card mt-6">
                    <div class="card-header">
                        <div class="card-title">
                            <i data-lucide="gauge" class="w-5 h-5"></i>
                            R2 Free Tier Limits
                        </div>
                    </div>
                    <div class="grid-3">
                        <div class="mini-stat">
                            <div class="mini-label">Class A (Write)</div>
                            <div class="mini-value"><?= number_format($rateStats['class_a'] ?? 0) ?> / <?= number_format($rateLimits['class_a_monthly'] ?? 1000000) ?></div>
                        </div>
                        <div class="mini-stat">
                            <div class="mini-label">Class B (Read)</div>
                            <div class="mini-value"><?= number_format($rateStats['class_b'] ?? 0) ?> / <?= number_format($rateLimits['class_b_monthly'] ?? 10000000) ?></div>
                        </div>
                        <div class="mini-stat">
                            <div class="mini-label">Storage</div>
                            <div class="mini-value">10 GB / month</div>
                        </div>
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
        .storage-bar { margin: 16px 0; }
        .bar-bg { background: var(--border-color); border-radius: 8px; height: 20px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 8px; transition: width 0.3s; }
        .bar-label { text-align: center; font-size: 12px; color: var(--text-secondary); margin-top: 6px; }
        
        .storage-stats { display: flex; flex-direction: column; gap: 8px; }
        .stat-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-color); font-size: 13px; }
        .stat-row:last-child { border-bottom: none; }
        .stat-row span:first-child { color: var(--text-secondary); }
        
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 500; }
        .badge-green { background: rgba(34, 197, 94, 0.15); color: var(--accent-green); }
        .badge-gray { background: rgba(156, 163, 175, 0.15); color: #9ca3af; }
        .badge-cyan { background: rgba(34, 211, 238, 0.15); color: var(--accent-cyan); }
        .badge-purple { background: rgba(168, 85, 247, 0.15); color: var(--accent-purple); }
        
        .mini-stat { background: var(--bg-tertiary); border-radius: 10px; padding: 16px; text-align: center; }
        .mini-label { font-size: 11px; color: var(--text-secondary); margin-bottom: 6px; }
        .mini-value { font-size: 14px; font-weight: 600; }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => lucide.createIcons());
    </script>
</body>
</html>
