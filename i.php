<?php
/**
 * PixelHop - S3 Image Proxy
 * Proxies image requests to S3 with caching headers
 */

// Load config
$config = require __DIR__ . '/config/s3.php';

// Get the image path from URL
$requestUri = $_SERVER['REQUEST_URI'];

// Remove /i/ prefix
$imagePath = preg_replace('#^/i/#', '', $requestUri);

// Security: validate path format (only allow safe characters)
if (!preg_match('#^[\d]{4}/[\d]{2}/[\d]{2}/[a-zA-Z0-9_]+\.(jpg|jpeg|png|gif|webp)$#', $imagePath)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Image not found';
    exit;
}

// Build S3 URL using the public_url (includes tenant ID)
$s3Url = $config['s3']['public_url'] . '/' . $imagePath;

// Initialize cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $s3Url,
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
curl_close($ch);

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
if (isset($_SERVER['HTTP_RANGE'])) {
    preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $rangeMatches);
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
