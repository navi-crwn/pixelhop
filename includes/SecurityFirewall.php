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
    private bool $preflightMissing = false;
    private static bool $preflightChecked = false;
    private static ?bool $preflightTablesExist = null;
    
    // Blocked patterns in user agents
    private const BAD_BOTS = [
        'semrush', 'ahref', 'mj12bot', 'dotbot', 'petalbot',
        'baiduspider', 'yandexbot', 'sogou', 'exabot',
        'gigabot', 'ia_archiver', 'webzip',
        'python-urllib', 'libwww-perl',
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
            $this->runPreflight();
        } catch (Exception $e) {
            error_log('SecurityFirewall: Database init failed - ' . $e->getMessage());
        }
    }
    
    /**
     * Preflight sekali-per-proses (static flag).
     *
     * DDL inline telah dihapus (D5-03). Tabel kini dibuat di
     * database/schema.sql. Cukup periksa bahwa tabel ip_requests tersedia;
     * bila tidak, firewall dimatikan (fail-closed state di-handle per-query)
     * dan error_log keras agar operator segera menjalankan schema.
     */
    private function runPreflight(): void
    {
        if (self::$preflightChecked) {
            $this->preflightMissing = (self::$preflightTablesExist === false);
            return;
        }
        
        self::$preflightChecked = true;
        
        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'ip_requests'");
            self::$preflightTablesExist = $stmt->fetch() !== false;
        } catch (PDOException $e) {
            self::$preflightTablesExist = false;
        }
        
        if (self::$preflightTablesExist === false) {
            $this->preflightMissing = true;
            $this->settings['enabled'] = false;
            error_log('SecurityFirewall: Table ip_requests missing - firewall disabled for this process. Run database/schema.sql.');
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
            'rate_limit_uploads' => 20,        // uploads per hour (selaras seed database/r2_security_migration.sql)
            'auto_block_threshold' => 10,      // suspicious events before auto-block
            'auto_block_duration' => 24,       // hours
            'blocked_countries' => [],         // empty = don't block by country
        ];
        
        // Load from database if available
        if ($this->db) {
            try {
                $stmt = $this->db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'firewall_%' OR setting_key = 'security_failopen_override'");
                foreach ($stmt->fetchAll() as $row) {
                    $key = str_replace('firewall_', '', $row['setting_key']);
                    $this->settings[$key] = $row['setting_value'];
                }
            } catch (PDOException $e) {
                // Use defaults
            }
            
            if ($this->preflightMissing) {
                $this->settings['enabled'] = false;
            }
        }
    }
    
    /**
     * Main check - run on every request
     * Returns true if request is allowed, false if blocked
     */
    public function check(bool $trackRequest = true): array
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
        
        // Track this request AFTER all limit checks so the current request
        // is not counted against itself (off-by-one).
        if ($trackRequest) {
            $this->trackRequest();
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Check specifically for upload endpoints
     */
    public function checkUpload(): array
    {
        // Hormati flag enabled (D3-11): bila firewall dimatikan (termasuk
        // preflight gagal), izinkan upload dan jangan evaluasi limit upload.
        if (!$this->settings['enabled']) {
            return ['allowed' => true];
        }
        
        $baseCheck = $this->check(false);
        if (!$baseCheck['allowed']) {
            return $baseCheck;
        }
        
        // Additional upload rate limiting
        if ($this->settings['rate_limit_enabled'] && $this->isUploadRateLimited()) {
            $this->logEvent('upload_rate_limited');
            return [
                'allowed' => false,
                'reason' => 'Upload limit exceeded. Please wait before uploading more.',
                'code' => 429,
            ];
        }
        
        // Track the upload request after its own limit checks.
        $this->trackRequest();
        
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
            error_log('SecurityFirewall: isIPBlocked unavailable (no DB) - fail-closed');
            return !($this->settings['security_failopen_override'] ?? false);
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM blocked_ips 
                WHERE ip_address = ? 
                AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $stmt->execute([$this->clientIP]);
            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            error_log('SecurityFirewall: isIPBlocked check failed - ' . $e->getMessage());
            return !($this->settings['security_failopen_override'] ?? false);
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
        
        $bots = array_merge(
            self::BAD_BOTS,
            array_filter(array_map('strtolower', array_map('trim',
                explode(',', (string)($this->settings['firewall_bad_bot_extra'] ?? ''))
            )))
        );
        
        foreach ($bots as $bot) {
            if ($bot !== '' && strpos($userAgent, $bot) !== false) {
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
        // URL-decode sebelum substring-matching agar bypass seperti
        // %2e%2e%2f (../) tertangkap.
        $uri = strtolower(rawurldecode($_SERVER['REQUEST_URI'] ?? ''));
        $queryString = strtolower(rawurldecode($_SERVER['QUERY_STRING'] ?? ''));
        
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (strpos($uri, $pattern) !== false || strpos($queryString, $pattern) !== false) {
                return true;
            }
        }
        
        // Check POST data for injection attempts. Skip body inspection for
        // multipart uploads (php://input is not populated for multipart).
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
            if (!str_starts_with($contentType, 'multipart/form-data')) {
                $postData = file_get_contents('php://input');
                if (preg_match('/(union\s+select|<script|javascript:|on\w+\s*=)/i', $postData)) {
                    return true;
                }
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
            error_log('SecurityFirewall: isRateLimited unavailable (no DB) - fail-closed');
            return !($this->settings['security_failopen_override'] ?? false);
        }
        
        try {
            // Count requests in last minute. The current request is inserted
            // by trackRequest() AFTER all limit checks, so it is not counted
            // against itself (off-by-one fix).
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM ip_requests 
                WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ");
            $stmt->execute([$this->clientIP]);
            $count = (int) $stmt->fetchColumn();
            
            return $count >= $this->settings['rate_limit_requests'];
        } catch (PDOException $e) {
            error_log('SecurityFirewall: isRateLimited check failed - ' . $e->getMessage());
            return !($this->settings['security_failopen_override'] ?? false);
        }
    }
    
    /**
     * Check upload-specific rate limit
     */
    private function isUploadRateLimited(): bool
    {
        if (!$this->settings['rate_limit_enabled']) {
            return false;
        }
        
        if (!$this->db) {
            error_log('SecurityFirewall: isUploadRateLimited unavailable (no DB) - fail-closed');
            return !($this->settings['security_failopen_override'] ?? false);
        }
        
        try {
            // Count uploads in last hour. The current upload request is
            // inserted by trackRequest() AFTER this check.
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
            error_log('SecurityFirewall: isUploadRateLimited check failed - ' . $e->getMessage());
            return !($this->settings['security_failopen_override'] ?? false);
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
                INSERT INTO blocked_ips (ip_address, reason, expires_at)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason), expires_at = VALUES(expires_at)
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
                SELECT ip_address, reason, expires_at, created_at 
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
            $stmt = $this->db->query("SELECT COUNT(*) FROM blocked_ips WHERE expires_at IS NULL OR expires_at > NOW()");
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
            $stmt = $this->db->exec("DELETE FROM blocked_ips WHERE expires_at IS NOT NULL AND expires_at < NOW()");
            $results['expired_blocks'] = $stmt;
            
        } catch (PDOException $e) {
            error_log('SecurityFirewall: Cleanup failed - ' . $e->getMessage());
        }
        
        return $results;
    }
}
