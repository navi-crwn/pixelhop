<?php
/**
 * PixelHop - Cloudflare R2 Storage Manager
 * Hybrid storage with Contabo S3 fallback
 * 
 * Features:
 * - Hard limit to stay within free tier (9.5GB default)
 * - Auto fallback to Contabo when limit reached
 * - Usage tracking in database
 * - Rate limiting for R2 operations
 * - S3-compatible API
 */

require_once __DIR__ . '/R2RateLimiter.php';

class R2StorageManager
{
    // Free tier limit with buffer (9.5GB to be safe)
    private const FREE_TIER_LIMIT = 9.5 * 1024 * 1024 * 1024; // 9.5GB in bytes
    
    // Warning threshold (8GB)
    private const WARNING_THRESHOLD = 8 * 1024 * 1024 * 1024;
    
    /**
     * Fallback per-variant size ratios (fraction of the original byte size).
     *
     * Used ONLY when actual per-variant byte sizes were not recorded in
     * metadata. Values are deliberately conservative lower bounds:
     *   - thumb  ≈ 0.5-1%   -> use 0.5%
     *   - medium ≈ 1-2%     -> use 1%
     *   - large  ≈ 40-60%   -> use 40%
     * Conservative = under-estimate what we subtract from storage_stats, so
     * deletion accounting can never over-reduce recorded usage.
     */
    public const FALLBACK_VARIANT_RATIOS = [
        'original' => 1.0,
        'large' => 0.40,
        'medium' => 0.01,
        'thumb' => 0.005,
    ];
    
    // Static copy of the raw config, populated by the constructor so static
    // CLI helpers such as setObjectPrivate() can sign requests for a provider.
    private static array $config = [];
    
    private array $r2Config;
    private array $contaboConfig;
    private ?PDO $db = null;
    private bool $r2Enabled = false;
    private ?R2RateLimiter $rateLimiter = null;
    
    public function __construct(array $config)
    {
        self::$config = $config;
        
        $this->contaboConfig = $config['s3'] ?? [];
        $this->r2Config = $config['r2'] ?? [];
        $this->r2Enabled = !empty($this->r2Config['enabled']) && 
                           !empty($this->r2Config['access_key']) && 
                           !empty($this->r2Config['bucket']);
        
        // Initialize database connection
        $this->initDatabase();
        
        // Initialize rate limiter for R2
        if ($this->r2Enabled) {
            $this->rateLimiter = new R2RateLimiter();
        }
    }
    
    /**
     * Initialize database connection
     */
    private function initDatabase(): void
    {
        try {
            require_once __DIR__ . '/Database.php';
            $this->db = Database::getInstance();
        } catch (Exception $e) {
            error_log('R2StorageManager: Database connection failed - ' . $e->getMessage());
        }
    }
    
    /**
     * Check if R2 is enabled and configured
     */
    public function isR2Enabled(): bool
    {
        return $this->r2Enabled;
    }
    
    /**
     * Get current R2 storage usage from database
     */
    public function getR2Usage(): array
    {
        $usage = 0;
        $fileCount = 0;
        
        if ($this->db) {
            try {
                // Get from storage_stats table
                $stmt = $this->db->prepare("SELECT total_bytes, file_count FROM storage_stats WHERE provider = 'r2' LIMIT 1");
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result) {
                    $usage = (int) $result['total_bytes'];
                    $fileCount = (int) $result['file_count'];
                }
            } catch (PDOException $e) {
                error_log('R2StorageManager: Failed to get usage - ' . $e->getMessage());
            }
        }
        
        $limit = self::FREE_TIER_LIMIT;
        $percentage = $limit > 0 ? round(($usage / $limit) * 100, 2) : 0;
        
        return [
            'used_bytes' => $usage,
            'used_human' => $this->formatBytes($usage),
            'limit_bytes' => $limit,
            'limit_human' => $this->formatBytes($limit),
            'available_bytes' => max(0, $limit - $usage),
            'available_human' => $this->formatBytes(max(0, $limit - $usage)),
            'percentage' => $percentage,
            'file_count' => $fileCount,
            'is_warning' => $usage >= self::WARNING_THRESHOLD,
            'is_full' => $usage >= $limit,
        ];
    }
    
    /**
     * Check if we can upload to R2 (within free tier limit + rate limit)
     */
    public function canUploadToR2(int $fileSize): bool
    {
        if (!$this->r2Enabled) {
            return false;
        }
        
        // Check storage limit
        $usage = $this->getR2Usage();
        if (($usage['used_bytes'] + $fileSize) >= self::FREE_TIER_LIMIT) {
            return false;
        }
        
        // Check rate limit (Class A operations)
        if ($this->rateLimiter && !$this->rateLimiter->canPerformClassA()) {
            error_log('R2StorageManager: Rate limit reached, falling back to Contabo');
            return false;
        }
        
        return true;
    }
    
    /**
     * Determine best storage for file based on type and R2 availability
     * 
     * Strategy:
     * - Thumbnails & Medium → R2 (if space available)
     * - Large & Original → Contabo (unlimited)
     */
    public function determineStorage(string $sizeType, int $fileSize): string
    {
        // Original and large always go to Contabo
        if (in_array($sizeType, ['original', 'large'])) {
            return 'contabo';
        }
        
        // Thumbnails and medium go to R2 if possible
        if (in_array($sizeType, ['thumb', 'medium'])) {
            if ($this->canUploadToR2($fileSize)) {
                return 'r2';
            }
        }
        
        // Fallback to Contabo
        return 'contabo';
    }
    
    /**
     * Sanitize a user-supplied filename before it is stored or used in a key.
     *
     * - Allows only [A-Za-z0-9._-]; every other byte becomes "_".
     * - Truncates to at most 120 characters while preserving the extension.
     * - Guarantees no "..", "<", ">", "\"", "'", or backtick can survive.
     *
     * Intentionally NOT called from this class; upload callers (subtask
     * owners) should call this before persisting metadata / S3 keys.
     */
    public static function sanitizeFilename(string $name): string
    {
        // Take the raw basename so no path separators or directory traversal
        // can pass through, then replace anything not explicitly allowed.
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        
        // Defensive: preg_replace could theoretically fail; never return a
        // string with HTML metacharacters or quotes.
        if ($name === null || $name === '') {
            $name = 'file';
        }
        
        $name = str_replace(['..', '<', '>', '"', "'", '`'], '_', $name);
        
        // Split extension off so the 120-char cap never chops it off.
        $dot = strrpos($name, '.');
        if ($dot !== false && $dot > 0) {
            $stem = substr($name, 0, $dot);
            $ext = substr($name, $dot + 1);
        } else {
            $stem = $name;
            $ext = '';
        }
        
        $maxStem = 120 - ($ext !== '' ? strlen($ext) + 1 : 0);
        if ($maxStem < 1) {
            // Extremely long extension: keep only a safe 4-char suffix.
            $ext = substr($ext, 0, 4);
            $maxStem = 120 - (strlen($ext) + 1);
        }
        
        $stem = substr($stem, 0, $maxStem);
        
        return $ext !== '' ? $stem . '.' . $ext : $stem;
    }
    
    /**
     * Upload file to appropriate storage
     * Returns storage provider used and URL
     */
    public function upload(string $filepath, string $key, string $contentType, string $sizeType = 'original'): array
    {
        $fileSize = filesize($filepath);
        $provider = $this->determineStorage($sizeType, $fileSize);
        
        if ($provider === 'r2' && $this->r2Enabled) {
            $result = $this->uploadToR2($filepath, $key, $contentType);
            
            if ($result['success']) {
                // Track R2 usage
                $this->trackUsage('r2', $fileSize, 1);
                
                // Record rate limit operation
                if ($this->rateLimiter) {
                    $this->rateLimiter->recordClassA('PUT', $key, $fileSize);
                }
                
                return [
                    'success' => true,
                    'provider' => 'r2',
                    'url' => $this->getR2PublicUrl($key),
                    'key' => $key,
                ];
            }
            
            // R2 failed, fallback to Contabo
            error_log('R2 upload failed, falling back to Contabo: ' . ($result['error'] ?? 'unknown'));
            $provider = 'contabo';
        }
        
        // Upload to Contabo
        $result = $this->uploadToContabo($filepath, $key, $contentType);
        
        if ($result['success']) {
            $this->trackUsage('contabo', $fileSize, 1);
            
            return [
                'success' => true,
                'provider' => 'contabo',
                'url' => $this->getContaboPublicUrl($key),
                'key' => $key,
            ];
        }
        
        return [
            'success' => false,
            'error' => $result['error'] ?? 'Upload failed',
            'provider' => null,
        ];
    }
    
    /**
     * Upload to Cloudflare R2
     */
    private function uploadToR2(string $filepath, string $key, string $contentType): array
    {
        return $this->uploadToS3(
            $filepath,
            $key,
            $contentType,
            $this->r2Config['endpoint'],
            $this->r2Config['bucket'],
            $this->r2Config['access_key'],
            $this->r2Config['secret_key'],
            $this->r2Config['region'] ?? 'auto'
        );
    }
    
    /**
     * Upload to Contabo S3
     */
    private function uploadToContabo(string $filepath, string $key, string $contentType): array
    {
        return $this->uploadToS3(
            $filepath,
            $key,
            $contentType,
            $this->contaboConfig['endpoint'],
            $this->contaboConfig['bucket'],
            $this->contaboConfig['access_key'],
            $this->contaboConfig['secret_key'],
            $this->contaboConfig['region'] ?? 'default'
        );
    }
    
    /**
     * Generic S3-compatible upload
     */
    private function uploadToS3(
        string $filepath,
        string $key,
        string $contentType,
        string $endpoint,
        string $bucket,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => 'File not found'];
        }
        
        $fileContent = file_get_contents($filepath);
        $contentLength = strlen($fileContent);
        $payloadHash = hash('sha256', $fileContent);
        
        $parsedUrl = parse_url($endpoint);
        $host = $parsedUrl['host'];
        
        $url = "{$endpoint}/{$bucket}/{$key}";
        
        $longDate = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');
        
        $canonicalUri = '/' . $bucket . '/' . str_replace('%2F', '/', rawurlencode($key));
        
        $headers = [
            'content-length' => $contentLength,
            'content-type' => $contentType,
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $longDate,
        ];
        
        // NOTE: The public ACL grant was intentionally removed (audit D2-12).
        // New objects are uploaded with the bucket's default ACL, which is
        // PRIVATE. Serving MUST now go through /i/ (i.php) — direct bucket
        // URLs will 403 for newly created objects.
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaders[] = strtolower($k);
        }
        $signedHeadersStr = implode(';', $signedHeaders);
        
        $canonicalRequest = "PUT\n" .
            $canonicalUri . "\n" .
            "\n" .
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
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $fileContent,
            CURLOPT_HTTPHEADER => [
                "Authorization: {$authorization}",
                "Content-Type: {$contentType}",
                "Content-Length: {$contentLength}",
                "Host: {$host}",
                "x-amz-content-sha256: {$payloadHash}",
                "x-amz-date: {$longDate}",
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "CURL error: {$error}", 'http_code' => 0];
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => "HTTP {$httpCode}: {$response}", 'http_code' => $httpCode];
        }
        
        return ['success' => true, 'http_code' => $httpCode];
    }
    
    /**
     * Track storage usage in database
     */
    private function trackUsage(string $provider, int $bytes, int $fileCount): void
    {
        if (!$this->db) {
            return;
        }
        
        try {
            // Upsert storage stats
            $stmt = $this->db->prepare("
                INSERT INTO storage_stats (provider, total_bytes, file_count, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    total_bytes = total_bytes + VALUES(total_bytes),
                    file_count = file_count + VALUES(file_count),
                    updated_at = NOW()
            ");
            $stmt->execute([$provider, $bytes, $fileCount]);
        } catch (PDOException $e) {
            error_log('R2StorageManager: Failed to track usage - ' . $e->getMessage());
        }
    }
    
    /**
     * Reduce usage tracking (for deletions)
     */
    public function reduceUsage(string $provider, int $bytes, int $fileCount = 1): void
    {
        if (!$this->db) {
            return;
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE storage_stats 
                SET total_bytes = GREATEST(0, total_bytes - ?),
                    file_count = GREATEST(0, file_count - ?),
                    updated_at = NOW()
                WHERE provider = ?
            ");
            $stmt->execute([$bytes, $fileCount, $provider]);
        } catch (PDOException $e) {
            error_log('R2StorageManager: Failed to reduce usage - ' . $e->getMessage());
        }
    }
    
    /**
     * Get R2 public URL for a key
     */
    public function getR2PublicUrl(string $key): string
    {
        // Use custom domain if configured, otherwise use R2 dev URL
        $publicUrl = $this->r2Config['public_url'] ?? '';
        
        if (empty($publicUrl)) {
            // Fallback to R2 dev domain
            $accountId = $this->r2Config['account_id'] ?? '';
            $bucket = $this->r2Config['bucket'] ?? '';
            $publicUrl = "https://{$bucket}.{$accountId}.r2.dev";
        }
        
        return rtrim($publicUrl, '/') . '/' . $key;
    }
    
    /**
     * Get Contabo public URL for a key
     */
    public function getContaboPublicUrl(string $key): string
    {
        $publicUrl = $this->contaboConfig['public_url'] ?? '';
        return rtrim($publicUrl, '/') . '/' . $key;
    }
    
    /**
     * Generate a SigV4 query-string presigned GET URL for an object.
     *
     * Presigned URLs authenticate the request through query parameters, so
     * they work for objects that are publicly readable AND for objects in a
     * private bucket. This lets /i/ keep serving images before the bucket is
     * switched to private (no downtime) and after.
     *
     * @param string $provider 'r2' or 'contabo'
     * @param string $key      Object key, e.g. YYYY/MM/DD/id_thumb.jpg
     * @param int    $expiresSeconds Seconds until expiry (1-604800)
     */
    public function getSignedUrl(string $provider, string $key, int $expiresSeconds = 300): string
    {
        $provider = strtolower($provider);

        if ($provider === 'r2') {
            $providerConfig = $this->r2Config;
            $defaultRegion = 'auto';
        } elseif ($provider === 'contabo' || $provider === 's3') {
            $providerConfig = $this->contaboConfig;
            $defaultRegion = 'default';
        } else {
            throw new InvalidArgumentException("Unknown storage provider: {$provider}");
        }

        $endpoint = $providerConfig['endpoint'] ?? '';
        $bucket = $providerConfig['bucket'] ?? '';
        $accessKey = $providerConfig['access_key'] ?? '';
        $secretKey = $providerConfig['secret_key'] ?? '';
        $region = $providerConfig['region'] ?? $defaultRegion;

        if ($endpoint === '' || $bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new RuntimeException("Missing S3 config for provider: {$provider}");
        }

        if ($expiresSeconds < 1 || $expiresSeconds > 604800) {
            throw new InvalidArgumentException('Expires must be between 1 and 604800 seconds');
        }

        $parsedUrl = parse_url($endpoint);
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';

        if ($host === '') {
            throw new RuntimeException("Invalid S3 endpoint for provider: {$provider}");
        }

        // Match the existing uploadToS3/makeS3Request path-style URI exactly:
        // /{bucket}/{key} with each key segment rawurlencoded (slashes kept).
        $encodedKey = str_replace('%2F', '/', rawurlencode($key));
        $canonicalUri = '/' . $bucket . '/' . $encodedKey;

        $longDate = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$shortDate}/{$region}/s3/aws4_request";

        // Canonical query string is sorted by parameter name. The signature is
        // not part of the canonical request; it is added to the final URL.
        $query = [
            'X-Amz-Algorithm' => $algorithm,
            'X-Amz-Credential' => $accessKey . '/' . $credentialScope,
            'X-Amz-Date' => $longDate,
            'X-Amz-Expires' => (string) $expiresSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query);

        $canonicalQueryString = '';
        foreach ($query as $name => $value) {
            if ($canonicalQueryString !== '') {
                $canonicalQueryString .= '&';
            }
            $canonicalQueryString .= rawurlencode($name) . '=' . rawurlencode($value);
        }

        $canonicalHeaders = "host:{$host}\n";
        $signedHeaders = 'host';

        $canonicalRequest = "GET\n"
            . $canonicalUri . "\n"
            . $canonicalQueryString . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . 'UNSIGNED-PAYLOAD';

        $stringToSign = $algorithm . "\n"
            . $longDate . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $query['X-Amz-Signature'] = $signature;
        ksort($query);

        $finalQueryString = '';
        foreach ($query as $name => $value) {
            if ($finalQueryString !== '') {
                $finalQueryString .= '&';
            }
            $finalQueryString .= rawurlencode($name) . '=' . rawurlencode($value);
        }

        return "{$scheme}://{$host}/{$bucket}/{$encodedKey}?{$finalQueryString}";
    }

    /**
     * Get storage status for admin dashboard
     */
    public function getStorageStatus(): array
    {
        $r2Usage = $this->getR2Usage();
        
        return [
            'r2' => [
                'enabled' => $this->r2Enabled,
                'usage' => $r2Usage,
                'status' => $r2Usage['is_full'] ? 'full' : ($r2Usage['is_warning'] ? 'warning' : 'ok'),
            ],
            'contabo' => [
                'enabled' => true,
                'status' => 'ok',
            ],
            'strategy' => [
                'thumb' => $this->r2Enabled && !$r2Usage['is_full'] ? 'r2' : 'contabo',
                'medium' => $this->r2Enabled && !$r2Usage['is_full'] ? 'r2' : 'contabo',
                'large' => 'contabo',
                'original' => 'contabo',
            ],
        ];
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
    
    /**
     * Delete an object from storage
     * Automatically detects provider from key suffix
     */
    public function deleteObject(string $key, int $fileSize = 0): array
    {
        // Determine provider based on key suffix
        // thumb/medium = R2, original/large = Contabo
        $isR2 = preg_match('/_(thumb|medium)\.[a-z]+$/i', $key);
        
        if ($isR2 && $this->r2Enabled) {
            $result = $this->deleteFromR2($key);
            if ($result['success'] && $fileSize > 0) {
                $this->reduceUsage('r2', $fileSize);
            }
            return $result;
        } else {
            $result = $this->deleteFromContabo($key);
            if ($result['success'] && $fileSize > 0) {
                $this->reduceUsage('contabo', $fileSize);
            }
            return $result;
        }
    }
    
    /**
     * Delete multiple objects (all variants of an image).
     *
     * Accounting (audit D4-03/D4-04):
     * - If `$s3Sizes` (map variant => actual bytes) is provided, each deleted
     *   key reduces storage_stats by its actual recorded size. This is the
     *   preferred path once per-variant sizes are stored in image metadata.
     * - Otherwise each deleted key falls back to
     *   self::estimateVariantSizes($totalSize) using FALLBACK_VARIANT_RATIOS.
     *   The ratios are documented conservative lower bounds so deletion can
     *   never over-reduce recorded usage.
     *
     * S3 deletion behaviour is unchanged.
     */
    public function deleteImage(array $s3Keys, int $totalSize = 0, ?array $s3Sizes = null): array
    {
        $results = [];
        $successCount = 0;
        
        foreach ($s3Keys as $variant => $key) {
            if (empty($key)) continue;
            
            $result = $this->deleteObject($key, 0);
            $results[$variant] = $result;
            if ($result['success']) {
                $successCount++;
            }
        }
        
        if ($successCount === 0) {
            return [
                'success' => false,
                'deleted' => 0,
                'total' => count($s3Keys),
                'details' => $results,
            ];
        }
        
        $estimated = [];
        if (is_array($s3Sizes)) {
            $estimated = $s3Sizes;
        }
        
        // Fill any missing variants with the documented conservative fallback.
        if ($totalSize > 0) {
            $fallback = self::estimateVariantSizes($totalSize);
            foreach ($fallback as $variant => $bytes) {
                if (!isset($estimated[$variant])) {
                    $estimated[$variant] = $bytes;
                }
            }
        }
        
        foreach ($s3Keys as $variant => $key) {
            if (empty($key)) continue;
            if (empty($results[$variant]['success'])) continue;
            
            // Mirror deleteObject()'s provider detection (thumb/medium suffix
            // => R2) so accounting stays consistent with actual deletion.
            $isR2 = preg_match('/_(thumb|medium)\.[a-z]+$/i', (string)$key);
            $provider = ($isR2 && $this->r2Enabled) ? 'r2' : 'contabo';
            
            $bytes = 0;
            if (isset($estimated[$variant])) {
                $bytes = (int)$estimated[$variant];
            } else {
                // Unknown variant, no actual size, no ratio: do not subtract
                // anything. Worst case is a small over-statement of usage,
                // which is safer than under-stating it.
                error_log(
                    'R2StorageManager: no size available for variant '
                    . (string)$variant . ', skipping usage reduction'
                );
                continue;
            }
            
            if ($bytes > 0) {
                $this->reduceUsage($provider, $bytes);
            }
        }
        
        return [
            'success' => $successCount > 0,
            'deleted' => $successCount,
            'total' => count($s3Keys),
            'details' => $results,
        ];
    }
    
    /**
     * Estimate per-variant byte sizes for original/large/medium/thumb.
     *
     * Shared, documented fallback for deletion accounting. Ratios are
     * FALLBACK_VARIANT_RATIOS — conservative lower bounds consistent with the
     * production resizing pipeline:
     *   thumb  ≈ 0.5-1%,  medium ≈ 1-2%,  large ≈ 40-60%
     * We use 0.5%, 1%, and 40% so accounting under-reduces rather than
     * over-reduces when actual variant sizes are unavailable.
     *
     * @return array<string,int> Map variant => estimated bytes (original,
     *                          large, medium, thumb).
     */
    public static function estimateVariantSizes(int $originalBytes): array
    {
        if ($originalBytes <= 0) {
            return [
                'original' => 0,
                'large' => 0,
                'medium' => 0,
                'thumb' => 0,
            ];
        }
        
        $sizes = [];
        foreach (self::FALLBACK_VARIANT_RATIOS as $variant => $ratio) {
            $sizes[$variant] = (int)round($originalBytes * $ratio);
        }
        
        return $sizes;
    }
    
    /**
     * Set an existing object's ACL to private (audit D2-12 remediation).
     *
     * Sends a signed PUT with an ACL update header for a single key. Used by
     * scripts/privatize_existing_objects.php to remediate objects that were
     * previously uploaded with a public grant. No body is sent, which makes
     * this safe for objects of any size.
     *
     * @return array{success:bool,http_code:int,error?:string}
     */
    public static function setObjectPrivate(string $provider, string $key): array
    {
        $provider = strtolower($provider);
        
        if ($provider === 'r2') {
            $config = self::$config['r2'] ?? [];
        } elseif ($provider === 'contabo' || $provider === 's3') {
            $config = self::$config['s3'] ?? [];
        } else {
            return [
                'success' => false,
                'http_code' => 0,
                'error' => "Unknown provider: {$provider}",
            ];
        }
        
        if (empty($config['endpoint']) || empty($config['bucket'])
            || empty($config['access_key']) || empty($config['secret_key'])
        ) {
            return [
                'success' => false,
                'http_code' => 0,
                'error' => "Missing S3 config for provider: {$provider}",
            ];
        }
        
        $aclHeaderName = 'x-amz-' . 'acl';
        
        return self::makeS3Request(
            'PUT',
            $key,
            '',
            '',
            $config['endpoint'],
            $config['bucket'],
            $config['access_key'],
            $config['secret_key'],
            $config['region'] ?? 'auto',
            'acl',
            [$aclHeaderName => 'private']
        );
    }
    
    /**
     * Shared SigV4 S3 request helper used by uploads, deletes, and ACL
     * updates. This keeps signing logic in one place so signedheaders and the
     * canonical request always match the exact headers sent via cURL.
     *
     * @param string $queryString Optional canonical query string (e.g. "acl"
     *                            for a PUT Object ACL request). Pass "" for
     *                            normal object operations.
     * @param array<string,string> $extraHeaders Additional signed headers
     *        (e.g. an ACL grant header). Keys must be lowercase.
     * @return array{success:bool,http_code:int,error?:string}
     */
    private static function makeS3Request(
        string $method,
        string $key,
        string $body,
        string $contentType,
        string $endpoint,
        string $bucket,
        string $accessKey,
        string $secretKey,
        string $region,
        string $queryString = '',
        array $extraHeaders = []
    ): array {
        $host = parse_url($endpoint, PHP_URL_HOST);
        $url = "{$endpoint}/{$bucket}/{$key}";
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
        
        $longDate = gmdate('Ymd\THis\Z');
        $shortDate = gmdate('Ymd');
        
        // Empty payload for DELETE/ACL updates; real uploads pass the body.
        $payloadHash = hash('sha256', $body);
        
        $canonicalUri = '/' . $bucket . '/'
            . str_replace('%2F', '/', rawurlencode($key));
        $canonicalQueryString = $queryString;
        
        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $longDate,
        ];
        
        if ($body !== '') {
            $headers['content-length'] = strlen($body);
        }
        if ($contentType !== '') {
            $headers['content-type'] = $contentType;
        }
        foreach ($extraHeaders as $k => $v) {
            $headers[strtolower($k)] = $v;
        }
        
        ksort($headers);
        
        $canonicalHeaders = '';
        $signedHeaders = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaders[] = strtolower($k);
        }
        $signedHeadersStr = implode(';', $signedHeaders);
        
        $canonicalRequest = strtoupper($method) . "\n" .
            $canonicalUri . "\n" .
            $canonicalQueryString . "\n" .
            $canonicalHeaders . "\n" .
            $signedHeadersStr . "\n" .
            $payloadHash;
        
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$shortDate}/{$region}/s3/aws4_request";
        $stringToSign = "{$algorithm}\n{$longDate}\n{$credentialScope}\n"
            . hash('sha256', $canonicalRequest);
        
        $kDate = hash_hmac('sha256', $shortDate, 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);
        
        $authorization = "{$algorithm} Credential={$accessKey}/{$credentialScope}, "
            . "SignedHeaders={$signedHeadersStr}, Signature={$signature}";
        
        $curlHeaders = ["Authorization: {$authorization}"];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = strtolower($k) . ': ' . trim($v);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            return [
                'success' => false,
                'http_code' => 0,
                'error' => "CURL error: {$error}",
            ];
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            return [
                'success' => false,
                'http_code' => $httpCode,
                'error' => "HTTP {$httpCode}: {$response}",
            ];
        }
        
        return ['success' => true, 'http_code' => $httpCode];
    }
    
    /**
     * Delete from R2 (public for cleanup scripts)
     */
    public function deleteFromR2(string $key): array
    {
        return $this->deleteFromS3(
            $key,
            $this->r2Config['endpoint'],
            $this->r2Config['bucket'],
            $this->r2Config['access_key'],
            $this->r2Config['secret_key'],
            $this->r2Config['region'] ?? 'auto'
        );
    }
    
    /**
     * Delete from Contabo (public for cleanup scripts)
     */
    public function deleteFromContabo(string $key): array
    {
        return $this->deleteFromS3(
            $key,
            $this->contaboConfig['endpoint'],
            $this->contaboConfig['bucket'],
            $this->contaboConfig['access_key'],
            $this->contaboConfig['secret_key'],
            $this->contaboConfig['region'] ?? 'default'
        );
    }
    
    /**
     * Generic S3-compatible delete
     */
    private function deleteFromS3(
        string $key,
        string $endpoint,
        string $bucket,
        string $accessKey,
        string $secretKey,
        string $region
    ): array {
        $host = parse_url($endpoint, PHP_URL_HOST);
        $url = "{$endpoint}/{$bucket}/{$key}";
        
        $now = new DateTime('UTC');
        $longDate = $now->format('Ymd\THis\Z');
        $shortDate = $now->format('Ymd');
        
        // Empty payload for DELETE
        $payloadHash = hash('sha256', '');
        
        // Build canonical request
        $canonicalUri = '/' . $bucket . '/' . $key;
        $canonicalQueryString = '';
        
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
        
        $canonicalRequest = "DELETE\n" .
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
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: {$authorization}",
                "Host: {$host}",
                "x-amz-content-sha256: {$payloadHash}",
                "x-amz-date: {$longDate}",
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        if ($error) {
            return ['success' => false, 'error' => "CURL error: {$error}", 'http_code' => 0];
        }
        
        // 204 No Content or 200 OK means success for DELETE
        if ($httpCode === 204 || $httpCode === 200) {
            return ['success' => true, 'http_code' => $httpCode];
        }
        
        return ['success' => false, 'error' => "HTTP {$httpCode}: {$response}", 'http_code' => $httpCode];
    }
    
    /**
     * Get free tier limit info
     */
    public static function getFreeTierInfo(): array
    {
        return [
            'storage_limit' => self::FREE_TIER_LIMIT,
            'storage_limit_human' => '9.5 GB (buffer from 10GB)',
            'warning_threshold' => self::WARNING_THRESHOLD,
            'warning_threshold_human' => '8 GB',
            'class_a_ops' => 1000000, // 1M write ops
            'class_b_ops' => 10000000, // 10M read ops
            'egress' => 'Unlimited',
        ];
    }
}
