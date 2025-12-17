#!/usr/bin/env php
<?php
/**
 * PixelHop - Maintenance Cron Script
 * Run hourly to perform cleanup and maintenance tasks
 *
 * Tasks:
 * 1. Clean up temp files older than 6 hours
 * 2. Clean up rate limit files older than 5 minutes
 * 3. Reset daily API usage counters
 * 4. Check S3 connectivity
 * 5. Log maintenance report
 *
 * CRON Expression (run every hour):
 * 0 * * * * /usr/bin/php /var/www/pichost/cron/maintenance.php >> /var/www/pichost/cron/maintenance.log 2>&1
 */

// Ensure running from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script must be run from command line');
}

// Set working directory
chdir(__DIR__ . '/..');

// Load dependencies
require_once __DIR__ . '/../includes/ImageHandler.php';
require_once __DIR__ . '/../includes/RateLimiter.php';

// Configuration
define('TEMP_DIR', __DIR__ . '/../temp');
define('LOG_FILE', __DIR__ . '/maintenance.log');
define('MAX_LOG_SIZE', 5 * 1024 * 1024);

/**
 * Main maintenance routine
 */
function runMaintenance(): array
{
    $startTime = microtime(true);
    $report = [
        'timestamp' => date('Y-m-d H:i:s'),
        'tasks' => [],
        'errors' => [],
    ];


    $report['tasks']['temp_cleanup'] = cleanupTempFiles();


    $report['tasks']['ratelimit_cleanup'] = cleanupRateLimitFiles();


    $report['tasks']['api_counters'] = resetApiCounters();


    $report['tasks']['s3_check'] = checkS3Connectivity();


    $report['tasks']['log_rotation'] = rotateLogsIfNeeded();


    $report['tasks']['abuse_watchdog'] = runAbuseWatchdog();

    $report['duration_ms'] = round((microtime(true) - $startTime) * 1000);

    return $report;
}

/**
 * Task 1: Clean up temp files older than 6 hours
 */
function cleanupTempFiles(): array
{
    try {
        $result = ImageHandler::cleanupTemp(TEMP_DIR);
        return [
            'success' => true,
            'deleted_files' => $result['deleted'],
            'freed_space' => $result['size_human'],
            'errors' => $result['errors'],
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Task 2: Clean up rate limit files
 */
function cleanupRateLimitFiles(): array
{
    try {
        $rateLimiter = new RateLimiter();
        $deleted = $rateLimiter->cleanup();
        return [
            'success' => true,
            'deleted_files' => $deleted,
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Task 3: Reset daily API usage counters
 */
function resetApiCounters(): array
{
    try {

        if (!extension_loaded('pdo_mysql')) {
            return [
                'success' => true,
                'message' => 'PDO MySQL not available, skipping',
            ];
        }


        $dbConfigFile = __DIR__ . '/../config/database.php';
        if (!file_exists($dbConfigFile)) {
            return [
                'success' => true,
                'message' => 'Database not configured, skipping',
            ];
        }

        require_once __DIR__ . '/../includes/Database.php';

        $db = Database::getInstance();


        $stmt = $db->prepare("DELETE FROM rate_limits WHERE last_refill < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
        $deletedRateLimits = $stmt->rowCount();


        $stmt = $db->prepare("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
        $deletedLoginAttempts = $stmt->rowCount();


        $stmt = $db->prepare("DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute();
        $deletedSessions = $stmt->rowCount();

        return [
            'success' => true,
            'deleted_rate_limits' => $deletedRateLimits,
            'deleted_login_attempts' => $deletedLoginAttempts,
            'deleted_sessions' => $deletedSessions,
        ];
    } catch (PDOException $e) {

        return [
            'success' => true,
            'message' => 'Database cleanup skipped: ' . $e->getMessage(),
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Task 4: Check S3 connectivity
 */
function checkS3Connectivity(): array
{
    try {

        if (!function_exists('curl_init')) {
            return [
                'success' => true,
                'message' => 'cURL not available, skipping S3 check',
            ];
        }

        $configFile = __DIR__ . '/../config/s3.php';
        if (!file_exists($configFile)) {
            return [
                'success' => true,
                'message' => 'S3 not configured',
            ];
        }

        $config = require $configFile;
        $s3Config = $config['s3'];


        $endpoint = rtrim($s3Config['endpoint'], '/');
        $bucket = $s3Config['bucket'];
        $url = "{$endpoint}/{$bucket}/";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return [
                'success' => false,
                'error' => $error,
            ];
        }


        return [
            'success' => true,
            'http_code' => $httpCode,
            'endpoint' => $endpoint,
            'bucket' => $bucket,
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Task 5: Rotate log file if too large
 */
function rotateLogsIfNeeded(): array
{
    if (!file_exists(LOG_FILE)) {
        return [
            'success' => true,
            'message' => 'No log file to rotate',
        ];
    }

    $size = filesize(LOG_FILE);

    if ($size > MAX_LOG_SIZE) {
        $backupFile = LOG_FILE . '.' . date('Ymd_His') . '.bak';
        rename(LOG_FILE, $backupFile);


        $backups = glob(LOG_FILE . '.*.bak');
        if (count($backups) > 5) {
            usort($backups, fn($a, $b) => filemtime($a) - filemtime($b));
            $toDelete = array_slice($backups, 0, count($backups) - 5);
            foreach ($toDelete as $file) {
                unlink($file);
            }
        }

        return [
            'success' => true,
            'rotated' => true,
            'old_size' => formatBytes($size),
        ];
    }

    return [
        'success' => true,
        'rotated' => false,
        'current_size' => formatBytes($size),
    ];
}

/**
 * Format bytes to human readable
 */
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

/**
 * Task 6: Run AbuseGuard watchdog
 */
function runAbuseWatchdog(): array
{
    try {
        require_once __DIR__ . '/../core/AbuseGuard.php';
        $abuseGuard = new AbuseGuard();
        $watchdogReport = $abuseGuard->runWatchdog();

        return [
            'success' => true,
            'scanned_ips' => $watchdogReport['scanned'],
            'suspicious_found' => count($watchdogReport['suspicious_ips']),
            'auto_blocked' => $watchdogReport['blocked'],
            'warnings' => $watchdogReport['warnings'],
            'expired_cleaned' => $watchdogReport['cleaned_expired'],
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * Log report to file
 */
function logReport(array $report): void
{
    $logLine = sprintf(
        "[%s] Duration: %dms | Temp: %d files | RateLimit: %d files | S3: %s | Abuse: %d blocked\n",
        $report['timestamp'],
        $report['duration_ms'],
        $report['tasks']['temp_cleanup']['deleted_files'] ?? 0,
        $report['tasks']['ratelimit_cleanup']['deleted_files'] ?? 0,
        $report['tasks']['s3_check']['success'] ? 'OK' : 'FAIL',
        $report['tasks']['abuse_watchdog']['auto_blocked'] ?? 0
    );

    file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
}

// Run maintenance
echo "=== PixelHop Maintenance ===\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

$report = runMaintenance();

// Output report
echo "Tasks completed:\n";
foreach ($report['tasks'] as $task => $result) {
    $status = ($result['success'] ?? false) ? '✓' : '✗';
    echo "  [{$status}] {$task}\n";

    foreach ($result as $key => $value) {
        if ($key === 'success') continue;
        if (is_array($value)) {
            echo "      {$key}: " . json_encode($value) . "\n";
        } else {
            echo "      {$key}: {$value}\n";
        }
    }
}

echo "\nDuration: {$report['duration_ms']}ms\n";
echo "Completed: " . date('Y-m-d H:i:s') . "\n";

// Log to file
logReport($report);

// Exit with appropriate code
$hasErrors = false;
foreach ($report['tasks'] as $result) {
    if (!($result['success'] ?? false)) {
        $hasErrors = true;
        break;
    }
}

exit($hasErrors ? 1 : 0);
