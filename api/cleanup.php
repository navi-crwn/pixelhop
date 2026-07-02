<?php
/**
 * PixelHop - Scheduled Image Cleanup
 *
 * Run this via cron every 5 minutes:
 * crontab example: 0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/cleanup.php
 */

// Prevent web access: CLI always allowed; HTTP access requires a secret key
// configured via the CRON_KEY environment variable (never hardcode it here)
if (php_sapi_name() !== 'cli') {
    $expectedKey = getenv('CRON_KEY') ?: '';
    $cronKey = $_GET['cron_key'] ?? '';

    if ($expectedKey === '' || $cronKey === '' || !hash_equals($expectedKey, $cronKey)) {
        http_response_code(403);
        die('Forbidden');
    }
}

// Load config
$config = require __DIR__ . '/../config/s3.php';

// Hybrid storage manager (routes deletes to R2 or Contabo per key)
require_once __DIR__ . '/../includes/R2StorageManager.php';
$storageManager = new R2StorageManager($config);

// Load image data
$dataFile = __DIR__ . '/../data/images.json';

if (!file_exists($dataFile)) {
    echo "No images file found.\n";
    exit(0);
}

$images = json_decode(file_get_contents($dataFile), true) ?: [];
$currentTime = time();
$deletedCount = 0;
$checkedCount = 0;

foreach ($images as $imageId => $imageData) {
    $checkedCount++;


    if (!empty($imageData['delete_at']) && $imageData['delete_at'] <= $currentTime) {
        echo "Deleting image {$imageId} (scheduled for " . date('Y-m-d H:i:s', $imageData['delete_at']) . ")...\n";


        if (!empty($imageData['s3_keys'])) {
            foreach ($imageData['s3_keys'] as $sizeName => $s3Key) {
                $deleteResult = $storageManager->deleteObject($s3Key);
                if ($deleteResult['success']) {
                    echo "  - Deleted: {$s3Key}\n";
                } else {
                    echo "  - Failed to delete: {$s3Key} (" . ($deleteResult['error'] ?? 'unknown') . ")\n";
                }
            }
        }


        unset($images[$imageId]);
        $deletedCount++;
    }
}

// Save updated images
if ($deletedCount > 0) {
    file_put_contents($dataFile, json_encode($images, JSON_PRETTY_PRINT), LOCK_EX);
    echo "\nCleanup complete: {$deletedCount} images deleted, {$checkedCount} checked.\n";
} else {
    echo "No images to delete. Checked {$checkedCount} images.\n";
}
