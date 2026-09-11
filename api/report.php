<?php
/**
 * PixelHop - Image Report API
 * Handles user reports for abusive content
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Csrf-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// D4-09: firewall global sebelum pemrosesan input.
require_once __DIR__ . '/../includes/SecurityFirewall.php';
$firewall = new SecurityFirewall();
$check = $firewall->check();
if (!$check['allowed']) {
    http_response_code($check['code'] ?? 403);
    echo json_encode(['success' => false, 'error' => $check['reason'] ?? 'Forbidden']);
    exit;
}

// D3-08: CSRF ringan. Token via header X-Csrf-Token atau body `csrf_token`
// diterima BILA caller menyediakannya. API ini publik (guest boleh report
// tanpa sesi), jadi token tidak diwajibkan — anti-spam diandalkan pada
// rate-limit per IP + dedup pending per IP+image_id, bukan blokir guest.
$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true) ?: [];
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($csrfToken === '' && isset($input['csrf_token'])) {
    $csrfToken = (string)$input['csrf_token'];
}
if ($csrfToken !== '' && strlen($csrfToken) > 256) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Rate limiting - max 5 reports per IP per hour.
// D3-08: file rate-limit memakai JsonStore (RMW aman, bukan read + write).
require_once __DIR__ . '/../includes/ClientIp.php';
require_once __DIR__ . '/../includes/JsonStore.php';

$ip = ClientIp::get();
$rateLimitFile = __DIR__ . '/../data/ratelimit/report_' . md5($ip) . '.json';
$rateLimitStore = new JsonStore($rateLimitFile);

$oneHourAgo = time() - 3600;
$rateLimited = false;

try {
    // RMW aman: cek + reserve slot timestamp dalam SATU lock yang sama
    // sehingga dua request konkuren tidak bisa sama-sama lolos limit 5/jam.
    $rateLimitStore->mutate(function (array $rateLimit) use ($oneHourAgo, &$rateLimited): array {
        // Clean old entries (older than 1 hour)
        $rateLimit = array_filter($rateLimit, fn($ts) => $ts > $oneHourAgo);

        if (count($rateLimit) >= 5) {
            $rateLimited = true;
            return $rateLimit;
        }

        $rateLimit[] = time();
        return $rateLimit;
    });
} catch (Exception $e) {
    // Fail-closed untuk anti-spam: anggap rate limit service unavailable.
    error_log('report.php: rate limit store error - ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Rate limit service unavailable']);
    exit;
}

if ($rateLimited) {
    echo json_encode(['success' => false, 'error' => 'Too many reports. Please try again later.']);
    exit;
}

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

// D4-09: verifikasi image ada. Repo images.json dipakai untuk cek
// keberadaan; bila file/key tidak ada, balas 404 (sebelumnya lolos saat
// file tidak ada).
require_once __DIR__ . '/../includes/ImageRepository.php';
$imageRepo = new ImageRepository();

if (!$imageRepo->exists($imageId)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Image not found']);
    exit;
}

// Append `abuse_reports.json` via JsonStore::mutate. Dedup pending per
// IP+image_id dilakukan DI DALAM lock sehingga tidak ada race antar request
// konkuren yang lolos bersamaan (D4-09).
$reportsFile = __DIR__ . '/../data/abuse_reports.json';
$reportsStore = new JsonStore($reportsFile);

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

$submitted = false;
$duplicate = false;

try {
    $reportsStore->mutate(function (array $reports) use ($report, $imageId, $ip, &$submitted, &$duplicate): array {
        // Dedup pending per IP+image_id di dalam lock.
        foreach ($reports as $existing) {
            if (
                ($existing['image_id'] ?? null) === $imageId
                && ($existing['ip'] ?? null) === $ip
                && ($existing['status'] ?? null) === 'pending'
            ) {
                $duplicate = true;
                return $reports;
            }
        }

        $reports[] = $report;
        $submitted = true;
        return $reports;
    });
} catch (Exception $e) {
    error_log('report.php: abuse reports store error - ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Failed to save report']);
    exit;
}

if ($duplicate) {
    echo json_encode(['success' => false, 'error' => 'You have already reported this image']);
    exit;
}

if (!$submitted) {
    echo json_encode(['success' => false, 'error' => 'Failed to save report']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Report submitted successfully']);
