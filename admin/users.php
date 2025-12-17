<?php
/**
 * PixelHop - Admin Users Management
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
        case 'toggle_user':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $block = (int) ($_POST['block'] ?? 0);
            if ($userId > 0 && $userId !== $currentUser['id']) {
                $stmt = $db->prepare("UPDATE users SET is_blocked = ? WHERE id = ?");
                $stmt->execute([$block, $userId]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Cannot modify this user']);
            }
            break;

        case 'change_role':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? 'user';
            if ($userId > 0 && $userId !== $currentUser['id'] && in_array($role, ['user', 'admin'])) {
                $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$role, $userId]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Cannot modify this user']);
            }
            break;

        case 'change_type':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $type = $_POST['type'] ?? 'free';
            if ($userId > 0 && in_array($type, ['free', 'premium'])) {
                $stmt = $db->prepare("UPDATE users SET account_type = ? WHERE id = ?");
                $stmt->execute([$type, $userId]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Invalid account type']);
            }
            break;

        case 'delete_user':
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId > 0 && $userId !== $currentUser['id']) {
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Cannot delete this user']);
            }
            break;

        default:
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// Get users with pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$totalUsers = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalPages = ceil($totalUsers / $perPage);

$users = $db->query("SELECT * FROM users ORDER BY created_at DESC LIMIT $perPage OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - Admin - PixelHop</title>
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

        .content-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 16px;
            padding: 20px;
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
        }

        th {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255, 255, 255, 0.5);
            font-weight: 600;
        }

        td {
            font-size: 13px;
            color: #fff;
        }

        tr:hover {
            background: rgba(255, 255, 255, 0.02);
        }

        .user-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: 600;
            font-size: 14px;
        }

        .user-info {
            display: flex;
            flex-direction: column;
        }

        .user-email {
            font-weight: 500;
        }

        .user-date {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }

        .badge-green { background: rgba(34, 197, 94, 0.2); color: #22c55e; }
        .badge-red { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
        .badge-blue { background: rgba(59, 130, 246, 0.2); color: #3b82f6; }
        .badge-purple { background: rgba(168, 85, 247, 0.2); color: #a855f7; }
        .badge-yellow { background: rgba(234, 179, 8, 0.2); color: #eab308; }

        .action-btn {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            margin-right: 6px;
        }

        .action-btn-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
        }

        .action-btn-success {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .action-btn:hover {
            transform: translateY(-1px);
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }

        .page-link {
            padding: 8px 14px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.05);
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            font-size: 13px;
            transition: all 0.2s;
        }

        .page-link:hover, .page-link.active {
            background: rgba(34, 211, 238, 0.2);
            color: #22d3ee;
        }

        .stats-row {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-box {
            padding: 16px 24px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 12px;
            text-align: center;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #fff;
        }

        .stat-label {
            font-size: 12px;
            color: rgba(255, 255, 255, 0.5);
        }

        @media (max-width: 900px) {
            .nav-links { display: none; }
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
                    <i data-lucide="users" class="w-6 h-6 text-white"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">User Management</h1>
                    <p class="text-xs text-white/50"><?= number_format($totalUsers) ?> registered users</p>
                </div>
            </div>
            <div class="nav-links">
                <a href="/admin/dashboard.php" class="nav-link">
                    <i data-lucide="layout-dashboard" class="w-4 h-4"></i>
                    Dashboard
                </a>
                <a href="/admin/users.php" class="nav-link active">
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
            </div>
        </div>

        <!-- Users Table -->
        <div class="content-card">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Type</th>
                            <th>Storage</th>
                            <th>OCR Today</th>
                            <th>RemBG Today</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr data-user-id="<?= $user['id'] ?>">
                            <td>
                                <div class="user-cell">
                                    <div class="user-avatar">
                                        <?= strtoupper(substr($user['email'], 0, 1)) ?>
                                    </div>
                                    <div class="user-info">
                                        <span class="user-email"><?= htmlspecialchars($user['email']) ?></span>
                                        <span class="user-date"><?= date('M j, Y', strtotime($user['created_at'])) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= $user['role'] === 'admin' ? 'badge-purple' : 'badge-blue' ?>">
                                    <?= ucfirst($user['role']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= ($user['account_type'] ?? 'free') === 'premium' ? 'badge-yellow' : 'badge-blue' ?>">
                                    <?= ucfirst($user['account_type'] ?? 'free') ?>
                                </span>
                            </td>
                            <td><?= round(($user['storage_used'] ?? 0) / 1048576, 1) ?> MB</td>
                            <td><?= $user['daily_ocr_count'] ?? 0 ?></td>
                            <td><?= $user['daily_removebg_count'] ?? 0 ?></td>
                            <td>
                                <?php if ($user['is_blocked']): ?>
                                    <span class="badge badge-red">Blocked</span>
                                <?php else: ?>
                                    <span class="badge badge-green">Active</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['id'] != $currentUser['id']): ?>
                                    <?php if ($user['is_blocked']): ?>
                                        <button class="action-btn action-btn-success btn-toggle" data-action="unblock">Unblock</button>
                                    <?php else: ?>
                                        <button class="action-btn action-btn-danger btn-toggle" data-action="block">Block</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-xs text-white/30">You</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
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

        async function api(action, data = {}) {
            const fd = new FormData();
            fd.append('ajax', '1');
            fd.append('action', action);
            fd.append('csrf_token', csrf);
            for (const k in data) fd.append(k, data[k]);
            const res = await fetch('/admin/users.php', { method: 'POST', body: fd });
            return res.json();
        }

        document.querySelectorAll('.btn-toggle').forEach(btn => {
            btn.onclick = async () => {
                const row = btn.closest('tr');
                const userId = row.dataset.userId;
                const block = btn.dataset.action === 'block' ? 1 : 0;

                const result = await api('toggle_user', { user_id: userId, block });
                if (result.success) {
                    location.reload();
                } else {
                    alert(result.error || 'Failed to update user');
                }
            };
        });
    </script>
</body>
</html>
