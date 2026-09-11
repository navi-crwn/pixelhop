<?php
/**
 * PicHost - Image Upload API
 * Handles image upload, resize, and S3 storage
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0);

// CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Upload-Token');

/**
 * Lightweight debug logger for upload issues.
 *
 * Only writes when PIXELHOP_DEBUG_LOG=1. IPs are masked to the last octet
 * (1.2.3.x) and remote URLs are reduced to their hostname so the log never
 * contains full IPs or full remote URLs.
 */
function uploadDebug(string $message, array $context = []): void {
    if (getenv('PIXELHOP_DEBUG_LOG') !== '1') {
        return;
    }

    $safeContext = [];
    foreach ($context as $key => $value) {
        if ($key === 'ip' && is_string($value)) {
            $value = maskIpForDebug($value);
        } elseif ($key === 'url' && is_string($value)) {
            $host = parse_url($value, PHP_URL_HOST);
            $value = is_string($host) ? $host : 'invalid-url';
        } elseif ($key === 'path' && is_string($value)) {
            $path = parse_url($value, PHP_URL_PATH);
            $value = is_string($path) ? $path : '/';
        }
        $safeContext[$key] = $value;
    }

    $logFile = __DIR__ . '/../temp/upload_debug.log';
    $line = date('c') . ' ' . $message;
    if (!empty($safeContext)) {
        $line .= ' ' . json_encode($safeContext);
    }
    $line .= PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * Mask an IP address for debug logging.
 */
function maskIpForDebug(string $ip): string {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $parts[3] = 'x';
            return implode('.', $parts);
        }
        return 'x.x.x.x';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        // Mask the last hextet.
        $masked = preg_replace('/[^:]*$/', 'x', $ip);
        return $masked === null ? 'x' : $masked;
    }

    return 'x.x.x.x';
}

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', 405);
}

// Central session bootstrap (replaces direct session_start()).
require_once __DIR__ . '/../includes/bootstrap.php';

// Security Firewall Check
require_once __DIR__ . '/../includes/SecurityFirewall.php';
require_once __DIR__ . '/../includes/ImageRepository.php';
require_once __DIR__ . '/../includes/Logger.php';
require_once __DIR__ . '/../includes/ImageVariantProcessor.php';
require_once __DIR__ . '/../includes/UploadService.php';

// Single repository instance for the whole request (shared static store).
$imageRepo = new ImageRepository();

// Upload journal (crash recovery). Best-effort only: DB may be unavailable
// in local/json-only dev, and a journal failure must never break an upload.
$journal = null;
try {
    require_once __DIR__ . '/../includes/Database.php';
    require_once __DIR__ . '/../includes/UploadJournal.php';
    $journal = new UploadJournal(Database::getInstance());
} catch (Throwable $journalInitException) {
    Logger::error('upload', 'Upload journal init failed: ' . $journalInitException->getMessage(), [
        'exception' => get_class($journalInitException),
    ]);
}

// Bypass firewall for valid API token requests (e.g. Shottr)
$_preAuthToken = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? $_POST['upload_token'] ?? null;
$_tokenBypass = false;
if ($_preAuthToken) {
    require_once __DIR__ . '/../includes/Database.php';
    $_tokenCheckDb = Database::getInstance();
    $_tokenCheckStmt = $_tokenCheckDb->prepare("SELECT id FROM users WHERE upload_token = ? AND is_blocked = 0 AND account_status = 'active' LIMIT 1");
    $_tokenCheckStmt->execute([$_preAuthToken]);
    if ($_tokenCheckStmt->fetch()) {
        $_tokenBypass = true;
    }
}

$firewall = new SecurityFirewall();
$firewallCheck = $_tokenBypass ? ['allowed' => true] : $firewall->checkUpload();
if (!$firewallCheck['allowed']) {
    uploadDebug('firewall_block', [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'reason' => $firewallCheck['reason'] ?? null,
        'code' => $firewallCheck['code'] ?? null,
        'path' => $_SERVER['REQUEST_URI'] ?? '',
    ]);
    http_response_code($firewallCheck['code'] ?? 403);
    echo json_encode(['success' => false, 'error' => $firewallCheck['reason']]);
    exit;
}

// Load config
$config = require __DIR__ . '/../config/s3.php';

// AbuseGuard check - before processing upload
require_once __DIR__ . '/../core/AbuseGuard.php';
require_once __DIR__ . '/../core/SafeGuard.php';
require_once __DIR__ . '/../includes/ClientIp.php';
$abuseGuard = new AbuseGuard();
$safeGuard = new SafeGuard();
$clientIP = ClientIp::get();

$sessionUserId = $_SESSION['user_id'] ?? null;

// API token auth (for Shottr / external tools)
if (!$sessionUserId) {
    $uploadToken = $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? $_POST['upload_token'] ?? null;
    if ($uploadToken) {
        require_once __DIR__ . '/../includes/Database.php';
        $tokenDb = Database::getInstance();
        $tokenStmt = $tokenDb->prepare("SELECT id FROM users WHERE upload_token = ? AND is_blocked = 0 AND account_status = 'active' LIMIT 1");
        $tokenStmt->execute([$uploadToken]);
        $tokenUser = $tokenStmt->fetch(PDO::FETCH_ASSOC);
        if ($tokenUser) {
            $sessionUserId = (int)$tokenUser['id'];
        }
    }
}

// CSRF verification for authenticated browser sessions only.
// Guest uploads have no session to protect, so no CSRF token is required
// (AbuseGuard still limits abuse). API-token requests (Shottr) and the
// pre-auth firewall bypass remain exempt as well.
if (!empty($sessionUserId) && empty($_tokenBypass)) {
    $csrfToken = $_POST['csrf_token'] ?? null;

    if ($csrfToken === null) {
        $jsonInput = json_decode(file_get_contents('php://input'), true);
        if (is_array($jsonInput)) {
            $csrfToken = $jsonInput['csrf_token'] ?? null;
        }
    }

    if ($csrfToken === null) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    if (!is_string($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', 403);
    }
}

$fileSize = $_FILES['image']['size'] ?? 0;
$uploadFile = $_FILES['image'] ?? null;

$abuseCheck = $abuseGuard->checkUpload($clientIP, $sessionUserId, $fileSize);
if (!$abuseCheck['allowed']) {
    uploadDebug('abuse_block', [
        'ip' => $clientIP,
        'reason' => $abuseCheck['reason'] ?? null,
        'code' => $abuseCheck['code'] ?? null,
        'user_id' => $sessionUserId,
    ]);
    $httpCode = ($abuseCheck['code'] ?? '') === 'rate_limit' ? 429 : 403;
    jsonResponse(false, $abuseCheck['reason'], $httpCode);
}

// Gatekeeper wiring (D2-01): enforce maintenance mode, kill switch, global
// storage and per-user quota before any file processing. API-token requests
// (Shottr) are NOT exempt from Gatekeeper; the bypass token only skips the
// firewall/abuse layers.
require_once __DIR__ . '/../core/Gatekeeper.php';
$gatekeeper = new Gatekeeper();
$gk = $gatekeeper->canUpload((int)$fileSize, $sessionUserId ?: null);
if (!($gk['allowed'] ?? true)) {
    $gkCode = $gk['code'] ?? '';
    $gkHttp = $gk['code'] ?? 403;
    if (!is_int($gkHttp) && !ctype_digit((string) $gkHttp)) {
        $gkHttp = 403;
    }
    $gkHttp = (int) $gkHttp;
    if (in_array($gkCode, [
        Gatekeeper::ERROR_MAINTENANCE,
        Gatekeeper::ERROR_KILL_SWITCH,
        Gatekeeper::ERROR_STORAGE_FULL,
    ], true)) {
        $gkHttp = 503;
    } elseif ($gkCode === Gatekeeper::ERROR_USER_QUOTA) {
        $gkHttp = 429;
    }

    uploadDebug('gatekeeper_block', [
        'code' => $gkCode,
        'user_id' => $sessionUserId,
    ]);
    jsonResponse(false, $gk['reason'] ?? 'Upload ditolak', $gkHttp);
}

// Handle URL upload (remote fetch)
$remoteUrl = $_POST['url'] ?? '';

// Orkestrasi upload utama kini ada di UploadService::handle(). Controller
// hanya membangun context (file/remoteUrl/session/clientIP/config + objek
// middleware yang sudah dibuat di atas) dan meneruskan hasilnya ke respons.
$uploadService = new UploadService($imageRepo, $journal);

$result = $uploadService->handle([
    'file' => $uploadFile,
    'remoteUrl' => $remoteUrl,
    'sessionUserId' => $sessionUserId,
    'clientIP' => $clientIP,
    'config' => $config,
    'abuseGuard' => $abuseGuard,
    'safeGuard' => $safeGuard,
    'gatekeeper' => $gatekeeper,
]);

if ($result['success']) {
    jsonResponse(true, null, $result['http_code'], $result['payload']);
}

jsonResponse(false, $result['payload']['error'] ?? 'Upload failed', $result['http_code']);

/**
 * Send JSON response
 */
function jsonResponse($success, $error = null, $code = 200, $data = []) {
    http_response_code($code);

    $response = ['success' => $success];

    if ($error) {
        $response['error'] = $error;
    }

    if ($success && !empty($data)) {
        $response['data'] = $data;
    }

    echo json_encode($response);
    exit;
}
