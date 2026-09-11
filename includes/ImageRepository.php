<?php
/**
 * PixelHop - ImageRepository
 *
 * SATU kelas resmi untuk semua akses data foto.
 *
 * Reliability Fase 2: backend dikendalikan oleh properti $mode:
 *   - 'json'       (default) baca JSON, tulis JSON. Perilaku SEBYTE-IDENTIK
 *                  dengan kode lama (data/images.json via JsonStore).
 *   - 'dual_write' baca JSON (sumber kebenaran sementara), tulis JSON LALU DB.
 *                  DB hanya best-effort: kegagalan DB dicatat (Logger::error +
 *                  Alerter::critical) dan TIDAK menggagalkan operasi.
 *   - 'db_primary' baca DB dulu, fallback ke JSON bila record tidak ada di DB.
 *                  Tulis DB LALU JSON (DB dulu; gagal JSON hanya log).
 *   - 'db_only'    baca DB saja, tulis DB saja. (Disiapkan untuk cutover final;
 *                  JANGAN dipakai sekarang.)
 *
 * Mode di-resolve per instance dari (prioritas):
 *   1. env IMAGE_STORE_MODE (instant, DB-independent)
 *   2. site_settings.images_store_mode (database, di-cache statis per proses)
 *   3. default 'json'
 *
 * Kelas ini diletakkan di global namespace agar bisa di-require manual
 * tanpa autoloader, konsisten dengan struktur repo PixelHop.
 */

require_once __DIR__ . '/JsonStore.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Logger.php';

if (!class_exists('Alerter', false)) {
    @require_once __DIR__ . '/Alerter.php';
}

final class ImageRepository
{
    private const DATA_FILE = __DIR__ . '/../data/images.json';

    /** Daftar mode yang sah. Nilai lain dianggap invalid -> fallback 'json'. */
    private const VALID_MODES = ['json', 'dual_write', 'db_primary', 'db_only'];

    /** Kolom yang boleh ditulis ke tabel `images` (skema migrasi 003). */
    private const DB_COLUMNS = [
        'id',
        'user_id',
        'ip',
        'filename',
        'mime_type',
        'extension',
        'size',
        'width',
        'height',
        'hash',
        'urls',
        's3_keys',
        'storage_providers',
        'view_count',
        'last_viewed_at',
        'delete_at',
        'marked_for_deletion',
        'deleting_at',
        'last_delete_error',
        'created_at',
        'updated_at',
    ];

    /** Kolom array/JSON yang di-encode saat tulis & di-decode saat baca DB. */
    private const DB_JSON_COLUMNS = [
        'urls',
        's3_keys',
        'storage_providers',
        'last_delete_error',
    ];

    /**
     * Cache statis JsonStore per proses. Semua instance ImageRepository
     * berbagi satu JsonStore sehingga path/lock/read konsisten.
     */
    private static ?JsonStore $jsonStore = null;

    /**
     * Cache statis nilai site_settings.images_store_mode per proses.
     * null = belum di-resolve; false = DB tidak tersedia / tidak ada setting.
     */
    private static ?bool $settingsCacheInitialized = null;
    private static ?string $settingsMode = null;

    /**
     * Cache statis instance PDO per proses (dipakai semua instance repo).
     */
    private static ?PDO $pdo = null;

    /**
     * Jumlah fallback baca DB -> JSON pada mode db_primary.
     */
    private static int $fallbackHits = 0;

    /**
     * Backend aktif. Default 'json'.
     */
    private string $mode;

    public function __construct(?string $mode = null)
    {
        $this->mode = $this->resolveMode($mode);
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Jumlah fallback DB -> JSON yang terjadi pada mode db_primary
     * selama proses berjalan (per PHP process / request).
     */
    public function getFallbackHits(): int
    {
        return self::$fallbackHits;
    }

    /**
     * Ambil satu record by id, atau null bila tidak ada / bukan array.
     */
    public function find(string $id): ?array
    {
        $this->assertReadableMode();

        if ($this->mode === 'json' || $this->mode === 'dual_write') {
            $record = $this->json()->read()[$id] ?? null;

            return is_array($record) ? $record : null;
        }

        if ($this->mode === 'db_primary') {
            $record = $this->dbFind($id);

            if ($record !== null) {
                return $record;
            }

            // Jaring pengaman: record belum ada di DB (backfill sedang
            // berjalan / DB baru). Baca dari JSON dan hitung fallback.
            $record = $this->json()->read()[$id] ?? null;

            if (is_array($record)) {
                self::$fallbackHits++;
                Logger::warning('images_store_fallback', 'Record tidak ditemukan di DB, fallback ke JSON', ['id' => $id]);
                return $record;
            }

            return null;
        }

        // db_only
        return $this->dbFind($id);
    }

    /**
     * Cek keberadaan key (tidak peduli isinya, seperti array_key_exists
     * yang dipakai generateId sebelumnya).
     */
    public function exists(string $id): bool
    {
        $this->assertReadableMode();

        if ($this->mode === 'json' || $this->mode === 'dual_write') {
            return array_key_exists($id, $this->json()->read());
        }

        if ($this->mode === 'db_primary') {
            if ($this->dbExists($id)) {
                return true;
            }

            if (array_key_exists($id, $this->json()->read())) {
                self::$fallbackHits++;
                Logger::warning('images_store_fallback', 'Record tidak ditemukan di DB, fallback ke JSON', ['id' => $id]);
                return true;
            }

            return false;
        }

        // db_only
        return $this->dbExists($id);
    }

    /**
     * Cari duplikat berdasarkan hash.
     *
     * Disalin persis dari logika findDuplicateImage() lama di api/upload.php:
     * - record yang punya delete_at dilewati
     * - user login hanya cocok dengan upload miliknya sendiri
     * - guest hanya cocok dengan upload guest lain dari IP yang sama
     *
     * Catatan: parameter $size dipertahankan untuk kompatibilitas signature;
     * sengaja TIDAK dipakai membandingkan karena kode lama juga tidak
     * membandingkan size (perilaku dipertahankan sebyte-identik).
     */
    public function findDuplicate(string $hash, int $size, ?int $userId, ?string $ip): ?array
    {
        if ($this->mode === 'db_primary') {
            $fromDb = $this->findDuplicateInRows($this->dbAll(), $hash, $userId, $ip);

            if ($fromDb !== null) {
                return $fromDb;
            }

            $fromJson = $this->findDuplicateInRows($this->json()->read(), $hash, $userId, $ip);
            if ($fromJson !== null) {
                self::$fallbackHits++;
                Logger::warning('images_store_fallback', 'Pencarian duplikat DB kosong, fallback ke JSON', [
                    'hash' => $hash,
                ]);
                return $fromJson;
            }

            return null;
        }

        return $this->findDuplicateInRows($this->readAll(), $hash, $userId, $ip);
    }

    /**
     * Cari duplikat di dalam map [id => record].
     *
     * Logika identik dengan findDuplicateImage() lama di api/upload.php:
     * record dengan delete_at dilewati, user login hanya cocok dengan
     * upload miliknya sendiri, guest hanya cocok dengan guest dari IP sama.
     * Parameter $size sengaja tidak dipakai (kode lama juga tidak memakai).
     */
    private function findDuplicateInRows(array $images, string $hash, ?int $userId, ?string $ip): ?array
    {
        foreach ($images as $imageId => $imageData) {
            if (!is_array($imageData)) {
                continue;
            }

            if (!empty($imageData['delete_at'])) {
                continue;
            }

            $recordUserId = isset($imageData['user_id']) ? (int) $imageData['user_id'] : 0;

            if ($userId) {
                if ($recordUserId !== (int) $userId) {
                    continue;
                }
            } else {
                // Keduanya harus guest upload dan IP tercatat harus sama.
                if ($recordUserId !== 0) {
                    continue;
                }
                if (($imageData['ip'] ?? '') !== $ip) {
                    continue;
                }
            }

            if (!empty($imageData['hash']) && $imageData['hash'] === $hash) {
                $imageData['id'] = $imageId;
                return $imageData;
            }
        }

        return null;
    }

    /**
     * Simpan entri (buat baru atau overwrite).
     *
     * Mode json: JsonStore::mutate() atomik (perilaku lama persis).
     * Mode dual_write: JSON dulu (authoritative), lalu DB best-effort.
     * Mode db_primary/db_only: DB dulu; db_primary menulis JSON
     * best-effort setelah DB sukses.
     */
    public function save(string $id, array $data): void
    {
        if ($this->mode === 'json') {
            $this->jsonWrite($id, $data);
            return;
        }

        if ($this->mode === 'dual_write') {
            $this->jsonWrite($id, $data);
            $this->dbSaveBestEffort($id, $data);
            return;
        }

        if ($this->mode === 'db_primary') {
            $this->dbSave($id, $data);

            try {
                $this->jsonWrite($id, $data);
            } catch (Throwable $e) {
                Logger::warning('images_store', 'JSON write failed (DB authoritative)', [
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // db_only
        $this->dbSave($id, $data);
    }

    /**
     * Hapus entri by id. Return true bila key/record ada dan dihapus.
     */
    public function delete(string $id): bool
    {
        if ($this->mode === 'json') {
            return $this->json()->delete($id);
        }

        if ($this->mode === 'dual_write') {
            $removed = $this->json()->delete($id);

            try {
                $this->dbDelete($id);
            } catch (Throwable $e) {
                Logger::error('images_store', 'DB write failed', [
                    'op' => 'delete',
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $this->alertDbFailure('DB write failed', [
                    'op' => 'delete',
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $removed;
        }

        if ($this->mode === 'db_primary') {
            $removed = $this->dbDelete($id);

            try {
                if ($removed) {
                    $this->json()->delete($id);
                }
            } catch (Throwable $e) {
                Logger::warning('images_store', 'JSON write failed (DB authoritative)', [
                    'op' => 'delete',
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $removed;
        }

        // db_only
        return $this->dbDelete($id);
    }

    /**
     * Increment view_count, set last_viewed_at, dan hapus marked_for_deletion
     * (bila ada) dalam SATU operasi tulis.
     */
    public function markViewed(string $id): void
    {
        $now = time();

        if ($this->mode === 'json') {
            $this->json()->mutate(function (array $images) use ($id, $now): array {
                if (array_key_exists($id, $images) && is_array($images[$id])) {
                    $record = $images[$id];
                    $record['view_count'] = (int) ($record['view_count'] ?? 0) + 1;
                    $record['last_viewed_at'] = $now;
                    unset($record['marked_for_deletion']);
                    $images[$id] = $record;
                }

                return $images;
            });
            return;
        }

        if ($this->mode === 'dual_write') {
            $updated = $this->jsonMarkViewed($id, $now);
            if ($updated !== null) {
                $this->dbSaveBestEffort($id, $updated);
            }
            return;
        }

        if ($this->mode === 'db_primary') {
            $updated = $this->dbMarkViewed($id, $now);

            try {
                if ($updated !== null) {
                    $this->jsonWrite($id, $updated);
                }
            } catch (Throwable $e) {
                Logger::warning('images_store', 'JSON write failed (DB authoritative)', [
                    'op' => 'markViewed',
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // db_only
        $this->dbMarkViewed($id, $now);
    }

    /**
     * Tandai untuk penghapusan (guest inactive 60 hari).
     * Return true bila record ada dan berhasil ditandai.
     */
    public function markForDeletion(string $id): bool
    {
        $now = time();

        if ($this->mode === 'json') {
            $found = false;

            $this->json()->mutate(function (array $images) use ($id, $now, &$found): array {
                if (array_key_exists($id, $images)) {
                    if (!is_array($images[$id])) {
                        $images[$id] = [];
                    }
                    $images[$id]['marked_for_deletion'] = $now;
                    $found = true;
                }

                return $images;
            });

            return $found;
        }

        if ($this->mode === 'dual_write') {
            $found = $this->jsonMarkForDeletion($id, $now);
            if ($found) {
                $record = $this->jsonReadRecord($id);
                if ($record !== null) {
                    $this->dbSaveBestEffort($id, $record);
                }
            }
            return $found;
        }

        if ($this->mode === 'db_primary') {
            $found = $this->dbMarkForDeletion($id, $now);

            try {
                if ($found) {
                    $record = $this->dbFind($id);
                    if ($record !== null) {
                        $this->jsonWrite($id, $record);
                    }
                }
            } catch (Throwable $e) {
                Logger::warning('images_store', 'JSON write failed (DB authoritative)', [
                    'op' => 'markForDeletion',
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $found;
        }

        // db_only
        return $this->dbMarkForDeletion($id, $now);
    }

    /**
     * Klaim record untuk dihapus antar proses.
     *
     * Set deleting_at = epoch sekarang. Return false bila record tidak ada
     * atau deleting_at sudah ada dan belum basi (< 1 jam), sehingga proses
     * lain tidak double-claim record yang sedang dihapus.
     */
    public function claimForDeletion(string $id): bool
    {
        $now = time();

        if ($this->mode === 'json') {
            $claimed = false;

            $this->json()->mutate(function (array $images) use ($id, $now, &$claimed): array {
                if (!array_key_exists($id, $images)) {
                    return $images;
                }

                $record = is_array($images[$id]) ? $images[$id] : [];
                $existing = $record['deleting_at'] ?? null;

                if ($existing !== null && ($now - (int) $existing) < 3600) {
                    return $images;
                }

                $record['deleting_at'] = $now;
                $images[$id] = $record;
                $claimed = true;

                return $images;
            });

            return $claimed;
        }

        if ($this->mode === 'dual_write') {
            $claimed = $this->jsonClaimForDeletion($id, $now);
            if ($claimed) {
                $record = $this->jsonReadRecord($id);
                if ($record !== null) {
                    $this->dbSaveBestEffort($id, $record);
                }
            }
            return $claimed;
        }

        if ($this->mode === 'db_primary') {
            $claimed = $this->dbClaimForDeletion($id, $now);

            try {
                if ($claimed) {
                    $record = $this->dbFind($id);
                    if ($record !== null) {
                        $this->jsonWrite($id, $record);
                    }
                }
            } catch (Throwable $e) {
                Logger::warning('images_store', 'JSON write failed (DB authoritative)', [
                    'op' => 'claimForDeletion',
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $claimed;
        }

        // db_only
        return $this->dbClaimForDeletion($id, $now);
    }

    /**
     * Lepas klaim penghapusan (hapus deleting_at). Bila $error diberikan,
     * set last_delete_error untuk keperluan retry/debug.
     */
    public function releaseClaim(string $id, array|string|null $error = null): void
    {
        if ($this->mode === 'json') {
            $this->json()->mutate(function (array $images) use ($id, $error): array {
                if (array_key_exists($id, $images) && is_array($images[$id])) {
                    unset($images[$id]['deleting_at']);
                    if ($error !== null) {
                        $images[$id]['last_delete_error'] = $error;
                    }
                }

                return $images;
            });
            return;
        }

        if ($this->mode === 'dual_write') {
            $this->jsonReleaseClaim($id, $error);

            try {
                $this->dbReleaseClaim($id, $error);
            } catch (Throwable $e) {
                Logger::error('images_store', 'DB write failed', [
                    'op' => 'releaseClaim',
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $this->alertDbFailure('DB write failed', [
                    'op' => 'releaseClaim',
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        if ($this->mode === 'db_primary') {
            $this->dbReleaseClaim($id, $error);

            try {
                $record = $this->dbFind($id);
                if ($record !== null) {
                    // Legacy JSON unset deleting_at; samakan mirror agar
                    // tidak muncul key dengan nilai null.
                    unset($record['deleting_at']);
                    $this->jsonWrite($id, $record);
                }
            } catch (Throwable $e) {
                Logger::warning('images_store', 'JSON write failed (DB authoritative)', [
                    'op' => 'releaseClaim',
                    'id' => $id,
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // db_only
        $this->dbReleaseClaim($id, $error);
    }

    /**
     * Jumlah record dengan user_id tidak null (upload user login).
     */
    public function countUsers(): int
    {
        if ($this->mode === 'json' || $this->mode === 'dual_write') {
            return $this->countUsersFromJson();
        }

        if ($this->mode === 'db_primary') {
            $count = $this->countUsersFromDb();

            if ($count > 0) {
                return $count;
            }

            // DB masih kosong/backfill: pakai JSON sebagai jaring pengaman.
            if ($this->dbCountAll() > 0) {
                return 0;
            }

            $jsonCount = $this->countUsersFromJson();
            if ($jsonCount > 0) {
                self::$fallbackHits++;
                Logger::warning('images_store_fallback', 'Perhitungan DB kosong, fallback ke JSON', []);
                return $jsonCount;
            }

            return 0;
        }

        // db_only
        return $this->countUsersFromDb();
    }

    /**
     * Jumlah record milik user tertentu.
     */
    public function countByUser(int $userId): int
    {
        if ($this->mode === 'json' || $this->mode === 'dual_write') {
            return $this->countByUserFromJson($userId);
        }

        if ($this->mode === 'db_primary') {
            $count = $this->countByUserFromDb($userId);

            if ($count > 0) {
                return $count;
            }

            // DB masih kosong/backfill: pakai JSON sebagai jaring pengaman.
            if ($this->dbCountAll() > 0) {
                return 0;
            }

            $jsonCount = $this->countByUserFromJson($userId);
            if ($jsonCount > 0) {
                self::$fallbackHits++;
                Logger::warning('images_store_fallback', 'Perhitungan DB kosong, fallback ke JSON', []);
                return $jsonCount;
            }

            return 0;
        }

        // db_only
        return $this->countByUserFromDb($userId);
    }

    /**
     * Iterasi semua record sebagai Generator [id => data].
     *
     * Dipakai AbuseGuard/watchdog yang full-scan; kompleksitas dibiarkan sama
     * (baca penuh), hanya disentralisasi lewat repo.
     */
    public function iterateAll(): Generator
    {
        yield from $this->readAll();
    }

    /**
     * Seluruh array data (untuk kebutuhan render yang memang membaca semua).
     */
    public function readAll(): array
    {
        $this->assertReadableMode();

        if ($this->mode === 'json' || $this->mode === 'dual_write') {
            return $this->json()->read();
        }

        if ($this->mode === 'db_primary') {
            $dbRows = $this->dbAll();

            if ($dbRows !== []) {
                return $dbRows;
            }

            $jsonRows = $this->json()->read();
            if ($jsonRows !== []) {
                self::$fallbackHits++;
                Logger::warning('images_store_fallback', 'Data DB kosong, fallback ke JSON', []);
                return $jsonRows;
            }

            return [];
        }

        // db_only
        return $this->dbAll();
    }

    /**
     * JsonStore bersama untuk backend berbasis JSON.
     */
    private function json(): JsonStore
    {
        if (self::$jsonStore === null) {
            self::$jsonStore = new JsonStore(self::DATA_FILE);
        }

        return self::$jsonStore;
    }

    /**
     * PDO bersama untuk backend berbasis DB.
     */
    private function db(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = Database::getInstance();
        }

        return self::$pdo;
    }

    /**
     * Resolve mode aktif dari konstruktor/param, env, atau site_settings.
     *
     * @param string|null $requestedMode Mode eksplisit dari konstruktor
     *                                   (hanya dipakai pengujian). Bila null,
     *                                   resolve dari env lalu site_settings.
     */
    private function resolveMode(?string $requestedMode): string
    {
        if ($requestedMode !== null && $requestedMode !== '') {
            return $this->normalizeMode($requestedMode, 'constructor');
        }

        $env = getenv('IMAGE_STORE_MODE');
        if (is_string($env) && $env !== '') {
            return $this->normalizeMode($env, 'IMAGE_STORE_MODE');
        }

        $setting = $this->readModeSetting();
        if ($setting !== null) {
            return $this->normalizeMode($setting, 'site_settings.images_store_mode');
        }

        return 'json';
    }

    /**
     * Validasi nilai mode. Nilai invalid dikembalikan sebagai 'json' + warning.
     */
    private function normalizeMode(string $mode, string $source): string
    {
        $mode = strtolower(trim($mode));

        if (in_array($mode, self::VALID_MODES, true)) {
            return $mode;
        }

        Logger::warning('images_store', 'Mode penyimpanan tidak dikenal, fallback ke json', [
            'source' => $source,
            'mode' => $mode,
        ]);

        return 'json';
    }

    /**
     * Baca site_settings.images_store_mode via Database::fetchOne.
     *
     * Di-cache statis per proses. Semua error DB ditelan (return null)
     * karena resolver env/default harus tetap berfungsi tanpa DB.
     */
    private function readModeSetting(): ?string
    {
        if (self::$settingsCacheInitialized) {
            return self::$settingsMode;
        }

        self::$settingsCacheInitialized = true;
        self::$settingsMode = null;

        try {
            $row = Database::fetchOne(
                "SELECT setting_value FROM site_settings WHERE setting_key='images_store_mode' LIMIT 1"
            );

            if (is_array($row) && array_key_exists('setting_value', $row)) {
                $value = $row['setting_value'];
                if (is_string($value) && trim($value) !== '') {
                    self::$settingsMode = trim($value);
                }
            }
        } catch (Throwable $e) {
            // DB tidak tersedia/site_settings belum ada: biarkan null,
            // resolver lanjut ke default 'json'.
        }

        return self::$settingsMode;
    }

    /**
     * Pastikan mode saat ini mendukung operasi baca. Mode selain 4 mode
     * yang sah seharusnya sudah dinormalisasi di konstruktor; guard ini
     * adalah lapisan defensif terakhir.
     */
    private function assertReadableMode(): void
    {
        if (!in_array($this->mode, self::VALID_MODES, true)) {
            throw new RuntimeException(
                'ImageRepository: backend "' . $this->mode . '" belum diimplementasikan'
            );
        }
    }

    /**
     * Tulis satu record ke JSON (buat/overwrite) via mutate() atomik.
     */
    private function jsonWrite(string $id, array $data): void
    {
        $this->json()->mutate(function (array $images) use ($id, $data): array {
            $images[$id] = $data;
            return $images;
        });
    }

    /**
     * Baca satu record dari JSON.
     */
    private function jsonReadRecord(string $id): ?array
    {
        $record = $this->json()->read()[$id] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * Increment view_count + set last_viewed_at + hapus marked_for_deletion
     * di JSON. Return record terbaru, atau null bila record tidak ada.
     */
    private function jsonMarkViewed(string $id, int $now): ?array
    {
        $updated = null;

        $this->json()->mutate(function (array $images) use ($id, $now, &$updated): array {
            if (array_key_exists($id, $images) && is_array($images[$id])) {
                $record = $images[$id];
                $record['view_count'] = (int) ($record['view_count'] ?? 0) + 1;
                $record['last_viewed_at'] = $now;
                unset($record['marked_for_deletion']);
                $images[$id] = $record;
                $updated = $record;
            }

            return $images;
        });

        return $updated;
    }

    /**
     * Tandai marked_for_deletion di JSON. Return true bila record ada.
     */
    private function jsonMarkForDeletion(string $id, int $now): bool
    {
        $found = false;

        $this->json()->mutate(function (array $images) use ($id, $now, &$found): array {
            if (array_key_exists($id, $images)) {
                if (!is_array($images[$id])) {
                    $images[$id] = [];
                }
                $images[$id]['marked_for_deletion'] = $now;
                $found = true;
            }

            return $images;
        });

        return $found;
    }

    /**
     * Klaim deleting_at di JSON. Return true bila berhasil klaim.
     */
    private function jsonClaimForDeletion(string $id, int $now): bool
    {
        $claimed = false;

        $this->json()->mutate(function (array $images) use ($id, $now, &$claimed): array {
            if (!array_key_exists($id, $images)) {
                return $images;
            }

            $record = is_array($images[$id]) ? $images[$id] : [];
            $existing = $record['deleting_at'] ?? null;

            if ($existing !== null && ($now - (int) $existing) < 3600) {
                return $images;
            }

            $record['deleting_at'] = $now;
            $images[$id] = $record;
            $claimed = true;

            return $images;
        });

        return $claimed;
    }

    /**
     * Lepas klaim di JSON; bila $error diberikan, catat last_delete_error.
     */
    private function jsonReleaseClaim(string $id, array|string|null $error): void
    {
        $this->json()->mutate(function (array $images) use ($id, $error): array {
            if (array_key_exists($id, $images) && is_array($images[$id])) {
                unset($images[$id]['deleting_at']);
                if ($error !== null) {
                    $images[$id]['last_delete_error'] = $error;
                }
            }

            return $images;
        });
    }

    /**
     * Hitung record user login dari JSON.
     */
    private function countUsersFromJson(): int
    {
        $count = 0;

        foreach ($this->json()->read() as $record) {
            if (is_array($record) && ($record['user_id'] ?? null) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Hitung record milik user tertentu dari JSON.
     */
    private function countByUserFromJson(int $userId): int
    {
        $count = 0;

        foreach ($this->json()->read() as $record) {
            if (
                is_array($record)
                && ($record['user_id'] ?? null) !== null
                && (int) $record['user_id'] === $userId
            ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Ambil satu record dari DB.
     */
    private function dbFind(string $id): ?array
    {
        $row = Database::fetchOne('SELECT * FROM images WHERE id = ?', [$id]);

        return is_array($row) ? $this->decodeDbRow($row) : null;
    }

    /**
     * Cek keberadaan record di DB.
     */
    private function dbExists(string $id): bool
    {
        $row = Database::fetchOne('SELECT id FROM images WHERE id = ? LIMIT 1', [$id]);

        return is_array($row) && array_key_exists('id', $row);
    }

    /**
     * Ambil semua record dari DB dalam bentuk [id => record].
     */
    private function dbAll(): array
    {
        $rows = Database::fetchAll('SELECT * FROM images');

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $decoded = $this->decodeDbRow($row);
            $id = $decoded['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $result[$id] = $decoded;
            }
        }

        return $result;
    }

    /**
     * Simpan record ke DB (upsert). Dipakai mode db_primary & db_only
     * (kegagalan DB harus dilempar).
     */
    private function dbSave(string $id, array $data): void
    {
        $columns = array_intersect(array_keys($data), self::DB_COLUMNS);

        // Pastikan id selalu ikut tersimpan walaupun caller tidak
        // menyertakannya di $data (findDuplicate mengembalikan id, upload
        // menyertakan id; guard ini untuk keamanan).
        if (!in_array('id', $columns, true)) {
            $columns[] = 'id';
        }

        $driver = (string) $this->db()->getAttribute(PDO::ATTR_DRIVER_NAME);

        $assignments = [];
        foreach ($columns as $column) {
            if ($column === 'id') {
                continue;
            }

            // UPDATE clause TIDAK boleh memakai named placeholder yang sama
            // dengan VALUES clause. MySQL native prepares (ATTR_EMULATE_PREPARES
            // false) menolak placeholder ganda (HY093). MySQL memakai VALUES(),
            // SQLite memakai excluded.* (pola ON CONFLICT).
            if ($driver === 'mysql') {
                $assignments[] = $column . ' = VALUES(' . $column . ')';
            } else {
                $assignments[] = $column . ' = excluded.' . $column;
            }
        }

        if ($assignments === []) {
            // Upsert dengan data minim (hanya id): no-op assignment yang valid.
            if ($driver === 'mysql') {
                $assignments[] = 'id = VALUES(id)';
            } else {
                $assignments[] = 'id = excluded.id';
            }
        }

        $values = [];
        foreach ($columns as $column) {
            $values[] = ':' . $column;
        }

        $params = [];
        foreach ($columns as $column) {
            $params[':' . $column] = $this->encodeDbValue($column, $column === 'id' ? $id : ($data[$column] ?? null));
        }

        if ($driver === 'mysql') {
            $sql = 'INSERT INTO images (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);
        } else {
            // SQLite (pengujian) & driver lain yang mendukung ON CONFLICT.
            $sql = 'INSERT INTO images (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')'
                . ' ON CONFLICT(id) DO UPDATE SET ' . implode(', ', $assignments);
        }

        Database::query($sql, $params);
    }

    /**
     * Hapus record dari DB. Return true bila ada baris terhapus.
     */
    private function dbDelete(string $id): bool
    {
        return Database::execute('DELETE FROM images WHERE id = ?', [$id]) > 0;
    }

    /**
     * Increment view_count + last_viewed_at + hapus marked_for_deletion di DB.
     * Return record terbaru dari DB, atau null bila record tidak ada.
     */
    private function dbMarkViewed(string $id, int $now): ?array
    {
        Database::execute(
            "UPDATE images
             SET view_count = view_count + 1,
                 last_viewed_at = :last_viewed_at,
                 marked_for_deletion = NULL
             WHERE id = :id",
            [
                ':last_viewed_at' => $now,
                ':id' => $id,
            ]
        );

        return $this->dbFind($id);
    }

    /**
     * Tandai marked_for_deletion di DB. Return true bila record ada.
     */
    private function dbMarkForDeletion(string $id, int $now): bool
    {
        return Database::execute(
            'UPDATE images SET marked_for_deletion = ? WHERE id = ?',
            [$now, $id]
        ) > 0;
    }

    /**
     * Klaim deleting_at di DB (anti double-claim). Return true bila berhasil.
     */
    private function dbClaimForDeletion(string $id, int $now): bool
    {
        return Database::execute(
            "UPDATE images
             SET deleting_at = ?
             WHERE id = ?
               AND (deleting_at IS NULL OR deleting_at < ?)",
            [$now, $id, $now - 3600]
        ) > 0;
    }

    /**
     * Lepas klaim di DB; bila $error diberikan, catat last_delete_error.
     */
    private function dbReleaseClaim(string $id, array|string|null $error): void
    {
        $params = [':id' => $id];

        if ($error === null) {
            $errorSql = 'last_delete_error = NULL';
        } else {
            $errorSql = 'last_delete_error = :last_delete_error';
            $params[':last_delete_error'] = $this->encodeDbValue('last_delete_error', $error);
        }

        Database::query(
            "UPDATE images SET deleting_at = NULL, {$errorSql} WHERE id = :id",
            $params
        );
    }

    /**
     * Hitung record user login dari DB.
     */
    private function countUsersFromDb(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS total FROM images WHERE user_id IS NOT NULL');

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * Jumlah total baris di tabel images (untuk mendeteksi DB kosong).
     */
    private function dbCountAll(): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS total FROM images');

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * Hitung record milik user tertentu dari DB.
     */
    private function countByUserFromDb(int $userId): int
    {
        $row = Database::fetchOne('SELECT COUNT(*) AS total FROM images WHERE user_id = ?', [$userId]);

        return is_array($row) ? (int) ($row['total'] ?? 0) : 0;
    }

    /**
     * Simpan ke DB best-effort (mode dual_write). Kegagalan DB dicatat via
     * Logger::error + Alerter::critical dan TIDAK dilempar ke pemanggil.
     */
    private function dbSaveBestEffort(string $id, array $data): void
    {
        try {
            $this->dbSave($id, $data);
        } catch (Throwable $e) {
            Logger::error('images_store', 'DB write failed', [
                'op' => 'save',
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            $this->alertDbFailure('DB write failed', [
                'op' => 'save',
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Kirim Alerter::critical bila class Alerter tersedia. Jaring pengaman
     * migrasi: alert tidak boleh menggagalkan operasi utama.
     */
    private function alertDbFailure(string $message, array $context): void
    {
        try {
            if (class_exists('Alerter', false)) {
                Alerter::critical('images_store', $message, $context);
            }
        } catch (Throwable $e) {
            Logger::error('images_store', 'Gagal mengirim alert DB: ' . $e->getMessage(), [
                'op' => $context['op'] ?? null,
            ]);
        }
    }

    /**
     * Decode row DB -> array PHP. Kolom JSON di-json_decode; nilai null
     * dibiarkan null. Kolom timestamp epoch tetap integer.
     */
    private function decodeDbRow(array $row): array
    {
        foreach (self::DB_JSON_COLUMNS as $column) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            $value = $row[$column];

            if (is_string($value) && trim($value) !== '') {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row[$column] = $decoded;
                }
            } elseif ($value === null) {
                $row[$column] = null;
            }
        }

        return $row;
    }

    /**
     * Encode value untuk ditulis ke DB. Kolom array/JSON di-json_encode;
     * scalar dibiarkan apa adanya (PDO mengikat native).
     *
     * @return mixed
     */
    private function encodeDbValue(string $column, mixed $value): mixed
    {
        if (in_array($column, self::DB_JSON_COLUMNS, true)) {
            if ($value === null) {
                return null;
            }

            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $value;
    }
}
