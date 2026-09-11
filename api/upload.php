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
require_once __DIR__ . '/../includes/R2StorageManager.php';
require_once __DIR__ . '/../includes/ImageHandler.php';
require_once __DIR__ . '/../includes/JsonStore.php';
require_once __DIR__ . '/../includes/Logger.php';

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
$file = null;
$tempFilePath = null;

uploadDebug('upload_request', [
    'has_remote_url' => !empty($remoteUrl),
    'has_file' => isset($_FILES['image']),
    'ip' => $clientIP,
    'user_id' => $sessionUserId,
]);

if (!empty($remoteUrl)) {
    uploadDebug('remote_url_upload', ['url' => $remoteUrl, 'url_length' => strlen($remoteUrl)]);
    
    // Validate URL format
    if (!filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
        jsonResponse(false, 'Invalid URL format');
    }
    
    // Only allow http/https
    $scheme = parse_url($remoteUrl, PHP_URL_SCHEME);
    if (!in_array(strtolower((string) $scheme), ['http', 'https'])) {
        jsonResponse(false, 'Only HTTP/HTTPS URLs are allowed');
    }

    // SSRF guard + streaming download delegated to ImageHandler. This avoids
    // the previous inlined cURL RETURNTRANSFER full-body fetch and keeps the
    // OOM-safe progress-abort download path (with manual redirect validation).
    $imageHandler = new ImageHandler();
    try {
        ImageHandler::assertPublicUrl($remoteUrl);
        $downloaded = $imageHandler->uploadFromUrl($remoteUrl);
    } catch (Exception $e) {
        Logger::error('upload', 'Remote upload failed: ' . $e->getMessage(), [
            'exception' => get_class($e),
            'ip' => $clientIP,
        ]);
        jsonResponse(false, 'Upload failed. Please try again.');
    }

    $tempFilePath = $downloaded['path'];

    // Extract filename from URL
    $urlPath = parse_url($remoteUrl, PHP_URL_PATH);
    $originalName = $urlPath ? (basename($urlPath) ?: 'image.jpg') : 'image.jpg';

    // Create pseudo $_FILES array so the rest of the validation flow is
    // identical for direct and remote uploads.
    $file = [
        'name' => $originalName,
        'type' => $downloaded['mime'],
        'tmp_name' => $tempFilePath,
        'error' => UPLOAD_ERR_OK,
        'size' => $downloaded['size'],
    ];

    uploadDebug('remote_fetch_success', ['size' => $downloaded['size'], 'type' => $downloaded['mime']]);
    
} elseif (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['image'];
} else {
    // Check if image was uploaded
    $errorMessages = [
        UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
    ];
    $error = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    jsonResponse(false, $errorMessages[$error] ?? 'Upload failed');
}

// Validate file type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $config['upload']['allowed_types'])) {
    jsonResponse(false, 'Invalid file type. Allowed: JPG, PNG, GIF, WebP');
}

// Validate file size
if ($file['size'] > $config['upload']['max_size']) {
    jsonResponse(false, 'File too large. Maximum size: 10 MB');
}

// Determine if uploader is guest (for priority queue)
$isGuest = empty($sessionUserId);

// SafeGuard AI Content Moderation Check
$safetyCheck = $safeGuard->analyzeImage($file['tmp_name']);
uploadDebug('safeguard_check', [
    'result' => $safetyCheck,
    'ip' => $clientIP,
    'user_id' => $sessionUserId,
    'is_guest' => $isGuest,
]);

if (!$safetyCheck['safe'] && empty($safetyCheck['skipped']) && empty($safetyCheck['queued'])) {
    // Content is IMMEDIATELY unsafe - block and quarantine
    $safeGuard->quarantine(
        'image',
        'upload_' . time() . '_' . mt_rand(1000, 9999),
        $file['tmp_name'],
        $safetyCheck['threat_type'],
        $safetyCheck['threat_details'] ?? null
    );
    
    // Log the abuse incident
    $abuseGuard->logAbuse($clientIP, 'suspicious_content', 'high', $sessionUserId, json_encode($safetyCheck));
    
    // Clean up temp file
    if ($tempFilePath && file_exists($tempFilePath)) {
        @unlink($tempFilePath);
    }
    
    jsonResponse(false, 'This image has been flagged by our AI safety system and cannot be uploaded. If you believe this is an error, please contact support.', 403);
}

// If rate limited or queued, we'll allow upload but queue for async verification
// This is the "approve first, verify later" approach
$pendingVerification = !empty($safetyCheck['queued']) || !empty($safetyCheck['rate_limited']);

// Check storage quota for logged-in users (session already started above).
// Limits come from Gatekeeper settings (single source of truth); the final
// enforcement is the atomic UPDATE below.
$uploadUserId = $sessionUserId;
$storageLimit = null;

if ($uploadUserId) {
    require_once __DIR__ . '/../includes/Database.php';
    $db = Database::getInstance();

    $userStmt = $db->prepare("SELECT storage_used, account_type FROM users WHERE id = ?");
    $userStmt->execute([$uploadUserId]);
    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

    if ($userInfo) {
        $storageUsed = (int)($userInfo['storage_used'] ?? 0);
        $isPremium = ($userInfo['account_type'] ?? 'free') === 'premium';
        $storageLimit = (int) ($isPremium
            ? $gatekeeper->getSetting('storage_limit_premium', 5368709120)
            : $gatekeeper->getSetting('storage_limit_free', 524288000));

        if (($storageUsed + $file['size']) > $storageLimit) {
            $usedMB = round($storageUsed / 1024 / 1024, 1);
            $limitMB = round($storageLimit / 1024 / 1024);
            jsonResponse(false, 'Storage quota exceeded. You are using ' . $usedMB . 'MB of ' . $limitMB . 'MB. ' . ($isPremium ? '' : 'Upgrade to Premium for 5GB storage!'));
        }
    }
}

// Check for duplicate image using file hash
$fileHash = hash_file('sha256', $file['tmp_name']);
$duplicateImage = findDuplicateImage($fileHash, $file['size'], $sessionUserId, $clientIP);

if ($duplicateImage) {
    // Build proxy URLs for duplicate response
    $dupProxyUrls = [];
    $dupS3Keys = $duplicateImage['s3_keys'] ?? [];
    foreach ($dupS3Keys as $sizeName => $key) {
        $dupProxyUrls[$sizeName] = $config['site']['url'] . '/i/' . $key;
    }
    // Fallback to stored urls if s3_keys not available
    if (empty($dupProxyUrls)) {
        $dupProxyUrls = $duplicateImage['urls'] ?? [];
    }

    // Clean up remote temp file before responding
    if ($tempFilePath && file_exists($tempFilePath)) {
        @unlink($tempFilePath);
    }

    jsonResponse(true, null, 200, [
        'id' => $duplicateImage['id'],
        'urls' => $dupProxyUrls,
        'view_url' => $config['site']['url'] . '/' . $duplicateImage['id'],
        'width' => $duplicateImage['width'],
        'height' => $duplicateImage['height'],
        'duplicate' => true,
        'message' => 'Image already exists'
    ]);
}

// Generate descriptive unique ID with filename slug
$imageId = generateId($file['name']);
$extension = getExtension($mimeType);
$timestamp = time();

// Validate image is actually readable before processing
// This catches corrupted files that pass MIME check but fail GD
// Returns 'gd', 'imagick', or false
$imageProcessor = validateImageFile($file['tmp_name'], $mimeType);
if (!$imageProcessor) {
    jsonResponse(false, 'Invalid or corrupted image file. Please try a different image.');
}

$useImagick = ($imageProcessor === 'imagick');

// Create temp directory for processing
$tempDir = sys_get_temp_dir() . '/pichost_' . $imageId;
if (!mkdir($tempDir, 0755, true)) {
    jsonResponse(false, 'Failed to create temp directory');
}

// Initialized before try so the catch block can safely test them even when
// an exception is thrown before storage upload begins (e.g. loadImage fails).
$storageManager = null;
$s3Keys = [];

try {

    $sourceImage = loadImage($file['tmp_name'], $mimeType, $useImagick);
    if (!$sourceImage) {
        throw new Exception('Failed to load image. The file may be corrupted.');
    }

    $originalWidth = imagesx($sourceImage);
    $originalHeight = imagesy($sourceImage);

    $sizes = [
        'original' => null,
        'large' => $config['image']['sizes']['large'],
        'medium' => $config['image']['sizes']['medium'],
        'thumb' => $config['image']['sizes']['thumb'],
    ];

    $uploadedUrls = [];
    $uploadedFiles = [];

    foreach ($sizes as $sizeName => $sizeConfig) {
        if ($sizeName === 'original') {

            $filename = "{$imageId}_original.{$extension}";
            $filepath = "{$tempDir}/{$filename}";

            // D6-10/D2-08: never store the raw upload as "original". Strip
            // EXIF/metadata by re-encoding and cap the max dimension to 9000px
            // to reject decompression bombs before pixel buffers are allocated.
            $dimInfo = @getimagesize($file['tmp_name']);
            if ($dimInfo === false || $dimInfo[0] <= 0 || $dimInfo[1] <= 0) {
                throw new Exception('Invalid image dimensions.');
            }
            if ($dimInfo[0] > 9000 || $dimInfo[1] > 9000) {
                throw new Exception('Image dimensions exceed the maximum allowed size of 9000px.');
            }

            $imagickUsed = false;
            if (extension_loaded('imagick')) {
                try {
                    $imagick = new Imagick();
                    $imagick->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
                    $imagick->setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
                    $imagick->setResourceLimit(Imagick::RESOURCETYPE_AREA, 128 * 1024 * 1024);
                    $imagick->readImage($file['tmp_name']);
                    $imagick->stripImage();
                    $imagick->setImageCompressionQuality($config['image']['quality'] ?? 95);
                    $imagick->writeImage($filepath);
                    $imagick->clear();
                    $imagick->destroy();
                    $imagickUsed = true;
                } catch (Exception $imagickException) {
                    Logger::error('upload', 'Original Imagick re-encode failed: ' . $imagickException->getMessage(), [
                        'exception' => get_class($imagickException),
                    ]);
                    if (isset($imagick)) {
                        $imagick->clear();
                        $imagick->destroy();
                    }
                    @unlink($filepath);
                }
            }

            if (!$imagickUsed) {
                // Fallback GD re-encode (quality 95).
                $gdImage = loadImage($file['tmp_name'], $mimeType, false);
                if (!$gdImage) {
                    throw new Exception('Failed to re-encode original image.');
                }
                $ok = saveImage($gdImage, $filepath, $mimeType, 95);
                imagedestroy($gdImage);
                if (!$ok) {
                    throw new Exception('Failed to write original image.');
                }
            }
        } else {

            $maxWidth = $sizeConfig['width'];
            $maxHeight = $sizeConfig['height'];

            // Cap variant dimensions to 9000px as well, so downstream
            // processing never allocates an oversized pixel buffer.
            $resizeNeeded = $originalWidth > $maxWidth || $originalHeight > $maxHeight;
            $capNeeded = $originalWidth > 9000 || $originalHeight > 9000;

            if ($resizeNeeded || $capNeeded) {
                $maxWidth = min($maxWidth, 9000);
                $maxHeight = min($maxHeight, 9000);
                $resizedImage = resizeImage($sourceImage, $originalWidth, $originalHeight, $maxWidth, $maxHeight);
                $filename = "{$imageId}_{$sizeName}.{$extension}";
                $filepath = "{$tempDir}/{$filename}";
                saveImage($resizedImage, $filepath, $mimeType, $config['image']['quality']);
                imagedestroy($resizedImage);
            } else {

                $filename = "{$imageId}_{$sizeName}.{$extension}";
                $filepath = "{$tempDir}/{$filename}";
                saveImage($sourceImage, $filepath, $mimeType, $config['image']['quality']);
            }
        }

        $uploadedFiles[$sizeName] = [
            'filename' => $filename,
            'filepath' => $filepath,
        ];
    }

    imagedestroy($sourceImage);

    // Initialize hybrid storage manager (R2 + Contabo)
    $storageManager = new R2StorageManager($config);
    
    $s3Keys = [];
    $storageProviders = []; // Track which provider stores each size
    
    foreach ($uploadedFiles as $sizeName => $fileInfo) {
        $s3Key = date('Y/m/d', $timestamp) . '/' . $fileInfo['filename'];
        
        // Use hybrid storage: thumb/medium → R2, original/large → Contabo
        $uploadResult = $storageManager->upload(
            $fileInfo['filepath'],
            $s3Key,
            $mimeType,
            $sizeName // 'original', 'large', 'medium', 'thumb'
        );

        if (!$uploadResult['success']) {
            throw new Exception("Failed to upload {$sizeName}: " . ($uploadResult['error'] ?? 'Unknown error'));
        }

        $uploadedUrls[$sizeName] = $uploadResult['url'];
        $s3Keys[$sizeName] = $s3Key;
        $storageProviders[$sizeName] = $uploadResult['provider']; // 'r2' or 'contabo'
    }

    $deleteAfter = $_POST['delete_after'] ?? 'never';
    $deleteAt = null;

    if ($deleteAfter !== 'never') {
        $deleteIntervals = [
            '1h' => 3600,
            '24h' => 86400,
            '7d' => 604800,
            '30d' => 2592000,
        ];

        if (isset($deleteIntervals[$deleteAfter])) {
            $deleteAt = $timestamp + $deleteIntervals[$deleteAfter];
        }
    }



    $imageData = [
        'id' => $imageId,
        'user_id' => $uploadUserId,
        'filename' => $file['name'],
        'mime_type' => $mimeType,
        'extension' => $extension,
        'size' => $file['size'],
        'width' => $originalWidth,
        'height' => $originalHeight,
        'hash' => $fileHash,
        'urls' => $uploadedUrls,
        's3_keys' => $s3Keys,
        'storage_providers' => $storageProviders, // Track R2 vs Contabo per size
        'created_at' => $timestamp,
        'delete_at' => $deleteAt,
        'ip' => $clientIP,
    ];

    saveImageData($imageId, $imageData);


    if ($uploadUserId) {
        $db = Database::getInstance();
        // Atomic quota enforcement (D2-06/D5-19): increment only when the
        // effective limit is not exceeded. 0 rows updated => quota exceeded.
        $stmt = $db->prepare(
            "UPDATE users SET storage_used = storage_used + ? WHERE id = ? AND storage_used + ? <= ?"
        );
        $stmt->execute([$file['size'], $uploadUserId, $file['size'], $storageLimit]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Storage quota exceeded');
        }
    }

    // Update global storage counter after a fully successful upload.
    $gatekeeper->updateGlobalStorage((int) $file['size']);

    // Record successful upload in AbuseGuard counters. Guarded so a counter
    // error never turns a successful upload into a failed response.
    try {
        $abuseGuard->recordUpload($clientIP, $sessionUserId, (int) $file['size']);
    } catch (Throwable $recordException) {
        Logger::error('upload', 'Abuse counter update failed: ' . $recordException->getMessage(), [
            'exception' => get_class($recordException),
        ]);
    }

    // Clean up remote temp file if any
    if ($tempFilePath && file_exists($tempFilePath)) {
        @unlink($tempFilePath);
    }

    cleanupTempDir($tempDir);

    // Build proxy URLs for response (use site domain instead of raw S3)
    $proxyUrls = [];
    foreach ($s3Keys as $sizeName => $key) {
        $proxyUrls[$sizeName] = $config['site']['url'] . '/i/' . $key;
    }

    // Queue for async verification if initial scan was rate limited/skipped
    // Content is accessible immediately, but will be auto-takedown if flagged later
    if ($pendingVerification) {
        $safeGuard->queueForAsyncModeration(
            'image',
            $imageId,
            $proxyUrls['original'] ?? ($config['site']['url'] . '/' . $imageId),
            $isGuest,
            $sessionUserId
        );
    }

    jsonResponse(true, null, 200, [
        'id' => $imageId,
        'filename' => $file['name'],
        'extension' => $extension,
        'size' => $file['size'],
        'urls' => $proxyUrls,
        'view_url' => $config['site']['url'] . '/' . $imageId,
        'width' => $originalWidth,
        'height' => $originalHeight,
        'pending_verification' => $pendingVerification ?? false,
    ]);

} catch (Exception $e) {

    Logger::error('upload', 'Upload failed: ' . $e->getMessage(), [
        'exception' => get_class($e),
        'ip' => $clientIP,
    ]);

    // Compensate: delete S3 variants that were already uploaded for this
    // image before the failure (quota exceeded, DB/JSON failure, etc).
    if ($storageManager instanceof R2StorageManager && !empty($s3Keys)) {
        try {
            $storageManager->deleteImage($s3Keys, (int) $file['size']);
        } catch (Exception $deleteException) {
            Logger::error('upload', 'Compensation delete failed: ' . $deleteException->getMessage(), [
                'exception' => get_class($deleteException),
            ]);
        }
    }

    // Clean up remote temp file if any
    if (isset($tempFilePath) && $tempFilePath && file_exists($tempFilePath)) {
        @unlink($tempFilePath);
    }

    cleanupTempDir($tempDir);
    jsonResponse(false, 'Upload failed. Please try again.');
}

/**
 * Generate unique short ID (for fallback or internal use)
 */
function generateShortId($length = 6) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

/**
 * Slugify filename to URL-safe string
 * "rumah baru.png" -> "rumah-baru"
 * 
 * Strategy:
 * - Short names (≤15 chars): use full name
 * - Long names (>15 chars): truncate to ~15 chars, try to cut at word boundary
 */
function slugifyFilename($filename) {
    // Remove file extension
    $name = pathinfo($filename, PATHINFO_FILENAME);
    
    // Convert to lowercase
    $slug = mb_strtolower($name, 'UTF-8');
    
    // Replace common characters with dash
    $slug = str_replace(['_', '+', '(', ')', '[', ']', '{', '}', '@', '#', '$', '%', '&', '*', '!', '.'], '-', $slug);
    
    // Replace spaces and multiple dashes with single dash
    $slug = preg_replace('/[\s]+/', '-', $slug);
    
    // Remove any character that is not alphanumeric or dash
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    
    // Remove multiple consecutive dashes
    $slug = preg_replace('/-+/', '-', $slug);
    
    // Trim dashes from beginning and end
    $slug = trim($slug, '-');
    
    // If slug is empty, return empty
    if (empty($slug)) {
        return '';
    }
    
    // For short names (≤15 chars), use full name
    // For longer names, truncate intelligently
    $maxLength = 15;
    
    if (strlen($slug) > $maxLength) {
        // Try to cut at a word boundary (dash)
        $truncated = substr($slug, 0, $maxLength);
        
        // Find last dash position
        $lastDash = strrpos($truncated, '-');
        
        // If there's a dash in the last 5 characters, cut there for cleaner URL
        if ($lastDash !== false && $lastDash >= ($maxLength - 5)) {
            $slug = substr($slug, 0, $lastDash);
        } else {
            // Otherwise just truncate
            $slug = rtrim($truncated, '-');
        }
    }
    
    return $slug;
}

/**
 * Generate descriptive unique ID combining filename slug + unique code
 * Format: {filename-slug}_{short-unique-code}
 * Example: "rumah-baru_a3x9K2"
 */
function generateId($filename = null) {
    // D5-18: 10-char unique code with JsonStore collision checks (max 5 tries).
    $store = new JsonStore(__DIR__ . '/../data/images.json');

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $uniqueCode = generateShortId(10);

        if ($filename) {
            $slug = slugifyFilename($filename);
            if (!empty($slug)) {
                $imageId = $slug . '_' . $uniqueCode;
            } else {
                $imageId = $uniqueCode;
            }
        } else {
            $imageId = $uniqueCode;
        }

        $existing = $store->read();
        if (!array_key_exists($imageId, $existing)) {
            return $imageId;
        }
    }

    // Last-ditch fallback: random_bytes hex is overwhelmingly collision-free.
    return (is_string($filename) && ($slug = slugifyFilename($filename)) !== '')
        ? $slug . '_' . bin2hex(random_bytes(8))
        : bin2hex(random_bytes(8));
}

/**
 * Get file extension from mime type
 */
function getExtension($mimeType) {
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    return $map[$mimeType] ?? 'jpg';
}

/**
 * Validate image file is readable by GD before processing
 * This catches corrupted files that pass MIME type check
 */
function validateImageFile($filepath, $mimeType) {

    $imageInfo = @getimagesize($filepath);
    if ($imageInfo === false) {
        return false;
    }


    if ($imageInfo[0] <= 0 || $imageInfo[1] <= 0) {
        return false;
    }


    $testImage = null;
    switch ($mimeType) {
        case 'image/jpeg':
            $testImage = @imagecreatefromjpeg($filepath);
            break;
        case 'image/png':
            $testImage = @imagecreatefrompng($filepath);
            break;
        case 'image/gif':
            $testImage = @imagecreatefromgif($filepath);
            break;
        case 'image/webp':
            $testImage = @imagecreatefromwebp($filepath);
            break;
    }

    if ($testImage === false || $testImage === null) {

        if (extension_loaded('imagick')) {
            try {
                $imagick = new Imagick($filepath);
                $imagick->clear();
                $imagick->destroy();
                return 'imagick';
            } catch (Exception $e) {
                return false;
            }
        }
        return false;
    }


    imagedestroy($testImage);
    return 'gd';
}

/**
 * Load image from file - with Imagick fallback
 */
function loadImage($filepath, $mimeType, $useImagick = false) {
    if ($useImagick && extension_loaded('imagick')) {
        try {
            $imagick = new Imagick($filepath);

            $imagick->setImageFormat('png');
            $blob = $imagick->getImageBlob();
            $gdImage = imagecreatefromstring($blob);
            $imagick->clear();
            $imagick->destroy();
            return $gdImage;
        } catch (Exception $e) {
            Logger::error('upload', 'Imagick load failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
            ]);
            return false;
        }
    }

    switch ($mimeType) {
        case 'image/jpeg':
            return @imagecreatefromjpeg($filepath);
        case 'image/png':
            return @imagecreatefrompng($filepath);
        case 'image/gif':
            return @imagecreatefromgif($filepath);
        case 'image/webp':
            return @imagecreatefromwebp($filepath);
        default:
            return false;
    }
}

/**
 * Resize image maintaining aspect ratio
 */
function resizeImage($source, $srcWidth, $srcHeight, $maxWidth, $maxHeight) {

    $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
    $newWidth = (int) ($srcWidth * $ratio);
    $newHeight = (int) ($srcHeight * $ratio);

    $dest = imagecreatetruecolor($newWidth, $newHeight);

    imagealphablending($dest, false);
    imagesavealpha($dest, true);
    $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
    imagefilledrectangle($dest, 0, 0, $newWidth, $newHeight, $transparent);

    imagecopyresampled($dest, $source, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);

    return $dest;
}

/**
 * Save image to file
 */
function saveImage($image, $filepath, $mimeType, $quality) {
    switch ($mimeType) {
        case 'image/jpeg':
            return imagejpeg($image, $filepath, $quality);
        case 'image/png':
            return imagepng($image, $filepath, 9 - (int)($quality / 11));
        case 'image/gif':
            return imagegif($image, $filepath);
        case 'image/webp':
            return imagewebp($image, $filepath, $quality);
        default:
            return false;
    }
}

/**
 * Save image data to JSON database
 */
function saveImageData($imageId, $data) {
    $store = new JsonStore(__DIR__ . '/../data/images.json');
    $store->mutate(function (array $images) use ($imageId, $data): array {
        $images[$imageId] = $data;
        return $images;
    });
}

/**
 * Clean up temp directory
 */
function cleanupTempDir($dir) {
    if (!is_dir($dir)) return;

    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $path = $dir . '/' . $file;
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($dir);
}

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

/**
 * Find duplicate image by hash and size.
 *
 * D2-04: duplicates are only deduplicated within the same owner scope:
 * - logged-in users only match their own previous uploads
 * - guests only match previous guest uploads from the same IP
 * Images owned by someone else are never reused.
 */
function findDuplicateImage($hash, $size, $sessionUserId, $clientIP) {
    $store = new JsonStore(__DIR__ . '/../data/images.json');
    $images = $store->read();

    foreach ($images as $imageData) {

        if (!empty($imageData['delete_at'])) {
            continue;
        }

        $recordUserId = isset($imageData['user_id']) ? (int) $imageData['user_id'] : 0;

        if ($sessionUserId) {
            if ($recordUserId !== (int) $sessionUserId) {
                continue;
            }
        } else {
            // Both must be guest uploads and the recorded IP must match.
            if ($recordUserId !== 0) {
                continue;
            }
            if (($imageData['ip'] ?? '') !== $clientIP) {
                continue;
            }
        }

        if (!empty($imageData['hash']) && $imageData['hash'] === $hash) {
            return $imageData;
        }
    }

    return null;
}
