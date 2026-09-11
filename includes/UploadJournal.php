<?php
/**
 * PixelHop - UploadJournal
 *
 * Reliability Fase 2: jurnal saga upload untuk crash recovery.
 *
 * Menyimpan state machine operasi multi-langkah di tabel `pending_operations`
 * (migrasi 004):
 *
 *   started -> s3_uploaded -> metadata_saved -> completed | failed
 *
 * Bila proses upload mati/OOM/dibunuh di tengah (mis. objek S3 sudah
 * ter-upload tetapi metadata belum tersimpan), baris jurnal tetap berada di
 * state non-completed. Cron `reconcile_pending.php` kemudian mengambil
 * baris-baris stale dan memutuskan:
 *   - metadata sudah ada di `images` -> op sebenarnya sukses, tandai completed;
 *   - metadata belum ada -> objek S3 yatim, hapus lalu tandai failed.
 *
 * Pilihan desain:
 *   - Constructor MENERIMA PDO dari luar (constructor injection). Ini
 *     memungkinkan pengujian memakai SQLite in-memory tanpa menyentuh
 *     singleton Database produksi, dan tetap satu jalur kode yang sama di
 *     produksi (caller memanggil Database::getInstance()).
 *   - `complete()` memakai UPDATE state='completed' (BUKAN DELETE) sehingga
 *     jurnal tetap menjadi audit trail; baris completed otomatis tidak
 *     diambil oleh stalePending() karena filter state-nya hanya state
 *     non-terminal yang belum selesai.
 *   - `open()` idempotent memakai REPLACE (MySQL & SQLite sama-sama
 *     mendukung) sehingga pemanggilan ulang dengan operation_id yang sama
 *     tidak menimbulkan duplicate-key error; row di-reset ke `started`.
 *   - `created_at` memakai epoch INT (detik), konsisten dengan skema 004.
 *
 * Kelas ini diletakkan di global namespace agar bisa di-require manual tanpa
 * autoloader, konsisten dengan struktur repo PixelHop. File ini TIDAK
 * menghasilkan output apa pun saat di-require.
 */

final class UploadJournal
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * Buka operasi baru (atau reset operasi yang sudah ada) ke state `started`.
     *
     * Idempotent: operation_id UNIQUE sehingga REPLACE menimpa row lama.
     */
    public function open(string $operationId, string $type, array $payload): void
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new RuntimeException('UploadJournal: payload gagal di-encode');
        }

        $stmt = $this->db->prepare(
            'REPLACE INTO pending_operations
                (operation_id, type, state, payload, attempts, last_error, created_at)
             VALUES
                (:operation_id, :type, :state, :payload, 0, NULL, :created_at)'
        );

        $stmt->execute([
            ':operation_id' => $operationId,
            ':type' => $type,
            ':state' => 'started',
            ':payload' => $json,
            ':created_at' => time(),
        ]);
    }

    /**
     * Majukan state operasi (started -> s3_uploaded -> metadata_saved).
     */
    public function progress(string $operationId, string $state): void
    {
        $stmt = $this->db->prepare(
            'UPDATE pending_operations
             SET state = :state, updated_at = CURRENT_TIMESTAMP
             WHERE operation_id = :operation_id'
        );

        $stmt->execute([
            ':state' => $state,
            ':operation_id' => $operationId,
        ]);
    }

    /**
     * Tandai operasi selesai. Row sengaja dipertahankan sebagai audit trail.
     */
    public function complete(string $operationId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE pending_operations
             SET state = 'completed', last_error = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE operation_id = :operation_id"
        );

        $stmt->execute([':operation_id' => $operationId]);
    }

    /**
     * Tandai operasi gagal dan catat error terakhir + increment attempts.
     */
    public function fail(string $operationId, string $error): void
    {
        $stmt = $this->db->prepare(
            "UPDATE pending_operations
             SET state = 'failed',
                 last_error = :last_error,
                 attempts = attempts + 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE operation_id = :operation_id"
        );

        $stmt->execute([
            ':last_error' => self::clipError($error),
            ':operation_id' => $operationId,
        ]);
    }

    /**
     * Ambil operasi non-terminal yang sudah lebih tua dari batas detik.
     *
     * @return array<int, array<string, mixed>>
     */
    public function stalePending(int $olderThanSeconds = 900): array
    {
        $cutoff = time() - $olderThanSeconds;

        $stmt = $this->db->prepare(
            "SELECT *
             FROM pending_operations
             WHERE state IN ('started', 's3_uploaded', 'metadata_saved')
               AND created_at < :cutoff
             ORDER BY created_at ASC"
        );

        $stmt->execute([':cutoff' => $cutoff]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Tandai hasil akhir dari proses reconcile (tanpa mengubah attempts).
     */
    public function markReconciled(string $operationId, string $finalState, ?string $error = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE pending_operations
             SET state = :state,
                 last_error = :last_error,
                 updated_at = CURRENT_TIMESTAMP
             WHERE operation_id = :operation_id'
        );

        $stmt->execute([
            ':state' => $finalState,
            ':last_error' => $error !== null ? self::clipError($error) : null,
            ':operation_id' => $operationId,
        ]);
    }

    /**
     * Kolom last_error adalah varchar(500) di skema 004. Clip agar UPDATE
     * jurnal tidak pernah gagal hanya karena pesan exception terlalu panjang.
     */
    private static function clipError(string $error): string
    {
        return mb_strlen($error) > 500 ? mb_substr($error, 0, 500) : $error;
    }
}
