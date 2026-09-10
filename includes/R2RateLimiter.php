<?php
/**
 * PixelHop - R2 Request Rate Limiter
 * Ensures we stay within Cloudflare R2 free tier limits
 * 
 * Free Tier Limits:
 * - Class A (PUT/POST/LIST): 1,000,000 ops/month
 * - Class B (GET): 10,000,000 ops/month
 * 
 * We set conservative daily limits:
 * - Class A: 30,000/day (~900K/month with buffer)
 * - Class B: 300,000/day (~9M/month with buffer)
 */

class R2RateLimiter
{
    private const CLASS_A_DAILY_LIMIT = 30000;  // PUT, POST, LIST operations
    private const CLASS_B_DAILY_LIMIT = 300000; // GET operations
    
    private const CLASS_A_MONTHLY_LIMIT = 900000;  // Buffer from 1M
    private const CLASS_B_MONTHLY_LIMIT = 9000000; // Buffer from 10M
    
    private ?PDO $db = null;
    private array $settings = ['security_failopen_override' => 0];
    private static bool $preflightChecked = false;
    private static ?bool $preflightTableExists = null;

    public function __construct()
    {
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
            error_log('R2RateLimiter: Database init failed - ' . $e->getMessage());
        }
    }
    
    /**
     * Muat tombol darurat security_failopen_override dari site_settings.
     * Nilai di-cache per proses; bila DB error, gunakan default 0 (deny).
     */
    private function loadSettings(): void
    {
        if (!$this->db) {
            return;
        }

        try {
            $stmt = $this->db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'security_failopen_override'");
            $stmt->execute();
            $value = $stmt->fetchColumn();
            if ($value !== false) {
                $this->settings['security_failopen_override'] = (int)$value;
            }
        } catch (PDOException $e) {
            error_log('R2RateLimiter: loadSettings failed - ' . $e->getMessage());
        }
    }

    /**
     * Preflight sekali-per-proses (static flag).
     *
     * DDL inline telah dihapus (D5-03). Tabel r2_operations kini dibuat di
     * database/schema.sql. Bila tabel tidak tersedia, error_log keras dan
     * semua operasi R2 baru ditolak (fail-closed), kecuali admin mengaktifkan
     * tombol darurat security_failopen_override.
     */
    private function runPreflight(): void
    {
        if (self::$preflightChecked) {
            return;
        }

        self::$preflightChecked = true;

        try {
            $stmt = $this->db->query("SHOW TABLES LIKE 'r2_operations'");
            self::$preflightTableExists = $stmt->fetch() !== false;
        } catch (PDOException $e) {
            self::$preflightTableExists = false;
        }

        if (self::$preflightTableExists === false) {
            error_log('R2RateLimiter: Table r2_operations missing. Run database/schema.sql.');
        }
    }
    
    /**
     * Check if we can perform a Class A operation (PUT/POST/LIST)
     */
    public function canPerformClassA(): bool
    {
        return $this->checkLimit('A', self::CLASS_A_DAILY_LIMIT, self::CLASS_A_MONTHLY_LIMIT);
    }
    
    /**
     * Check if we can perform a Class B operation (GET)
     */
    public function canPerformClassB(): bool
    {
        return $this->checkLimit('B', self::CLASS_B_DAILY_LIMIT, self::CLASS_B_MONTHLY_LIMIT);
    }
    
    /**
     * Check rate limit for operation class
     */
    private function checkLimit(string $class, int $dailyLimit, int $monthlyLimit): bool
    {
        if (!$this->db || self::$preflightTableExists === false) {
            error_log("R2RateLimiter: Class {$class} cannot be verified - denying new R2 operations (fail-closed)");
            return (bool)$this->settings['security_failopen_override'];
        }
        
        try {
            // Check daily limit
            $today = date('Y-m-d');
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM r2_operations 
                WHERE operation_class = ? AND DATE(created_at) = ?
            ");
            $stmt->execute([$class, $today]);
            $dailyCount = (int) $stmt->fetchColumn();
            
            if ($dailyCount >= $dailyLimit) {
                error_log("R2RateLimiter: Daily Class {$class} limit reached ({$dailyCount}/{$dailyLimit})");
                return false;
            }
            
            // Check monthly limit
            $monthStart = date('Y-m-01');
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM r2_operations 
                WHERE operation_class = ? AND created_at >= ?
            ");
            $stmt->execute([$class, $monthStart]);
            $monthlyCount = (int) $stmt->fetchColumn();
            
            if ($monthlyCount >= $monthlyLimit) {
                error_log("R2RateLimiter: Monthly Class {$class} limit reached ({$monthlyCount}/{$monthlyLimit})");
                return false;
            }
            
            return true;
        } catch (PDOException $e) {
            error_log('R2RateLimiter: Check failed - ' . $e->getMessage());
            return (bool)$this->settings['security_failopen_override']; // Fail closed: DB error means deny new R2 operations unless overridden
        }
    }
    
    /**
     * Record a Class A operation (PUT, POST, LIST)
     */
    public function recordClassA(string $operationType, ?string $fileKey = null, int $fileSize = 0): void
    {
        $this->recordOperation('A', $operationType, $fileKey, $fileSize);
    }
    
    /**
     * Record a Class B operation (GET)
     */
    public function recordClassB(string $operationType, ?string $fileKey = null): void
    {
        $this->recordOperation('B', $operationType, $fileKey, 0);
    }
    
    /**
     * Record an operation
     */
    private function recordOperation(string $class, string $type, ?string $fileKey, int $fileSize): void
    {
        if (!$this->db || self::$preflightTableExists === false) {
            error_log("R2RateLimiter: Cannot record Class {$class} operation - DB/table unavailable");
            return;
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO r2_operations (operation_class, operation_type, file_key, file_size)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$class, $type, $fileKey, $fileSize]);
        } catch (PDOException $e) {
            error_log('R2RateLimiter: Record failed - ' . $e->getMessage());
        }
    }
    
    /**
     * Get current usage stats
     */
    public function getUsageStats(): array
    {
        $stats = [
            'daily' => [
                'class_a' => ['used' => 0, 'limit' => self::CLASS_A_DAILY_LIMIT, 'percentage' => 0],
                'class_b' => ['used' => 0, 'limit' => self::CLASS_B_DAILY_LIMIT, 'percentage' => 0],
            ],
            'monthly' => [
                'class_a' => ['used' => 0, 'limit' => self::CLASS_A_MONTHLY_LIMIT, 'percentage' => 0],
                'class_b' => ['used' => 0, 'limit' => self::CLASS_B_MONTHLY_LIMIT, 'percentage' => 0],
            ],
        ];
        
        if (!$this->db) {
            return $stats;
        }
        
        try {
            $today = date('Y-m-d');
            $monthStart = date('Y-m-01');
            
            // Daily counts
            $stmt = $this->db->prepare("
                SELECT operation_class, COUNT(*) as cnt 
                FROM r2_operations 
                WHERE DATE(created_at) = ?
                GROUP BY operation_class
            ");
            $stmt->execute([$today]);
            foreach ($stmt->fetchAll() as $row) {
                $key = $row['operation_class'] === 'A' ? 'class_a' : 'class_b';
                $limit = $row['operation_class'] === 'A' ? self::CLASS_A_DAILY_LIMIT : self::CLASS_B_DAILY_LIMIT;
                $stats['daily'][$key]['used'] = (int) $row['cnt'];
                $stats['daily'][$key]['percentage'] = round(($row['cnt'] / $limit) * 100, 2);
            }
            
            // Monthly counts
            $stmt = $this->db->prepare("
                SELECT operation_class, COUNT(*) as cnt 
                FROM r2_operations 
                WHERE created_at >= ?
                GROUP BY operation_class
            ");
            $stmt->execute([$monthStart]);
            foreach ($stmt->fetchAll() as $row) {
                $key = $row['operation_class'] === 'A' ? 'class_a' : 'class_b';
                $limit = $row['operation_class'] === 'A' ? self::CLASS_A_MONTHLY_LIMIT : self::CLASS_B_MONTHLY_LIMIT;
                $stats['monthly'][$key]['used'] = (int) $row['cnt'];
                $stats['monthly'][$key]['percentage'] = round(($row['cnt'] / $limit) * 100, 2);
            }
        } catch (PDOException $e) {
            error_log('R2RateLimiter: Stats failed - ' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Clean up old operation logs (keep 60 days)
     */
    public function cleanup(): int
    {
        if (!$this->db) {
            return 0;
        }
        
        try {
            $cutoff = date('Y-m-d H:i:s', strtotime('-60 days'));
            $stmt = $this->db->prepare("DELETE FROM r2_operations WHERE created_at < ?");
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log('R2RateLimiter: Cleanup failed - ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get free tier limits info
     */
    public static function getFreeTierLimits(): array
    {
        return [
            'class_a' => [
                'description' => 'PUT, POST, LIST operations',
                'monthly_limit' => 1000000,
                'our_limit' => self::CLASS_A_MONTHLY_LIMIT,
                'daily_limit' => self::CLASS_A_DAILY_LIMIT,
            ],
            'class_b' => [
                'description' => 'GET operations',
                'monthly_limit' => 10000000,
                'our_limit' => self::CLASS_B_MONTHLY_LIMIT,
                'daily_limit' => self::CLASS_B_DAILY_LIMIT,
            ],
        ];
    }
}
