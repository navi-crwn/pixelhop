<?php
/**
 * SafeGuard Engine - Unified Content Moderation
 * Standalone PHP version for PicHost (p.hel.ink)
 * 
 * Shares rate limiting with hel.ink via shared database
 * 
 * Features:
 * - Auto-retry on rate limit (429)
 * - Approve first, verify later (async moderation)
 * - Priority queue: Guest > Registered users
 * - Auto-takedown on threat detection
 */

require_once __DIR__ . '/../includes/Database.php';

class SafeGuard
{
    private PDO $db;
    private ?PDO $helinkDb = null;
    private string $platform = 'pichost';
    
    // API Keys
    private ?string $virusTotalKey;
    private ?string $safeBrowsingKey;
    private ?string $geminiKey;
    
    // Priority levels (higher = scanned first)
    public const PRIORITY_CRITICAL = 100;  // Reported content
    public const PRIORITY_GUEST = 50;      // Guest uploads - higher priority
    public const PRIORITY_USER = 20;       // Registered users
    public const PRIORITY_RESCAN = 10;     // Periodic rescan
    
    // Threat types
    public const THREAT_SAFE = 'safe';
    public const THREAT_MALWARE = 'malware';
    public const THREAT_PHISHING = 'phishing';
    public const THREAT_ADULT = 'adult_content';
    public const THREAT_CSAM = 'csam';
    public const THREAT_VIOLENCE = 'violence';
    public const THREAT_SUSPICIOUS = 'suspicious';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loadApiKeys();
    }

    /**
     * Get helink database connection (shared rate limits & queue)
     */
    private function getHelinkDb(): PDO
    {
        if ($this->helinkDb === null) {
            $this->helinkDb = new PDO(
                'mysql:host=127.0.0.1;dbname=helink_db;charset=utf8mb4',
                'helink_user',
                'VeryStrongPassword2024',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }
        return $this->helinkDb;
    }

    /**
     * Load API keys from environment or config
     */
    private function loadApiKeys(): void
    {
        $this->virusTotalKey = getenv('VIRUSTOTAL_API_KEY') ?: 'c0373e9d4935510193a228a4cbcf611b0a545602d7e0b0793863ffc5939f1064';
        $this->safeBrowsingKey = getenv('SAFE_BROWSING_API_KEY') ?: 'AIzaSyAEI_s8LKdmfHfsPLXaTLCZfJIWRxtrA1g';
        $this->geminiKey = getenv('GEMINI_API_KEY') ?: 'AIzaSyDEd_JLObh7fVpJHrxnrSjLT3jC3bA8RVs';
    }

    /**
     * Check if we can make an API request (shared rate limiting)
     */
    public function canMakeRequest(string $service): bool
    {
        try {
            $helinkDb = $this->getHelinkDb();
            
            $stmt = $helinkDb->prepare("
                SELECT * FROM api_rate_status WHERE api_service = ?
            ");
            $stmt->execute([$service]);
            $status = $stmt->fetch(PDO::FETCH_OBJ);
            
            if (!$status) {
                return true;
            }

            // Reset minute counter if needed
            if ($status->minute_reset_at && strtotime($status->minute_reset_at) < time()) {
                $stmt = $helinkDb->prepare("
                    UPDATE api_rate_status 
                    SET requests_per_minute = 0, minute_reset_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE)
                    WHERE api_service = ?
                ");
                $stmt->execute([$service]);
                $status->requests_per_minute = 0;
            }

            // Reset day counter if needed
            if ($status->day_reset_at && strtotime($status->day_reset_at) < time()) {
                $stmt = $helinkDb->prepare("
                    UPDATE api_rate_status 
                    SET requests_per_day = 0, day_reset_at = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
                    WHERE api_service = ?
                ");
                $stmt->execute([$service]);
                $status->requests_per_day = 0;
            }

            return $status->requests_per_minute < $status->limit_per_minute 
                && $status->requests_per_day < $status->limit_per_day;
                
        } catch (Exception $e) {
            error_log('SafeGuard: Rate limit check failed - ' . $e->getMessage());
            return true; // Allow if we can't check
        }
    }

    /**
     * Increment API usage counter (shared with helink)
     */
    private function incrementUsage(string $service): void
    {
        try {
            $helinkDb = $this->getHelinkDb();
            
            $stmt = $helinkDb->prepare("
                UPDATE api_rate_status 
                SET requests_per_minute = requests_per_minute + 1,
                    requests_per_day = requests_per_day + 1,
                    updated_at = NOW()
                WHERE api_service = ?
            ");
            $stmt->execute([$service]);
            
        } catch (Exception $e) {
            error_log('SafeGuard: Usage increment failed - ' . $e->getMessage());
        }
    }

    /**
     * Log API usage
     */
    private function logUsage(string $service, array $data): void
    {
        try {
            $helinkDb = $this->getHelinkDb();
            
            $stmt = $helinkDb->prepare("
                INSERT INTO api_usage_logs 
                (platform, api_service, endpoint, request_type, request_data, response_code, response_time_ms, success, error_message, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $this->platform,
                $service,
                $data['endpoint'] ?? null,
                $data['request_type'] ?? 'check',
                json_encode($data['request'] ?? null),
                $data['response_code'] ?? null,
                $data['response_time_ms'] ?? null,
                $data['success'] ?? true,
                $data['error_message'] ?? null,
            ]);
            
        } catch (Exception $e) {
            error_log('SafeGuard: Log usage failed - ' . $e->getMessage());
        }
    }

    /**
     * Analyze image content with Gemini AI
     */
    public function analyzeImage(string $imagePath): array
    {
        if (!$this->geminiKey) {
            return ['safe' => true, 'skipped' => true, 'reason' => 'API key not configured'];
        }

        if (!$this->canMakeRequest('gemini')) {
            return ['safe' => true, 'queued' => true, 'reason' => 'Rate limit reached'];
        }

        $startTime = microtime(true);
        
        try {
            // Read image and convert to base64
            if (filter_var($imagePath, FILTER_VALIDATE_URL)) {
                $imageData = @file_get_contents($imagePath);
            } else {
                $imageData = @file_get_contents($imagePath);
            }
            
            if (!$imageData) {
                return ['safe' => true, 'skipped' => true, 'reason' => 'Could not read image'];
            }

            $base64Image = base64_encode($imageData);
            $mimeType = $this->detectMimeType($imagePath, $imageData);

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            [
                                'text' => "You are a content safety analyzer. Analyze this image for any of the following:\n" .
                                    "1. CSAM (Child Sexual Abuse Material) - ANY content sexualizing minors\n" .
                                    "2. Adult/Sexual Content - Nudity, pornography, sexually explicit material\n" .
                                    "3. Violence/Gore - Graphic violence, blood, injuries, death\n" .
                                    "4. Hate/Extremism - Hate symbols, extremist content\n" .
                                    "5. Dangerous Content - Weapons, drugs, self-harm\n\n" .
                                    "Reply ONLY with one of these exact formats:\n" .
                                    "- 'SAFE' if the image is safe\n" .
                                    "- 'UNSAFE: [category] - [brief reason]' if unsafe\n\n" .
                                    "Categories: CSAM, ADULT, VIOLENCE, HATE, DANGEROUS"
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $base64Image,
                                ]
                            ]
                        ]
                    ]
                ],
                'safetySettings' => [
                    ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                    ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => 100,
                    'temperature' => 0.1,
                ],
            ];

            $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key={$this->geminiKey}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 30,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $elapsed = round((microtime(true) - $startTime) * 1000);
            $this->incrementUsage('gemini');
            
            $this->logUsage('gemini', [
                'endpoint' => 'gemini-flash-latest:generateContent',
                'request_type' => 'image_analysis',
                'response_code' => $httpCode,
                'response_time_ms' => $elapsed,
                'success' => $httpCode === 200,
                'error_message' => $curlError ?: null,
            ]);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $text = strtoupper(trim($text));

                if (strpos($text, 'UNSAFE') === 0) {
                    $threatType = self::THREAT_SUSPICIOUS;
                    
                    if (strpos($text, 'CSAM') !== false) {
                        $threatType = self::THREAT_CSAM;
                    } elseif (strpos($text, 'ADULT') !== false) {
                        $threatType = self::THREAT_ADULT;
                    } elseif (strpos($text, 'VIOLENCE') !== false) {
                        $threatType = self::THREAT_VIOLENCE;
                    }

                    return [
                        'safe' => false,
                        'threat_type' => $threatType,
                        'threat_details' => $text,
                        'source' => 'gemini',
                    ];
                }

                // Check for blocked content by Gemini's safety filters
                if (isset($data['candidates'][0]['finishReason']) && $data['candidates'][0]['finishReason'] === 'SAFETY') {
                    return [
                        'safe' => false,
                        'threat_type' => self::THREAT_SUSPICIOUS,
                        'threat_details' => 'Content blocked by AI safety filters',
                        'source' => 'gemini',
                    ];
                }

                return ['safe' => true, 'source' => 'gemini', 'analysis' => $text];
            }

            // Handle 429 - rate limit, requeue for retry
            if ($httpCode === 429) {
                $contentId = md5($imagePath);
                return $this->handleRateLimitError('gemini', 'image', $contentId, $imagePath);
            }

            return ['safe' => true, 'skipped' => true, 'reason' => 'API error: ' . $httpCode];
            
        } catch (Exception $e) {
            error_log('SafeGuard: Gemini error - ' . $e->getMessage());
            return ['safe' => true, 'skipped' => true, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Check URL with Google Safe Browsing
     */
    public function checkUrl(string $url): array
    {
        if (!$this->safeBrowsingKey) {
            return ['safe' => true, 'skipped' => true, 'reason' => 'API key not configured'];
        }

        if (!$this->canMakeRequest('safebrowsing')) {
            return ['safe' => true, 'queued' => true, 'reason' => 'Rate limit reached'];
        }

        $startTime = microtime(true);
        
        try {
            $payload = [
                'client' => [
                    'clientId' => 'pichost-safeguard',
                    'clientVersion' => '1.0.0',
                ],
                'threatInfo' => [
                    'threatTypes' => [
                        'MALWARE',
                        'SOCIAL_ENGINEERING',
                        'UNWANTED_SOFTWARE',
                        'POTENTIALLY_HARMFUL_APPLICATION',
                    ],
                    'platformTypes' => ['ANY_PLATFORM'],
                    'threatEntryTypes' => ['URL'],
                    'threatEntries' => [['url' => $url]],
                ],
            ];

            $ch = curl_init("https://safebrowsing.googleapis.com/v4/threatMatches:find?key={$this->safeBrowsingKey}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 10,
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $elapsed = round((microtime(true) - $startTime) * 1000);
            $this->incrementUsage('safebrowsing');
            
            $this->logUsage('safebrowsing', [
                'endpoint' => 'threatMatches:find',
                'request' => ['url' => $url],
                'response_code' => $httpCode,
                'response_time_ms' => $elapsed,
                'success' => $httpCode === 200,
            ]);

            if ($httpCode === 200 && $response) {
                $data = json_decode($response, true);
                
                if (!empty($data['matches'])) {
                    $threatType = $data['matches'][0]['threatType'] ?? 'UNKNOWN';
                    return [
                        'safe' => false,
                        'threat_type' => $this->mapSafeBrowsingThreat($threatType),
                        'threat_details' => $data['matches'],
                        'source' => 'safebrowsing',
                    ];
                }
                
                return ['safe' => true, 'source' => 'safebrowsing'];
            }

            return ['safe' => true, 'skipped' => true, 'reason' => 'API error: ' . $httpCode];
            
        } catch (Exception $e) {
            error_log('SafeGuard: Safe Browsing error - ' . $e->getMessage());
            return ['safe' => true, 'skipped' => true, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Add to moderation queue
     */
    public function addToQueue(string $contentType, string $contentId, string $contentUrl, int $priority = 0): int
    {
        try {
            $helinkDb = new PDO(
                'mysql:host=127.0.0.1;dbname=helink_db;charset=utf8mb4',
                'helink_user',
                'VeryStrongPassword2024',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            $stmt = $helinkDb->prepare("
                INSERT INTO moderation_queue 
                (platform, content_type, content_id, content_url, status, priority, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NOW())
            ");
            $stmt->execute([$this->platform, $contentType, $contentId, $contentUrl, $priority]);
            
            return (int) $helinkDb->lastInsertId();
            
        } catch (Exception $e) {
            error_log('SafeGuard: Add to queue failed - ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Quarantine content
     */
    public function quarantine(string $contentType, string $originalId, ?string $originalPath, string $threatType, ?string $threatDetails = null): bool
    {
        $quarantinePath = null;
        
        // Move file to quarantine if path exists
        if ($originalPath && file_exists($originalPath)) {
            $quarantineDir = '/var/www/quarantine/' . ($contentType === 'image' ? 'images/' : 'links/');
            if (!is_dir($quarantineDir)) {
                mkdir($quarantineDir, 0755, true);
            }
            $quarantinePath = $quarantineDir . date('Ymd') . '_' . basename($originalPath);
            @rename($originalPath, $quarantinePath);
        }

        try {
            $helinkDb = new PDO(
                'mysql:host=127.0.0.1;dbname=helink_db;charset=utf8mb4',
                'helink_user',
                'VeryStrongPassword2024',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            $stmt = $helinkDb->prepare("
                INSERT INTO quarantine_records 
                (platform, content_type, original_id, original_path, quarantine_path, threat_type, threat_details, action_taken, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'blocked', NOW(), NOW())
            ");
            $stmt->execute([
                $this->platform,
                $contentType,
                $originalId,
                $originalPath,
                $quarantinePath,
                $threatType,
                $threatDetails,
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log('SafeGuard: Quarantine failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Map Safe Browsing threat type
     */
    private function mapSafeBrowsingThreat(string $sbThreat): string
    {
        return match ($sbThreat) {
            'MALWARE' => self::THREAT_MALWARE,
            'SOCIAL_ENGINEERING' => self::THREAT_PHISHING,
            'UNWANTED_SOFTWARE' => self::THREAT_SUSPICIOUS,
            'POTENTIALLY_HARMFUL_APPLICATION' => self::THREAT_MALWARE,
            default => self::THREAT_SUSPICIOUS,
        };
    }

    /**
     * Detect MIME type from image data
     */
    private function detectMimeType(string $path, string $data): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data);
        
        if ($mime && $mime !== 'application/octet-stream') {
            return $mime;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * Get moderation status badge HTML
     */
    public static function getStatusBadge(string $status): string
    {
        return match ($status) {
            'verified' => '<span class="badge bg-success"><i class="fas fa-shield-check"></i> Verified</span>',
            'pending' => '<span class="badge bg-warning"><i class="fas fa-clock"></i> Pending</span>',
            'flagged' => '<span class="badge bg-danger"><i class="fas fa-flag"></i> Flagged</span>',
            'blocked' => '<span class="badge bg-dark"><i class="fas fa-ban"></i> Blocked</span>',
            default => '<span class="badge bg-secondary"><i class="fas fa-question"></i> Unknown</span>',
        };
    }

    /**
     * Queue content for async moderation (approve first, verify later)
     * Content is accessible immediately while being verified in background
     * 
     * @param string $contentType 'image' or 'url'
     * @param string $contentId Unique identifier (filename, image ID)
     * @param string $contentUrl Full URL/path to content
     * @param bool $isGuest Whether uploader is guest (higher priority)
     * @param int|null $userId User ID if registered
     * @return array ['approved' => true, 'queue_id' => int]
     */
    public function queueForAsyncModeration(
        string $contentType,
        string $contentId,
        string $contentUrl,
        bool $isGuest = true,
        ?int $userId = null
    ): array {
        try {
            $helinkDb = $this->getHelinkDb();
            
            // Guest gets higher priority (scanned first)
            $priority = $isGuest ? self::PRIORITY_GUEST : self::PRIORITY_USER;
            
            $stmt = $helinkDb->prepare("
                INSERT INTO moderation_queue 
                (platform, content_type, content_id, content_url, user_id, is_guest, status, priority, retry_count, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, 0, NOW(), NOW())
            ");
            $stmt->execute([
                $this->platform,
                $contentType,
                $contentId,
                $contentUrl,
                $userId,
                $isGuest ? 1 : 0,
                $priority,
            ]);
            
            $queueId = (int) $helinkDb->lastInsertId();
            
            error_log("SafeGuard: Content queued for async moderation - ID: {$queueId}, Type: {$contentType}, Priority: {$priority}");
            
            return [
                'approved' => true, // Content is accessible immediately
                'pending_verification' => true,
                'queue_id' => $queueId,
                'message' => 'Content approved, verification pending',
            ];
            
        } catch (Exception $e) {
            error_log('SafeGuard: Queue for async moderation failed - ' . $e->getMessage());
            return [
                'approved' => true,
                'pending_verification' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle rate limit (429) response - requeue with backoff
     */
    private function handleRateLimitError(
        string $service,
        string $contentType,
        string $contentId,
        string $contentUrl,
        int $currentRetry = 0
    ): array {
        try {
            $helinkDb = $this->getHelinkDb();
            
            // Calculate backoff delay (exponential)
            $backoffMinutes = min(pow(2, $currentRetry), 60); // Max 60 min
            
            // Check if already in queue
            $stmt = $helinkDb->prepare("
                SELECT id, retry_count FROM moderation_queue 
                WHERE content_id = ? AND content_type = ? AND platform = ?
            ");
            $stmt->execute([$contentId, $contentType, $this->platform]);
            $existing = $stmt->fetch(PDO::FETCH_OBJ);
            
            if ($existing) {
                // Update retry count and schedule
                $stmt = $helinkDb->prepare("
                    UPDATE moderation_queue SET
                        status = 'rate_limited',
                        retry_count = retry_count + 1,
                        scheduled_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                        last_error = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$backoffMinutes, "Rate limited by {$service}", $existing->id]);
                $queueId = $existing->id;
            } else {
                // Add to queue with retry info
                $stmt = $helinkDb->prepare("
                    INSERT INTO moderation_queue 
                    (platform, content_type, content_id, content_url, is_guest, status, priority, retry_count, scheduled_at, last_error, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, 'rate_limited', ?, 1, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $this->platform,
                    $contentType,
                    $contentId,
                    $contentUrl,
                    self::PRIORITY_GUEST,
                    $backoffMinutes,
                    "Rate limited by {$service}",
                ]);
                $queueId = (int) $helinkDb->lastInsertId();
            }
            
            error_log("SafeGuard: Rate limited by {$service}, requeued ID: {$queueId}, retry in {$backoffMinutes} min");
            
            return [
                'safe' => true, // Assume safe, let it through
                'queued' => true,
                'rate_limited' => true,
                'queue_id' => $queueId,
                'reason' => "Rate limited, scheduled retry in {$backoffMinutes} minutes",
            ];
            
        } catch (Exception $e) {
            error_log('SafeGuard: Handle rate limit failed - ' . $e->getMessage());
            return [
                'safe' => true,
                'queued' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Auto-takedown image that was flagged during async verification
     */
    public function autoTakedown(string $imageId, string $threatType, ?string $threatDetails = null): bool
    {
        try {
            // Get image info from local database
            $stmt = $this->db->prepare("SELECT * FROM images WHERE id = ? OR filename = ?");
            $stmt->execute([$imageId, $imageId]);
            $image = $stmt->fetch(PDO::FETCH_OBJ);
            
            if ($image) {
                // Mark as blocked in database
                $stmt = $this->db->prepare("
                    UPDATE images SET 
                        status = 'blocked',
                        blocked_reason = ?,
                        blocked_at = NOW(),
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute(["SafeGuard: {$threatType}", $image->id]);
                
                // Move file to quarantine
                $this->quarantine('image', $imageId, $image->filepath ?? null, $threatType, $threatDetails);
                
                error_log("SafeGuard: Auto-takedown image ID: {$imageId}, Threat: {$threatType}");
                return true;
            }
            
            // Update moderation queue status
            $helinkDb = $this->getHelinkDb();
            $stmt = $helinkDb->prepare("
                UPDATE moderation_queue SET
                    status = 'blocked',
                    threat_type = ?,
                    threat_details = ?,
                    processed_at = NOW(),
                    updated_at = NOW()
                WHERE content_id = ? AND content_type = 'image' AND platform = ?
            ");
            $stmt->execute([$threatType, $threatDetails, $imageId, $this->platform]);
            
            return true;
            
        } catch (Exception $e) {
            error_log('SafeGuard: Auto-takedown failed - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(): array
    {
        try {
            $helinkDb = $this->getHelinkDb();
            
            $stmt = $helinkDb->prepare("
                SELECT 
                    status,
                    COUNT(*) as count,
                    SUM(CASE WHEN is_guest = 1 THEN 1 ELSE 0 END) as guest_count,
                    SUM(CASE WHEN is_guest = 0 OR is_guest IS NULL THEN 1 ELSE 0 END) as user_count
                FROM moderation_queue
                WHERE platform = ?
                GROUP BY status
            ");
            $stmt->execute([$this->platform]);
            $stats = $stmt->fetchAll(PDO::FETCH_OBJ);
            
            $pending = 0;
            $rateLimited = 0;
            foreach ($stats as $stat) {
                if ($stat->status === 'pending') $pending = $stat->count;
                if ($stat->status === 'rate_limited') $rateLimited = $stat->count;
            }
            
            return [
                'pending' => $pending,
                'rate_limited' => $rateLimited,
                'details' => $stats,
            ];
            
        } catch (Exception $e) {
            error_log('SafeGuard: Get queue stats failed - ' . $e->getMessage());
            return ['pending' => 0, 'rate_limited' => 0, 'error' => $e->getMessage()];
        }
    }
}
