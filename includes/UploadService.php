<?php
/**
 * PixelHop - UploadService
 *
 * Orkestrasi upload utama yang sebelumnya berada di blok besar api/upload.php
 * (temp file, validasi file, dedup, pemrosesan varian, upload S3 hybrid,
 * saveImageData, kuota user atomik, cleanup, dan respons sukses).
 *
 * Controller (api/upload.php) tetap memiliki SEMUA middleware inline:
 * firewall, token Shottr bypass, AbuseGuard checkUpload, token auth, CSRF,
 * Gatekeeper::canUpload, dan uploadDebug. Setelah middleware lolos,
 * controller membangun $context dan memanggil UploadService::handle().
 *
 * Desain dependency:
 * - ImageRepository dan UploadJournal di-inject lewat constructor
 *   (dibuat oleh controller, memungkinkan test double).
 * - AbuseGuard, SafeGuard, Gatekeeper, dan config dimasukkan lewat $context
 *   karena objek-objek tersebut sudah dibangun controller sebagai bagian
 *   middleware (checkUpload/canUpload) dan dipakai ulang oleh service.
 * - Method handle() TIDAK echo/exit; ia mengembalikan
 *   ['success'=>bool, 'http_code'=>int, 'payload'=>array].
 *
 * File ini tidak menghasilkan output apa pun saat di-require.
 */

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ImageVariantProcessor.php';
require_once __DIR__ . '/ImageHandler.php';
require_once __DIR__ . '/R2StorageManager.php';

final class UploadService
{
    /** @var ImageRepository|object */
    private $imageRepo;

    /** @var UploadJournal|object|null */
    private $journal;

    public function __construct($imageRepo, $journal = null)
    {
        $this->imageRepo = $imageRepo;
        $this->journal = $journal;
    }

    /**
     * Jalankan orkestrasi upload dan kembalikan hasil terstruktur.
     *
     * @param array $context Key yang dipakai:
     *   file, remoteUrl, sessionUserId, clientIP, config,
     *   abuseGuard, safeGuard, gatekeeper,
     *   storageManager (opsional, untuk test double)
     * @return array{success:bool,http_code:int,payload:array}
     */
    public function handle(array $context): array
    {
        $remoteUrl = $context['remoteUrl'] ?? '';
        $file = $context['file'] ?? null;
        $tempFilePath = null;
        $sessionUserId = $context['sessionUserId'] ?? null;
        $clientIP = $context['clientIP'] ?? '';
        $config = $context['config'] ?? null;
        $abuseGuard = $context['abuseGuard'] ?? null;
        $safeGuard = $context['safeGuard'] ?? null;
        $gatekeeper = $context['gatekeeper'] ?? null;

        uploadDebug('upload_request', [
            'has_remote_url' => !empty($remoteUrl),
            'has_file' => isset($context['file']),
            'ip' => $clientIP,
            'user_id' => $sessionUserId,
        ]);

        if (!empty($remoteUrl)) {
            uploadDebug('remote_url_upload', ['url' => $remoteUrl, 'url_length' => strlen($remoteUrl)]);
            
            // Validate URL format
            if (!filter_var($remoteUrl, FILTER_VALIDATE_URL)) {
                return ['success' => false, 'http_code' => 200, 'payload' => ['error' => 'Invalid URL format']];
            }
            
            // Only allow http/https
            $scheme = parse_url($remoteUrl, PHP_URL_SCHEME);
            if (!in_array(strtolower((string) $scheme), ['http', 'https'])) {
                return ['success' => false, 'http_code' => 200, 'payload' => ['error' => 'Only HTTP/HTTPS URLs are allowed']];
            }

            // SSRF guard + streaming download delegated to ImageHandler. This avoids
            // the previous inlined cURL RETURNTRANSFER full-body fetch and keeps the
            // OOM-safe progress-abort download path (with manual redirect validation).
            $imageHandler = new ImageHandler();
            try {
                ImageHandler::assertPublicUrl($remoteUrl);
                $downloaded = $imageHandler->uploadFromUrl($remoteUrl);
            } catch (Exception $e) {
                Logger::error('upload', 'Remote upload failed: ' . $e->getMessage(), [
                    'exception' => get_class($e),
                    'ip' => $clientIP,
                ]);
                return ['success' => false, 'http_code' => 200, 'payload' => ['error' => 'Upload failed. Please try again.']];
            }

            $tempFilePath = $downloaded['path'];

            // Extract filename from URL
            $urlPath = parse_url($remoteUrl, PHP_URL_PATH);
            $originalName = $urlPath ? (basename($urlPath) ?: 'image.jpg') : 'image.jpg';

            // Create pseudo $_FILES array so the rest of the validation flow is
            // identical for direct and remote uploads.
            $file = [
                'name' => $originalName,
                'type' => $downloaded['mime'],
                'tmp_name' => $tempFilePath,
                'error' => UPLOAD_ERR_OK,
                'size' => $downloaded['size'],
            ];

            uploadDebug('remote_fetch_success', ['size' => $downloaded['size'], 'type' => $downloaded['mime']]);
            
        } elseif (is_array($file) && ($file['error'] ?? null) === UPLOAD_ERR_OK) {
            // Direct upload: keep the controller-provided $_FILES entry.
        } else {
            // Check if image was uploaded
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'Upload stopped by extension',
            ];
            $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            return ['success' => false, 'http_code' => 200, 'payload' => ['error' => $errorMessages[$error] ?? 'Upload failed']];
        }

        // Validate file type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $config['upload']['allowed_types'])) {
            return ['success' => false, 'http_code' => 200, 'payload' => ['error' => 'Invalid file type. Allowed: JPG, PNG, GIF, WebP']];
        }

        // Validate file size
        if ($file['size'] > $config['upload']['max_size']) {
            return ['success' => false, 'http_code' => 200, 'payload' => ['error' => 'File too large. Maximum size: 10 MB']];
        }

        // Determine if uploader is guest (for priority queue)
        $isGuest = empty($sessionUserId);

        // SafeGuard AI Content Moderation Check
        $safetyCheck = $safeGuard->analyzeImage($file['tmp_name']);
        uploadDebug('safeguard_check', [
            'result' => $safetyCheck,
            'ip' => $clientIP,
            'user_id' => $sessionUserId,
            'is_guest' => $isGuest,
        ]);

        if (!$safetyCheck['safe'] && empty($safetyCheck['skipped']) && empty($safetyCheck['queued'])) {
            // Content is IMMEDIATELY unsafe - block and quarantine
            $safeGuard->quarantine(
                'image',
                'upload_' . time() . '_' . mt_rand(1000, 9999),
                $file['tmp_name'],
                $safetyCheck['threat_type'],
                $safetyCheck['threat_details'] ?? null
            );
            
            // Log the abuse incident
            $abuseGuard->logAbuse($clientIP, 'suspicious_content', 'high', $sessionUserId, json_encode($safetyCheck));
            
            // Clean up temp file
            if ($tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }
            
            return ['success' => false, 'http_code' => 403, 'payload' => ['error' => 'This image has been flagged by our AI safety system and cannot be uploaded. If you believe this is an error, please contact support.']];
        }

        // If rate limited or queued, we'll allow upload but queue for async verification
        // This is the "approve first, verify later" approach
        $pendingVerification = !empty($safetyCheck['queued']) || !empty($safetyCheck['rate_limited']);

        // Check storage quota for logged-in users (session already started above).
        // Limits come from Gatekeeper settings (single source of truth); the final
        // enforcement is the atomic UPDATE below.
        $uploadUserId = $sessionUserId;
        $storageLimit = null;

        if ($uploadUserId) {
            require_once __DIR__ . '/Database.php';
            $db = Database::getInstance();

            $userStmt = $db->prepare("SELECT storage_used, account_type FROM users WHERE id = ?");
            $userStmt->execute([$uploadUserId]);
            $userInfo = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($userInfo) {
                $storageUsed = (int)($userInfo['storage_used'] ?? 0);
                $isPremium = ($userInfo['account_type'] ?? 'free') === 'premium';
                $storageLimit = (int) ($isPremium
                    ? $gatekeeper->getSetting('storage_limit_premium', 5368709120)
                    : $gatekeeper->getSetting('storage_limit_free', 524288000));

                if (($storageUsed + $file['size']) > $storageLimit) {
                    $usedMB = round($storageUsed / 1024 / 1024, 1);
                    $limitMB = round($storageLimit / 1024 / 1024);
                    return ['success' => false, 'http_code' => 200, 'payload' => ['error' => 'Storage quota exceeded. You are using ' . $usedMB . 'MB of ' . $limitMB . 'MB. ' . ($isPremium ? '' : 'Upgrade to Premium for 5GB storage!')]];
                }
            }
        }

        // Check for duplicate image using file hash
        $fileHash = hash_file('sha256', $file['tmp_name']);
        $duplicateImage = $this->findDuplicateImage($fileHash, $file['size'], $sessionUserId, $clientIP);

        if ($duplicateImage) {
            // Build proxy URLs for duplicate response
            $dupProxyUrls = [];
            $dupS3Keys = $duplicateImage['s3_keys'] ?? [];
            foreach ($dupS3Keys as $sizeName => $key) {
                $dupProxyUrls[$sizeName] = $config['site']['url'] . '/i/' . $key;
            }
            // Fallback to stored urls if s3_keys not available
            if (empty($dupProxyUrls)) {
                $dupProxyUrls = $duplicateImage['urls'] ?? [];
            }

            // Clean up remote temp file before responding
            if ($tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }

            return ['success' => true, 'http_code' => 200, 'payload' => [
                'id' => $duplicateImage['id'],
                'urls' => $dupProxyUrls,
                'view_url' => $config['site']['url'] . '/' . $duplicateImage['id'],
                'width' => $duplicateImage['width'],
                'height' => $duplicateImage['height'],
                'duplicate' => true,
                'message' => 'Image already exists'
            ]];
        }

        // Generate descriptive unique ID with filename slug
        $imageId = $this->generateId($file['name']);
        $extension = ImageVariantProcessor::getExtension($mimeType);
        $timestamp = time();

        // Validate image is actually readable before processing
        // This catches corrupted files that pass MIME check but fail GD
        // Returns 'gd', 'imagick', or false
        $imageProcessor = ImageVariantProcessor::validateImageFile($file['tmp_name'], $mimeType);
        if (!$imageProcessor) {
            return ['success' => false, 'http_code' => 200, 'payload' => ['error' => 'Invalid or corrupted image file. Please try a different image.']];
        }

        $useImagick = ($imageProcessor === 'imagick');

        // Create temp directory for processing
        $tempDir = sys_get_temp_dir() . '/pichost_' . $imageId;
        if (!mkdir($tempDir, 0755, true)) {
            return ['success' => false, 'http_code' => 200, 'payload' => ['error' => 'Failed to create temp directory']];
        }

        // Initialized before try so the catch block can safely test them even when
        // an exception is thrown before storage upload begins (e.g. loadImage fails).
        $storageManager = null;
        $s3Keys = [];

        try {

            $sourceImage = ImageVariantProcessor::loadImage($file['tmp_name'], $mimeType, $useImagick);
            if (!$sourceImage) {
                throw new Exception('Failed to load image. The file may be corrupted.');
            }

            $originalWidth = imagesx($sourceImage);
            $originalHeight = imagesy($sourceImage);

            $sizes = [
                'original' => null,
                'large' => $config['image']['sizes']['large'],
                'medium' => $config['image']['sizes']['medium'],
                'thumb' => $config['image']['sizes']['thumb'],
            ];

            $uploadedUrls = [];
            $uploadedFiles = [];

            foreach ($sizes as $sizeName => $sizeConfig) {
                if ($sizeName === 'original') {

                    $filename = "{$imageId}_original.{$extension}";
                    $filepath = "{$tempDir}/{$filename}";

                    // D6-10/D2-08: never store the raw upload as "original". Strip
                    // EXIF/metadata by re-encoding and cap the max dimension to 9000px
                    // to reject decompression bombs before pixel buffers are allocated.
                    $dimInfo = @getimagesize($file['tmp_name']);
                    if ($dimInfo === false || $dimInfo[0] <= 0 || $dimInfo[1] <= 0) {
                        throw new Exception('Invalid image dimensions.');
                    }
                    if ($dimInfo[0] > 9000 || $dimInfo[1] > 9000) {
                        throw new Exception('Image dimensions exceed the maximum allowed size of 9000px.');
                    }

                    $imagickUsed = false;
                    if (extension_loaded('imagick')) {
                        try {
                            $imagick = new Imagick();
                            $imagick->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
                            $imagick->setResourceLimit(Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
                            $imagick->setResourceLimit(Imagick::RESOURCETYPE_AREA, 128 * 1024 * 1024);
                            $imagick->readImage($file['tmp_name']);
                            $imagick->stripImage();
                            $imagick->setImageCompressionQuality($config['image']['quality'] ?? 95);
                            $imagick->writeImage($filepath);
                            $imagick->clear();
                            $imagick->destroy();
                            $imagickUsed = true;
                        } catch (Exception $imagickException) {
                            Logger::error('upload', 'Original Imagick re-encode failed: ' . $imagickException->getMessage(), [
                                'exception' => get_class($imagickException),
                            ]);
                            if (isset($imagick)) {
                                $imagick->clear();
                                $imagick->destroy();
                            }
                            @unlink($filepath);
                        }
                    }

                    if (!$imagickUsed) {
                        // Fallback GD re-encode (quality 95).
                        $gdImage = ImageVariantProcessor::loadImage($file['tmp_name'], $mimeType, false);
                        if (!$gdImage) {
                            throw new Exception('Failed to re-encode original image.');
                        }
                        $ok = ImageVariantProcessor::saveImage($gdImage, $filepath, $mimeType, 95);
                        imagedestroy($gdImage);
                        if (!$ok) {
                            throw new Exception('Failed to write original image.');
                        }
                    }
                } else {

                    $maxWidth = $sizeConfig['width'];
                    $maxHeight = $sizeConfig['height'];

                    // Cap variant dimensions to 9000px as well, so downstream
                    // processing never allocates an oversized pixel buffer.
                    $resizeNeeded = $originalWidth > $maxWidth || $originalHeight > $maxHeight;
                    $capNeeded = $originalWidth > 9000 || $originalHeight > 9000;

                    if ($resizeNeeded || $capNeeded) {
                        $maxWidth = min($maxWidth, 9000);
                        $maxHeight = min($maxHeight, 9000);
                        $resizedImage = ImageVariantProcessor::resizeImage($sourceImage, $originalWidth, $originalHeight, $maxWidth, $maxHeight);
                        $filename = "{$imageId}_{$sizeName}.{$extension}";
                        $filepath = "{$tempDir}/{$filename}";
                        ImageVariantProcessor::saveImage($resizedImage, $filepath, $mimeType, $config['image']['quality']);
                        imagedestroy($resizedImage);
                    } else {

                        $filename = "{$imageId}_{$sizeName}.{$extension}";
                        $filepath = "{$tempDir}/{$filename}";
                        ImageVariantProcessor::saveImage($sourceImage, $filepath, $mimeType, $config['image']['quality']);
                    }
                }

                $uploadedFiles[$sizeName] = [
                    'filename' => $filename,
                    'filepath' => $filepath,
                ];
            }

            imagedestroy($sourceImage);

            // Initialize hybrid storage manager (R2 + Contabo).
            // Optional: test harness may inject a test double via context.
            if (isset($context['storageManager']) && is_object($context['storageManager'])) {
                $storageManager = $context['storageManager'];
            } else {
                $storageManager = new R2StorageManager($config);
            }
            
            $s3Keys = [];
            $storageProviders = []; // Track which provider stores each size

            // Precompute the deterministic S3 keys we are about to create so the
            // journal payload is meaningful even if the process dies mid-loop. The
            // actual upload loop below uses the same key format unchanged.
            $journalS3Keys = [];
            foreach ($uploadedFiles as $sizeName => $fileInfo) {
                $journalS3Keys[$sizeName] = date('Y/m/d', $timestamp) . '/' . $fileInfo['filename'];
            }

            // Crash recovery journal: record the S3 keys we are about to create so
            // a reconcile cron can clean them up if we die before metadata is saved.
            if ($this->journal instanceof UploadJournal) {
                try {
                    $this->journal->open($imageId, 'upload', [
                        's3_keys' => $journalS3Keys,
                        'size' => $file['size'],
                        'user_id' => $uploadUserId,
                    ]);
                } catch (Throwable $journalOpenException) {
                    Logger::error('upload', 'Upload journal open failed: ' . $journalOpenException->getMessage(), [
                        'exception' => get_class($journalOpenException),
                    ]);
                }
            }

            foreach ($uploadedFiles as $sizeName => $fileInfo) {
                $s3Key = date('Y/m/d', $timestamp) . '/' . $fileInfo['filename'];
                
                // Use hybrid storage: thumb/medium → R2, original/large → Contabo
                $uploadResult = $storageManager->upload(
                    $fileInfo['filepath'],
                    $s3Key,
                    $mimeType,
                    $sizeName // 'original', 'large', 'medium', 'thumb'
                );

                if (!$uploadResult['success']) {
                    throw new Exception("Failed to upload {$sizeName}: " . ($uploadResult['error'] ?? 'Unknown error'));
                }

                $uploadedUrls[$sizeName] = $uploadResult['url'];
                $s3Keys[$sizeName] = $s3Key;
                $storageProviders[$sizeName] = $uploadResult['provider']; // 'r2' or 'contabo'
            }

            if ($this->journal instanceof UploadJournal) {
                try {
                    $this->journal->progress($imageId, 's3_uploaded');
                } catch (Throwable $journalProgressException) {
                    Logger::error('upload', 'Upload journal progress failed: ' . $journalProgressException->getMessage(), [
                        'exception' => get_class($journalProgressException),
                    ]);
                }
            }

            $deleteAfter = $_POST['delete_after'] ?? 'never';
            $deleteAt = null;

            if ($deleteAfter !== 'never') {
                $deleteIntervals = [
                    '1h' => 3600,
                    '24h' => 86400,
                    '7d' => 604800,
                    '30d' => 2592000,
                ];

                if (isset($deleteIntervals[$deleteAfter])) {
                    $deleteAt = $timestamp + $deleteIntervals[$deleteAfter];
                }
            }



            $imageData = [
                'id' => $imageId,
                'user_id' => $uploadUserId,
                'filename' => $file['name'],
                'mime_type' => $mimeType,
                'extension' => $extension,
                'size' => $file['size'],
                'width' => $originalWidth,
                'height' => $originalHeight,
                'hash' => $fileHash,
                'urls' => $uploadedUrls,
                's3_keys' => $s3Keys,
                'storage_providers' => $storageProviders, // Track R2 vs Contabo per size
                'created_at' => $timestamp,
                'delete_at' => $deleteAt,
                'ip' => $clientIP,
            ];

            $this->saveImageData($imageId, $imageData);

            if ($this->journal instanceof UploadJournal) {
                try {
                    $this->journal->progress($imageId, 'metadata_saved');
                    $this->journal->complete($imageId);
                } catch (Throwable $journalCompleteException) {
                    Logger::error('upload', 'Upload journal complete failed: ' . $journalCompleteException->getMessage(), [
                        'exception' => get_class($journalCompleteException),
                    ]);
                }
            }

            if ($uploadUserId) {
                $db = Database::getInstance();
                // Atomic quota enforcement (D2-06/D5-19): increment only when the
                // effective limit is not exceeded. 0 rows updated => quota exceeded.
                $stmt = $db->prepare(
                    "UPDATE users SET storage_used = storage_used + ? WHERE id = ? AND storage_used + ? <= ?"
                );
                $stmt->execute([$file['size'], $uploadUserId, $file['size'], $storageLimit]);

                if ($stmt->rowCount() === 0) {
                    throw new Exception('Storage quota exceeded');
                }
            }

            // Update global storage counter after a fully successful upload.
            $gatekeeper->updateGlobalStorage((int) $file['size']);

            // Record successful upload in AbuseGuard counters. Guarded so a counter
            // error never turns a successful upload into a failed response.
            try {
                $abuseGuard->recordUpload($clientIP, $sessionUserId, (int) $file['size']);
            } catch (Throwable $recordException) {
                Logger::error('upload', 'Abuse counter update failed: ' . $recordException->getMessage(), [
                    'exception' => get_class($recordException),
                ]);
            }

            // Clean up remote temp file if any
            if ($tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }

            self::cleanupTempDir($tempDir);

            // Build proxy URLs for response (use site domain instead of raw S3)
            $proxyUrls = [];
            foreach ($s3Keys as $sizeName => $key) {
                $proxyUrls[$sizeName] = $config['site']['url'] . '/i/' . $key;
            }

            // Queue for async verification if initial scan was rate limited/skipped
            // Content is accessible immediately, but will be auto-takedown if flagged later
            if ($pendingVerification) {
                $safeGuard->queueForAsyncModeration(
                    'image',
                    $imageId,
                    $proxyUrls['original'] ?? ($config['site']['url'] . '/' . $imageId),
                    $isGuest,
                    $sessionUserId
                );
            }

            return ['success' => true, 'http_code' => 200, 'payload' => [
                'id' => $imageId,
                'filename' => $file['name'],
                'extension' => $extension,
                'size' => $file['size'],
                'urls' => $proxyUrls,
                'view_url' => $config['site']['url'] . '/' . $imageId,
                'width' => $originalWidth,
                'height' => $originalHeight,
                'pending_verification' => $pendingVerification ?? false,
            ]];

        } catch (Exception $e) {

            Logger::error('upload', 'Upload failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'ip' => $clientIP,
            ]);

            // Crash recovery journal: record the failure. Best-effort; the existing
            // S3 compensation delete below stays as the primary cleanup path.
            if ($this->journal instanceof UploadJournal) {
                try {
                    $this->journal->fail($imageId, $e->getMessage());
                } catch (Throwable $journalFailException) {
                    Logger::error('upload', 'Upload journal fail failed: ' . $journalFailException->getMessage(), [
                        'exception' => get_class($journalFailException),
                    ]);
                }
            }

            // Compensate: delete S3 variants that were already uploaded for this
            // image before the failure (quota exceeded, DB/JSON failure, etc).
            if ($storageManager instanceof R2StorageManager && !empty($s3Keys)) {
                try {
                    $storageManager->deleteImage($s3Keys, (int) $file['size']);
                } catch (Exception $deleteException) {
                    Logger::error('upload', 'Compensation delete failed: ' . $deleteException->getMessage(), [
                        'exception' => get_class($deleteException),
                    ]);
                }
            }

            // Clean up remote temp file if any
            if (isset($tempFilePath) && $tempFilePath && file_exists($tempFilePath)) {
                @unlink($tempFilePath);
            }

            self::cleanupTempDir($tempDir);
            return ['success' => false, 'http_code' => 200, 'payload' => ['error' => 'Upload failed. Please try again.']];
        }
    }

    /**
     * Save image data to JSON database
     */
    private function saveImageData($imageId, $data) {
        $this->imageRepo->save($imageId, $data);
    }

    /**
     * Find duplicate image by hash and size.
     *
     * D2-04: duplicates are only deduplicated within the same owner scope:
     * - logged-in users only match their own previous uploads
     * - guests only match previous guest uploads from the same IP
     * Images owned by someone else are never reused.
     */
    private function findDuplicateImage($hash, $size, $sessionUserId, $clientIP) {
        return $this->imageRepo->findDuplicate($hash, $size, $sessionUserId, $clientIP);
    }

    /**
     * Generate descriptive unique ID combining filename slug + unique code
     * Format: {filename-slug}_{short-unique-code}
     * Example: "rumah-baru_a3x9K2"
     */
    private function generateId($filename = null) {
        // D5-18: 10-char unique code with ImageRepository collision checks (max 5 tries).
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $uniqueCode = ImageVariantProcessor::generateShortId(10);

            if ($filename) {
                $slug = ImageVariantProcessor::slugifyFilename($filename);
                if (!empty($slug)) {
                    $imageId = $slug . '_' . $uniqueCode;
                } else {
                    $imageId = $uniqueCode;
                }
            } else {
                $imageId = $uniqueCode;
            }

            if (!$this->imageRepo->exists($imageId)) {
                return $imageId;
            }
        }

        // Last-ditch fallback: random_bytes hex is overwhelmingly collision-free.
        return (is_string($filename) && ($slug = ImageVariantProcessor::slugifyFilename($filename)) !== '')
            ? $slug . '_' . bin2hex(random_bytes(8))
            : bin2hex(random_bytes(8));
    }

    /**
     * Clean up temp directory
     */
    private static function cleanupTempDir($dir) {
        if (!is_dir($dir)) return;

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
