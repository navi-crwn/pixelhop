<?php
/**
 * PixelHop - Centralised global helpers (Fase 3 / A3)
 *
 * Tujuan
 * ------
 * File ini menyediakan helper global terpusat untuk fungsi-fungsi yang saat ini
 * terduplikasi di banyak file repo (audit menemukan `formatBytes` di ~10 file
 * serta `jsonResponse`/`jsonError` yang implementasinya bervariasi).
 *
 * Strategi transisi
 * -----------------
 * Fungsi lokal lama yang masih didefinisikan di file lain TIDAK diubah di sini.
 * Semua fungsi di file ini dibungkus guard `function_exists()` agar:
 *   1. tidak terjadi "Cannot redeclare function" selama masa transisi;
 *   2. helper ini aman di-require berkali-kali (idempotent).
 * Penghapusan/penyeragaman definisi lokal lama di file lain dilakukan bertahap
 * di subtask lain, bukan di file ini.
 *
 * Catatan
 * -------
 * - Semua fungsi memakai prefix `ph_` agar tidak bentrok dengan fungsi lokal.
 * - File ini sengaja TIDAK me-require file lain (bebas dependensi siklik) dan
 *   TIDAK menghasilkan output apa pun saat di-require.
 * - `e()` dari includes/bootstrap.php TIDAK didefinisikan ulang di sini.
 */

if (!function_exists('ph_format_bytes')) {
    /**
     * Format ukuran byte menjadi string human-readable (B/KB/MB/GB/TB).
     *
     * Perilaku dibuat konsisten dengan mayoritas duplikat di repo, mengacu pada
     * pola loop di includes/ImageHandler.php::formatBytes(), dengan tambahan
     * dukungan satuan TB dan parameter $precision.
     *
     * Contoh:
     *   ph_format_bytes(524288000) === '500 MB'
     *   ph_format_bytes(1536)      === '1.5 KB'
     *
     * @param int $bytes     Jumlah byte.
     * @param int $precision Jumlah digit di belakang koma (default 2).
     * @return string        Contoh: "1.5 KB", "500 MB".
     */
    function ph_format_bytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

if (!function_exists('ph_json_response')) {
    /**
     * Kirim respons JSON lalu akhiri eksekusi.
     *
     * Mengirim header Content-Type: application/json, menetapkan status code,
     * men-echo payload, lalu `exit`. Bentuk payload diselaraskan dengan variasi
     * `jsonResponse()` yang ada di repo:
     *   - selalu menyertakan key `success`;
     *   - `message` ditambahkan bila tidak null;
     *   - `$extra` di-merge ke payload utama (dapat menambah/menimpa key).
     *
     * @param bool        $success Status sukses/gagal.
     * @param string|null $message Pesan opsional (di-skip bila null).
     * @param int         $code    HTTP status code (default 200).
     * @param array       $extra   Key tambahan yang di-merge ke respons.
     * @return void
     */
    function ph_json_response(bool $success, ?string $message, int $code = 200, array $extra = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');

        $response = ['success' => $success];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }

        echo json_encode($response);
        exit;
    }
}

if (!function_exists('ph_json_error')) {
    /**
     * Kirim respons JSON error lalu akhiri eksekusi.
     *
     * Alias tipis dari ph_json_response(false, $message, $code).
     *
     * @param string $message Pesan error.
     * @param int    $code    HTTP status code (default 400).
     * @return void
     */
    function ph_json_error(string $message, int $code = 400): void
    {
        ph_json_response(false, $message, $code);
    }
}

if (!function_exists('ph_e')) {
    /**
     * Escape output HTML — alias htmlspecialchars().
     *
     * Sama dengan e() di includes/bootstrap.php (ENT_QUOTES, UTF-8). Disimpan
     * terpisah dengan prefix `ph_` agar tidak mendefinisikan ulang `e()`.
     *
     * @param mixed $s Nilai yang akan di-escape.
     * @return string  String yang aman untuk konteks HTML.
     */
    function ph_e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
