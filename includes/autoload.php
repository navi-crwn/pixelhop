<?php
/**
 * PixelHop - Autoloader ringan untuk kelas di includes/
 *
 * Tujuan: file BARU di includes/ tidak perlu di-require manual di banyak
 * tempat. Cukup panggil kelasnya, dan autoloader ini akan memuat
 * includes/<NamaKelas>.php saat dibutuhkan.
 *
 * Karakteristik:
 *   - Repo ini TIDAK memakai namespace, jadi hanya nama kelas global
 *     (tanpa backslash) yang ditangani.
 *   - Hanya bertindak sebagai FALLBACK: kelas yang sudah di-require manual
 *     tidak akan disentuh (autoload tidak dipanggil untuk kelas yang sudah
 *     dideklarasikan), sehingga tidak mengganggu require_once yang ada.
 *   - Aman terhadap kelas yang tidak ada: cukup return tanpa error/warning.
 *   - Idempotent: aman di-require berulang, callback hanya didaftarkan sekali.
 *   - Tanpa output apa pun saat di-require.
 *
 * Cara pakai (opt-in) dari file baru:
 *   require_once __DIR__ . '/includes/autoload.php';
 */

if (!defined('PIXELHOP_AUTOLOAD_REGISTERED')) {
    define('PIXELHOP_AUTOLOAD_REGISTERED', true);

    spl_autoload_register(static function (string $class): void {
        // Hanya tangani kelas global tanpa namespace.
        if ($class === '' || strpos($class, '\\') !== false) {
            return;
        }

        // Nama kelas harus aman (hindari path traversal / karakter aneh).
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)) {
            return;
        }

        // Daftar file .php di includes/, di-cache agar tidak scan berulang.
        static $files = null;
        if ($files === null) {
            $files = [];
            foreach ((array) @scandir(__DIR__) as $entry) {
                $files[$entry] = true;
            }
        }

        // Cocokkan nama file secara PERSIS (case-sensitive). Ini penting di
        // filesystem case-insensitive (mis. macOS): tanpa ini is_file() akan
        // true untuk 'Bootstrap' dan autoloader bisa memuat file helper
        // non-kelas seperti bootstrap.php.
        $target = $class . '.php';
        if (!isset($files[$target])) {
            return;
        }

        require_once __DIR__ . '/' . $target;
    });
}
