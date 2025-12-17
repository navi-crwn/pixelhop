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

class Gatekeeper
{
    private PDO $db;
    private array $settings = [];


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
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value, setting_type FROM site_settings");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $value = $row['setting_value'];


                switch ($row['setting_type']) {
                    case 'int':
                        $value = (int) $value;
                        break;
                    case 'bool':
                        $value = (bool) (int) $value;
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }

                $this->settings[$row['setting_key']] = $value;
            }
        } catch (Exception $e) {
            error_log('Gatekeeper: Failed to load settings - ' . $e->getMessage());

            $this->settings = [
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
    }

    /**
     * Get a specific setting value
     */
    public function getSetting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
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

        if ($this->settings['maintenance_mode']) {
            return $this->deny(self::ERROR_MAINTENANCE, 'System is under maintenance. Please try again later.');
        }


        if ($this->settings['kill_switch_active']) {
            return $this->deny(self::ERROR_KILL_SWITCH, 'Uploads are temporarily disabled due to storage limits.');
        }


        $emergencyThreshold = 263070212096;
        $globalUsed = $this->settings['global_storage_used'];

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
                    $storageLimit = min($storageLimit, $this->settings['storage_limit_free']);
                } elseif ($user['account_type'] === 'premium') {
                    $storageLimit = min($storageLimit, $this->settings['storage_limit_premium']);
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

        if ($this->settings['maintenance_mode']) {
            return $this->deny(self::ERROR_MAINTENANCE, 'System is under maintenance. Please try again later.');
        }


        $loadAvg = sys_getloadavg();
        $currentLoad = $loadAvg[0];
        $threshold = (float) $this->settings['cpu_load_threshold'];

        if ($currentLoad > $threshold) {
            return $this->deny(
                self::ERROR_SERVER_BUSY,
                sprintf('Server is busy (load: %.1f). Please try again in a few moments.', $currentLoad),
                ['cpu_load' => $currentLoad, 'threshold' => $threshold]
            );
        }


        $maxProcesses = (int) $this->settings['max_concurrent_processes'];
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
                $limitKey = "daily_{$toolName}_limit_{$accountType}";
                $countKey = "daily_{$toolName}_count";

                $dailyLimit = $this->settings[$limitKey] ?? 5;
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

            $countColumn = $toolName === 'ocr' ? 'daily_ocr_count' : 'daily_removebg_count';

            $stmt = $this->db->prepare("UPDATE users SET {$countColumn} = {$countColumn} + 1 WHERE id = ?");
            $stmt->execute([$userId]);


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
            if ($delta >= 0) {
                $stmt = $this->db->prepare("
                    UPDATE site_settings
                    SET setting_value = setting_value + ?
                    WHERE setting_key = 'global_storage_used'
                ");
            } else {
                $stmt = $this->db->prepare("
                    UPDATE site_settings
                    SET setting_value = GREATEST(0, CAST(setting_value AS SIGNED) - ?)
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
            $lifetime = (int) $this->settings['temp_file_lifetime_hours'];
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


        $pythonProcesses = $this->countPythonProcesses();

        return [
            'cpu' => [
                'load_1m' => round($loadAvg[0], 2),
                'load_5m' => round($loadAvg[1], 2),
                'load_15m' => round($loadAvg[2], 2),
                'threshold' => (float) $this->settings['cpu_load_threshold'],
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
            'storage' => [
                'global_used' => $this->settings['global_storage_used'],
                'global_used_human' => $this->formatBytes($this->settings['global_storage_used']),
                'cap' => 268435456000,
                'cap_human' => '250 GB',
                'percent' => round(($this->settings['global_storage_used'] / 268435456000) * 100, 2),
            ],
            'processes' => [
                'python_running' => $pythonProcesses,
                'max_allowed' => (int) $this->settings['max_concurrent_processes'],
            ],
            'status' => [
                'maintenance' => $this->settings['maintenance_mode'],
                'kill_switch' => $this->settings['kill_switch_active'],
            ],
        ];
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
            ? $this->settings['storage_limit_premium']
            : $this->settings['storage_limit_free'];

        $ocrLimit = $accountType === 'premium'
            ? $this->settings['daily_ocr_limit_premium']
            : $this->settings['daily_ocr_limit_free'];

        $removebgLimit = $accountType === 'premium'
            ? $this->settings['daily_removebg_limit_premium']
            : $this->settings['daily_removebg_limit_free'];

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

    private function resetDailyCountersIfNeeded(int $userId, array $user): void
    {
        $today = date('Y-m-d');
        $lastReset = $user['daily_reset_at'] ?? null;

        if ($lastReset !== $today) {
            try {
                $stmt = $this->db->prepare("
                    UPDATE users
                    SET daily_ocr_count = 0, daily_removebg_count = 0, daily_reset_at = ?
                    WHERE id = ?
                ");
                $stmt->execute([$today, $userId]);
            } catch (Exception $e) {
                error_log('Gatekeeper: Failed to reset daily counters - ' . $e->getMessage());
            }
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
