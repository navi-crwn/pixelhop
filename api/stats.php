<?php
/**
 * PixelHop - Statistics API
 * Returns site-wide statistics for dashboard
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$config = require __DIR__ . '/../config/s3.php';
$imagesFile = __DIR__ . '/../data/images.json';

// Load images data
$images = [];
if (file_exists($imagesFile)) {
    $images = json_decode(file_get_contents($imagesFile), true) ?: [];
}

// Calculate statistics
$totalImages = count($images);
$totalSize = 0;
$formats = [];
$uploadsByDate = [];
$recentUploads = [];

foreach ($images as $image) {

    if (isset($image['sizes']['original']['size'])) {
        $totalSize += $image['sizes']['original']['size'];
    }


    $format = strtoupper($image['format'] ?? 'unknown');
    $formats[$format] = ($formats[$format] ?? 0) + 1;


    $uploadDate = date('Y-m-d', strtotime($image['uploaded_at'] ?? 'now'));
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
$sortedImages = $images;
usort($sortedImages, function($a, $b) {
    return strtotime($b['uploaded_at'] ?? 0) - strtotime($a['uploaded_at'] ?? 0);
});
$recentUploads = array_slice($sortedImages, 0, 10);

// Format recent uploads for response
$recentFormatted = array_map(function($img) {
    return [
        'id' => $img['id'],
        'filename' => $img['original_name'] ?? $img['filename'],
        'format' => strtoupper($img['format'] ?? 'unknown'),
        'size' => $img['sizes']['original']['size'] ?? 0,
        'uploaded_at' => $img['uploaded_at'],
        'thumbnail' => $img['sizes']['thumbnail']['url'] ?? $img['sizes']['small']['url'] ?? null
    ];
}, $recentUploads);

// Calculate storage breakdown by format
$storageByFormat = [];
foreach ($images as $image) {
    $format = strtoupper($image['format'] ?? 'unknown');
    $size = $image['sizes']['original']['size'] ?? 0;
    $storageByFormat[$format] = ($storageByFormat[$format] ?? 0) + $size;
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
