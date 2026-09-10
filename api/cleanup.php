<?php
/**
 * PixelHop - Scheduled Image Cleanup
 *
 * Run this via cron every 5 minutes:
 * crontab example: 0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/cleanup.php
 *
 * CRON_KEY support:
 * - Preferred: `X-Cron-Key` request header.
 * - Deprecated (kept for 1 release compatibility): `?cron_key=` GET parameter.
 *   Migrate callers to the header; GET keys leak into access logs.
 */

// Prevent web access: CLI always allowed; HTTP access requires a secret key
// configured via the CRON_KEY environment variable (never hardcode it here)
if (php_sapi_name() !== 'cli') {
    $expectedKey = getenv('CRON_KEY') ?: '';
    $headerKey = $_SERVER['HTTP_X_CRON_KEY'] ?? '';
    $getKey = $_GET['cron_key'] ?? '';

    $providedKey = $headerKey !== '' ? $headerKey : $getKey;

    if ($expectedKey === '' || $providedKey === '' || !is_string($providedKey) || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(403);
        die('Forbidden');
    }
}

// Load config
$config = require __DIR__ . '/../config/s3.php';

// Hybrid storage manager (routes deletes to R2 or Contabo per key)
require_once __DIR__ . '/../includes/R2StorageManager.php';
$storageManager = new R2StorageManager($config);

// D2-05 / D4-02: gunakan JsonStore untuk baca & tulis images.json (RMW aman).
require_once __DIR__ . '/../includes/JsonStore.php';

$dataFile = __DIR__ . '/../data/images.json';
$store = new JsonStore($dataFile);

if (!is_file($dataFile)) {
    echo "No images file found.\n";
    exit(0);
}

$images = $store->read();
$currentTime = time();
$deletedCount = 0;
$checkedCount = 0;
$errorCount = 0;

$candidates = [];
foreach ($images as $imageId => $imageData) {
    $checkedCount++;

    if (!empty($imageData['delete_at']) && $imageData['delete_at'] <= $currentTime) {
        $candidates[$imageId] = $imageData;
    }
}

// Proses per item: delete S3 dulu, lalu metadata dihapus HANYA bila semua
// delete S3 sukses. Gagal => metadata tetap ada + `last_delete_error` dicatat
// supaya run berikutnya otomatis me-retry (D4-02).
foreach ($candidates as $imageId => $imageData) {
    echo "Deleting image {$imageId} (scheduled for " . date('Y-m-d H:i:s', $imageData['delete_at']) . ")...\n";

    $s3Keys = $imageData['s3_keys'] ?? [];
    $size = (int)($imageData['size'] ?? 0);

    // D4-03: perkiraan ukuran per varian untuk akuntansi storage_stats yang
    // akurat. Bila nanti metadata punya ukuran aktual per varian, prioritaskan
    // itu di sini.
    $s3Sizes = R2StorageManager::estimateVariantSizes($size);

    $deleteResult = ['success' => true, 'deleted' => 0, 'details' => []];

    if (empty($s3Keys)) {
        // Tidak ada objek untuk dihapus; metadata boleh langsung dibersihkan.
        $deleteResult = ['success' => true, 'deleted' => 0, 'details' => []];
    } else {
        try {
            $deleteResult = $storageManager->deleteImage($s3Keys, $size, $s3Sizes);
        } catch (Exception $e) {
            $deleteResult = [
                'success' => false,
                'deleted' => 0,
                'details' => ['error' => $e->getMessage()],
            ];
        }
    }

    $allS3Succeeded = !empty($deleteResult['success'])
        && ($deleteResult['deleted'] ?? 0) >= count(array_filter($s3Keys));

    if ($allS3Succeeded) {
        try {
            $store->mutate(function (array $data) use ($imageId): array {
                unset($data[$imageId]);
                return $data;
            });
            $deletedCount++;
            echo "  - Metadata removed: {$imageId}\n";
        } catch (Exception $e) {
            $errorCount++;
            echo "  - Failed to remove metadata: {$imageId} (" . $e->getMessage() . ")\n";
        }
    } else {
        // JANGAN hapus metadata bila S3 gagal sebagian/seluruhnya (D4-02).
        $message = 'S3 delete failed: ' . json_encode($deleteResult['details'] ?? []);
        try {
            $store->mutate(function (array $data) use ($imageId, $currentTime, $message): array {
                if (isset($data[$imageId])) {
                    $data[$imageId]['last_delete_error'] = [
                        'timestamp' => $currentTime,
                        'message' => substr($message, 0, 500),
                    ];
                }
                return $data;
            });
        } catch (Exception $e) {
            echo "  - Failed to record delete error: {$imageId} (" . $e->getMessage() . ")\n";
        }
        $errorCount++;
        echo "  - Delete incomplete, metadata retained: {$imageId}\n";
    }
}

echo "\nCleanup complete: {$deletedCount} images deleted, {$errorCount} errors, {$checkedCount} checked.\n";
