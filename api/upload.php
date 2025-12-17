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
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed', 405);
}

// Load config
$config = require __DIR__ . '/../config/s3.php';

// AbuseGuard check - before processing upload
require_once __DIR__ . '/../core/AbuseGuard.php';
$abuseGuard = new AbuseGuard();
$clientIP = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
// Handle comma-separated IPs from X-Forwarded-For
if (strpos($clientIP, ',') !== false) {
    $clientIP = trim(explode(',', $clientIP)[0]);
}

session_start();
$sessionUserId = $_SESSION['user_id'] ?? null;
$fileSize = $_FILES['image']['size'] ?? 0;

$abuseCheck = $abuseGuard->checkUpload($clientIP, $sessionUserId, $fileSize);
if (!$abuseCheck['allowed']) {
    $httpCode = ($abuseCheck['code'] ?? '') === 'rate_limit' ? 429 : 403;
    jsonResponse(false, $abuseCheck['reason'], $httpCode);
}

// Check if image was uploaded
if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
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

$file = $_FILES['image'];

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

// Check storage quota for logged-in users (session already started above)
$uploadUserId = $sessionUserId;

if ($uploadUserId) {
    require_once __DIR__ . '/../includes/Database.php';
    $db = Database::getInstance();


    $userStmt = $db->prepare("SELECT storage_used, account_type FROM users WHERE id = ?");
    $userStmt->execute([$uploadUserId]);
    $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

    if ($userInfo) {
        $storageUsed = (int)($userInfo['storage_used'] ?? 0);
        $isPremium = ($userInfo['account_type'] ?? 'free') === 'premium';
        $storageLimit = $isPremium ? (5 * 1024 * 1024 * 1024) : (500 * 1024 * 1024);


        if (($storageUsed + $file['size']) > $storageLimit) {
            $usedMB = round($storageUsed / 1024 / 1024, 1);
            $limitMB = round($storageLimit / 1024 / 1024);
            jsonResponse(false, 'Storage quota exceeded. You are using ' . $usedMB . 'MB of ' . $limitMB . 'MB. ' . ($isPremium ? '' : 'Upgrade to Premium for 5GB storage!'));
        }
    }
}

// Check for duplicate image using file hash
$fileHash = hash_file('sha256', $file['tmp_name']);
$duplicateImage = findDuplicateImage($fileHash, $file['size']);

if ($duplicateImage) {

    jsonResponse(true, null, 200, [
        'id' => $duplicateImage['id'],
        'urls' => $duplicateImage['urls'],
        'view_url' => $config['site']['url'] . '/' . $duplicateImage['id'],
        'width' => $duplicateImage['width'],
        'height' => $duplicateImage['height'],
        'duplicate' => true,
        'message' => 'Image already exists'
    ]);
}

// Generate unique ID
$imageId = generateId();
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
            copy($file['tmp_name'], $filepath);
        } else {

            $maxWidth = $sizeConfig['width'];
            $maxHeight = $sizeConfig['height'];


            if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
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

    $s3Keys = [];
    foreach ($uploadedFiles as $sizeName => $fileInfo) {
        $s3Key = date('Y/m/d', $timestamp) . '/' . $fileInfo['filename'];
        $uploadResult = uploadToS3(
            $fileInfo['filepath'],
            $s3Key,
            $mimeType,
            $config['s3']
        );

        if (!$uploadResult) {
            throw new Exception("Failed to upload {$sizeName} to S3");
        }

        $uploadedUrls[$sizeName] = $config['site']['url'] . '/i/' . $s3Key;
        $s3Keys[$sizeName] = $s3Key;
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
        'created_at' => $timestamp,
        'delete_at' => $deleteAt,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ];

    saveImageData($imageId, $imageData);


    if ($uploadUserId) {
        try {
            require_once __DIR__ . '/../includes/Database.php';
            $db = Database::getInstance();
            $stmt = $db->prepare("UPDATE users SET storage_used = storage_used + ? WHERE id = ?");
            $stmt->execute([$file['size'], $uploadUserId]);
        } catch (Exception $e) {

            error_log('Failed to update storage usage: ' . $e->getMessage());
        }
    }

    cleanupTempDir($tempDir);

    jsonResponse(true, null, 200, [
        'id' => $imageId,
        'urls' => $uploadedUrls,
        'view_url' => $config['site']['url'] . '/' . $imageId,
        'width' => $originalWidth,
        'height' => $originalHeight,
    ]);

} catch (Exception $e) {

    cleanupTempDir($tempDir);
    jsonResponse(false, $e->getMessage());
}

/**
 * Generate unique short ID
 */
function generateId($length = 6) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = '';
    for ($i = 0; $i < $length; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
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
            error_log("Imagick load failed: " . $e->getMessage());
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
 * Upload file to S3 using AWS Signature V4 with retry logic
 */
function uploadToS3($filepath, $key, $contentType, $s3Config, $maxRetries = 3) {

    if (!file_exists($filepath)) {
        error_log("S3 upload: File not found: {$filepath}");
        return false;
    }

    $endpoint = $s3Config['endpoint'];
    $bucket = $s3Config['bucket'];
    $accessKey = $s3Config['access_key'];
    $secretKey = $s3Config['secret_key'];
    $region = $s3Config['region'];

    $fileContent = file_get_contents($filepath);


    $lastError = '';
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $result = _doS3Upload($endpoint, $bucket, $key, $accessKey, $secretKey, $region, $fileContent, $contentType);

        if ($result['success']) {
            return true;
        }

        $lastError = $result['error'];
        $httpCode = $result['http_code'];


        if ($httpCode >= 500 && $httpCode < 600 && $attempt < $maxRetries) {

            $sleepTime = pow(2, $attempt - 1);
            error_log("S3 upload attempt {$attempt} failed with HTTP {$httpCode}, retrying in {$sleepTime}s...");
            sleep($sleepTime);
            continue;
        }


        break;
    }

    error_log("S3 upload failed after {$attempt} attempts: {$lastError}");
    return false;
}

/**
 * Perform actual S3 upload request
 */
function _doS3Upload($endpoint, $bucket, $key, $accessKey, $secretKey, $region, $fileContent, $contentType) {
    $contentLength = strlen($fileContent);
    $payloadHash = hash('sha256', $fileContent);

    $parsedUrl = parse_url($endpoint);
    $host = $parsedUrl['host'];

    $url = "{$endpoint}/{$bucket}/{$key}";

    $longDate = gmdate('Ymd\THis\Z');
    $shortDate = gmdate('Ymd');

    $canonicalUri = '/' . $bucket . '/' . str_replace('%2F', '/', rawurlencode($key));

    $headers = [
        'content-length' => $contentLength,
        'content-type' => $contentType,
        'host' => $host,
        'x-amz-acl' => 'public-read',
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $longDate,
    ];

    ksort($headers);
    $canonicalHeaders = '';
    $signedHeaders = [];
    foreach ($headers as $k => $v) {
        $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
        $signedHeaders[] = strtolower($k);
    }
    $signedHeadersStr = implode(';', $signedHeaders);

    $canonicalRequest = "PUT\n" .
        $canonicalUri . "\n" .
        "\n" .
        $canonicalHeaders . "\n" .
        $signedHeadersStr . "\n" .
        $payloadHash;

    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = "{$shortDate}/{$region}/s3/aws4_request";
    $stringToSign = "{$algorithm}\n{$longDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

    $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authorization = "{$algorithm} Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeadersStr}, Signature={$signature}";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $fileContent,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$authorization}",
            "Content-Type: {$contentType}",
            "Content-Length: {$contentLength}",
            "Host: {$host}",
            "x-amz-acl: public-read",
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$longDate}",
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return [
            'success' => false,
            'error' => "CURL error: {$error}",
            'http_code' => 0
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'error' => "HTTP {$httpCode}: {$response}",
            'http_code' => $httpCode
        ];
    }

    return [
        'success' => true,
        'error' => null,
        'http_code' => $httpCode
    ];
}

/**
 * Save image data to JSON database
 */
function saveImageData($imageId, $data) {
    $dataDir = __DIR__ . '/../data';
    $dataFile = $dataDir . '/images.json';

    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    $images = [];
    if (file_exists($dataFile)) {
        $content = file_get_contents($dataFile);
        $images = json_decode($content, true) ?: [];
    }

    $images[$imageId] = $data;

    file_put_contents($dataFile, json_encode($images, JSON_PRETTY_PRINT));
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
 * Find duplicate image by hash and size
 */
function findDuplicateImage($hash, $size) {
    $dataFile = __DIR__ . '/../data/images.json';

    if (!file_exists($dataFile)) {
        return null;
    }

    $images = json_decode(file_get_contents($dataFile), true) ?: [];

    foreach ($images as $imageId => $imageData) {

        if (!empty($imageData['delete_at'])) {
            continue;
        }


        if (!empty($imageData['hash']) && $imageData['hash'] === $hash) {
            return $imageData;
        }


        if (empty($imageData['hash']) && isset($imageData['size']) && $imageData['size'] === $size) {

        }
    }

    return null;
}
