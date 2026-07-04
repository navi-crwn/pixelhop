<?php
/**
 * PixelHop - Hybrid Storage Image Proxy
 * Proxies image requests to R2 (thumb/medium) or Contabo S3 (original/large)
 */

// Load config
$config = require __DIR__ . '/config/s3.php';

// Get the image path from URL
$requestUri = $_SERVER['REQUEST_URI'];

// Remove /i/ prefix
$imagePath = preg_replace('#^/i/#', '', $requestUri);

// Security: validate path format (only allow safe characters)
// Format: YYYY/MM/DD/imageId_size.ext where size can be original, large, medium, thumb
if (!preg_match('#^[\d]{4}/[\d]{2}/[\d]{2}/([a-zA-Z0-9]+)_(original|large|medium|thumb)\.(jpg|jpeg|png|gif|webp)$#', $imagePath, $matches)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Image not found';
    exit;
}

$sizeType = $matches[2]; // original, large, medium, or thumb

// Determine which storage to use based on size type
// Hybrid strategy: thumb/medium → R2, original/large → Contabo
if (in_array($sizeType, ['thumb', 'medium']) && !empty($config['r2']['enabled'])) {
    // Use Cloudflare R2 for thumbnails and medium
    $storageUrl = $config['r2']['public_url'] . '/' . $imagePath;
} else {
    // Use Contabo S3 for original and large
    $storageUrl = $config['s3']['public_url'] . '/' . $imagePath;
}

// Initialize cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $storageUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$error = curl_error($ch);

// Handle errors
if ($error || $httpCode !== 200) {
    http_response_code($httpCode ?: 500);
    header('Content-Type: text/plain');
    echo 'Image not found or unavailable';
    exit;
}

// Split headers and body
$headers = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);

// Get content type from response headers
preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $matches);
$contentType = $matches[1] ?? 'image/jpeg';

// Get content length
$contentLength = strlen($body);

// Send headers
http_response_code(200);
header('Content-Type: ' . $contentType);
header('Content-Length: ' . $contentLength);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');

// Handle range requests for partial content
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $rangeMatches)) {
    $start = intval($rangeMatches[1]);
    $end = $rangeMatches[2] !== '' ? intval($rangeMatches[2]) : $contentLength - 1;

    if ($start >= $contentLength || $end >= $contentLength || $start > $end) {
        http_response_code(416);
        header('Content-Range: bytes */' . $contentLength);
        exit;
    }

    $length = $end - $start + 1;
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $contentLength);
    header('Content-Length: ' . $length);
    echo substr($body, $start, $length);
} else {

    echo $body;
}
