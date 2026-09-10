<?php
/**
 * PixelHop - Admin Users Management
 * Clean responsive design
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
        case 'toggle_user':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $block = (int) ($_POST['block'] ?? 0);
            if ($userId > 0 && $userId !== $currentUser['id']) {
                $stmt = $db->prepare("UPDATE users SET is_blocked = ? WHERE id = ?");
                if ($stmt->execute([$block, $userId])) {
                    incrementSessionVersion($userId);
                }
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
                if ($stmt->execute([$role, $userId])) {
                    incrementSessionVersion($userId);
                }
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
                if ($stmt->execute([$type, $userId])) {
                    incrementSessionVersion($userId);
                }
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Invalid account type']);
            }
            break;

        case 'delete_user':
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId > 0 && $userId !== $currentUser['id']) {
                // Naikkan session_version sebelum delete agar sesi target mati
                // secepatnya; bila user sempat request lagi sebelum delete,
                // middleware akan melihat versi yang tidak cocok.
                incrementSessionVersion($userId);
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Cannot delete this user']);
            }
            break;

        case 'set_status':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            $reason = trim($_POST['reason'] ?? '');
            
            if ($userId > 0 && $userId !== $currentUser['id'] && in_array($status, ['active', 'locked', 'suspended'])) {
                $suspendUntil = null;
                if ($status === 'suspended') {
                    // Suspend for 30 days before deletion
                    $suspendUntil = date('Y-m-d H:i:s', strtotime('+30 days'));
                }
                
                $stmt = $db->prepare("UPDATE users SET 
                    account_status = ?, 
                    status_reason = ?, 
                    status_updated_at = NOW(), 
                    status_updated_by = ?,
                    suspend_until = ?
                    WHERE id = ?");
                if ($stmt->execute([$status, $reason, $currentUser['id'], $suspendUntil, $userId])) {
                    incrementSessionVersion($userId);
                }
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Cannot modify this user']);
            }
            break;

        case 'set_warning':
            $userId = (int) ($_POST['user_id'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            
            if ($userId > 0 && $userId !== $currentUser['id']) {
                $stmt = $db->prepare("UPDATE users SET 
                    warning_message = ?, 
                    warning_shown = 0,
                    status_updated_at = NOW(), 
                    status_updated_by = ?
                    WHERE id = ?");
                $stmt->execute([$message ?: null, $currentUser['id'], $userId]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Cannot modify this user']);
            }
            break;

        case 'clear_warning':
            $userId = (int) ($_POST['user_id'] ?? 0);
            if ($userId > 0) {
                $stmt = $db->prepare("UPDATE users SET warning_message = NULL, warning_shown = 1 WHERE id = ?");
                $stmt->execute([$userId]);
                echo json_encode(['success' => true]);
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

// Stats
$premiumCount = $db->query("SELECT COUNT(*) FROM users WHERE account_type = 'premium'")->fetchColumn();
$adminCount = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$blockedCount = $db->query("SELECT COUNT(*) FROM users WHERE is_blocked = 1")->fetchColumn();
$lockedCount = $db->query("SELECT COUNT(*) FROM users WHERE account_status = 'locked'")->fetchColumn();
$suspendedCount = $db->query("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'")->fetchColumn();
$warningCount = $db->query("SELECT COUNT(*) FROM users WHERE warning_message IS NOT NULL AND warning_shown = 0")->fetchColumn();

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

$csrfToken = generateCsrfToken();
$currentPage = 'users';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users - Admin - PixelHop</title>
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
                            <i data-lucide="users" class="w-4 h-4 text-cyan"></i>
                            Total Users
                        </div>
                        <div class="stat-value"><?= number_format($totalUsers) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="lock" class="w-4 h-4 text-yellow"></i>
                            Locked
                        </div>
                        <div class="stat-value"><?= number_format($lockedCount) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="ban" class="w-4 h-4 text-red"></i>
                            Suspended
                        </div>
                        <div class="stat-value"><?= number_format($suspendedCount) ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">
                            <i data-lucide="alert-triangle" class="w-4 h-4 text-orange"></i>
                            Warnings
                        </div>
                        <div class="stat-value"><?= number_format($warningCount) ?></div>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i data-lucide="list" class="w-5 h-5"></i>
                            User List (Page <?= $page ?> of <?= max(1, $totalPages) ?>)
                        </div>
                    </div>
                    <div class="table-wrapper">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Storage</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): 
                                    $accountStatus = $user['account_status'] ?? 'active';
                                    $hasWarning = !empty($user['warning_message']) && !$user['warning_shown'];
                                ?>
                                <tr data-id="<?= $user['id'] ?>" 
                                    data-status="<?= $accountStatus ?>"
                                    data-warning="<?= $hasWarning ? '1' : '0' ?>"
                                    class="<?= $accountStatus !== 'active' ? 'status-row-' . $accountStatus : '' ?>">
                                    <td>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div class="user-avatar">
                                                <?= strtoupper(substr($user['email'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div style="font-weight: 500;">
                                                    <?= htmlspecialchars($user['name'] ?? 'No name') ?>
                                                    <?php if ($hasWarning): ?>
                                                    <i data-lucide="alert-triangle" class="w-3 h-3 text-orange" style="display: inline;"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-muted" style="font-size: 12px;"><?= htmlspecialchars($user['email']) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?= $user['account_type'] === 'premium' ? 'badge-yellow' : 'badge-gray' ?>">
                                            <?= ucfirst($user['account_type'] ?? 'free') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php 
                                        $statusBadge = match($accountStatus) {
                                            'locked' => 'badge-yellow',
                                            'suspended' => 'badge-red',
                                            default => 'badge-green'
                                        };
                                        ?>
                                        <span class="badge <?= $statusBadge ?>">
                                            <?= ucfirst($accountStatus) ?>
                                        </span>
                                        <?php if ($accountStatus === 'suspended' && !empty($user['suspend_until'])): ?>
                                        <div class="text-muted" style="font-size: 10px;">Until <?= date('M j', strtotime($user['suspend_until'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatBytes($user['storage_used'] ?? 0) ?></td>
                                    <td><?= date('M j, Y', strtotime($user['created_at'])) ?></td>
                                    <td>
                                        <?php if ($user['id'] !== $currentUser['id']): ?>
                                        <div class="action-btns">
                                            <button class="btn-icon btn-status" title="Change Status" onclick="openStatusModal(<?= $user['id'] ?>, '<?= $accountStatus ?>')">
                                                <i data-lucide="shield" class="w-4 h-4"></i>
                                            </button>
                                            <button class="btn-icon btn-warning" title="Send Warning" onclick="openWarningModal(<?= $user['id'] ?>)">
                                                <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                                            </button>
                                            <button class="btn-icon btn-delete" title="Delete">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-muted">You</span>
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
                        <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>" class="btn btn-secondary">← Previous</a>
                        <?php endif; ?>
                        <span class="text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>" class="btn btn-secondary">Next →</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
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
        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent-cyan), var(--accent-purple));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
            color: white;
        }
        .badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-gray { background: var(--border-color); color: var(--text-secondary); }
        .badge-yellow { background: rgba(234, 179, 8, 0.15); color: var(--accent-yellow); }
        .badge-purple { background: rgba(168, 85, 247, 0.15); color: var(--accent-purple); }
        .blocked-row { opacity: 0.5; }
        .status-row-locked { background: rgba(234, 179, 8, 0.05); }
        .status-row-suspended { background: rgba(239, 68, 68, 0.05); }
        .action-btns { display: flex; gap: 6px; }
        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: transparent;
            color: var(--text-secondary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .btn-icon:hover { background: var(--border-color-hover); color: var(--text-primary); }
        .btn-delete:hover { background: rgba(239, 68, 68, 0.15); color: var(--accent-red); }
        .btn-warning:hover { background: rgba(251, 146, 60, 0.15); color: #fb923c; }
        .btn-status:hover { background: rgba(168, 85, 247, 0.15); color: var(--accent-purple); }
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 16px;
            border-top: 1px solid var(--border-color);
        }
        .badge-green { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .badge-red { background: rgba(239, 68, 68, 0.15); color: #ef4444; }
        .badge-orange { background: rgba(251, 146, 60, 0.15); color: #fb923c; }
        .text-orange { color: #fb923c; }
        
        /* Modal styles - with explicit colors for light mode support */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.show { display: flex; }
        .modal-content {
            background: #1a1a2e;
            border: 1px solid #2a2a4a;
            border-radius: 16px;
            padding: 24px;
            max-width: 400px;
            width: 90%;
            color: #e2e8f0;
        }
        [data-theme="light"] .modal-content {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            color: #1a1a2e;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .modal-title { font-size: 18px; font-weight: 600; }
        .modal-close {
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px;
        }
        .modal-close:hover { color: #e2e8f0; }
        [data-theme="light"] .modal-close:hover { color: #1a1a2e; }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; margin-bottom: 8px; font-size: 14px; color: #94a3b8; }
        [data-theme="light"] .form-label { color: #64748b; }
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid #2a2a4a;
            background: #0f0f1a;
            color: #e2e8f0;
            font-size: 14px;
        }
        [data-theme="light"] .form-input, 
        [data-theme="light"] .form-select, 
        [data-theme="light"] .form-textarea {
            border: 1px solid #d1d5db;
            background: #f8fafc;
            color: #1a1a2e;
        }
        .form-textarea { resize: vertical; min-height: 80px; }
        .modal-actions { display: flex; gap: 12px; margin-top: 20px; }
        .modal-actions .btn { flex: 1; }
    </style>

    <script>
        let currentUserId = null;
        
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const csrf = '<?= $csrfToken ?>';
            
            async function api(action, data = {}) {
                const fd = new FormData();
                fd.append('ajax', '1');
                fd.append('action', action);
                fd.append('csrf_token', csrf);
                for (const k in data) fd.append(k, data[k]);
                const res = await fetch('/admin/users.php', { method: 'POST', body: fd });
                return res.json();
            }
            
            window.api = api;
            
            document.querySelectorAll('.btn-delete').forEach(btn => {
                btn.onclick = async function() {
                    if (!confirm('Are you sure you want to delete this user?')) return;
                    const row = this.closest('tr');
                    const userId = row.dataset.id;
                    const r = await api('delete_user', { user_id: userId });
                    if (r.success) row.remove();
                };
            });
        });
        
        function openStatusModal(userId, currentStatus) {
            currentUserId = userId;
            document.getElementById('statusSelect').value = currentStatus;
            document.getElementById('statusReason').value = '';
            document.getElementById('statusModal').classList.add('show');
        }
        
        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('show');
        }
        
        async function saveStatus() {
            const status = document.getElementById('statusSelect').value;
            const reason = document.getElementById('statusReason').value;
            
            const r = await window.api('set_status', { user_id: currentUserId, status, reason });
            if (r.success) {
                location.reload();
            } else {
                alert(r.error || 'Failed to update status');
            }
        }
        
        function openWarningModal(userId) {
            currentUserId = userId;
            document.getElementById('warningMessage').value = '';
            document.getElementById('warningModal').classList.add('show');
        }
        
        function closeWarningModal() {
            document.getElementById('warningModal').classList.remove('show');
        }
        
        async function saveWarning() {
            const message = document.getElementById('warningMessage').value.trim();
            
            if (!message) {
                alert('Please enter a warning message');
                return;
            }
            
            const r = await window.api('set_warning', { user_id: currentUserId, message });
            if (r.success) {
                location.reload();
            } else {
                alert(r.error || 'Failed to send warning');
            }
        }
    </script>

    <!-- Status Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title">Change Account Status</span>
                <button class="modal-close" onclick="closeStatusModal()">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="form-group">
                <label class="form-label">Status</label>
                <select id="statusSelect" class="form-select">
                    <option value="active">Active - Normal access</option>
                    <option value="locked">Locked - Can view images, cannot login</option>
                    <option value="suspended">Suspended - No access, 30 day deletion</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Reason (shown to user)</label>
                <textarea id="statusReason" class="form-textarea" placeholder="Reason for this action..."></textarea>
            </div>
            <div style="font-size: 12px; color: #94a3b8; margin-bottom: 16px;">
                <strong>Locked:</strong> User cannot login or reset password. Must email support@hel.ink<br>
                <strong>Suspended:</strong> Account and images inaccessible. Auto-deleted in 30 days.
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeStatusModal()">Cancel</button>
                <button class="btn btn-primary" onclick="saveStatus()">Save</button>
            </div>
        </div>
    </div>

    <!-- Warning Modal -->
    <div id="warningModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title">Send Warning to User</span>
                <button class="modal-close" onclick="closeWarningModal()">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="form-group">
                <label class="form-label">Warning Message</label>
                <textarea id="warningMessage" class="form-textarea" rows="4" placeholder="This message will be shown to the user when they login..."></textarea>
            </div>
            <div style="font-size: 12px; color: #94a3b8; margin-bottom: 16px;">
                This warning will appear as a popup when the user logs in. They must acknowledge it before continuing.
            </div>
            <div class="modal-actions">
                <button class="btn btn-secondary" onclick="closeWarningModal()">Cancel</button>
                <button class="btn btn-warning" style="background: #fb923c;" onclick="saveWarning()">Send Warning</button>
            </div>
        </div>
    </div>
</body>
</html>
