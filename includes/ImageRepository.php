<?php
/**
 * PixelHop - ImageRepository
 *
 * SATU kelas resmi untuk semua akses data foto.
 *
 * Backend saat ini adalah JsonStore (data/images.json) dengan perilaku yang
 * SEBYTE-IDENTIK dengan kode lama. Cutover ke database (subtask W2) hanya
 * akan mengubah isi kelas ini, bukan callsite.
 *
 * Kelas ini diletakkan di global namespace agar bisa di-require manual
 * tanpa autoloader, konsisten dengan struktur repo PixelHop.
 */

require_once __DIR__ . '/JsonStore.php';

final class ImageRepository
{
    private const DATA_FILE = __DIR__ . '/../data/images.json';

    /**
     * Cache statis JsonStore per proses. Semua instance ImageRepository
     * berbagi satu JsonStore sehingga path/lock/read konsisten.
     */
    private static ?JsonStore $jsonStore = null;

    /**
     * Backend aktif. Default 'json', dapat diganti lewat
     * IMAGE_STORE_MODE. Backend selain 'json' (DB) belum diimplementasikan
     * dan akan menolak operasi data sampai subtask W2 selesai.
     */
    private string $mode;

    public function __construct(?string $mode = null)
    {
        $this->mode = $mode ?? (getenv('IMAGE_STORE_MODE') ?: 'json');
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Ambil satu record by id, atau null bila tidak ada / bukan array.
     */
    public function find(string $id): ?array
    {
        $record = $this->json()->read()[$id] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * Cek keberadaan key (tidak peduli isinya, seperti array_key_exists
     * yang dipakai generateId sebelumnya).
     */
    public function exists(string $id): bool
    {
        return array_key_exists($id, $this->json()->read());
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
        $images = $this->json()->read();

        foreach ($images as $imageId => $imageData) {
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
     * Simpan entri (buat baru atau overwrite) lewat mutate() atomik.
     *
     * Melempar exception dengan cara yang sama seperti JsonStore::mutate().
     */
    public function save(string $id, array $data): void
    {
        $this->json()->mutate(function (array $images) use ($id, $data): array {
            $images[$id] = $data;
            return $images;
        });
    }

    /**
     * Hapus entri by id lewat mutate(). Return true bila key ada dan dihapus.
     */
    public function delete(string $id): bool
    {
        return $this->json()->delete($id);
    }

    /**
     * Increment view_count, set last_viewed_at, dan hapus marked_for_deletion
     * (bila ada) dalam SATU mutate atomik.
     */
    public function markViewed(string $id): void
    {
        $now = time();

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
    }

    /**
     * Tandai untuk penghapusan (guest inactive 60 hari).
     * Return true bila record ada dan berhasil ditandai.
     */
    public function markForDeletion(string $id): bool
    {
        $found = false;
        $now = time();

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
     * Klaim record untuk dihapus antar proses.
     *
     * Set deleting_at = epoch sekarang. Return false bila record tidak ada
     * atau deleting_at sudah ada dan belum basi (< 1 jam), sehingga proses
     * lain tidak double-claim record yang sedang dihapus.
     */
    public function claimForDeletion(string $id): bool
    {
        $claimed = false;
        $now = time();

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
     * Lepas klaim penghapusan (hapus deleting_at). Bila $error diberikan,
     * set last_delete_error untuk keperluan retry/debug.
     */
    public function releaseClaim(string $id, array|string|null $error = null): void
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
     * Jumlah record dengan user_id tidak null (upload user login).
     */
    public function countUsers(): int
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
     * Jumlah record milik user tertentu.
     */
    public function countByUser(int $userId): int
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
     * Iterasi semua record sebagai Generator [id => data].
     *
     * Dipakai AbuseGuard/watchdog yang full-scan; kompleksitas dibiarkan sama
     * (baca penuh), hanya disentralisasi lewat repo.
     */
    public function iterateAll(): Generator
    {
        foreach ($this->json()->read() as $id => $data) {
            yield $id => $data;
        }
    }

    /**
     * Seluruh array data (untuk kebutuhan render yang memang membaca semua).
     */
    public function readAll(): array
    {
        return $this->json()->read();
    }

    /**
     * JsonStore bersama untuk backend 'json'.
     */
    private function json(): JsonStore
    {
        if ($this->mode !== 'json') {
            throw new RuntimeException(
                'ImageRepository: backend "' . $this->mode . '" belum diimplementasikan'
            );
        }

        if (self::$jsonStore === null) {
            self::$jsonStore = new JsonStore(self::DATA_FILE);
        }

        return self::$jsonStore;
    }
}
