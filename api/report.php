<?php
/**
 * PixelHop - Image Report API
 * Handles user reports for abusive content
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Rate limiting - max 5 reports per IP per hour
require_once __DIR__ . '/../includes/ClientIp.php';
$ip = ClientIp::get();
$rateLimitFile = __DIR__ . '/../data/ratelimit/report_' . md5($ip) . '.json';

$rateLimitDir = dirname($rateLimitFile);
if (!is_dir($rateLimitDir)) {
    mkdir($rateLimitDir, 0755, true);
}

$rateLimit = [];
if (file_exists($rateLimitFile)) {
    $rateLimit = json_decode(file_get_contents($rateLimitFile), true) ?: [];
}

// Clean old entries (older than 1 hour)
$oneHourAgo = time() - 3600;
$rateLimit = array_filter($rateLimit, fn($ts) => $ts > $oneHourAgo);

if (count($rateLimit) >= 5) {
    echo json_encode(['success' => false, 'error' => 'Too many reports. Please try again later.']);
    exit;
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);

$imageId = trim($input['image_id'] ?? '');
$reason = trim($input['reason'] ?? '');
$details = trim($input['details'] ?? '');

// Validate
if (empty($imageId)) {
    echo json_encode(['success' => false, 'error' => 'Image ID is required']);
    exit;
}

if (empty($reason)) {
    echo json_encode(['success' => false, 'error' => 'Reason is required']);
    exit;
}

$validReasons = ['illegal', 'nsfw', 'violence', 'harassment', 'copyright', 'spam', 'malware', 'other'];
if (!in_array($reason, $validReasons)) {
    echo json_encode(['success' => false, 'error' => 'Invalid reason']);
    exit;
}

// Verify image exists
$imagesFile = __DIR__ . '/../data/images.json';
if (file_exists($imagesFile)) {
    $images = json_decode(file_get_contents($imagesFile), true) ?: [];
    if (!isset($images[$imageId])) {
        echo json_encode(['success' => false, 'error' => 'Image not found']);
        exit;
    }
}

// Load existing reports
$reportsFile = __DIR__ . '/../data/abuse_reports.json';
$reports = [];
if (file_exists($reportsFile)) {
    $reports = json_decode(file_get_contents($reportsFile), true) ?: [];
}

// Check for duplicate reports from same IP
foreach ($reports as $report) {
    if ($report['image_id'] === $imageId && $report['ip'] === $ip && $report['status'] === 'pending') {
        echo json_encode(['success' => false, 'error' => 'You have already reported this image']);
        exit;
    }
}

// Create report
$reportId = bin2hex(random_bytes(8));
$report = [
    'id' => $reportId,
    'image_id' => $imageId,
    'reason' => $reason,
    'details' => substr($details, 0, 1000), // Limit details to 1000 chars
    'ip' => $ip,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'status' => 'pending', // pending, reviewed, resolved, dismissed
    'created_at' => time(),
    'reviewed_at' => null,
    'reviewed_by' => null,
    'action_taken' => null,
    'notes' => null
];

$reports[] = $report;

// Save reports
if (file_put_contents($reportsFile, json_encode($reports, JSON_PRETTY_PRINT), LOCK_EX)) {
    // Update rate limit
    $rateLimit[] = time();
    file_put_contents($rateLimitFile, json_encode($rateLimit));
    
    echo json_encode(['success' => true, 'message' => 'Report submitted successfully']);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save report']);
}
