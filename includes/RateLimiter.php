<?php
/**
 * PixelHop - Rate Limiter
 * File-based token bucket rate limiting system
 *
 * Limits:
 * - Guests: 10 requests per minute per IP
 * - Logged-in users: 50 requests per minute per user
 */

class RateLimiter
{
    private const GUEST_LIMIT = 10;
    private const USER_LIMIT = 50;
    private const WINDOW_SECONDS = 60;
    private const CLEANUP_PROBABILITY = 0.01;

    private string $storageDir;

    public function __construct(?string $storageDir = null)
    {
        $this->storageDir = $storageDir ?? __DIR__ . '/../data/ratelimit';
        $this->ensureStorageDir();


        if (mt_rand(1, 100) <= self::CLEANUP_PROBABILITY * 100) {
            $this->cleanup();
        }
    }

    /**
     * Check if request is allowed
     * Returns true if allowed, false if rate limited
     */
    public function isAllowed(?int $userId = null): bool
    {
        $identifier = $this->getIdentifier($userId);
        $limit = $userId ? self::USER_LIMIT : self::GUEST_LIMIT;

        $data = $this->getTokenData($identifier);
        $now = time();


        if ($now - $data['window_start'] >= self::WINDOW_SECONDS) {
            $data = [
                'window_start' => $now,
                'requests' => 0,
            ];
        }


        if ($data['requests'] >= $limit) {
            return false;
        }


        $data['requests']++;
        $this->saveTokenData($identifier, $data);

        return true;
    }

    /**
     * Check rate limit and return HTTP 429 if exceeded
     */
    public function enforce(?int $userId = null): void
    {
        if (!$this->isAllowed($userId)) {
            $this->sendTooManyRequestsResponse($userId);
        }
    }

    /**
     * Get remaining requests for identifier
     */
    public function getRemaining(?int $userId = null): array
    {
        $identifier = $this->getIdentifier($userId);
        $limit = $userId ? self::USER_LIMIT : self::GUEST_LIMIT;

        $data = $this->getTokenData($identifier);
        $now = time();


        if ($now - $data['window_start'] >= self::WINDOW_SECONDS) {
            $remaining = $limit;
            $resetIn = self::WINDOW_SECONDS;
        } else {
            $remaining = max(0, $limit - $data['requests']);
            $resetIn = self::WINDOW_SECONDS - ($now - $data['window_start']);
        }

        return [
            'limit' => $limit,
            'remaining' => $remaining,
            'reset_in' => $resetIn,
            'is_authenticated' => $userId !== null,
        ];
    }

    /**
     * Get identifier for rate limiting (IP or user ID)
     */
    private function getIdentifier(?int $userId): string
    {
        if ($userId) {
            return 'user_' . $userId;
        }

        return 'ip_' . $this->hashIp($this->getClientIp());
    }

    /**
     * Get client IP address
     */
    public function getClientIp(): string
    {

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        }


        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }


        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Hash IP for filename safety
     */
    private function hashIp(string $ip): string
    {
        return substr(hash('sha256', $ip . 'pixelhop_salt'), 0, 16);
    }

    /**
     * Get token data from file
     */
    private function getTokenData(string $identifier): array
    {
        $file = $this->getFilePath($identifier);

        if (!file_exists($file)) {
            return [
                'window_start' => time(),
                'requests' => 0,
            ];
        }

        $content = file_get_contents($file);
        $data = json_decode($content, true);

        if (!$data || !isset($data['window_start'])) {
            return [
                'window_start' => time(),
                'requests' => 0,
            ];
        }

        return $data;
    }

    /**
     * Save token data to file
     */
    private function saveTokenData(string $identifier, array $data): void
    {
        $file = $this->getFilePath($identifier);
        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    /**
     * Get file path for identifier
     */
    private function getFilePath(string $identifier): string
    {
        return $this->storageDir . '/' . $identifier . '.json';
    }

    /**
     * Ensure storage directory exists
     */
    private function ensureStorageDir(): void
    {
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Cleanup old rate limit files (> 5 minutes old)
     */
    public function cleanup(): int
    {
        $deleted = 0;
        $cutoff = time() - 300;

        foreach (glob($this->storageDir . '/*.json') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Send HTTP 429 response and exit
     */
    private function sendTooManyRequestsResponse(?int $userId): void
    {
        $info = $this->getRemaining($userId);

        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . $info['reset_in']);
        header('X-RateLimit-Limit: ' . $info['limit']);
        header('X-RateLimit-Remaining: 0');
        header('X-RateLimit-Reset: ' . (time() + $info['reset_in']));

        echo json_encode([
            'error' => 'Too many requests. Please try again later.',
            'retry_after' => $info['reset_in'],
            'limit' => $info['limit'],
        ]);
        exit;
    }

    /**
     * Add rate limit headers to response
     */
    public function addHeaders(?int $userId = null): void
    {
        $info = $this->getRemaining($userId);

        header('X-RateLimit-Limit: ' . $info['limit']);
        header('X-RateLimit-Remaining: ' . $info['remaining']);
        header('X-RateLimit-Reset: ' . (time() + $info['reset_in']));
    }

    /**
     * Get all current rate limit entries (for admin)
     */
    public function getAllEntries(): array
    {
        $entries = [];

        foreach (glob($this->storageDir . '/*.json') as $file) {
            $identifier = basename($file, '.json');
            $data = json_decode(file_get_contents($file), true);

            if ($data) {
                $entries[] = [
                    'identifier' => $identifier,
                    'type' => strpos($identifier, 'user_') === 0 ? 'user' : 'ip',
                    'requests' => $data['requests'],
                    'window_start' => $data['window_start'],
                    'last_modified' => filemtime($file),
                ];
            }
        }


        usort($entries, fn($a, $b) => $b['requests'] <=> $a['requests']);

        return $entries;
    }
}
