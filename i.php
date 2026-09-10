<?php
/**
 * PixelHop - Hybrid Storage Image Proxy
 *
 * Proksi publik untuk gambar (path /i/YYYY/MM/DD/<id>_<size>.<ext>).
 *
 * Perbaikan audit D2-10 & D2-11:
 *   - TIDAK lagi men-buffer seluruh objek S3 ke RAM. Body upstream
 *     di-streaming langsung ke output memakai CURLOPT_WRITEFUNCTION.
 *   - Selalu lookup metadata di data/images.json sebelum memproksi:
 *     record hilang -> 404, delete_at lewat -> 410, marked_for_deletion
 *     lewat masa tenggang -> 410, pemilik suspended/non-aktif -> 451.
 *   - Cache diturunkan dari 1 tahun immutable menjadi 1 hari.
 *   - Range request diteruskan apa adanya ke upstream (tidak di-substr).
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap & helper aplikasi
// ---------------------------------------------------------------------------
// Hindari header cache bawaan sesi PHP (no-store) menimpa Cache-Control
// gambar yang kita set sendiri di bawah.
if (session_status() === PHP_SESSION_NONE && function_exists('session_cache_limiter')) {
    session_cache_limiter('');
}

// bootstrap.php dipakai untuk helper keamanan (bukan untuk autentikasi sesi).
// Catatan: bootstrap memulai sesi PHP; i.php sendiri tidak membaca/menulis
// data sesi apa pun.
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/JsonStore.php';
require_once __DIR__ . '/includes/ClientIp.php';
require_once __DIR__ . '/includes/R2StorageManager.php';

// Batas ukuran body upstream (50MB). Di atas ini kita tolak dengan 502.
const PIXELHOP_IMAGE_MAX_BYTES = 50 * 1024 * 1024;

// Rate limit ringan khusus /i/: 60 request per menit per IP.
const PIXELHOP_IMAGE_RATE_LIMIT = 60;
const PIXELHOP_IMAGE_RATE_WINDOW_SECONDS = 60;

// Masa tenggang marked_for_deletion: 30 hari (mengikuti cron image_expiration).
const PIXELHOP_IMAGE_MARKED_GRACE_SECONDS = 30 * 24 * 60 * 60;

/**
 * Kirim respons teks polos non-200 dan pastikan tidak di-cache.
 */
function pixelhop_image_send_text(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $message;
}

/**
 * Periksa status akses metadata gambar (tanpa DB).
 *
 * Mengembalikan pasangan [status, pesan]. Bila status null, gambar boleh
 * dilanjutkan ke pengecekan pemilik. Fungsi ini sengaja dipisah agar logika
 * ACL mudah diuji tanpa HTTP request penuh.
 */
function pixelhop_image_metadata_status(array $image, ?int $now = null): array
{
    $now = $now ?? time();

    // Jadwal hapus eksplisit (delete_at) yang sudah lewat.
    if (!empty($image['delete_at']) && (int) $image['delete_at'] <= $now) {
        return [410, 'Image has expired or has been deleted.'];
    }

    // Gambar publik tanpa user yang ditandai hapus oleh cron. Bila sudah
    // melewati masa tenggang 30 hari, objek dianggap sudah/tidak boleh diambil.
    if (!empty($image['marked_for_deletion'])) {
        $markedAt = (int) $image['marked_for_deletion'];
        if ($now - $markedAt >= PIXELHOP_IMAGE_MARKED_GRACE_SECONDS) {
            return [410, 'Image has expired or has been deleted.'];
        }
    }

    return [null, null];
}

/**
 * Pemetaan status akun pemilik ke status respons ACL.
 *
 * Murni (tanpa DB/IO) agar mudah diuji. Sesuai aturan view.php:
 * hanya akun `active` yang boleh menyajikan gambar; suspended/non-aktif
 * mendapat 451.
 */
function pixelhop_image_owner_access_status(?string $accountStatus): array
{
    if ($accountStatus === 'active') {
        return [null, null];
    }

    return [
        451,
        'This image is not accessible because the account owner has been suspended.',
    ];
}

/**
 * Ambil account_status pemilik gambar (1 query indexed). Hasil di-cache
 * statis per proses agar beberapa request ke gambar yang sama tidak
 * memukul DB berulang kali.
 */
function pixelhop_image_owner_account_status(array $image): ?string
{
    static $cache = [];

    if (empty($image['user_id'])) {
        return 'active'; // Gambar publik/guest tidak punya pemilik.
    }

    $userId = (int) $image['user_id'];
    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $owner = Database::fetchOne(
        'SELECT account_status FROM users WHERE id = ?',
        [$userId]
    );

    $status = $owner['account_status'] ?? null;
    $cache[$userId] = $status;

    return $status;
}

/**
 * Penghitung rate limit sederhana berbasis JsonStore (flock + tulis atomik).
 *
 * Return true bila request diizinkan, false bila melebihi batas. Gagal baca/
 * tulis file rate limit sengaja fail-open agar gambar tetap dapat diakses;
 * error tetap dicatat agar tidak senyap.
 */
function pixelhop_image_rate_limit_allow(
    JsonStore $store,
    int $limit,
    int $windowSeconds,
    ?int $now = null
): bool {
    $now = $now ?? time();
    $allowed = false;

    try {
        $store->mutate(function (array $data) use ($now, $limit, $windowSeconds, &$allowed): array {
            $windowStart = $data['window_start'] ?? $now;
            $requests = $data['requests'] ?? 0;

            // Jendela baru atau jendela lama sudah lewat -> reset.
            if (!is_int($windowStart) || $now - $windowStart >= $windowSeconds) {
                $allowed = true;
                return [
                    'window_start' => $now,
                    'requests' => 1,
                ];
            }

            if ((int) $requests >= $limit) {
                $allowed = false;
                return $data;
            }

            $data['window_start'] = $windowStart;
            $data['requests'] = (int) $requests + 1;
            $allowed = true;
            return $data;
        });
    } catch (Throwable $e) {
        error_log('i.php rate limiter error: ' . $e->getMessage());
        return true; // Fail open: jangan blokir seluruh layanan gambar.
    }

    return $allowed;
}

// ---------------------------------------------------------------------------
// Parse path /i/YYYY/MM/DD/<imageId>_<size>.<ext>
// ---------------------------------------------------------------------------

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
if (!is_string($requestPath) || $requestPath === '') {
    $requestPath = '/';
}

// Regex whitelist yang sudah ketat — TIDAK dikendorkan.
// imageId = [a-zA-Z0-9_-]+ (mendukung slug_code, mis. rumah-baru_a3x9K2).
if (!preg_match(
    '#^/i/([\d]{4}/[\d]{2}/[\d]{2}/([a-zA-Z0-9_\-]+)_(original|large|medium|thumb)\.(jpg|jpeg|png|gif|webp))$#',
    $requestPath,
    $pathMatches
)) {
    pixelhop_image_send_text(404, 'Image not found');
    exit;
}

$imagePath = $pathMatches[1];   // YYYY/MM/DD/id_size.ext
$imageId = $pathMatches[2];     // id sebelum _size
$sizeType = $pathMatches[3];    // original|large|medium|thumb
$extension = strtolower($pathMatches[4]);

// ---------------------------------------------------------------------------
// Rate limit ringan per IP
// ---------------------------------------------------------------------------

$clientIp = ClientIp::get();
$rateLimitFile = __DIR__ . '/data/ratelimit/i_' . md5($clientIp) . '.json';
$rateLimitStore = new JsonStore($rateLimitFile);

if (!pixelhop_image_rate_limit_allow(
    $rateLimitStore,
    PIXELHOP_IMAGE_RATE_LIMIT,
    PIXELHOP_IMAGE_RATE_WINDOW_SECONDS
)) {
    header('Retry-After: ' . PIXELHOP_IMAGE_RATE_WINDOW_SECONDS);
    pixelhop_image_send_text(429, 'Too many image requests. Please try again later.');
    exit;
}

// ---------------------------------------------------------------------------
// Lookup metadata gambar
// ---------------------------------------------------------------------------

$imageStore = new JsonStore(__DIR__ . '/data/images.json');
$images = $imageStore->read();
$image = $images[$imageId] ?? null;

if (!is_array($image)) {
    pixelhop_image_send_text(404, 'Image not found');
    exit;
}

// ACL metadata: delete_at dan marked_for_deletion.
[$metaStatus, $metaMessage] = pixelhop_image_metadata_status($image);
if ($metaStatus !== null) {
    pixelhop_image_send_text($metaStatus, (string) $metaMessage);
    exit;
}

// ACL pemilik: gambar milik user non-aktif/suspended tidak boleh diambil.
if (!empty($image['user_id'])) {
    try {
        $ownerStatus = pixelhop_image_owner_account_status($image);
        [$ownerAclStatus, $ownerAclMessage] = pixelhop_image_owner_access_status($ownerStatus);
        if ($ownerAclStatus !== null) {
            pixelhop_image_send_text($ownerAclStatus, (string) $ownerAclMessage);
            exit;
        }
    } catch (Throwable $e) {
        // Gagal memverifikasi status pemilik = tidak bisa menegakkan ACL.
        // Pilih fail-closed: jangan sajikan gambar.
        error_log('i.php owner status lookup failed for image ' . $imageId . ': ' . $e->getMessage());
        pixelhop_image_send_text(500, 'Unable to verify image owner status.');
        exit;
    }
}

// ---------------------------------------------------------------------------
// Pilih storage (strategi hybrid yang sudah ada)
// ---------------------------------------------------------------------------

$config = require __DIR__ . '/config/s3.php';

$r2Manager = new R2StorageManager($config);

if (in_array($sizeType, ['thumb', 'medium'], true) && !empty($config['r2']['enabled'])) {
    // Thumbnail & medium -> Cloudflare R2 (zero egress).
    // Presigned SigV4 URL: works on public objects today and keeps working
    // after the bucket is switched to private (no downtime).
    $storageUrl = $r2Manager->getSignedUrl('r2', $imagePath, 300);
} else {
    // Original & large -> Contabo S3.
    $storageUrl = $r2Manager->getSignedUrl('contabo', $imagePath, 300);
}

$contentTypeByExtension = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
];
$fallbackContentType = $contentTypeByExtension[$extension] ?? 'application/octet-stream';

// ---------------------------------------------------------------------------
// Streaming proxy ke upstream
// ---------------------------------------------------------------------------

$ch = curl_init($storageUrl);

$upstreamStatus = null;       // Status HTTP dari upstream (200/206/...).
$upstreamHeaders = [];        // Header upstream yang relevan (di-forward).
$responseStarted = false;     // Sudah kirim status + header final?
$aborted = false;             // Upstream dibatalkan (terlalu besar/status error).
$streamedBytes = 0;           // Total byte body yang sudah diteruskan.

/**
 * Header callback: dipanggil cURL untuk setiap baris header upstream.
 *
 * Strategi: kumpulkan header lebih dulu; begitu blok header selesai (baris
 * kosong), kirim status + header final SEBELUM body pertama ditulis. Dengan
 * begitu status 206/Content-Range bisa diteruskan dan content-length
 * oversized bisa ditolak sebelum satu byte body pun sempat dikirim.
 */
$headerFunction = function ($ch, string $headerLine) use (
    &$upstreamStatus,
    &$upstreamHeaders,
    &$responseStarted,
    &$aborted,
    $fallbackContentType,
    $requestPath
): int {
    $line = trim($headerLine);

    // Akhir blok header upstream -> finalisasi respons kita.
    if ($line === '') {
        if ($responseStarted) {
            return strlen($headerLine);
        }
        $responseStarted = true;

        // Hanya 200 dan 206 yang diteruskan sebagai body gambar.
        if ($upstreamStatus === 200 || $upstreamStatus === 206) {
            http_response_code($upstreamStatus);

            $hasContentType = false;
            foreach ($upstreamHeaders as $upstreamHeaderLine) {
                if (preg_match(
                    '#^(Content-Type|Content-Length|Accept-Ranges|Content-Range):\s*(.+)$#i',
                    $upstreamHeaderLine,
                    $headerMatches
                )) {
                    header($headerMatches[1] . ': ' . trim($headerMatches[2]));
                    if (strcasecmp($headerMatches[1], 'Content-Type') === 0) {
                        $hasContentType = true;
                    }
                }
            }

            if (!$hasContentType) {
                header('Content-Type: ' . $fallbackContentType);
            }

            // Cache publik 1 hari — BUKAN immutable 1 tahun.
            header('Cache-Control: public, max-age=86400');
            header('X-Content-Type-Options: nosniff');

            return strlen($headerLine);
        }

        // Status selain 200/206: jangan teruskan body upstream.
        $aborted = true;
        if ($upstreamStatus !== null && $upstreamStatus >= 300 && $upstreamStatus < 400) {
            pixelhop_image_send_text(502, 'Upstream image is not available.');
        } elseif ($upstreamStatus === 404 || $upstreamStatus === 410) {
            pixelhop_image_send_text($upstreamStatus, 'Image not found or unavailable.');
        } else {
            pixelhop_image_send_text(502, 'Upstream image is not available.');
        }

        // Return 0 membatalkan transfer sebelum body dibaca/ditulis.
        return 0;
    }

    // Baris status HTTP upstream.
    if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $statusMatches)) {
        $upstreamStatus = (int) $statusMatches[1];
        return strlen($headerLine);
    }

    // Jaga-jaga: tolak objek > 50MB sebelum body dikirim.
    if (preg_match('#^Content-Length:\s*(\d+)#i', $headerLine, $lengthMatches)) {
        if ((int) $lengthMatches[1] > PIXELHOP_IMAGE_MAX_BYTES) {
            $aborted = true;
            $responseStarted = true;
            error_log(
                'i.php upstream image too large ('
                . (int) $lengthMatches[1]
                . ' bytes) for path '
                . ($requestPath ?? '')
            );
            pixelhop_image_send_text(502, 'Upstream image exceeds maximum allowed size.');
            return 0;
        }
    }

    $upstreamHeaders[] = $headerLine;
    return strlen($headerLine);
};

/**
 * Write callback: streaming body langsung ke output, tanpa menampung
 * seluruh body di variabel PHP. Return strlen($data) agar cURL tahu semua
 * byte sudah dikonsumsi.
 */
$writeFunction = function ($ch, string $data) use (&$aborted, &$streamedBytes): int {
    if ($aborted) {
        return 0;
    }

    echo $data;
    $streamedBytes += strlen($data);

    return strlen($data);
};

$requestHeaders = [];
if (!empty($_SERVER['HTTP_RANGE'])) {
    // Teruskan Range apa adanya; biarkan upstream yang membalas 206.
    $rangeHeader = trim($_SERVER['HTTP_RANGE']);
    if (strlen($rangeHeader) <= 512) {
        $requestHeaders[] = 'Range: ' . $rangeHeader;
    }
}

curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER => $requestHeaders,
    CURLOPT_HEADER => false,
    CURLOPT_HEADERFUNCTION => $headerFunction,
    CURLOPT_WRITEFUNCTION => $writeFunction,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$curlResult = curl_exec($ch);
$curlError = curl_error($ch);
$upstreamHttpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
// Tidak memanggil curl_close(): sejak PHP 8 handle cURL adalah objek yang
// dibersihkan otomatis, dan curl_close() deprecated di PHP 8.5.

// Transfer dibatalkan oleh header callback (bad status/terlalu besar);
// respons final sudah dikirim di callback, jangan kirim body/header ganda.
if ($aborted) {
    exit;
}

// cURL gagal total (DNS/timeout/SSL). Bila belum ada header final terkirim,
// beri 502. Jika header final sudah terkirim dan body terlanjur stream,
// tidak ada yang bisa kita lakukan selain menghentikan respons.
if ($curlResult === false) {
    error_log('i.php upstream request failed: ' . $curlError . ' url=' . $storageUrl);
    if (!$responseStarted) {
        pixelhop_image_send_text(502, 'Upstream image is not available.');
    }
    exit;
}

// Upstream tidak memanggil write callback (mis. body kosong). Pastikan tetap
// ada respons, dan perlakukan 3xx sebagai error karena FOLLOWLOCATION=false.
if (!$responseStarted) {
    error_log('i.php upstream returned empty body status=' . $upstreamHttpCode . ' url=' . $storageUrl);
    pixelhop_image_send_text(502, 'Upstream image is not available.');
    exit;
}
