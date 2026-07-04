<?php
/**
 * PixelHop - Statistics API
 * Returns site-wide statistics for the admin dashboard
 */

header('Content-Type: application/json');

// Admin-only endpoint (consumed by admin.php dashboard)
session_start();
require_once __DIR__ . '/../auth/middleware.php';

if (!isAuthenticated() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$config = require __DIR__ . '/../config/s3.php';
$imagesFile = __DIR__ . '/../data/images.json';

// Load images data
$images = [];
if (file_exists($imagesFile)) {
    $images = json_decode(file_get_contents($imagesFile), true) ?: [];
}

// Calculate statistics
// Data model (see api/upload.php): created_at = unix timestamp,
// size = original file size in bytes, extension = file format
$totalImages = count($images);
$totalSize = 0;
$formats = [];
$uploadsByDate = [];

foreach ($images as $image) {
    $totalSize += (int) ($image['size'] ?? 0);

    $format = strtoupper($image['extension'] ?? 'unknown');
    $formats[$format] = ($formats[$format] ?? 0) + 1;

    $uploadDate = date('Y-m-d', (int) ($image['created_at'] ?? 0));
    $uploadsByDate[$uploadDate] = ($uploadsByDate[$uploadDate] ?? 0) + 1;
}

// Sort formats by count
arsort($formats);

// Get last 30 days of upload data
$last30Days = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $last30Days[$date] = $uploadsByDate[$date] ?? 0;
}

// Get recent uploads (last 10)
$sortedImages = array_values($images);
usort($sortedImages, function($a, $b) {
    return ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0);
});
$recentUploads = array_slice($sortedImages, 0, 10);

// Format recent uploads for response
$siteUrl = $config['site']['url'] ?? '';
$recentFormatted = array_map(function($img) use ($siteUrl) {
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

// Calculate storage breakdown by format
$storageByFormat = [];
foreach ($images as $image) {
    $format = strtoupper($image['extension'] ?? 'unknown');
    $storageByFormat[$format] = ($storageByFormat[$format] ?? 0) + (int) ($image['size'] ?? 0);
}
arsort($storageByFormat);

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
