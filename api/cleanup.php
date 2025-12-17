<?php
/**
 * PixelHop - Scheduled Image Cleanup
 *
 * Run this via cron every 5 minutes:
 * crontab example: 0,5,10,15,20,25,30,35,40,45,50,55 * * * * php /path/to/cleanup.php
 */

// Prevent web access
if (php_sapi_name() !== 'cli' && !isset($_GET['cron_key'])) {
    http_response_code(403);
    die('CLI only');
}

// Optional: Add a secret key for HTTP access
$cronKey = $_GET['cron_key'] ?? '';
if (php_sapi_name() !== 'cli' && $cronKey !== 'your-secret-cron-key-here') {
    http_response_code(403);
    die('Invalid cron key');
}

// Load config
$config = require __DIR__ . '/../config/s3.php';

// Load image data
$dataFile = __DIR__ . '/../data/images.json';

if (!file_exists($dataFile)) {
    echo "No images file found.\n";
    exit(0);
}

$images = json_decode(file_get_contents($dataFile), true) ?: [];
$currentTime = time();
$deletedCount = 0;
$checkedCount = 0;

foreach ($images as $imageId => $imageData) {
    $checkedCount++;


    if (!empty($imageData['delete_at']) && $imageData['delete_at'] <= $currentTime) {
        echo "Deleting image {$imageId} (scheduled for " . date('Y-m-d H:i:s', $imageData['delete_at']) . ")...\n";


        if (!empty($imageData['s3_keys'])) {
            foreach ($imageData['s3_keys'] as $sizeName => $s3Key) {
                $deleteResult = deleteFromS3($s3Key, $config['s3']);
                if ($deleteResult) {
                    echo "  - Deleted S3: {$s3Key}\n";
                } else {
                    echo "  - Failed to delete S3: {$s3Key}\n";
                }
            }
        }


        unset($images[$imageId]);
        $deletedCount++;
    }
}

// Save updated images
if ($deletedCount > 0) {
    file_put_contents($dataFile, json_encode($images, JSON_PRETTY_PRINT));
    echo "\nCleanup complete: {$deletedCount} images deleted, {$checkedCount} checked.\n";
} else {
    echo "No images to delete. Checked {$checkedCount} images.\n";
}

/**
 * Delete file from S3 using AWS Signature V4
 */
function deleteFromS3($key, $s3Config) {
    $endpoint = $s3Config['endpoint'];
    $bucket = $s3Config['bucket'];
    $accessKey = $s3Config['access_key'];
    $secretKey = $s3Config['secret_key'];
    $region = $s3Config['region'];

    $parsedUrl = parse_url($endpoint);
    $host = $parsedUrl['host'];

    $url = "{$endpoint}/{$bucket}/{$key}";

    $longDate = gmdate('Ymd\THis\Z');
    $shortDate = gmdate('Ymd');

    $payloadHash = hash('sha256', '');

    $canonicalUri = '/' . $bucket . '/' . str_replace('%2F', '/', rawurlencode($key));

    $headers = [
        'host' => $host,
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

    $canonicalRequest = "DELETE\n" .
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
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$authorization}",
            "Host: {$host}",
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$longDate}",
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        error_log("S3 delete error: {$error}");
        return false;
    }

    return ($httpCode >= 200 && $httpCode < 300);
}
