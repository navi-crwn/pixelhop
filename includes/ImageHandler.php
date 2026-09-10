<?php
/**
 * PixelHop - Image Handler Utility Class
 * Core image processing with Imagick
 *
 * Features:
 * - Upload from URL with validation
 * - Temp file management with auto-cleanup
 * - Memory-efficient processing
 * - Animated GIF/WebP support
 */

class ImageHandler
{
    private const MAX_FILE_SIZE = 15 * 1024 * 1024;
    private const TEMP_LIFETIME = 6 * 60 * 60;
    private const MAX_DIMENSION = 8000;
    private const CHUNK_SIZE = 8192;
    private const MAX_REDIRECTS = 3;
    private const CURL_TIMEOUT_HEAD = 10;
    private const CURL_TIMEOUT_DOWNLOAD = 60;

    /**
     * IP ranges that must never be reached by the image fetcher.
     * Includes private, loopback, link-local, CGNAT, benchmark, multicast,
     * reserved, documentation and IPv6 transition ranges.
     */
    private const BLOCKED_IPV4_CIDRS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10', // CGNAT (e.g. Alibaba metadata 100.100.100.200)
        '127.0.0.0/8',
        '169.254.0.0/16', // link-local (e.g. cloud metadata 169.254.169.254)
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24', // TEST-NET-1
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15', // benchmark
        '198.51.100.0/24', // TEST-NET-2
        '203.0.113.0/24', // TEST-NET-3
        '224.0.0.0/4', // multicast
        '240.0.0.0/4', // reserved
        '255.255.255.255/32',
    ];

    private const BLOCKED_IPV6_CIDRS = [
        '::1/128',
        'fc00::/7', // ULA
        'fe80::/10', // link-local
        '64:ff9b::/96', // NAT64
        '2001:db8::/32', // documentation
        '2002::/16', // 6to4
        'ff00::/8', // multicast
        '::/128',
    ];

    private string $tempDir;
    private ?string $sessionId;

    private static array $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/tiff' => 'tiff',
    ];

    public function __construct(?string $sessionId = null)
    {
        $this->tempDir = __DIR__ . '/../temp';
        $this->sessionId = $sessionId ?: session_id() ?: $this->generateSessionId();


        $this->ensureTempDir();
    }

    /**
     * Generate a random session ID for temp folder
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Ensure temp directory exists
     */
    private function ensureTempDir(): void
    {
        $sessionDir = $this->getSessionTempDir();

        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0755, true);
        }
    }

    /**
     * Get session-specific temp directory
     */
    public function getSessionTempDir(): string
    {
        return $this->tempDir . '/' . $this->sessionId;
    }

    /**
     * Upload image from URL with validation
     * Uses streaming to avoid loading entire file into memory
     */
    public function uploadFromUrl(string $url): array
    {

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid URL format');
        }


        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP/HTTPS URLs are allowed');
        }

        self::assertPublicUrl($url);


        $headers = $this->getUrlHeaders($url);


        $contentType = $headers['content-type'] ?? '';
        $mimeType = strtok($contentType, ';');

        if (!isset(self::$allowedMimes[$mimeType])) {
            throw new InvalidArgumentException('URL does not point to a valid image');
        }


        $contentLength = (int) ($headers['content-length'] ?? 0);
        if ($contentLength > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('Image exceeds maximum size of 15MB');
        }


        $extension = self::$allowedMimes[$mimeType];
        $tempFile = $this->generateTempPath($extension);

        $this->downloadWithLimit($url, $tempFile, self::MAX_FILE_SIZE);


        $actualMime = $this->detectMimeType($tempFile);
        if (!isset(self::$allowedMimes[$actualMime])) {
            unlink($tempFile);
            throw new InvalidArgumentException('Downloaded file is not a valid image');
        }


        $dimensions = $this->getImageDimensions($tempFile);

        return [
            'path' => $tempFile,
            'mime' => $actualMime,
            'extension' => self::$allowedMimes[$actualMime],
            'size' => filesize($tempFile),
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'filename' => basename($tempFile),
        ];
    }

    /**
     * Validate that a URL does not resolve to a private/internal address (SSRF guard)
     */
    public static function assertPublicUrl(string $url): void
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            throw new InvalidArgumentException('Invalid URL host');
        }

        // Resolves AND rejects the host if ANY resolved IP is blocked.
        self::resolveAndValidate($host);
    }

    /**
     * Is the given IP address publicly routable?
     *
     * FILTER_VALIDATE_IP is used only as a sanity check. The real allow-list
     * decision is made by isBlockedIp(): an explicit CIDR blocklist covering
     * private, loopback, link-local, CGNAT, benchmark, multicast, reserved and
     * IPv6 transition ranges.
     */
    public static function isPublicIp(string $ip): bool
    {
        return $ip !== ''
            && (bool) filter_var($ip, FILTER_VALIDATE_IP)
            && !self::isBlockedIp($ip);
    }

    /**
     * Return true when $ip belongs to any blocked CIDR range.
     */
    private static function isBlockedIp(string $ip): bool
    {
        $version = filter_var($ip, FILTER_VALIDATE_IP) === false
            ? null
            : (strpos($ip, ':') === false ? 4 : 6);

        if ($version === 4) {
            foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return true;
                }
            }
            return false;
        }

        if ($version === 6) {
            foreach (self::BLOCKED_IPV6_CIDRS as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    /**
     * Check whether an IPv4 or IPv6 address is inside a CIDR network.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefix] = explode('/', $cidr, 2) + [1 => null];
        if ($prefix === null || !ctype_digit($prefix)) {
            return false;
        }

        $prefix = (int) $prefix;
        $subnetBytes = @inet_pton($subnet);
        $ipBytes = @inet_pton($ip);

        if ($subnetBytes === false || $ipBytes === false || strlen($subnetBytes) !== strlen($ipBytes)) {
            return false;
        }

        $maxBits = strlen($subnetBytes) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($subnetBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits > 0) {
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($ipBytes[$fullBytes]) & $mask) !== (ord($subnetBytes[$fullBytes]) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reject the request if curl actually connected to a private/internal IP.
     *
     * This is a defence-in-depth check on top of resolveAndValidate() + per-hop
     * CURLOPT_RESOLVE pinning. Note on the residual TOCTOU limitation: libcurl
     * reports CURLINFO_PRIMARY_IP only after the connection is established, so
     * on this callback curl may already have started receiving the response
     * body. The progress callback is used to run this check as early as curl
     * exposes the value (normally the first callback after handshake), so an
     * unexpected peer aborts the transfer before the whole body is consumed.
     */
    public static function assertConnectedIpPublic(\CurlHandle $ch): void
    {
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        if ($primaryIp !== '' && !self::isPublicIp($primaryIp)) {
            throw new InvalidArgumentException('URL resolved to a private or internal address');
        }
    }

    /**
     * Resolve a host and fail closed unless EVERY resolved address is public.
     *
     * DNS round-robin/rebinding protection: if the record set contains even one
     * blocked IP the whole host is rejected (we never pick "the good one" from
     * a mixed set). Returns the first public IP so the caller can pin it with
     * CURLOPT_RESOLVE.
     */
    public static function resolveAndValidate(string $host): string
    {
        $host = trim($host, '[]');

        if ($host === '') {
            throw new InvalidArgumentException('Invalid URL host');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!self::isPublicIp($host)) {
                throw new InvalidArgumentException('URL points to a private or internal address');
            }
            return $host;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
        $ips = [];
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        if (empty($ips)) {
            throw new InvalidArgumentException('Could not resolve URL host');
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new InvalidArgumentException('URL points to a private or internal address');
            }
        }

        return $ips[0];
    }

    /**
     * Validate a URL (scheme + host) and return the per-hop curl pinning values:
     * [hostWithoutBrackets, port, scheme, validatedIp]
     */
    private static function assertPublicUrlWithResolution(string $url): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP/HTTPS URLs are allowed');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            throw new InvalidArgumentException('Invalid URL host');
        }

        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80));

        return [trim($host, '[]'), $port, $scheme, self::resolveAndValidate($host)];
    }

    /**
     * Resolve a relative or absolute redirect target against the current URL
     * and validate it BEFORE the next hop is followed.
     */
    private static function assertValidRedirect(string $currentUrl, string $redirectUrl): string
    {
        if ($redirectUrl === '') {
            throw new InvalidArgumentException('Invalid redirect URL');
        }

        $target = $redirectUrl;

        if (stripos($target, '//') === 0) {
            $scheme = parse_url($currentUrl, PHP_URL_SCHEME);
            $target = strtolower((string) $scheme) . ':' . $target;
        } elseif (stripos($target, '://') === false) {
            $base = parse_url($currentUrl);
            if (empty($base['scheme']) || empty($base['host'])) {
                throw new InvalidArgumentException('Invalid redirect URL');
            }

            $scheme = strtolower((string) $base['scheme']);
            $host = $base['host'];
            $port = !empty($base['port']) ? ':' . $base['port'] : '';
            $user = isset($base['user']) ? rawurlencode($base['user']) : '';
            $pass = isset($base['pass']) ? ':' . rawurlencode($base['pass']) : '';
            $auth = ($user !== '' || $pass !== '') ? $user . $pass . '@' : '';

            if (str_starts_with($target, '/')) {
                $path = $target;
            } else {
                $basePath = $base['path'] ?? '/';
                $path = preg_replace('#/[^/]*$#', '/', $basePath) . $target;
            }

            $query = isset($base['query']) && $base['query'] !== '' ? '?' . $base['query'] : '';
            $target = $scheme . '://' . $auth . $host . $port . $path . $query;
        }

        self::assertPublicUrlWithResolution($target);
        return $target;
    }

    /**
     * Get URL headers without downloading body.
     *
     * Redirects are followed manually (FOLLOWLOCATION disabled) so each hop can
     * be re-validated and IP-pinned before curl connects to it.
     */
    private function getUrlHeaders(string $url): array
    {
        $currentUrl = $url;
        $headers = [];

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            [$host, $port, $scheme, $ip] = self::assertPublicUrlWithResolution($currentUrl);

            $ch = curl_init($currentUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => self::CURL_TIMEOUT_HEAD,
                CURLOPT_CONNECTTIMEOUT => self::CURL_TIMEOUT_HEAD,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'PixelHop/1.0 (Image Downloader)',
                CURLOPT_RESOLVE => ["$host:$port:$ip"],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

            // Defence-in-depth: the peer curl actually connected to must be public.
            self::assertConnectedIpPublic($ch);

            if ($error) {
                throw new RuntimeException('Failed to fetch URL: ' . $error);
            }

            if ($redirectUrl !== false && $redirectUrl !== '') {
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new RuntimeException('Too many redirects');
                }

                $currentUrl = self::assertValidRedirect($currentUrl, $redirectUrl);
                continue;
            }

            if ($httpCode !== 200) {
                $httpMessages = [
                    401 => 'URL requires authentication. Make sure the image is publicly accessible.',
                    403 => 'Access forbidden. The server rejected the request.',
                    404 => 'Image not found at this URL.',
                    500 => 'Remote server error. Please try again later.',
                ];
                $message = $httpMessages[$httpCode] ?? 'URL returned HTTP ' . $httpCode;
                throw new RuntimeException($message);
            }

            foreach (explode("\r\n", (string) $response) as $line) {
                if (strpos($line, ':') !== false) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }
            }

            return $headers;
        }

        throw new RuntimeException('Too many redirects');
    }

    /**
     * Download file with size limit using streaming.
     *
     * Redirects are followed manually (FOLLOWLOCATION disabled). Every hop is
     * validated and IP-pinned with CURLOPT_RESOLVE before curl connects.
     */
    private function downloadWithLimit(string $url, string $destPath, int $maxSize): void
    {
        $currentUrl = $url;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            [$host, $port, $scheme, $ip] = self::assertPublicUrlWithResolution($currentUrl);

            $fp = fopen($destPath, 'wb');
            if (!$fp) {
                throw new RuntimeException('Cannot create temp file');
            }

            $aborted = false;

            $ch = curl_init($currentUrl);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fp,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => self::CURL_TIMEOUT_DOWNLOAD,
                CURLOPT_CONNECTTIMEOUT => self::CURL_TIMEOUT_HEAD,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'PixelHop/1.0 (Image Downloader)',
                CURLOPT_RESOLVE => ["$host:$port:$ip"],
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => function ($ch, $dlTotal, $dlNow) use ($maxSize, &$aborted) {
                    // Runs as early as libcurl exposes transfer info. PRIMARY_IP
                    // normally becomes available on the first callback after the
                    // connection handshake, so this aborts an unexpected private
                    // peer before any significant body data has been consumed.
                    // Limitation: libcurl still delivers CURLINFO_PRIMARY_IP only
                    // after connecting; the first small chunk may already be in
                    // flight. The post-transfer checks remain as a backstop.
                    self::assertConnectedIpPublic($ch);

                    if ($dlNow > $maxSize) {
                        $aborted = true;
                        return 1;
                    }
                    return 0;
                },
            ]);

            try {
                $success = curl_exec($ch);
                $error = curl_error($ch);

                // Defence-in-depth backstop (also runs when no progress callback fires).
                self::assertConnectedIpPublic($ch);
            } catch (InvalidArgumentException $e) {
                if (file_exists($destPath)) {
                    unlink($destPath);
                }
                throw $e;
            } finally {
                if (is_resource($fp)) {
                    fclose($fp);
                }
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

            if ($redirectUrl !== false && $redirectUrl !== '') {
                if ($redirects >= self::MAX_REDIRECTS) {
                    if (file_exists($destPath)) {
                        unlink($destPath);
                    }
                    throw new RuntimeException('Too many redirects');
                }

                $currentUrl = self::assertValidRedirect($currentUrl, $redirectUrl);
                continue;
            }

            clearstatcache(true, $destPath);
            if (filesize($destPath) > $maxSize) {
                unlink($destPath);
                throw new InvalidArgumentException('Downloaded file exceeds maximum size');
            }

            if ($aborted) {
                unlink($destPath);
                throw new InvalidArgumentException('Downloaded file exceeds maximum size');
            }

            if (!$success && $error) {
                unlink($destPath);
                throw new RuntimeException('Download failed: ' . $error);
            }

            return;
        }

        throw new RuntimeException('Too many redirects');
    }

    /**
     * Process uploaded file (from $_FILES)
     */
    public function processUpload(array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException($this->getUploadErrorMessage($file['error']));
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new InvalidArgumentException('File exceeds maximum size of 15MB');
        }

        $mimeType = $this->detectMimeType($file['tmp_name']);

        if (!isset(self::$allowedMimes[$mimeType])) {
            throw new InvalidArgumentException('Invalid image type');
        }


        $extension = self::$allowedMimes[$mimeType];
        $tempPath = $this->generateTempPath($extension);

        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            throw new RuntimeException('Failed to move uploaded file');
        }

        $dimensions = $this->getImageDimensions($tempPath);

        return [
            'path' => $tempPath,
            'mime' => $mimeType,
            'extension' => $extension,
            'size' => filesize($tempPath),
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'filename' => $file['name'],
            'original_name' => pathinfo($file['name'], PATHINFO_FILENAME),
        ];
    }

    /**
     * Detect MIME type of file
     */
    public function detectMimeType(string $filePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        return $mime;
    }

    /**
     * Get image dimensions
     */
    public function getImageDimensions(string $filePath): array
    {
        $info = getimagesize($filePath);
        if (!$info) {
            throw new RuntimeException('Cannot read image dimensions');
        }

        return [
            'width' => $info[0],
            'height' => $info[1],
        ];
    }

    /**
     * Generate unique temp file path
     */
    public function generateTempPath(string $extension): string
    {
        $this->ensureTempDir();
        $filename = uniqid('img_', true) . '.' . $extension;
        return $this->getSessionTempDir() . '/' . $filename;
    }

    /**
     * Get upload error message
     */
    private function getUploadErrorMessage(int $code): string
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
        ];

        return $messages[$code] ?? 'Unknown upload error';
    }

    /**
     * Cleanup old temp files (> 6 hours)
     */
    public static function cleanupTemp(?string $tempDir = null): array
    {
        $tempDir = $tempDir ?? __DIR__ . '/../temp';
        $deletedCount = 0;
        $deletedSize = 0;
        $errors = [];

        if (!is_dir($tempDir)) {
            return ['deleted' => 0, 'size' => 0, 'errors' => []];
        }

        $cutoffTime = time() - self::TEMP_LIFETIME;


        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            try {
                if ($file->isFile() && $file->getMTime() < $cutoffTime) {
                    $size = $file->getSize();
                    if (unlink($file->getPathname())) {
                        $deletedCount++;
                        $deletedSize += $size;
                    }
                } elseif ($file->isDir()) {

                    $dirPath = $file->getPathname();
                    if (count(scandir($dirPath)) === 2) {
                        rmdir($dirPath);
                    }
                }
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [
            'deleted' => $deletedCount,
            'size' => $deletedSize,
            'size_human' => self::formatBytes($deletedSize),
            'errors' => $errors,
        ];
    }

    /**
     * Format bytes to human readable
     */
    public static function formatBytes(int $bytes): string
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
     * Create Imagick instance from file
     * Memory efficient - uses ping for metadata first
     */
    public function createImagick(string $filePath): Imagick
    {
        if (!class_exists('Imagick')) {
            throw new RuntimeException('ImageMagick extension is not installed');
        }

        $imagick = new Imagick();


        $imagick->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        $imagick->setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
        $imagick->setResourceLimit(Imagick::RESOURCETYPE_AREA, 128 * 1024 * 1024);

        $imagick->readImage($filePath);

        return $imagick;
    }

    /**
     * Check if image is animated (GIF/WebP)
     */
    public function isAnimated(Imagick $imagick): bool
    {
        return $imagick->getNumberImages() > 1;
    }

    /**
     * Strip metadata from image
     */
    public function stripMetadata(Imagick $imagick): void
    {
        $imagick->stripImage();
    }

    /**
     * Preserve/copy EXIF orientation
     */
    public function autoOrient(Imagick $imagick): void
    {
        $imagick->autoOrient();
    }
}
