<?php
/**
 * PixelHop - DashboardService
 *
 * Agregasi data untuk halaman dashboard user. Logika dipindahkan dari
 * dashboard.php (controller) agar controller hanya menangani bootstrap,
 * auth, dan render. Perilaku agregasi dipertahankan sebyte-identik dengan
 * implementasi lama.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ImageRepository.php';
require_once __DIR__ . '/../core/Gatekeeper.php';

final class DashboardService
{
    /**
     * Agregasi data dashboard untuk satu user.
     *
     * @param int $userId ID user yang sedang login.
     * @return array Data siap render: storage, kuota harian, recent uploads,
     *               upload count, dan recent activity.
     */
    public function getData(int $userId): array
    {
        $db = Database::getInstance();
        $gatekeeper = new Gatekeeper();

        $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $isAdmin = ($currentUser['role'] ?? null) === 'admin';

        // Get user's quota and usage - Admin has unlimited access
        $storageUsed = (int) ($currentUser['storage_used'] ?? 0);
        if ($isAdmin) {
            $storageLimit = PHP_INT_MAX;
            $storagePercent = 0;
            $ocrLimit = PHP_INT_MAX;
            $rembgLimit = PHP_INT_MAX;
        } else {
            $accountType = (string) ($currentUser['account_type'] ?? 'free');
            $storageLimit = $accountType === 'premium' ? 5 * 1024 * 1024 * 1024 : 500 * 1024 * 1024;
            $storagePercent = $storageLimit > 0 ? round(($storageUsed / $storageLimit) * 100, 1) : 0;
            $ocrLimit = $gatekeeper->getSetting($accountType === 'premium' ? 'daily_ocr_limit_premium' : 'daily_ocr_limit_free');
            $rembgLimit = $gatekeeper->getSetting($accountType === 'premium' ? 'daily_removebg_limit_premium' : 'daily_removebg_limit_free');
        }

        $ocrUsed = (int) ($currentUser['daily_ocr_count'] ?? 0);
        $rembgUsed = (int) ($currentUser['daily_removebg_count'] ?? 0);

        // Get user's recent uploads - filter by user_id (via ImageRepository,
        // identik dengan implementasi lama di dashboard.php).
        $repo = new ImageRepository();

        $allImages = $repo->readAll();

        $myImages = array_filter($allImages, function ($img) use ($userId) {
            return isset($img['user_id']) && $img['user_id'] == $userId;
        });

        usort($myImages, fn($a, $b) => ($b['created_at'] ?? 0) - ($a['created_at'] ?? 0));
        $uploadCount = count($myImages);
        $userImages = array_slice($myImages, 0, 6);

        // Get recent tool usage
        $recentLogsStmt = $db->prepare('SELECT * FROM usage_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
        $recentLogsStmt->execute([$userId]);
        $recentLogs = $recentLogsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'storage_used' => $storageUsed,
            'storage_limit' => $storageLimit,
            'storage_percent' => $storagePercent,
            'ocr_used' => $ocrUsed,
            'ocr_limit' => $ocrLimit,
            'rembg_used' => $rembgUsed,
            'rembg_limit' => $rembgLimit,
            'upload_count' => $uploadCount,
            'recent_uploads' => $userImages,
            'recent_activity' => $recentLogs,
        ];
    }
}
