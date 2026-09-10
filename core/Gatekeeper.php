<?php
/**
 * PixelHop - Gatekeeper
 * Server Protection Logic for Resource Management
 *
 * Protects the server from overload by:
 * - Checking storage limits before uploads
 * - Monitoring CPU load before heavy operations
 * - Enforcing concurrency limits on Python processes
 * - Managing user quotas for AI tools
 */

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/ClientIp.php';

class Gatekeeper
{
    private PDO $db;
    private array $settings = [];
    /**
     * True unless loading site_settings from DB threw.
     * Defaults are loaded in that case, so canUpload() and canRunHeavyTool()
     * remain usable with safe fallback values (maintenance=false,
     * kill_switch=false, default limits). Features whose safety depends on a
     * real DB value must inspect settingsLoaded() and fail explicitly rather
     * than silently acting on a default.
     *
     * @var bool
     */
    private bool $settingsLoaded = true;
    public const OK = 'ok';
    public const ERROR_STORAGE_FULL = 'storage_full';
    public const ERROR_USER_QUOTA = 'user_quota_exceeded';
    public const ERROR_SERVER_BUSY = 'server_busy';
    public const ERROR_MAINTENANCE = 'maintenance_mode';
    public const ERROR_KILL_SWITCH = 'kill_switch_active';
    public const ERROR_DAILY_LIMIT = 'daily_limit_exceeded';
    public const ERROR_CONCURRENT = 'too_many_processes';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loadSettings();
    }

    /**
     * Load site settings from database
     */
    private function loadSettings(): void
    {
        // Fail-aware settings load. If the site_settings query fails, keep
        // uploads alive with safe defaults instead of dying silently. The
        // defaults below are intentionally permissive for reads (maintenance
        // off, kill switch off, default limits) so availability is not lost;
        // other controls that need DB-backed state should check
        // settingsLoaded() and fail explicitly on their own.
        $defaults = $this->defaultSettings();

        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value, setting_type FROM site_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $key = $row['setting_key'];
                $rawValue = $row['setting_value'];
                $type = $row['setting_type'];

                switch ($type) {
                    case 'int':
                        $numeric = $this->castNumericSetting($key, $rawValue, true);
                        $value = $numeric !== null ? $numeric : ($defaults[$key] ?? 0);
                        break;
                    case 'bool':
                        $numeric = $this->castNumericSetting($key, $rawValue, true);
                        $value = $numeric !== null ? (bool) (int) $numeric : (bool) ($defaults[$key] ?? false);
                        break;
                    case 'json':
                        $decoded = json_decode($rawValue, true);
                        $value = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                        break;
                    default:
                        $value = $rawValue;
                        break;
                }

                $this->settings[$key] = $value;
            }
        } catch (Exception $e) {
            $this->settingsLoaded = false;
            error_log('Gatekeeper: Failed to load settings from site_settings; using safe defaults - ' . $e->getMessage());

            $this->settings = $defaults;
        }
    }

    /**
     * Canonical safe defaults used when site_settings cannot be read.
     * Kept in one place so getSetting() numeric validation and loadSettings()
     * fall back to the exact same values.
     */
    private function defaultSettings(): array
    {
        return [
            'global_storage_used' => 0,
            'maintenance_mode' => false,
            'max_concurrent_processes' => 2,
            'kill_switch_active' => false,
            'daily_ocr_limit_free' => 5,
            'daily_ocr_limit_premium' => 50,
            'daily_removebg_limit_free' => 3,
            'daily_removebg_limit_premium' => 30,
            'storage_limit_free' => 262144000,
            'storage_limit_premium' => 5368709120,
            'temp_file_lifetime_hours' => 6,
            'cpu_load_threshold' => 3.0,
            'storage_emergency_threshold' => 257698037760,
        ];
    }

    /**
     * Validate a numeric setting value. Returns the numeric value, or null
     * when the raw value is not strictly numeric or is negative. Every
     * canonical numeric setting in defaultSettings() is a non-negative
     * quantity (bytes, counts, thresholds), so a negative value is treated
     * as corrupt and rejected rather than allowed to skew limit comparisons.
     */
    private function castNumericSetting(string $key, $value, bool $warn): int|float|null
    {
        $numeric = null;

        if (is_int($value) || is_float($value)) {
            $numeric = $value;
        } elseif (is_string($value) && preg_match('/^\d+(\.\d+)?$/', trim($value)) === 1) {
            $numeric = strpos($value, '.') === false ? (int) $value : (float) $value;
        }

        if ($numeric !== null && $numeric >= 0) {
            return $numeric;
        }

        if ($warn) {
            error_log(
                "Gatekeeper: Non-numeric or negative site_settings value for '{$key}' ('" .
                (is_scalar($value) ? (string) $value : gettype($value)) .
                "'); using default"
            );
        }

        return null;
    }

    /**
     * Get a specific setting value.
     *
     * Numeric-looking settings are validated here so a garbage string in
     * site_settings (e.g. '-5' or 'abc' in an int field) can never corrupt
     * limit comparisons. If validation fails, the supplied default is used
     * and a warning is logged.
     *
     * @param mixed $default
     * @return mixed
     */
    public function getSetting(string $key, $default = null)
    {
        if (!array_key_exists($key, $this->settings)) {
            return $default;
        }

        $value = $this->settings[$key];
        $defaults = $this->defaultSettings();

        // Apply numeric validation when either the canonical default or the
        // caller-supplied default is int/float. This also covers numeric keys
        // that are not part of defaultSettings() (e.g. light-tool limits),
        // so a garbage value can never be cast to 0/-1 by accident.
        if (is_int($defaults[$key] ?? null) || is_float($defaults[$key] ?? null)
            || is_int($default) || is_float($default)) {
            $validated = $this->castNumericSetting($key, $value, false);
            if ($validated === null) {
                error_log(
                    "Gatekeeper: Non-numeric or negative setting '{$key}' ('" .
                    (is_scalar($value) ? (string) $value : gettype($value)) .
                    "'); falling back to default " .
                    (is_scalar($default) ? (string) $default : gettype($default))
                );

                // Negative defaults are a caller bug; clamp to 0 so limit
                // comparisons cannot become inverted.
                return is_int($default) ? max(0, $default) : $default;
            }
            return $validated;
        }

        return $value;
    }

    /**
     * Report whether site_settings were successfully loaded from the DB.
     */
    public function settingsLoaded(): bool
    {
        return $this->settingsLoaded;
    }

    /**
     * Update a setting in database
     */
    public function updateSetting(string $key, $value): bool
    {
        try {
            $stmt = $this->db->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?");
            $result = $stmt->execute([(string) $value, $key]);

            if ($result) {
                $this->settings[$key] = $value;
            }

            return $result;
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to update setting - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if an upload can proceed
     *
     * @param int $fileSize Size of file to upload in bytes
     * @param int|null $userId User ID (null for guest)
     * @return array ['allowed' => bool, 'reason' => string, 'code' => string]
     */
    public function canUpload(int $fileSize, ?int $userId = null): array
    {

        if ($this->getSetting('maintenance_mode', false)) {
            return $this->deny(self::ERROR_MAINTENANCE, 'System is under maintenance. Please try again later.');
        }
        if ($this->getSetting('kill_switch_active', false)) {
            return $this->deny(self::ERROR_KILL_SWITCH, 'Uploads are temporarily disabled due to storage limits.');
        }
        $emergencyThreshold = 263070212096;
        $globalUsed = $this->getSetting('global_storage_used', 0);

        if ($globalUsed + $fileSize > $emergencyThreshold) {

            $this->updateSetting('kill_switch_active', 1);
            return $this->deny(self::ERROR_STORAGE_FULL, 'Server storage is full. Uploads are temporarily disabled.');
        }
        if ($userId !== null) {
            $user = $this->getUser($userId);

            if ($user) {
                $storageUsed = (int) $user['storage_used'];
                $storageLimit = (int) $user['storage_limit'];
                if ($user['account_type'] === 'free') {
                    $storageLimit = min($storageLimit, $this->getSetting('storage_limit_free', 262144000));
                } elseif ($user['account_type'] === 'premium') {
                    $storageLimit = min($storageLimit, $this->getSetting('storage_limit_premium', 5368709120));
                }

                if ($storageUsed + $fileSize > $storageLimit) {
                    return $this->deny(
                        self::ERROR_USER_QUOTA,
                        sprintf(
                            'Storage quota exceeded. You have %s of %s used.',
                            $this->formatBytes($storageUsed),
                            $this->formatBytes($storageLimit)
                        )
                    );
                }
            }
        }

        return $this->allow();
    }

    /**
     * Check if a heavy tool (OCR, RemoveBG) can run
     *
     * @param string $toolName Tool identifier (ocr, removebg)
     * @param int|null $userId User ID
     * @return array ['allowed' => bool, 'reason' => string, 'code' => string, 'usage' => array]
     */
    public function canRunHeavyTool(string $toolName, ?int $userId = null): array
    {

        if ($this->getSetting('maintenance_mode', false)) {
            return $this->deny(self::ERROR_MAINTENANCE, 'System is under maintenance. Please try again later.');
        }
        $loadAvg = sys_getloadavg();
        $currentLoad = $loadAvg[0];
        $threshold = (float) $this->getSetting('cpu_load_threshold', 3.0);

        if ($currentLoad > $threshold) {
            return $this->deny(
                self::ERROR_SERVER_BUSY,
                sprintf('Server is busy (load: %.1f). Please try again in a few moments.', $currentLoad),
                ['cpu_load' => $currentLoad, 'threshold' => $threshold]
            );
        }
        $maxProcesses = (int) $this->getSetting('max_concurrent_processes', 2);
        $runningProcesses = $this->countPythonProcesses();

        if ($runningProcesses >= $maxProcesses) {
            return $this->deny(
                self::ERROR_CONCURRENT,
                sprintf('Too many processes running (%d/%d). Please wait...', $runningProcesses, $maxProcesses),
                ['running' => $runningProcesses, 'max' => $maxProcesses]
            );
        }
        if ($userId !== null) {
            $user = $this->getUser($userId);

            if ($user) {

                $this->resetDailyCountersIfNeeded($userId, $user);
                $user = $this->getUser($userId);

                $accountType = $user['account_type'] ?? 'free';
                $normalizedTool = $this->normalizeToolName($toolName);
                $limitKey = "daily_{$normalizedTool}_limit_{$accountType}";
                $countKey = "daily_{$normalizedTool}_count";

                $dailyLimit = $this->getSetting($limitKey, $normalizedTool === 'ocr' ? 5 : 3);
                $dailyCount = (int) ($user[$countKey] ?? 0);

                if ($dailyCount >= $dailyLimit) {
                    return $this->deny(
                        self::ERROR_DAILY_LIMIT,
                        sprintf('Daily %s limit reached (%d/%d). Resets at midnight.', strtoupper($toolName), $dailyCount, $dailyLimit),
                        ['used' => $dailyCount, 'limit' => $dailyLimit, 'tool' => $toolName]
                    );
                }

                return $this->allow(['used' => $dailyCount, 'limit' => $dailyLimit, 'tool' => $toolName]);
            }
        }
        return $this->allow(['used' => 0, 'limit' => 1, 'tool' => $toolName, 'guest' => true]);
    }

    /**
     * Increment usage counter for a tool
     */
    public function recordToolUsage(string $toolName, int $userId, int $fileSize = 0, int $processingTimeMs = 0, string $status = 'success'): bool
    {
        try {
            // Quota source roles:
            // - usage_logs is the ENFORCEMENT source (api/ocr.php & api/rembg.php
            //   read it; a separate subtask handles atomicity there).
            // - users.daily_*_count columns are for DISPLAY only (dashboard),
            //   kept so getUserStats() can render counts without aggregating
            //   usage_logs. Do not remove them.
            // - site_settings holds the LIMITS.
            //
            // Only AI tools (ocr / removebg synonyms) increment the display
            // counter columns. Light tools (compress/resize/crop/convert) do
            // NOT touch daily_*_count; they are still written to usage_logs
            // for audit/limits below.
            $normalizedTool = $this->normalizeToolName($toolName);
            $countColumn = null;

            if ($normalizedTool === 'ocr') {
                $countColumn = 'daily_ocr_count';
            } elseif ($normalizedTool === 'removebg') {
                $countColumn = 'daily_removebg_count';
            }

            if ($countColumn !== null) {
                $this->resetDailyCountersIfNeeded($userId);
                $stmt = $this->db->prepare("UPDATE users SET {$countColumn} = {$countColumn} + 1 WHERE id = ?");
                $stmt->execute([$userId]);
            }

            $stmt = $this->db->prepare("
                INSERT INTO usage_logs (user_id, tool_name, file_size, processing_time_ms, status, ip_address)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $toolName,
                $fileSize,
                $processingTimeMs,
                $status,
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            return true;
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to record usage - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update global storage counter
     */
    public function updateGlobalStorage(int $delta): bool
    {
        try {
            // Ensure the row exists before incrementing/decrementing. INSERT
            // IGNORE is idempotent and makes the later UPDATE not depend on a
            // pre-seeded settings row. If delta is 0, only the INSERT IGNORE
            // runs and the method is still a no-op.
            $this->db->exec("
                INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_type, description)
                VALUES ('global_storage_used', 0, 'int', 'Global storage used in bytes')
            ");

            if ($delta === 0) {
                return true;
            }

            if ($delta > 0) {
                $stmt = $this->db->prepare("
                    UPDATE site_settings
                    SET setting_value = CAST(CAST(setting_value AS SIGNED) + ? AS CHAR)
                    WHERE setting_key = 'global_storage_used'
                ");
            } else {
                $stmt = $this->db->prepare("
                    UPDATE site_settings
                    SET setting_value = CAST(GREATEST(0, CAST(setting_value AS SIGNED) - ?) AS CHAR)
                    WHERE setting_key = 'global_storage_used'
                ");
            }

            return $stmt->execute([abs($delta)]);
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to update global storage - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a light tool (compress, resize, convert, crop) can run
     * Returns allowed for guests with usage tracking by IP
     *
     * @param string $toolName Tool identifier
     * @param int|null $userId User ID (null for guests)
     * @param string|null $ip IP address for guest tracking
     * @return array ['allowed' => bool, 'reason' => string, 'code' => string]
     */
    public function canRunLightTool(string $toolName, ?int $userId = null, ?string $ip = null): array
    {
        // Maintenance mode check
        if ($this->getSetting('maintenance_mode', false)) {
            return $this->deny(self::ERROR_MAINTENANCE, 'System is under maintenance. Please try again later.');
        }
        
        $ip = $ip ?? ClientIp::get();

        // Guest limits - more restrictive
        if ($userId === null) {
            $guestHourlyLimit = (int) $this->getSetting('tool_guest_hourly_limit', 20);
            $guestDailyLimit = (int) $this->getSetting('tool_guest_daily_limit', 100);
            
            $hourlyUsage = $this->getToolUsageByIp($ip, $toolName, '-1 hour');
            $dailyUsage = $this->getToolUsageByIp($ip, $toolName, '-24 hours');
            
            if ($hourlyUsage >= $guestHourlyLimit) {
                return $this->deny(
                    'guest_hourly_limit',
                    "Hourly tool limit reached ({$hourlyUsage}/{$guestHourlyLimit}). Please register for unlimited access.",
                    ['used' => $hourlyUsage, 'limit' => $guestHourlyLimit]
                );
            }
            
            if ($dailyUsage >= $guestDailyLimit) {
                return $this->deny(
                    'guest_daily_limit',
                    "Daily tool limit reached ({$dailyUsage}/{$guestDailyLimit}). Please register for more.",
                    ['used' => $dailyUsage, 'limit' => $guestDailyLimit]
                );
            }
            
            return $this->allow(['used' => $dailyUsage, 'limit' => $guestDailyLimit, 'tool' => $toolName, 'guest' => true]);
        }
        
        // Logged-in users - very high limits (essentially unlimited for regular tools)
        $userDailyLimit = (int) $this->getSetting('tool_user_daily_limit', 1000);
        $dailyUsage = $this->getToolUsageByUser($userId, $toolName, '-24 hours');
        
        if ($dailyUsage >= $userDailyLimit) {
            return $this->deny(
                self::ERROR_DAILY_LIMIT,
                "Daily tool limit reached ({$dailyUsage}/{$userDailyLimit}). Please try again tomorrow.",
                ['used' => $dailyUsage, 'limit' => $userDailyLimit]
            );
        }
        
        return $this->allow(['used' => $dailyUsage, 'limit' => $userDailyLimit, 'tool' => $toolName]);
    }
    
    /**
     * Get tool usage count by IP
     */
    private function getToolUsageByIp(string $ip, string $toolName, string $since): int
    {
        try {
            $sinceTime = date('Y-m-d H:i:s', strtotime($since));
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM usage_logs 
                WHERE ip_address = ? AND tool_name = ? AND created_at >= ?
            ");
            $stmt->execute([$ip, $toolName, $sinceTime]);
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Get tool usage count by user
     */
    private function getToolUsageByUser(int $userId, string $toolName, string $since): int
    {
        try {
            $sinceTime = date('Y-m-d H:i:s', strtotime($since));
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM usage_logs 
                WHERE user_id = ? AND tool_name = ? AND created_at >= ?
            ");
            $stmt->execute([$userId, $toolName, $sinceTime]);
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
    
    /**
     * Record light tool usage (for guests too)
     */
    public function recordLightToolUsage(string $toolName, ?int $userId = null, int $fileSize = 0, int $processingTimeMs = 0, string $status = 'success'): bool
    {
        try {
            $ip = ClientIp::get();

            $stmt = $this->db->prepare("
                INSERT INTO usage_logs (user_id, tool_name, file_size, processing_time_ms, status, ip_address)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $toolName,
                $fileSize,
                $processingTimeMs,
                $status,
                $ip
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to record light tool usage - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Update user storage counter
     */
    public function updateUserStorage(int $userId, int $delta): bool
    {
        try {
            if ($delta >= 0) {
                $stmt = $this->db->prepare("UPDATE users SET storage_used = storage_used + ? WHERE id = ?");
            } else {
                $stmt = $this->db->prepare("UPDATE users SET storage_used = GREATEST(0, CAST(storage_used AS SIGNED) - ?) WHERE id = ?");
            }

            return $stmt->execute([abs($delta), $userId]);
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to update user storage - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Register a temp file for auto-cleanup
     * Returns file_id for view URL, or false on failure
     */
    public function registerTempFile(string $filePath, string $fileName, int $fileSize, ?int $userId = null, string $toolName = '', string $mimeType = '', string $originalName = ''): string|false
    {
        try {
            $lifetime = (int) $this->getSetting('temp_file_lifetime_hours', 6);
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$lifetime} hours"));
            $fileId = bin2hex(random_bytes(16));

            $stmt = $this->db->prepare("
                INSERT INTO temp_files (file_id, user_id, file_path, file_name, mime_type, original_name, file_size, tool_name, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $result = $stmt->execute([$fileId, $userId, $filePath, $fileName, $mimeType, $originalName ?: $fileName, $fileSize, $toolName, $expiresAt]);

            return $result ? $fileId : false;
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to register temp file - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Save processed image to temp and register for view
     * Returns array with file_id and view_url
     */
    public function saveTempResult(string $imageData, string $fileName, string $mimeType, ?int $userId = null, string $toolName = ''): array|false
    {
        try {

            $tempDir = __DIR__ . '/../temp/' . bin2hex(random_bytes(8));
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $ext = $extMap[$mimeType] ?? 'bin';

            $filePath = $tempDir . '/' . pathinfo($fileName, PATHINFO_FILENAME) . '.' . $ext;
            $bytes = file_put_contents($filePath, $imageData);
            if ($bytes === false) {
                return false;
            }
            $fileId = $this->registerTempFile($filePath, basename($filePath), $bytes, $userId, $toolName, $mimeType, $fileName);

            if (!$fileId) {
                @unlink($filePath);
                return false;
            }

            return [
                'file_id' => $fileId,
                'view_url' => '/view-temp.php?id=' . $fileId,
                'file_path' => $filePath,
                'file_size' => $bytes,
            ];
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to save temp result - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user's temp files with countdown
     */
    public function getUserTempFiles(int $userId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, file_path, file_name, file_size, tool_name, expires_at, created_at,
                       TIMESTAMPDIFF(SECOND, NOW(), expires_at) as seconds_remaining
                FROM temp_files
                WHERE user_id = ? AND expires_at > NOW()
                ORDER BY created_at DESC
            ");
            $stmt->execute([$userId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to get temp files - ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Clean up expired temp files
     */
    public function cleanupExpiredTempFiles(): array
    {
        $deleted = 0;
        $freedBytes = 0;

        try {

            $stmt = $this->db->query("SELECT id, file_path, file_size FROM temp_files WHERE expires_at <= NOW()");
            $expiredFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($expiredFiles as $file) {

                if (file_exists($file['file_path'])) {
                    @unlink($file['file_path']);
                }

                $deleted++;
                $freedBytes += (int) $file['file_size'];
            }
            $this->db->exec("DELETE FROM temp_files WHERE expires_at <= NOW()");
            $tempDir = __DIR__ . '/../temp';
            $lifetimeHours = $this->getSetting('temp_file_lifetime_hours', 6);
            $cutoffTime = time() - ($lifetimeHours * 3600);

            if (is_dir($tempDir)) {
                $dirs = glob($tempDir . '/*', GLOB_ONLYDIR);
                foreach ($dirs as $dir) {
                    $dirName = basename($dir);

                    if (strpos($dirName, '.') === 0) continue;
                    $mtime = filemtime($dir);
                    if ($mtime < $cutoffTime) {

                        $folderSize = 0;
                        $files = glob($dir . '/*');
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                $folderSize += filesize($file);
                                @unlink($file);
                                $deleted++;
                            }
                        }

                        @rmdir($dir);
                        $freedBytes += $folderSize;
                    }
                }
            }
            if ($freedBytes > 0) {
                $this->updateGlobalStorage(-$freedBytes);
            }

        } catch (Exception $e) {
            error_log('Gatekeeper: Cleanup failed - ' . $e->getMessage());
        }

        return [
            'deleted' => $deleted,
            'freed_bytes' => $freedBytes,
            'freed_human' => $this->formatBytes($freedBytes)
        ];
    }

    /**
     * Get server health stats
     */
    public function getServerHealth(): array
    {
        $loadAvg = sys_getloadavg();
        $memInfo = $this->getMemoryInfo();
        $diskFree = disk_free_space('/');
        $diskTotal = disk_total_space('/');
        $diskUsed = $diskTotal - $diskFree;
        
        // Calculate temp folder usage (allocated 10GB for website temp)
        $tempUsage = $this->getTempFolderUsage();

        $pythonProcesses = $this->countPythonProcesses();

        return [
            'cpu' => [
                'load_1m' => round($loadAvg[0], 2),
                'load_5m' => round($loadAvg[1], 2),
                'load_15m' => round($loadAvg[2], 2),
                'threshold' => (float) $this->getSetting('cpu_load_threshold', 3.0),
                'status' => $loadAvg[0] < 2.0 ? 'healthy' : ($loadAvg[0] < 3.0 ? 'warning' : 'critical'),
            ],
            'memory' => $memInfo,
            'disk' => [
                'free' => $diskFree,
                'total' => $diskTotal,
                'used' => $diskUsed,
                'percent' => round(($diskUsed / $diskTotal) * 100, 1),
                'free_human' => $this->formatBytes($diskFree),
                'used_human' => $this->formatBytes($diskUsed),
                'total_human' => $this->formatBytes($diskTotal),
            ],
            'temp' => $tempUsage,
            'storage' => [
                'global_used' => $this->getSetting('global_storage_used', 0),
                'global_used_human' => $this->formatBytes($this->getSetting('global_storage_used', 0)),
                'cap' => 268435456000,
                'cap_human' => '250 GB',
                'percent' => round(($this->getSetting('global_storage_used', 0) / 268435456000) * 100, 2),
                'providers' => $this->getStorageProviderStats(),
            ],
            'processes' => [
                'python_running' => $pythonProcesses,
                'max_allowed' => (int) $this->getSetting('max_concurrent_processes', 2),
            ],
            'status' => [
                'maintenance' => $this->getSetting('maintenance_mode', false),
                'kill_switch' => $this->getSetting('kill_switch_active', false),
            ],
        ];
    }
    
    /**
     * Get temp folder usage (allocated 10GB)
     */
    private function getTempFolderUsage(): array
    {
        $tempDir = __DIR__ . '/../temp';
        $allocatedBytes = 10 * 1024 * 1024 * 1024; // 10 GB
        $usedBytes = 0;
        $fileCount = 0;
        
        if (is_dir($tempDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $usedBytes += $file->getSize();
                    $fileCount++;
                }
            }
        }
        
        $percent = $allocatedBytes > 0 ? round(($usedBytes / $allocatedBytes) * 100, 2) : 0;
        
        return [
            'used' => $usedBytes,
            'used_human' => $this->formatBytes($usedBytes),
            'allocated' => $allocatedBytes,
            'allocated_human' => '10 GB',
            'available' => $allocatedBytes - $usedBytes,
            'available_human' => $this->formatBytes($allocatedBytes - $usedBytes),
            'percent' => $percent,
            'file_count' => $fileCount,
        ];
    }

    /**
     * Get storage stats per provider (R2, Contabo)
     */
    private function getStorageProviderStats(): array
    {
        $stats = [
            'r2' => ['used' => 0, 'used_human' => '0 B', 'limit' => 9.5 * 1024 * 1024 * 1024, 'limit_human' => '9.5 GB', 'percent' => 0, 'file_count' => 0],
            'contabo' => ['used' => 0, 'used_human' => '0 B', 'limit' => 250 * 1024 * 1024 * 1024, 'limit_human' => '250 GB', 'percent' => 0, 'file_count' => 0],
        ];
        
        try {
            $stmt = $this->db->prepare("SELECT provider, total_bytes, file_count FROM storage_stats WHERE provider IN ('r2', 'contabo')");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($results as $row) {
                $provider = $row['provider'];
                if (isset($stats[$provider])) {
                    $used = (int) $row['total_bytes'];
                    $limit = $stats[$provider]['limit'];
                    $stats[$provider]['used'] = $used;
                    $stats[$provider]['used_human'] = $this->formatBytes($used);
                    $stats[$provider]['file_count'] = (int) $row['file_count'];
                    $stats[$provider]['percent'] = $limit > 0 ? round(($used / $limit) * 100, 2) : 0;
                }
            }
        } catch (PDOException $e) {
            error_log('Gatekeeper: Failed to get storage provider stats - ' . $e->getMessage());
        }
        
        return $stats;
    }

    /**
     * Get user stats for dashboard
     */
    public function getUserStats(int $userId): array
    {
        $user = $this->getUser($userId);

        if (!$user) {
            return [];
        }
        $this->resetDailyCountersIfNeeded($userId, $user);
        $user = $this->getUser($userId);

        $accountType = $user['account_type'] ?? 'free';
        $storageLimit = $accountType === 'premium'
            ? $this->getSetting('storage_limit_premium', 5368709120)
            : $this->getSetting('storage_limit_free', 262144000);

        $ocrLimit = $accountType === 'premium'
            ? $this->getSetting('daily_ocr_limit_premium', 50)
            : $this->getSetting('daily_ocr_limit_free', 5);

        $removebgLimit = $accountType === 'premium'
            ? $this->getSetting('daily_removebg_limit_premium', 30)
            : $this->getSetting('daily_removebg_limit_free', 3);

        return [
            'storage' => [
                'used' => (int) $user['storage_used'],
                'limit' => $storageLimit,
                'used_human' => $this->formatBytes($user['storage_used']),
                'limit_human' => $this->formatBytes($storageLimit),
                'percent' => $storageLimit > 0 ? round(($user['storage_used'] / $storageLimit) * 100, 1) : 0,
            ],
            'ocr' => [
                'used' => (int) $user['daily_ocr_count'],
                'limit' => $ocrLimit,
                'remaining' => max(0, $ocrLimit - $user['daily_ocr_count']),
            ],
            'removebg' => [
                'used' => (int) $user['daily_removebg_count'],
                'limit' => $removebgLimit,
                'remaining' => max(0, $removebgLimit - $user['daily_removebg_count']),
            ],
            'account_type' => $accountType,
            'resets_at' => 'Midnight (server time)',
        ];
    }

    private function getUser(int $userId): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, email, role, account_type, storage_used, storage_limit,
                       daily_ocr_count, daily_removebg_count, daily_reset_at
                FROM users WHERE id = ?
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Normalize AI tool identifiers. Accepts 'removebg' and 'rembg' as
     * synonyms; light tools pass through unchanged.
     */
    private function normalizeToolName(string $toolName): string
    {
        $toolName = strtolower(trim($toolName));

        if ($toolName === 'rembg') {
            return 'removebg';
        }

        return $toolName;
    }

    private function resetDailyCountersIfNeeded(int $userId, ?array $user = null): void
    {
        try {
            $lastReset = $user['daily_reset_at'] ?? null;

            // If the caller did not pass a user row (recordToolUsage), fetch
            // the current reset marker. The UPDATE below is still the
            // authoritative gate and is idempotent: the WHERE clause matches
            // only when a reset is actually due, so a second run is a no-op.
            if ($lastReset === null) {
                $stmt = $this->db->prepare("SELECT daily_reset_at FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $lastReset = $stmt->fetchColumn();
            }

            if ($lastReset === date('Y-m-d')) {
                return;
            }

            $stmt = $this->db->prepare("
                UPDATE users
                SET daily_ocr_count = 0, daily_removebg_count = 0, daily_reset_at = CURDATE()
                WHERE id = ? AND (daily_reset_at IS NULL OR daily_reset_at < CURDATE())
            ");
            $stmt->execute([$userId]);
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to reset daily counters - ' . $e->getMessage());
        }
    }

    private function countPythonProcesses(): int
    {
        $output = shell_exec('pgrep -c python 2>/dev/null');
        return (int) trim($output);
    }

    private function getMemoryInfo(): array
    {
        $memInfo = [
            'total' => 0,
            'used' => 0,
            'free' => 0,
            'percent' => 0,
        ];
        if (is_readable('/proc/meminfo')) {
            $data = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)\s+kB/', $data, $total);
            preg_match('/MemAvailable:\s+(\d+)\s+kB/', $data, $available);

            if ($total && $available) {
                $memInfo['total'] = ((int) $total[1]) * 1024;
                $memInfo['free'] = ((int) $available[1]) * 1024;
                $memInfo['used'] = $memInfo['total'] - $memInfo['free'];
                $memInfo['percent'] = round(($memInfo['used'] / $memInfo['total']) * 100, 1);
            }
        }

        $memInfo['total_human'] = $this->formatBytes($memInfo['total']);
        $memInfo['used_human'] = $this->formatBytes($memInfo['used']);
        $memInfo['free_human'] = $this->formatBytes($memInfo['free']);

        return $memInfo;
    }

    private function allow(array $extra = []): array
    {
        return array_merge([
            'allowed' => true,
            'reason' => '',
            'code' => self::OK,
        ], $extra);
    }

    private function deny(string $code, string $reason, array $extra = []): array
    {
        return array_merge([
            'allowed' => false,
            'reason' => $reason,
            'code' => $code,
        ], $extra);
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
