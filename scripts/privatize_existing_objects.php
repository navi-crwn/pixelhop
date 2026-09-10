<?php
/**
 * PixelHop - Privatize Existing S3/R2 Objects (one-time remediation CLI)
 *
 * Audit D2-12: objects were previously uploaded with a public-read ACL.
 * New uploads are now private by default, but objects uploaded before the
 * fix remain public until their ACL is changed. This script walks
 * data/images.json and sends a signed PUT ACL update (private) for every
 * stored s3_keys entry.
 *
 * Idempotent + resumable:
 * - data/privatize_log.json records every key already processed.
 * - On re-run, keys present in the log are skipped, so the script can be
 *   interrupted and resumed safely.
 *
 * Usage:
 *   php scripts/privatize_existing_objects.php --limit=100 --offset=0 --dry-run
 *
 * Options:
 *   --limit=N   Max objects to process in this run (default 100)
 *   --offset=N  Skip the first N eligible images (default 0)
 *   --dry-run   Count and print what would be changed, do not send anything
 *
 * CLI only.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("CLI only\n");
}

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/includes/JsonStore.php';

$options = getopt('', ['limit:', 'offset:', 'dry-run']);
$limit = max(1, (int)($options['limit'] ?? 100));
$offset = max(0, (int)($options['offset'] ?? 0));
$dryRun = array_key_exists('dry-run', $options);

$configFile = ROOT_PATH . '/config/s3.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "Error: config/s3.php not found\n");
    exit(1);
}

$config = require $configFile;

require_once ROOT_PATH . '/includes/R2StorageManager.php';

// Static helpers (setObjectPrivate) need the config registered in the class.
new R2StorageManager($config);

$imagesStore = new JsonStore(ROOT_PATH . '/data/images.json');
$logStore = new JsonStore(ROOT_PATH . '/data/privatize_log.json');

$images = $imagesStore->read();
if (empty($images)) {
    echo "No images found in data/images.json\n";
    exit(0);
}

/**
 * Read processed keys from the resume log.
 *
 * Supports both a simple list of keys and a map of key => processed entry.
 *
 * @return array<string,bool>
 */
function readProcessedKeys(JsonStore $logStore): array
{
    $log = $logStore->read();
    $processed = [];

    foreach ($log as $k => $v) {
        // Metadata keys such as "_last_run" start with "_" and are not S3 keys.
        if (is_string($k) && !str_starts_with($k, '_')) {
            // {"key": {...}} or {"key": true} shape
            $processed[$k] = true;
        } elseif (is_string($v)) {
            // ["key1", "key2"] shape
            $processed[$v] = true;
        }
    }

    return $processed;
}

$processed = readProcessedKeys($logStore);

// Flatten eligible keys while preserving image ownership and offset ordering.
$eligible = [];
foreach ($images as $imageId => $image) {
    $s3Keys = $image['s3_keys'] ?? [];
    if (!is_array($s3Keys) || empty($s3Keys)) {
        continue;
    }

    foreach ($s3Keys as $variant => $key) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (isset($processed[$key])) {
            continue;
        }

        $provider = $image['storage_providers'][$variant]
            ?? (preg_match('/_(thumb|medium)\.[a-z0-9]+$/i', $key)
                ? 'r2'
                : 'contabo');

        $eligible[] = [
            'image_id' => $imageId,
            'variant' => $variant,
            'key' => $key,
            'provider' => $provider,
        ];
    }
}

$totalEligible = count($eligible);
$batch = array_slice($eligible, $offset, $limit);

if ($dryRun) {
    echo "DRY RUN: would process " . count($batch)
        . " of {$totalEligible} eligible object(s)\n";
    echo "Offset: {$offset}, Limit: {$limit}\n\n";

    foreach ($batch as $item) {
        printf(
            "  [DRY] %-10s %-8s %s\n",
            $item['provider'],
            $item['variant'],
            $item['key']
        );
    }

    exit(0);
}

$success = 0;
$failed = 0;
$skipped = 0;
$failures = [];

foreach ($batch as $item) {
    $key = $item['key'];
    $provider = $item['provider'];

    // Double-check against the live log so a concurrent run that just
    // processed this key is not duplicated.
    $alreadyProcessed = false;
    $logStore->mutate(function (array $log) use ($key, &$alreadyProcessed): array {
        $alreadyProcessed = array_key_exists($key, $log);
        return $log;
    });

    if ($alreadyProcessed) {
        $skipped++;
        echo "  [SKIP] {$provider} {$key}\n";
        continue;
    }

    try {
        $result = R2StorageManager::setObjectPrivate($provider, $key);
    } catch (Throwable $e) {
        $result = [
            'success' => false,
            'http_code' => 0,
            'error' => 'Exception: ' . $e->getMessage(),
        ];
    }

    if (!empty($result['success'])) {
        $logStore->mutate(function (array $log) use ($key, $provider): array {
            $log[$key] = [
                'provider' => $provider,
                'processed_at' => date('c'),
            ];
            return $log;
        });
        $success++;
        echo "  [OK] {$provider} {$key}\n";
    } else {
        $failed++;
        $message = sprintf(
            'privatize_existing_objects: FAILED %s key=%s error=%s',
            $provider,
            $key,
            $result['error'] ?? 'unknown'
        );
        error_log($message);
        $failures[] = [
            'key' => $key,
            'provider' => $provider,
            'error' => $result['error'] ?? 'unknown',
            'http_code' => $result['http_code'] ?? 0,
        ];
        echo "  [FAIL] {$provider} {$key}: "
            . ($result['error'] ?? 'unknown') . "\n";
    }
}

// Persist a run summary for operators.
$logStore->mutate(function (array $log) use ($success, $failed, $skipped, $failures): array {
    $log['_last_run'] = [
        'at' => date('c'),
        'success' => $success,
        'failed' => $failed,
        'skipped' => $skipped,
        'failures' => $failures,
    ];
    return $log;
});

echo "\n=== Summary ===\n";
echo "Eligible: {$totalEligible}\n";
echo "Processed: {$success}\n";
echo "Failed: {$failed}\n";
echo "Skipped (already processed): {$skipped}\n";

exit($failed > 0 ? 2 : 0);
