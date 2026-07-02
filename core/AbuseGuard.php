<?php
/**
 * PixelHop - AbuseGuard
 * Automated Abuse Prevention & Watchdog System
 *
 * Features:
 * - IP-based abuse detection
 * - Automatic blocking of abusive patterns
 * - Configurable thresholds
 * - Self-healing cleanup
 * - Admin reporting
 */

require_once __DIR__ . '/../includes/Database.php';

class AbuseGuard
{
    private PDO $db;
    private array $settings = [];


    public const ABUSE_UPLOAD_SPAM = 'upload_spam';
    public const ABUSE_API_ABUSE = 'api_abuse';
    public const ABUSE_BRUTE_FORCE = 'brute_force';
    public const ABUSE_SUSPICIOUS_CONTENT = 'suspicious_content';
    public const ABUSE_BANDWIDTH_ABUSE = 'bandwidth_abuse';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->ensureTables();
        $this->loadSettings();
    }

    /**
     * Ensure required tables exist
     * (blocked_ips is created by SecurityFirewall with a blocked_until column)
     */
    private function ensureTables(): void
    {
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS abuse_logs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(45) NOT NULL,
                    user_id INT UNSIGNED NULL,
                    abuse_type VARCHAR(32) NOT NULL,
                    severity VARCHAR(16) NOT NULL DEFAULT 'low',
                    details TEXT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

                    INDEX idx_ip (ip_address),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Exception $e) {
            error_log('AbuseGuard: ensureTables failed - ' . $e->getMessage());
        }
    }

    /**
     * Load abuse-related settings
     */
    private function loadSettings(): void
    {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'abuse_%'");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            $this->settings = [
                'abuse_threshold_uploads_per_hour' => 50,
                'abuse_threshold_uploads_per_day' => 200,
                'abuse_block_duration_hours' => 24,
                'abuse_auto_block_enabled' => 1,
                'abuse_guest_upload_enabled' => 1,
                'abuse_max_file_size_guest_mb' => 5,
            ];
        }
    }

    /**
     * Get setting value
     */
    public function getSetting(string $key, $default = null)
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Check if IP is blocked
     */
    public function isBlocked(string $ip): bool
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM blocked_ips
                WHERE ip_address = ?
                AND (blocked_until IS NULL OR blocked_until > NOW())
            ");
            $stmt->execute([$ip]);
            return (bool) $stmt->fetch();
        } catch (Exception $e) {
            error_log('AbuseGuard: isBlocked check failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Block an IP address
     */
    public function blockIP(string $ip, string $reason = '', int $durationHours = null, string $blockedBy = 'auto'): bool
    {
        if ($durationHours === null) {
            $durationHours = (int) $this->getSetting('abuse_block_duration_hours', 24);
        }

        $expiresAt = $durationHours > 0 ? date('Y-m-d H:i:s', strtotime("+{$durationHours} hours")) : null;

        try {
            $stmt = $this->db->prepare("
                INSERT INTO blocked_ips (ip_address, reason, blocked_until)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_until = VALUES(blocked_until)
            ");
            return $stmt->execute([$ip, "[{$blockedBy}] {$reason}", $expiresAt]);
        } catch (Exception $e) {
            error_log('AbuseGuard: Failed to block IP - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Unblock an IP address
     */
    public function unblockIP(string $ip): bool
    {
        $stmt = $this->db->prepare("DELETE FROM blocked_ips WHERE ip_address = ?");
        return $stmt->execute([$ip]);
    }

    /**
     * Log abuse incident
     */
    public function logAbuse(string $ip, string $type, string $severity = 'low', ?int $userId = null, string $details = ''): int
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO abuse_logs (ip_address, user_id, abuse_type, severity, details)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ip, $userId, $type, $severity, $details]);
            return (int) $this->db->lastInsertId();
        } catch (Exception $e) {
            error_log('AbuseGuard: Failed to log abuse - ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check upload and enforce limits
     * Called before processing upload
     */
    public function checkUpload(string $ip, ?int $userId = null, int $fileSize = 0): array
    {

        if ($this->isBlocked($ip)) {
            return [
                'allowed' => false,
                'reason' => 'Your IP has been temporarily blocked due to abuse. Please try again later.',
                'code' => 'ip_blocked'
            ];
        }


        if ($userId === null && !$this->getSetting('abuse_guest_upload_enabled', 1)) {
            return [
                'allowed' => false,
                'reason' => 'Guest uploads are currently disabled. Please login to upload.',
                'code' => 'guest_disabled'
            ];
        }


        if ($userId === null) {
            $maxSizeMB = (int) $this->getSetting('abuse_max_file_size_guest_mb', 5);
            $maxBytes = $maxSizeMB * 1024 * 1024;
            if ($fileSize > $maxBytes) {
                return [
                    'allowed' => false,
                    'reason' => "Guests can only upload files up to {$maxSizeMB}MB. Please login for larger uploads.",
                    'code' => 'guest_size_limit'
                ];
            }
            
            // Check daily bandwidth limit for guests (100MB default)
            $dailyBandwidthMB = (int) $this->getSetting('abuse_guest_daily_bandwidth_mb', 100);
            $usedBandwidth = $this->getUploadBandwidth($ip, '-24 hours');
            $usedBandwidthMB = $usedBandwidth / (1024 * 1024);
            
            if (($usedBandwidth + $fileSize) > ($dailyBandwidthMB * 1024 * 1024)) {
                $this->logAbuse($ip, self::ABUSE_BANDWIDTH_ABUSE, 'medium', null,
                    "Guest exceeded daily bandwidth: " . round($usedBandwidthMB, 2) . "MB/{$dailyBandwidthMB}MB");
                return [
                    'allowed' => false,
                    'reason' => "Daily upload limit reached ({$dailyBandwidthMB}MB). Please register for more storage.",
                    'code' => 'guest_bandwidth_limit',
                    'retry_after' => 86400
                ];
            }
        }


        $hourlyCount = $this->getUploadCount($ip, '-1 hour');
        $hourlyLimit = (int) $this->getSetting('abuse_threshold_uploads_per_hour', 100);

        if ($hourlyCount >= $hourlyLimit) {

            $this->logAbuse($ip, self::ABUSE_UPLOAD_SPAM, 'medium', $userId,
                "Exceeded hourly upload limit: {$hourlyCount}/{$hourlyLimit}");


            if ($this->getSetting('abuse_auto_block_enabled', 1)) {
                $dailyCount = $this->getUploadCount($ip, '-24 hours');
                $dailyLimit = (int) $this->getSetting('abuse_threshold_uploads_per_day', 2000);

                if ($dailyCount >= $dailyLimit) {
                    $this->blockIP($ip, 'Automatic block: Exceeded daily upload limit', null, 'auto');
                    $this->logAbuse($ip, self::ABUSE_UPLOAD_SPAM, 'high', $userId,
                        "Auto-blocked: Exceeded daily limit {$dailyCount}/{$dailyLimit}");
                }
            }

            return [
                'allowed' => false,
                'reason' => 'Upload limit reached. Please wait before uploading more images.',
                'code' => 'rate_limit',
                'retry_after' => 3600
            ];
        }
        
        // Check daily count limit
        $dailyCount = $this->getUploadCount($ip, '-24 hours');
        $dailyLimit = (int) $this->getSetting('abuse_threshold_uploads_per_day', 2000);
        
        if ($dailyCount >= $dailyLimit) {
            $this->logAbuse($ip, self::ABUSE_UPLOAD_SPAM, 'high', $userId,
                "Exceeded daily upload limit: {$dailyCount}/{$dailyLimit}");
            return [
                'allowed' => false,
                'reason' => 'Daily upload limit reached. Please try again tomorrow.',
                'code' => 'daily_limit',
                'retry_after' => 86400
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Record successful upload (for tracking)
     */
    public function recordUpload(string $ip, ?int $userId = null, int $fileSize = 0): void
    {

    }

    /**
     * Get upload count for IP in time period
     */
    private function getUploadCount(string $ip, string $since): int
    {

        $imagesFile = __DIR__ . '/../data/images.json';
        if (!file_exists($imagesFile)) {
            return 0;
        }

        $images = json_decode(file_get_contents($imagesFile), true) ?: [];
        $sinceTimestamp = strtotime($since);
        $count = 0;

        foreach ($images as $img) {
            if (($img['ip'] ?? '') === $ip && ($img['created_at'] ?? 0) >= $sinceTimestamp) {
                $count++;
            }
        }

        return $count;
    }
    
    /**
     * Get upload bandwidth (total bytes) for IP in time period
     */
    private function getUploadBandwidth(string $ip, string $since): int
    {
        $imagesFile = __DIR__ . '/../data/images.json';
        if (!file_exists($imagesFile)) {
            return 0;
        }

        $images = json_decode(file_get_contents($imagesFile), true) ?: [];
        $sinceTimestamp = strtotime($since);
        $totalBytes = 0;

        foreach ($images as $img) {
            if (($img['ip'] ?? '') === $ip && ($img['created_at'] ?? 0) >= $sinceTimestamp) {
                $totalBytes += $img['size'] ?? 0;
            }
        }

        return $totalBytes;
    }

    /**
     * Run watchdog scan - called by cron
     * Detects abuse patterns and takes action
     */
    public function runWatchdog(): array
    {
        $report = [
            'timestamp' => date('Y-m-d H:i:s'),
            'scanned' => 0,
            'suspicious_ips' => [],
            'blocked' => 0,
            'warnings' => 0,
            'cleaned_expired' => 0,
        ];


        $report['cleaned_expired'] = $this->cleanExpiredBlocks();


        $imagesFile = __DIR__ . '/../data/images.json';
        if (!file_exists($imagesFile)) {
            return $report;
        }

        $images = json_decode(file_get_contents($imagesFile), true) ?: [];
        $since24h = strtotime('-24 hours');


        $ipStats = [];
        foreach ($images as $img) {
            $ip = $img['ip'] ?? 'unknown';
            $createdAt = $img['created_at'] ?? 0;

            if ($createdAt >= $since24h) {
                if (!isset($ipStats[$ip])) {
                    $ipStats[$ip] = [
                        'count' => 0,
                        'size' => 0,
                        'user_ids' => [],
                        'is_guest' => true
                    ];
                }

                $ipStats[$ip]['count']++;
                $ipStats[$ip]['size'] += $img['size'] ?? 0;

                if (isset($img['user_id']) && $img['user_id']) {
                    $ipStats[$ip]['user_ids'][] = $img['user_id'];
                    $ipStats[$ip]['is_guest'] = false;
                }
            }
        }

        $report['scanned'] = count($ipStats);


        $hourlyThreshold = (int) $this->getSetting('abuse_threshold_uploads_per_hour', 50);
        $dailyThreshold = (int) $this->getSetting('abuse_threshold_uploads_per_day', 200);
        $autoBlockEnabled = (bool) $this->getSetting('abuse_auto_block_enabled', 1);

        foreach ($ipStats as $ip => $stats) {
            if ($ip === 'unknown') continue;


            if ($this->isBlocked($ip)) continue;

            $suspicionLevel = 0;
            $reasons = [];


            if ($stats['count'] >= $dailyThreshold) {
                $suspicionLevel = 3;
                $reasons[] = "Daily uploads ({$stats['count']}) exceeded threshold ({$dailyThreshold})";
            } elseif ($stats['count'] >= $dailyThreshold * 0.75) {
                $suspicionLevel = max($suspicionLevel, 2);
                $reasons[] = "Daily uploads ({$stats['count']}) approaching threshold";
            } elseif ($stats['count'] >= $hourlyThreshold) {
                $suspicionLevel = max($suspicionLevel, 1);
                $reasons[] = "High upload activity ({$stats['count']} in 24h)";
            }


            if ($stats['size'] > 500 * 1024 * 1024) {
                $suspicionLevel = max($suspicionLevel, 2);
                $sizeMB = round($stats['size'] / 1024 / 1024, 1);
                $reasons[] = "High bandwidth usage ({$sizeMB}MB in 24h)";
            }


            if ($stats['is_guest'] && $stats['count'] > 30) {
                $suspicionLevel = max($suspicionLevel, 1);
                $reasons[] = "Guest with high activity ({$stats['count']} uploads)";
            }


            if ($suspicionLevel >= 3 && $autoBlockEnabled) {
                $this->blockIP($ip, 'Watchdog: ' . implode('; ', $reasons));
                $this->logAbuse($ip, self::ABUSE_UPLOAD_SPAM, 'critical', null, implode('; ', $reasons));
                $report['blocked']++;
                $report['suspicious_ips'][] = [
                    'ip' => $ip,
                    'level' => 'critical',
                    'action' => 'blocked',
                    'stats' => $stats,
                    'reasons' => $reasons
                ];
            } elseif ($suspicionLevel >= 1) {
                $severity = $suspicionLevel >= 2 ? 'high' : 'medium';
                $this->logAbuse($ip, self::ABUSE_UPLOAD_SPAM, $severity, null, implode('; ', $reasons));
                $report['warnings']++;
                $report['suspicious_ips'][] = [
                    'ip' => $ip,
                    'level' => $severity,
                    'action' => 'logged',
                    'stats' => $stats,
                    'reasons' => $reasons
                ];
            }
        }

        return $report;
    }

    /**
     * Clean expired IP blocks
     */
    public function cleanExpiredBlocks(): int
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM blocked_ips WHERE blocked_until IS NOT NULL AND blocked_until <= NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Exception $e) {
            error_log('AbuseGuard: Failed to clean expired blocks - ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get blocked IPs list
     */
    public function getBlockedIPs(int $limit = 100): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM blocked_ips
            ORDER BY created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get abuse logs
     */
    public function getAbuseLogs(int $limit = 100, ?string $type = null, ?string $ip = null): array
    {
        $sql = "SELECT * FROM abuse_logs WHERE 1=1";
        $params = [];

        if ($type) {
            $sql .= " AND abuse_type = ?";
            $params[] = $type;
        }

        if ($ip) {
            $sql .= " AND ip_address = ?";
            $params[] = $ip;
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get abuse statistics
     */
    public function getStats(): array
    {
        $stats = [];


        $stmt = $this->db->query("SELECT COUNT(*) FROM blocked_ips WHERE blocked_until IS NULL OR blocked_until > NOW()");
        $stats['blocked_ips'] = (int) $stmt->fetchColumn();


        $stmt = $this->db->query("SELECT COUNT(*) FROM abuse_logs WHERE DATE(created_at) = CURDATE()");
        $stats['incidents_today'] = (int) $stmt->fetchColumn();


        $stmt = $this->db->query("SELECT COUNT(*) FROM abuse_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stats['incidents_week'] = (int) $stmt->fetchColumn();


        $stmt = $this->db->query("
            SELECT severity, COUNT(*) as count
            FROM abuse_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY severity
        ");
        $stats['by_severity'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);


        $stmt = $this->db->query("
            SELECT ip_address, COUNT(*) as count, MAX(created_at) as last_incident
            FROM abuse_logs
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY ip_address
            ORDER BY count DESC
            LIMIT 10
        ");
        $stats['top_offenders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $stats;
    }
}
