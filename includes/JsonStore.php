<?php
/**
 * PixelHop - JsonStore
 *
 * Helper read-modify-write (RMW) atomik untuk file JSON data aplikasi.
 *
 * Menutup temuan audit D2-05, D4-07, D4-09, dan D6-05-fondasi:
 * semua penulis data JSON harus memegang flock(LOCK_EX) SEPANJANG
 * baca-mutasi-tulis, lalu menulis via temp file + rename() agar pembaca
 * tidak pernah melihat file setengah tertulis (anti korupsi).
 *
 * Class ini diletakkan di global namespace agar bisa di-require manual
 * tanpa autoloader, konsisten dengan struktur repo PixelHop.
 */

final class JsonStore
{
    /**
     * @param string $filePath Path absolut ke file JSON yang dikelola.
     *                         Direktori induk dipastikan ada (dibuat bila perlu).
     */
    public function __construct(private string $filePath)
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * Baca seluruh data tanpa lock eksklusif (untuk pembaca murni).
     *
     * Aman terhadap file yang sedang ditengah proses rename() karena rename
     * bersifat atomik: path target selalu berisi file lama yang utuh atau
     * file baru yang utuh, tidak pernah file sebagian.
     *
     * @return array Data hasil decode, atau [] bila file tidak ada / korup.
     */
    public function read(): array
    {
        $content = @file_get_contents($this->filePath);
        if ($content === false) {
            // Retry 1x: toleransi terhadap momen file pertama kali dibuat.
            clearstatcache(true, $this->filePath);
            $content = @file_get_contents($this->filePath);
            if ($content === false) {
                return [];
            }
        }

        return $this->decodeContent($content);
    }

    /**
     * Read-modify-write ATOMIK.
     *
     * fopen 'c+' (tanpa truncate) -> flock(LOCK_EX) -> baca -> decode ->
     * panggil $mutator($data) -> bila hasil array, tulis atomik via temp
     * file + rename(). Lock dipegang sepanjang baca-mutasi-tulis sehingga
     * tidak ada lost update antar proses konkuren.
     *
     * Jika $mutator melempar Throwable: lock dilepas, TIDAK ada penulisan,
     * dan exception dilempar ulang.
     *
     * @return mixed Nilai yang dikembalikan $mutator (array final bila mutator
     *               mengembalikan array, atau nilai lain apa pun).
     */
    public function mutate(callable $mutator): mixed
    {
        $fp = $this->openLocked();

        try {
            $content = stream_get_contents($fp);
            if ($content === false) {
                throw new RuntimeException(
                    'JsonStore: gagal membaca ' . $this->filePath
                );
            }

            $data = $this->decodeContent($content);
            $result = $mutator($data);

            if (is_array($result)) {
                $this->writeAtomic($result);
                return $result;
            }

            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Tulis seluruh array (ganti isi) secara atomik di dalam lock.
     *
     * Penulisan memakai temp file + rename(); file target tidak pernah
     * ditulis langsung, sehingga pembaca tidak melihat data sebagian.
     */
    public function write(array $data): void
    {
        $fp = $this->openLocked();

        try {
            $this->writeAtomic($data);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Backup file saat ini.
     *
     * Salin ke <file>.<YmdHis>.bak, atau <file>.<YmdHis>.bak.gz bila
     * ukuran file > 5MB. Setelah backup, rotasi menyisakan maksimal $keep
     * backup terbaru (default 10) dan menghapus yang lebih tua.
     *
     * @return string|null Path backup yang dibuat, atau null bila gagal/file tidak ada.
     */
    public function backup(int $keep = 10): ?string
    {
        if (!is_file($this->filePath)) {
            return null;
        }

        $size = @filesize($this->filePath);
        if ($size === false) {
            return null;
        }

        $stamp = date('YmdHis');
        $compress = $size > 5 * 1024 * 1024;
        $backupPath = $this->filePath . '.' . $stamp . '.bak'
            . ($compress ? '.gz' : '');

        // Hindari penimpaan bila backup dipanggil lebih dari sekali
        // dalam detik yang sama (date('YmdHis') sama).
        $n = 1;
        while (file_exists($backupPath)) {
            $backupPath = $this->filePath . '.' . $stamp . '-' . $n . '.bak'
                . ($compress ? '.gz' : '');
            $n++;
        }

        if ($compress) {
            $src = @fopen($this->filePath, 'rb');
            if ($src === false) {
                return null;
            }

            $dst = @gzopen($backupPath, 'wb9');
            if ($dst === false) {
                fclose($src);
                return null;
            }

            $ok = stream_copy_to_stream($src, $dst) !== false;
            gzclose($dst);
            fclose($src);

            if (!$ok) {
                @unlink($backupPath);
                return null;
            }
        } else {
            if (!@copy($this->filePath, $backupPath)) {
                return null;
            }
        }

        $this->rotateBackups($keep);

        return $backupPath;
    }

    /**
     * Hapus entri by key melalui mutate() yang aman (convenience).
     *
     * @return bool true bila key ditemukan dan dihapus, false bila tidak ada.
     */
    public function delete(string $key): bool
    {
        if (!is_file($this->filePath)) {
            return false;
        }

        $removed = false;

        $this->mutate(function (array $data) use ($key, &$removed): array {
            if (array_key_exists($key, $data)) {
                unset($data[$key]);
                $removed = true;
            }
            return $data;
        });

        return $removed;
    }

    /**
     * Buka file data, kunci LOCK_EX, dan pastikan lock berada pada inode
     * yang masih ditunjuk path (file belum digantikan rename saat menunggu).
     *
     * Verifikasi inode penting karena flock melekat pada inode, sedangkan
     * rename() mengganti inode path. Tanpa verifikasi, proses yang sudah
     * membuka fd lama bisa memegang lock pada inode basi dan menimpa data.
     *
     * @return resource File handle terkunci.
     */
    private function openLocked()
    {
        $maxAttempts = 20;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $fp = @fopen($this->filePath, 'c+');
            if ($fp === false) {
                throw new RuntimeException(
                    'JsonStore: tidak bisa membuka ' . $this->filePath
                );
            }

            if (!flock($fp, LOCK_EX)) {
                fclose($fp);
                throw new RuntimeException(
                    'JsonStore: tidak bisa mengunci ' . $this->filePath
                );
            }

            $lockedStat = @fstat($fp);
            $pathStat = @stat($this->filePath);

            if (
                $lockedStat !== false
                && $pathStat !== false
                && $lockedStat['dev'] === $pathStat['dev']
                && $lockedStat['ino'] === $pathStat['ino']
            ) {
                return $fp;
            }

            // File sudah diganti via rename saat kita menunggu lock.
            // Lepas lock basi dan ulangi pada inode terbaru.
            flock($fp, LOCK_UN);
            fclose($fp);
            clearstatcache(true, $this->filePath);
            usleep(1000);
        }

        throw new RuntimeException(
            'JsonStore: tidak bisa mendapatkan lock stabil untuk ' . $this->filePath
        );
    }

    /**
     * Decode isi JSON menjadi array.
     *
     * File kosong dianggap []. JSON tidak valid dicatat ke error_log dan
     * diperlakukan sebagai []; file mentah TIDAK dihapus.
     */
    private function decodeContent(string $content): array
    {
        if (trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (is_array($data)) {
            return $data;
        }

        error_log(
            'JsonStore: JSON korup di ' . $this->filePath
            . ' - ' . json_last_error_msg()
        );

        return [];
    }

    /**
     * Tulis array ke temp file di direktori yang sama, lalu rename() atomik
     * ke file target. Pemanggil harus sudah memegang LOCK_EX.
     */
    private function writeAtomic(array $data): void
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(
                'JsonStore: gagal encode JSON untuk ' . $this->filePath
                . ' - ' . json_last_error_msg()
            );
        }

        $dir = dirname($this->filePath);
        $tmp = @tempnam($dir, '.' . basename($this->filePath) . '.tmp.');
        if ($tmp === false) {
            $tmp = $this->filePath . '.tmp';
        }

        $tfp = @fopen($tmp, 'wb');
        if ($tfp === false) {
            throw new RuntimeException(
                'JsonStore: tidak bisa menulis file sementara ' . $tmp
            );
        }

        try {
            $length = strlen($json);
            $written = 0;
            while ($written < $length) {
                $chunk = fwrite($tfp, substr($json, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new RuntimeException(
                        'JsonStore: gagal menulis file sementara ' . $tmp
                    );
                }
                $written += $chunk;
            }
            fflush($tfp);
        } finally {
            fclose($tfp);
        }

        // Pertahankan permission file lama bila ada, bila tidak pakai 0644.
        $perms = @fileperms($this->filePath);
        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        } else {
            @chmod($tmp, 0644);
        }

        if (!@rename($tmp, $this->filePath)) {
            @unlink($tmp);
            throw new RuntimeException(
                'JsonStore: gagal rename file sementara ke ' . $this->filePath
            );
        }
    }

    /**
     * Rotasi backup: sisakan maksimal $keep backup terbaru, hapus sisanya.
     */
    private function rotateBackups(int $keep): void
    {
        if ($keep < 1) {
            $keep = 1;
        }

        $backups = glob($this->filePath . '.*.bak*');
        if ($backups === false || count($backups) <= $keep) {
            return;
        }

        sort($backups, SORT_STRING);

        $excess = count($backups) - $keep;
        for ($i = 0; $i < $excess; $i++) {
            @unlink($backups[$i]);
        }
    }
}
