<?php
/**
 * PixelHop - Statistics API
 * Returns site-wide statistics for the admin dashboard
 *
 * Catatan performa (audit D5-24): data/images.json dibaca SATU kali dan
 * seluruh agregasi dilakukan dalam SATU loop. Daftar "recent uploads"
 * dibatasi N terbaru secara in-memory (bounded top-N) sehingga endpoint
 * ini tidak pernah menyortir atau me-render seluruh isi images.json.
 */

header('Content-Type: application/json');

// Admin-only endpoint (consumed by admin dashboard)
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../auth/middleware.php';
require_once __DIR__ . '/../includes/Logger.php';

if (!isAuthenticated() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$config = require __DIR__ . '/../config/s3.php';
$imagesFile = __DIR__ . '/../data/images.json';

// Baca images.json SATU kali saja.
$images = [];
if (is_file($imagesFile)) {
    $raw = @file_get_contents($imagesFile);
    if ($raw === false) {
        Logger::error('stats', 'Failed to read images data file', ['file' => basename($imagesFile)]);
    } else {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $images = $decoded;
        } elseif (trim($raw) !== '') {
            Logger::error('stats', 'Corrupt images data file', [
                'file' => basename($imagesFile),
                'json_error' => json_last_error_msg(),
            ]);
        }
    }
}

// ---------------------------------------------------------------------------
// Agregasi satu pass.
//
// Data model (lihat api/upload.php): created_at = unix timestamp,
// size = original file size in bytes, extension = file format.
// ---------------------------------------------------------------------------
$recentLimit = 20;

$totalImages = count($images);
$totalSize = 0;
$formats = [];
$storageByFormat = [];
$uploadsByDate = [];

// Bounded top-N terbaru, terurut created_at DESC (maks $recentLimit entri).
$recentUploads = [];

foreach ($images as $image) {
    $size = (int) ($image['size'] ?? 0);
    $createdAt = (int) ($image['created_at'] ?? 0);
    $format = strtoupper($image['extension'] ?? 'unknown');

    $totalSize += $size;
    $formats[$format] = ($formats[$format] ?? 0) + 1;
    $storageByFormat[$format] = ($storageByFormat[$format] ?? 0) + $size;

    $uploadDate = date('Y-m-d', $createdAt);
    $uploadsByDate[$uploadDate] = ($uploadsByDate[$uploadDate] ?? 0) + 1;

    // Sisipkan ke daftar top-N pada posisi urut, lalu potong ekornya.
    // Tidak ada sort atas array penuh.
    $insertAt = 0;
    $recentCount = count($recentUploads);
    while (
        $insertAt < $recentCount
        && (int) ($recentUploads[$insertAt]['created_at'] ?? 0) >= $createdAt
    ) {
        $insertAt++;
    }

    if ($insertAt < $recentLimit) {
        array_splice($recentUploads, $insertAt, 0, [$image]);
        if (count($recentUploads) > $recentLimit) {
            array_pop($recentUploads);
        }
    }
}

// Sort formats by count (array kecil, jumlah format unik saja).
arsort($formats);
arsort($storageByFormat);

// Get last 30 days of upload data
$last30Days = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $last30Days[$date] = $uploadsByDate[$date] ?? 0;
}

// Format recent uploads for response (hanya <= $recentLimit entri).
$siteUrl = $config['site']['url'] ?? '';
$recentFormatted = array_map(function ($img) use ($siteUrl) {
    $thumbKey = $img['s3_keys']['thumb'] ?? null;
    return [
        'id' => $img['id'] ?? '',
        'filename' => $img['filename'] ?? 'unknown',
        'format' => strtoupper($img['extension'] ?? 'unknown'),
        'size' => (int) ($img['size'] ?? 0),
        'uploaded_at' => date('Y-m-d H:i:s', (int) ($img['created_at'] ?? 0)),
        'thumbnail' => $thumbKey ? $siteUrl . '/i/' . $thumbKey : null,
    ];
}, $recentUploads);

// Response
echo json_encode([
    'success' => true,
    'stats' => [
        'total_images' => $totalImages,
        'total_size' => $totalSize,
        'total_size_formatted' => formatBytes($totalSize),
        'formats' => $formats,
        'storage_by_format' => array_map('formatBytes', $storageByFormat),
        'uploads_last_30_days' => $last30Days,
        'uploads_today' => $uploadsByDate[date('Y-m-d')] ?? 0,
        'uploads_this_week' => array_sum(array_slice($last30Days, -7)),
        'uploads_this_month' => array_sum($last30Days)
    ],
    'recent_uploads' => $recentFormatted
], JSON_PRETTY_PRINT);

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
