<?php
/**
 * PixelHop - Orphaned Files Cleanup
 * Finds and optionally deletes files in S3/R2 that are not in images.json
 * 
 * Usage:
 *   php cleanup-orphans.php          - Dry run (list only)
 *   php cleanup-orphans.php --delete - Actually delete orphaned files
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// CLI only
if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line');
}

require_once __DIR__ . '/../includes/R2StorageManager.php';
require_once __DIR__ . '/../includes/ImageRepository.php';

$config = require __DIR__ . '/../config/s3.php';

$dryRun = !in_array('--delete', $argv);

echo "=== PixelHop Orphan Cleanup ===\n";
echo "Mode: " . ($dryRun ? "DRY RUN (use --delete to actually delete)" : "DELETE MODE") . "\n\n";

// Load images metadata via ImageRepository (single access point)
$imageRepo = new ImageRepository();
$images = $imageRepo->readAll();
echo "Loaded " . count($images) . " images from database\n";

// Build set of valid S3 keys
$validKeys = [];
foreach ($images as $id => $img) {
    if (!empty($img['s3_keys'])) {
        foreach ($img['s3_keys'] as $variant => $key) {
            $validKeys[$key] = true;
        }
    }
}
echo "Found " . count($validKeys) . " valid S3 keys\n\n";

// List objects from Contabo S3
function listS3Objects($config, $provider = 'contabo') {
    $endpoint = $config['endpoint'];
    $bucket = $config['bucket'];
    $accessKey = $config['access_key'];
    $secretKey = $config['secret_key'];
    $region = $config['region'] ?? 'default';
    
    $host = parse_url($endpoint, PHP_URL_HOST);
    $url = "{$endpoint}/{$bucket}?list-type=2&max-keys=1000";
    
    $now = new DateTime('UTC');
    $longDate = $now->format('Ymd\THis\Z');
    $shortDate = $now->format('Ymd');
    
    $payloadHash = hash('sha256', '');
    $canonicalUri = '/' . $bucket;
    $canonicalQueryString = 'list-type=2&max-keys=1000';
    
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
    
    $canonicalRequest = "GET\n" .
        $canonicalUri . "\n" .
        $canonicalQueryString . "\n" .
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
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$authorization}",
            "Host: {$host}",
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$longDate}",
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    if ($error || $httpCode !== 200) {
        echo "Error listing $provider: $error (HTTP $httpCode)\n";
        return [];
    }
    
    // Parse XML response using regex (SimpleXML may not be available)
    $objects = [];
    
    // Try SimpleXML first
    if (function_exists('simplexml_load_string')) {
        $xml = @simplexml_load_string($response);
        if ($xml && isset($xml->Contents)) {
            foreach ($xml->Contents as $obj) {
                $objects[] = [
                    'key' => (string)$obj->Key,
                    'size' => (int)$obj->Size,
                    'modified' => (string)$obj->LastModified,
                ];
            }
        }
    } else {
        // Fallback: parse with regex
        preg_match_all('/<Contents>.*?<Key>(.*?)<\/Key>.*?<Size>(\d+)<\/Size>.*?<\/Contents>/s', $response, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $objects[] = [
                'key' => $match[1],
                'size' => (int)$match[2],
                'modified' => '',
            ];
        }
    }
    
    return $objects;
}

// Check Contabo
echo "Scanning Contabo S3...\n";
$contaboObjects = listS3Objects($config['s3'], 'contabo');
echo "Found " . count($contaboObjects) . " objects in Contabo\n";

$orphanedContabo = [];
$orphanedSize = 0;
foreach ($contaboObjects as $obj) {
    if (!isset($validKeys[$obj['key']])) {
        $orphanedContabo[] = $obj;
        $orphanedSize += $obj['size'];
    }
}

echo "Orphaned in Contabo: " . count($orphanedContabo) . " files (" . formatBytes($orphanedSize) . ")\n\n";

// Check R2 if enabled
$orphanedR2 = [];
$orphanedR2Size = 0;
if (!empty($config['r2']['enabled']) && !empty($config['r2']['access_key'])) {
    echo "Scanning Cloudflare R2...\n";
    $r2Config = [
        'endpoint' => $config['r2']['endpoint'],
        'bucket' => $config['r2']['bucket'],
        'access_key' => $config['r2']['access_key'],
        'secret_key' => $config['r2']['secret_key'],
        'region' => $config['r2']['region'] ?? 'auto',
    ];
    $r2Objects = listS3Objects($r2Config, 'r2');
    echo "Found " . count($r2Objects) . " objects in R2\n";
    
    foreach ($r2Objects as $obj) {
        if (!isset($validKeys[$obj['key']])) {
            $orphanedR2[] = $obj;
            $orphanedR2Size += $obj['size'];
        }
    }
    echo "Orphaned in R2: " . count($orphanedR2) . " files (" . formatBytes($orphanedR2Size) . ")\n\n";
}

// Summary
$totalOrphaned = count($orphanedContabo) + count($orphanedR2);
$totalSize = $orphanedSize + $orphanedR2Size;
echo "=== SUMMARY ===\n";
echo "Total orphaned files: $totalOrphaned\n";
echo "Total size to reclaim: " . formatBytes($totalSize) . "\n\n";

if ($totalOrphaned === 0) {
    echo "No orphaned files found. Storage is clean!\n";
    exit(0);
}

// List orphaned files (first 20)
if (count($orphanedContabo) > 0) {
    echo "Orphaned Contabo files (showing first 20):\n";
    foreach (array_slice($orphanedContabo, 0, 20) as $obj) {
        echo "  - {$obj['key']} (" . formatBytes($obj['size']) . ")\n";
    }
    if (count($orphanedContabo) > 20) {
        echo "  ... and " . (count($orphanedContabo) - 20) . " more\n";
    }
    echo "\n";
}

if (count($orphanedR2) > 0) {
    echo "Orphaned R2 files (showing first 20):\n";
    foreach (array_slice($orphanedR2, 0, 20) as $obj) {
        echo "  - {$obj['key']} (" . formatBytes($obj['size']) . ")\n";
    }
    if (count($orphanedR2) > 20) {
        echo "  ... and " . (count($orphanedR2) - 20) . " more\n";
    }
    echo "\n";
}

// Delete if not dry run
if (!$dryRun) {
    echo "Deleting orphaned files...\n";
    
    $storageManager = new R2StorageManager($config);
    $deletedCount = 0;
    $failedCount = 0;
    
    // Delete from Contabo (use direct method, not auto-detect)
    foreach ($orphanedContabo as $obj) {
        $result = $storageManager->deleteFromContabo($obj['key']);
        if ($result['success']) {
            $deletedCount++;
            echo ".";
        } else {
            $failedCount++;
            echo "x";
        }
        if (($deletedCount + $failedCount) % 50 === 0) {
            echo " " . ($deletedCount + $failedCount) . "/" . count($orphanedContabo) . "\n";
        }
    }
    
    // Delete from R2 (use direct method, not auto-detect)
    foreach ($orphanedR2 as $obj) {
        $result = $storageManager->deleteFromR2($obj['key']);
        if ($result['success']) {
            $deletedCount++;
            echo ".";
        } else {
            $failedCount++;
            echo "x";
        }
    }
    
    echo "\n\nDeletion complete!\n";
    echo "Deleted: $deletedCount\n";
    echo "Failed: $failedCount\n";
    echo "Space reclaimed: " . formatBytes($totalSize) . "\n";
} else {
    echo "=== DRY RUN - No files were deleted ===\n";
    echo "Run with --delete flag to actually delete orphaned files:\n";
    echo "  php cleanup-orphans.php --delete\n";
}

function formatBytes($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}
