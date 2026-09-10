<?php
/**
 * PixelHop - Image Expiration Cron
 * 
 * Manages automatic deletion of inactive public images:
 * - Public uploads (guest, no user_id) not viewed for 60 days → marked for deletion
 * - Marked images not viewed for additional 30 days (90 days total) → deleted
 * 
 * Run: crontab -e
 * 0 2 * * * php /var/www/pichost/cron/image_expiration.php >> /var/log/pichost/expiration.log 2>&1
 *
 * D4-01/D4-02/D4-07: semua mutasi images.json memakai JsonStore RMW
 * (flock + temp file + rename). Metadata TIDAK pernah dihapus sebelum semua
 * delete S3 sukses. Bila delete S3 gagal, penanda `deleting_at` dilepas dan
 * `last_delete_error` dicatat supaya retry otomatis pada run berikutnya.
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

define('ROOT_PATH', dirname(__DIR__));

// Load config
$config = require ROOT_PATH . '/config/s3.php';

// Load R2 storage manager for deletion
require_once ROOT_PATH . '/includes/R2StorageManager.php';
$r2 = new R2StorageManager($config);

// Load JsonStore for atomic RMW access to images.json (D2-05, D4-07)
require_once ROOT_PATH . '/includes/JsonStore.php';

$dataFile = ROOT_PATH . '/data/images.json';
$logFile = ROOT_PATH . '/data/expiration_log.json';

$store = new JsonStore($dataFile);
$logStore = new JsonStore($logFile);

echo "[" . date('Y-m-d H:i:s') . "] Starting image expiration check...\n";

// D4-07: backup sebelum modifikasi apa pun. Tidak ada backup bila file tidak ada.
$backupPath = $store->backup(10);
if ($backupPath !== null) {
    echo "Backup created: {$backupPath}\n";
}

if (!is_file($dataFile)) {
    die("Error: images.json not found\n");
}

$images = $store->read();
if (!is_array($images)) {
    die("Error: Invalid images.json format\n");
}

$now = time();
$sixtyDays = 60 * 24 * 60 * 60; // 60 days in seconds
$thirtyDays = 30 * 24 * 60 * 60; // 30 days in seconds

$stats = [
    'checked' => 0,
    'skipped_user_owned' => 0,
    'marked_for_deletion' => 0,
    'deleted' => 0,
    'deletion_errors' => 0,
    'still_active' => 0
];

// Tahap 1 (seleksi, read-only): kumpulkan kandidat delete dan kandidat mark
// tanpa memegang lock. Mutasi aktual dilakukan per-item di tahap 2 via
// JsonStore::mutate() sehingga tidak ada baca-penuh-lalu-timpa.
$deleteCandidates = [];
$markCandidates = [];

foreach ($images as $imageId => $image) {
    $stats['checked']++;

    // Skip user-owned images (registered users)
    if (!empty($image['user_id'])) {
        $stats['skipped_user_owned']++;
        continue;
    }

    // Get the last viewed timestamp (or fall back to created_at)
    $lastViewed = $image['last_viewed_at'] ?? $image['created_at'] ?? 0;
    $daysSinceView = ($now - $lastViewed) / (24 * 60 * 60);

    // Check if already marked for deletion
    if (isset($image['marked_for_deletion'])) {
        $markedAt = $image['marked_for_deletion'];
        $daysSinceMarked = ($now - $markedAt) / (24 * 60 * 60);

        // If 30 more days have passed since marking (90 days total without view), delete
        if ($daysSinceMarked >= 30) {
            $deleteCandidates[$imageId] = $image;
            echo "  [DELETE] {$imageId} - No views for 90+ days (marked " . round($daysSinceMarked) . " days ago)\n";
        } else {
            echo "  [PENDING] {$imageId} - Marked for deletion, " . round($daysSinceMarked, 1) . " days ago (will delete in " . round(30 - $daysSinceMarked) . " days)\n";
        }
    } else {
        // Not yet marked - check if it's been 60 days without a view
        if ($daysSinceView >= 60) {
            $markCandidates[$imageId] = $image;
            echo "  [MARKED] {$imageId} - No views for " . round($daysSinceView) . " days, marked for deletion (will delete in 30 days)\n";
        } else {
            $stats['still_active']++;
        }
    }
}

// Tahap 2a (mark): tandai kandidat via JsonStore::mutate() satu per satu.
foreach ($markCandidates as $imageId => $image) {
    try {
        $store->mutate(function (array $data) use ($imageId, $now): array {
            if (isset($data[$imageId]) && empty($data[$imageId]['user_id'])) {
                $data[$imageId]['marked_for_deletion'] = $now;
            }
            return $data;
        });
        $stats['marked_for_deletion']++;
    } catch (Exception $e) {
        echo "  [MARK ERROR] {$imageId}: " . $e->getMessage() . "\n";
    }
}

// Tahap 2b (delete): per image, tandai dulu `deleting_at` (dalam lock),
// lepas lock, hapus S3 (network di luar lock), lalu mutate lagi untuk unset
// HANYA bila semua delete S3 sukses. Gagal => hapus `deleting_at` dan catat
// `last_delete_error`; metadata tetap utuh untuk retry run berikutnya.
foreach ($deleteCandidates as $imageId => $image) {
    // Tandai di dalam lock agar run lain tidak memproses item yang sama.
    $claimed = false;
    try {
        $store->mutate(function (array $data) use ($imageId, $now, &$claimed): array {
            if (!isset($data[$imageId])) {
                return $data;
            }

            // Sudah ditandai proses oleh proses lain dan belum basi.
            if (!empty($data[$imageId]['deleting_at']) && ($now - (int)$data[$imageId]['deleting_at']) < 3600) {
                return $data;
            }

            $data[$imageId]['deleting_at'] = $now;
            $claimed = true;
            return $data;
        });

        // Pastikan marker dipasang oleh proses ini sebelum delete S3 dimulai.
        if (!$claimed) {
            echo "  [SKIP] {$imageId} - already being deleted by another process\n";
            continue;
        }
    } catch (Exception $e) {
        echo "  [DELETE MARK ERROR] {$imageId}: " . $e->getMessage() . "\n";
        $stats['deletion_errors']++;
        continue;
    }

    $s3Keys = $image['s3_keys'] ?? [];
    $imageSize = (int)($image['size'] ?? 0);

    // D4-03: estimasi ukuran per varian dipakai deleteImage() untuk akuntansi
    // storage_stats yang akurat. deleteImage() juga fallback ke estimasi ini.
    $s3Sizes = R2StorageManager::estimateVariantSizes($imageSize);

    // Network delete DI LUAR lock.
    $deleteResult = ['success' => true, 'deleted' => 0, 'details' => []];
    try {
        if (empty($s3Keys)) {
            $deleteResult = ['success' => true, 'deleted' => 0, 'details' => []];
        } else {
            $deleteResult = $r2->deleteImage($s3Keys, $imageSize, $s3Sizes);
        }
    } catch (Exception $e) {
        $deleteResult = [
            'success' => false,
            'deleted' => 0,
            'details' => ['error' => $e->getMessage()],
        ];
    }

    $allS3Succeeded = !empty($deleteResult['success']) && ($deleteResult['deleted'] ?? 0) >= count(array_filter($s3Keys));

    if ($allS3Succeeded) {
        // Semua delete S3 sukses: metadata boleh dihapus.
        try {
            $store->mutate(function (array $data) use ($imageId): array {
                unset($data[$imageId]);
                return $data;
            });
            $stats['deleted']++;
            echo "  [REMOVED] {$imageId} from database\n";
        } catch (Exception $e) {
            echo "  [METADATA ERROR] {$imageId}: " . $e->getMessage() . "\n";
            $stats['deletion_errors']++;
        }
    } else {
        // S3 gagal sebagian/seluruhnya: metadata TIDAK dihapus (D4-02).
        // Lepas marker `deleting_at` agar bisa diretried dan catat error.
        $errorMessage = 'S3 delete failed: ' . json_encode($deleteResult['details'] ?? []);
        try {
            $store->mutate(function (array $data) use ($imageId, $now, $errorMessage): array {
                if (isset($data[$imageId])) {
                    unset($data[$imageId]['deleting_at']);
                    $data[$imageId]['last_delete_error'] = [
                        'timestamp' => $now,
                        'message' => substr($errorMessage, 0, 500),
                    ];
                }
                return $data;
            });
        } catch (Exception $e) {
            echo "  [ERROR LOG ERROR] {$imageId}: " . $e->getMessage() . "\n";
        }
        $stats['deletion_errors']++;
        echo "  [DELETE ERROR] {$imageId}: metadata retained, will retry next run (" . substr($errorMessage, 0, 160) . ")\n";
    }
}

// Save log via JsonStore RMW (pertahankan format array per entri).
$log = [
    'timestamp' => date('Y-m-d H:i:s'),
    'stats' => $stats,
];

try {
    $logStore->mutate(function (array $logs) use ($log): array {
        $logs[] = $log;

        // Keep only last 30 days of logs
        return array_slice($logs, -30);
    });
} catch (Exception $e) {
    echo "  [LOG ERROR] " . $e->getMessage() . "\n";
}

echo "\n=== Summary ===\n";
echo "Checked: {$stats['checked']}\n";
echo "Skipped (user-owned): {$stats['skipped_user_owned']}\n";
echo "Still active: {$stats['still_active']}\n";
echo "Newly marked for deletion: {$stats['marked_for_deletion']}\n";
echo "Deleted: {$stats['deleted']}\n";
echo "Deletion errors: {$stats['deletion_errors']}\n";
echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
