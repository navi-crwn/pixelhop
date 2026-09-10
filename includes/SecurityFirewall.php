<?php
/**
 * PixelHop - Security Firewall
 * Protection against common attacks and abuse
 * 
 * Features:
 * - IP-based rate limiting
 * - Country blocking (optional)
 * - Bad bot detection
 * - Request validation
 * - Suspicious pattern detection
 */

class SecurityFirewall
{
    private ?PDO $db = null;
    private string $clientIP;
    private array $settings = [];
    
    // Blocked patterns in user agents
    private const BAD_BOTS = [
        'semrush', 'ahref', 'mj12bot', 'dotbot', 'petalbot',
        'baiduspider', 'yandexbot', 'sogou', 'exabot',
        'gigabot', 'ia_archiver', 'webzip', 'wget', 'curl',
        'python-requests', 'python-urllib', 'libwww-perl',
        'nikto', 'sqlmap', 'nmap', 'masscan', 'zgrab',
    ];
    
    // Suspicious URL patterns
    private const SUSPICIOUS_PATTERNS = [
        '/wp-admin', '/wp-login', '/xmlrpc.php', '/.env',
        '/config.php', '/phpmyadmin', '/admin/config',
        '/.git', '/.svn', '/backup', '/shell', '/c99',
        '/eval', '/base64_decode', '/passthru', '/exec',
        'union+select', 'concat(', '../', '..\\',
    ];
    
    // High-risk countries (optional, set in settings)
    private array $blockedCountries = [];
    
    public function __construct()
    {
        $this->clientIP = $this->getClientIP();
        $this->initDatabase();
        $this->loadSettings();
    }
    
    private function initDatabase(): void
    {
        try {
            require_once __DIR__ . '/Database.php';
            $this->db = Database::getInstance();
            $this->ensureTables();
        } catch (Exception $e) {
            error_log('SecurityFirewall: Database init failed - ' . $e->getMessage());
        }
    }
    
    private function ensureTables(): void
    {
        try {
            // Blocked IPs table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS blocked_ips (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(45) NOT NULL,
                    reason VARCHAR(255) NOT NULL,
                    blocked_until DATETIME NULL COMMENT 'NULL = permanent',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    
                    UNIQUE KEY unique_ip (ip_address),
                    INDEX idx_blocked_until (blocked_until)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Security events log
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS security_events (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(45) NOT NULL,
                    event_type VARCHAR(32) NOT NULL,
                    details TEXT NULL,
                    user_agent VARCHAR(512) NULL,
                    request_uri VARCHAR(512) NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_ip_type (ip_address, event_type),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // IP request tracking for rate limiting
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS ip_requests (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ip_address VARCHAR(45) NOT NULL,
                    request_path VARCHAR(255) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_ip_time (ip_address, created_at),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (PDOException $e) {
            // Tables might already exist
        }
    }
    
    private function loadSettings(): void
    {
        $this->settings = [
            'enabled' => true,
            'block_bad_bots' => true,
            'block_suspicious_patterns' => true,
            'rate_limit_enabled' => true,
            'rate_limit_requests' => 100,      // requests per minute
            'rate_limit_uploads' => 300,       // uploads per hour (raised to reduce false 429s)
            'auto_block_threshold' => 10,      // suspicious events before auto-block
            'auto_block_duration' => 24,       // hours
            'blocked_countries' => [],         // empty = don't block by country
        ];
        
        // Load from database if available
        if ($this->db) {
            try {
                $stmt = $this->db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'firewall_%'");
                foreach ($stmt->fetchAll() as $row) {
                    $key = str_replace('firewall_', '', $row['setting_key']);
                    $this->settings[$key] = $row['setting_value'];
                }
            } catch (PDOException $e) {
                // Use defaults
            }
        }
    }
    
    /**
     * Main check - run on every request
     * Returns true if request is allowed, false if blocked
     */
    public function check(): array
    {
        if (!$this->settings['enabled']) {
            return ['allowed' => true];
        }
        
        // Check if IP is blocked
        if ($this->isIPBlocked()) {
            return [
                'allowed' => false,
                'reason' => 'IP address is blocked',
                'code' => 403,
            ];
        }
        
        // Check for bad bots
        if ($this->settings['block_bad_bots'] && $this->isBadBot()) {
            $this->logEvent('bad_bot');
            return [
                'allowed' => false,
                'reason' => 'Automated access not allowed',
                'code' => 403,
            ];
        }
        
        // Check for suspicious patterns
        if ($this->settings['block_suspicious_patterns'] && $this->hasSuspiciousPattern()) {
            $this->logEvent('suspicious_pattern');
            $this->maybeAutoBlock();
            return [
                'allowed' => false,
                'reason' => 'Suspicious request blocked',
                'code' => 403,
            ];
        }
        
        // Rate limiting
        if ($this->settings['rate_limit_enabled'] && $this->isRateLimited()) {
            $this->logEvent('rate_limited');
            return [
                'allowed' => false,
                'reason' => 'Too many requests. Please slow down.',
                'code' => 429,
            ];
        }
        
        // Track this request
        $this->trackRequest();
        
        return ['allowed' => true];
    }
    
    /**
     * Check specifically for upload endpoints
     */
    public function checkUpload(): array
    {
        $baseCheck = $this->check();
        if (!$baseCheck['allowed']) {
            return $baseCheck;
        }
        
        // Additional upload rate limiting
        if ($this->isUploadRateLimited()) {
            $this->logEvent('upload_rate_limited');
            return [
                'allowed' => false,
                'reason' => 'Upload limit exceeded. Please wait before uploading more.',
                'code' => 429,
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Get client IP address (Cloudflare aware)
     */
    private function getClientIP(): string
    {
        require_once __DIR__ . '/ClientIp.php';
        return ClientIp::get();
    }
    
    /**
     * Check if IP is in blocked list
     */
    private function isIPBlocked(): bool
    {
        if (!$this->db) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM blocked_ips 
                WHERE ip_address = ? 
                AND (blocked_until IS NULL OR blocked_until > NOW())
            ");
            $stmt->execute([$this->clientIP]);
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Check if request is from a bad bot
     */
    private function isBadBot(): bool
    {
        $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        
        if (empty($userAgent)) {
            return true; // No user agent = suspicious
        }
        
        foreach (self::BAD_BOTS as $bot) {
            if (strpos($userAgent, $bot) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check for suspicious patterns in request
     */
    private function hasSuspiciousPattern(): bool
    {
        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
        $queryString = strtolower($_SERVER['QUERY_STRING'] ?? '');
        
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (strpos($uri, $pattern) !== false || strpos($queryString, $pattern) !== false) {
                return true;
            }
        }
        
        // Check POST data for injection attempts
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $postData = file_get_contents('php://input');
            if (preg_match('/(union\s+select|<script|javascript:|on\w+\s*=)/i', $postData)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if IP has exceeded rate limit
     */
    private function isRateLimited(): bool
    {
        if (!$this->db) {
            return false;
        }
        
        try {
            // Count requests in last minute
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM ip_requests 
                WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ");
            $stmt->execute([$this->clientIP]);
            $count = (int) $stmt->fetchColumn();
            
            return $count >= $this->settings['rate_limit_requests'];
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Check upload-specific rate limit
     */
    private function isUploadRateLimited(): bool
    {
        if (!$this->db) {
            return false;
        }
        
        try {
            // Count uploads in last hour
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM ip_requests 
                WHERE ip_address = ? 
                AND request_path LIKE '%upload%'
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$this->clientIP]);
            $count = (int) $stmt->fetchColumn();
            
            return $count >= $this->settings['rate_limit_uploads'];
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Track request for rate limiting
     */
    private function trackRequest(): void
    {
        if (!$this->db) {
            return;
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO ip_requests (ip_address, request_path)
                VALUES (?, ?)
            ");
            $stmt->execute([$this->clientIP, $_SERVER['REQUEST_URI'] ?? '/']);
        } catch (PDOException $e) {
            // Non-critical, ignore
        }
    }
    
    /**
     * Log security event
     */
    private function logEvent(string $eventType, ?string $details = null): void
    {
        if (!$this->db) {
            return;
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO security_events (ip_address, event_type, details, user_agent, request_uri)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $this->clientIP,
                $eventType,
                $details,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                substr($_SERVER['REQUEST_URI'] ?? '', 0, 512),
            ]);
        } catch (PDOException $e) {
            error_log('SecurityFirewall: Log event failed - ' . $e->getMessage());
        }
    }
    
    /**
     * Auto-block IP if too many suspicious events
     */
    private function maybeAutoBlock(): void
    {
        if (!$this->db) {
            return;
        }
        
        try {
            // Count recent suspicious events
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM security_events 
                WHERE ip_address = ? 
                AND event_type IN ('suspicious_pattern', 'bad_bot', 'rate_limited')
                AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute([$this->clientIP]);
            $count = (int) $stmt->fetchColumn();
            
            if ($count >= $this->settings['auto_block_threshold']) {
                $this->blockIP($this->clientIP, 'Auto-blocked: too many suspicious requests', $this->settings['auto_block_duration']);
            }
        } catch (PDOException $e) {
            // Non-critical
        }
    }
    
    /**
     * Block an IP address
     */
    public function blockIP(string $ip, string $reason, ?int $hours = null): bool
    {
        if (!$this->db) {
            return false;
        }
        
        try {
            $blockedUntil = $hours ? date('Y-m-d H:i:s', strtotime("+{$hours} hours")) : null;
            
            $stmt = $this->db->prepare("
                INSERT INTO blocked_ips (ip_address, reason, blocked_until)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_until = VALUES(blocked_until)
            ");
            $stmt->execute([$ip, $reason, $blockedUntil]);
            
            $this->logEvent('ip_blocked', "Reason: {$reason}, Duration: " . ($hours ? "{$hours}h" : 'permanent'));
            return true;
        } catch (PDOException $e) {
            error_log('SecurityFirewall: Block IP failed - ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Unblock an IP address
     */
    public function unblockIP(string $ip): bool
    {
        if (!$this->db) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("DELETE FROM blocked_ips WHERE ip_address = ?");
            $stmt->execute([$ip]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get list of blocked IPs
     */
    public function getBlockedIPs(): array
    {
        if (!$this->db) {
            return [];
        }
        
        try {
            $stmt = $this->db->query("
                SELECT ip_address, reason, blocked_until, created_at 
                FROM blocked_ips 
                ORDER BY created_at DESC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get recent security events
     */
    public function getRecentEvents(int $limit = 100): array
    {
        if (!$this->db) {
            return [];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT ip_address, event_type, details, user_agent, request_uri, created_at 
                FROM security_events 
                ORDER BY created_at DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get security stats
     */
    public function getStats(): array
    {
        $stats = [
            'blocked_ips' => 0,
            'events_today' => 0,
            'events_by_type' => [],
            'top_blocked_reasons' => [],
        ];
        
        if (!$this->db) {
            return $stats;
        }
        
        try {
            // Blocked IPs count
            $stmt = $this->db->query("SELECT COUNT(*) FROM blocked_ips WHERE blocked_until IS NULL OR blocked_until > NOW()");
            $stats['blocked_ips'] = (int) $stmt->fetchColumn();
            
            // Events today
            $stmt = $this->db->query("SELECT COUNT(*) FROM security_events WHERE DATE(created_at) = CURDATE()");
            $stats['events_today'] = (int) $stmt->fetchColumn();
            
            // Events by type (last 7 days)
            $stmt = $this->db->query("
                SELECT event_type, COUNT(*) as cnt 
                FROM security_events 
                WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY event_type
                ORDER BY cnt DESC
            ");
            $stats['events_by_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
        } catch (PDOException $e) {
            // Return default stats
        }
        
        return $stats;
    }
    
    /**
     * Cleanup old data
     */
    public function cleanup(): array
    {
        $results = ['ip_requests' => 0, 'security_events' => 0, 'expired_blocks' => 0];
        
        if (!$this->db) {
            return $results;
        }
        
        try {
            // Clean old request tracking (keep 1 hour)
            $stmt = $this->db->exec("DELETE FROM ip_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $results['ip_requests'] = $stmt;
            
            // Clean old events (keep 30 days)
            $stmt = $this->db->exec("DELETE FROM security_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $results['security_events'] = $stmt;
            
            // Remove expired blocks
            $stmt = $this->db->exec("DELETE FROM blocked_ips WHERE blocked_until IS NOT NULL AND blocked_until < NOW()");
            $results['expired_blocks'] = $stmt;
            
        } catch (PDOException $e) {
            error_log('SecurityFirewall: Cleanup failed - ' . $e->getMessage());
        }
        
        return $results;
    }
}
