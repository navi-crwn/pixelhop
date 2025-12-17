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

    private static array $mimeToImagick = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/gif' => 'GIF',
        'image/webp' => 'WEBP',
        'image/bmp' => 'BMP',
        'image/tiff' => 'TIFF',
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
        if (!in_array(strtolower($scheme), ['http', 'https'])) {
            throw new InvalidArgumentException('Only HTTP/HTTPS URLs are allowed');
        }


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
     * Get URL headers without downloading body
     */
    private function getUrlHeaders(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'PixelHop/1.0 (Image Downloader)',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new RuntimeException('Failed to fetch URL: ' . $error);
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


        $headers = [];
        foreach (explode("\r\n", $response) as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }

        return $headers;
    }

    /**
     * Download file with size limit using streaming
     */
    private function downloadWithLimit(string $url, string $destPath, int $maxSize): void
    {
        $fp = fopen($destPath, 'wb');
        if (!$fp) {
            throw new RuntimeException('Cannot create temp file');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'PixelHop/1.0 (Image Downloader)',
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function($ch, $dlTotal, $dlNow) use ($maxSize, $fp, $destPath) {
                if ($dlNow > $maxSize) {

                    return 1;
                }
                return 0;
            },
        ]);

        $success = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);


        if (filesize($destPath) > $maxSize) {
            unlink($destPath);
            throw new InvalidArgumentException('Downloaded file exceeds maximum size');
        }

        if (!$success && $error && strpos($error, 'aborted') === false) {
            unlink($destPath);
            throw new RuntimeException('Download failed: ' . $error);
        }
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
    public static function cleanupTemp(string $tempDir = null): array
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
     * Get Imagick format from MIME type
     */
    public static function getImagickFormat(string $mimeType): string
    {
        return self::$mimeToImagick[$mimeType] ?? 'JPEG';
    }

    /**
     * Get MIME type from extension
     */
    public static function getMimeFromExtension(string $ext): string
    {
        $map = array_flip(self::$allowedMimes);
        return $map[strtolower($ext)] ?? 'image/jpeg';
    }

    /**
     * Get allowed MIME types
     */
    public static function getAllowedMimes(): array
    {
        return self::$allowedMimes;
    }

    /**
     * Save processed image to temp
     */
    public function saveTempImage(Imagick $imagick, string $format, int $quality = 90): string
    {
        $extension = strtolower($format);
        if ($extension === 'jpeg') $extension = 'jpg';

        $tempPath = $this->generateTempPath($extension);

        $imagick->setImageFormat($format);


        if (in_array($format, ['JPEG', 'WEBP'])) {
            $imagick->setImageCompressionQuality($quality);
        } elseif ($format === 'PNG') {

            $pngLevel = (int) round((100 - $quality) / 10);
            $imagick->setImageCompressionQuality($pngLevel);
        }

        $imagick->writeImages($tempPath, true);

        return $tempPath;
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
