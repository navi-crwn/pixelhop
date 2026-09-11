<?php
/**
 * PixelHop - Tool Registry
 *
 * Satu sumber konfigurasi untuk semua image tool yang ditampilkan di tools.php.
 * Sebelumnya definisi tool tersebar: status enabled dibaca langsung dari
 * Gatekeeper, markup kartu/modal di-inline di tools.php, dan endpoint API
 * tersirat di blok JS. Registry ini mengumpulkan metadata tool sebagai data
 * (bukan markup) sehingga tools.php cukup me-loop dan meng-include partial.
 *
 * Yang TIDAK dilakukan registry ini:
 *   - tidak merender HTML apa pun;
 *   - tidak membaca DB (status enabled tetap dibaca tools.php via Gatekeeper);
 *   - tidak menyentuh JavaScript (masih inline di tools.php pada iterasi ini).
 *
 * Aman di-require berulang (idempotent): hanya mendeklarasikan kelas.
 */

if (!class_exists('ToolRegistry')) {
    /**
     * Registry statis tool gambar.
     */
    class ToolRegistry
    {
        /**
         * Default tool yang aktif bila setting belum ada di site_settings.
         * Menjaga perilaku lama (Gatekeeper::getSetting(..., 1)).
         */
        public const DEFAULT_ENABLED = 1;

        /**
         * Definisi seluruh tool, terurut sesuai tampilan di tools.php.
         *
         * Field per tool:
         *   id             slug stabil; dipakai untuk nama file partial,
         *                  key $toolStatus, dan modal id (modal-<id>).
         *   name           judul tampilan pada kartu & modal.
         *   icon           nama ikon Lucide (atribut data-lucide).
         *   description    deskripsi singkat pada kartu.
         *   endpoint       path API yang dipanggil tool.
         *   account        akun minimum yang dibutuhkan: 'guest' | 'user'.
         *   limits         batas harian free/premium, atau null bila tidak
         *                  dibatasi per-tool (tool ringan). Angka diambil dari
         *                  default Gatekeeper: daily_ocr_limit_* /
         *                  daily_removebg_limit_*.
         *   enabled_key    key site_settings untuk status aktif/nonaktif.
         *   type           kategori proses.
         *
         * @return array<string, array<string, mixed>>
         */
        public static function all(): array
        {
            return [
                'compress' => [
                    'id' => 'compress',
                    'name' => 'Compress',
                    'icon' => 'file-minus',
                    'description' => 'Reduce file size without losing quality. Supports JPEG, PNG, WebP.',
                    'endpoint' => '/api/compress.php',
                    'account' => 'guest',
                    'limits' => null,
                    'enabled_key' => 'tool_compress_enabled',
                    'type' => 'compress',
                ],
                'resize' => [
                    'id' => 'resize',
                    'name' => 'Resize',
                    'icon' => 'scaling',
                    'description' => 'Change dimensions while maintaining aspect ratio or exact sizes.',
                    'endpoint' => '/api/resize.php',
                    'account' => 'guest',
                    'limits' => null,
                    'enabled_key' => 'tool_resize_enabled',
                    'type' => 'resize',
                ],
                'crop' => [
                    'id' => 'crop',
                    'name' => 'Crop',
                    'icon' => 'crop',
                    'description' => 'Crop to standard ratios: 1:1, 4:3, 16:9, or custom dimensions.',
                    'endpoint' => '/api/crop.php',
                    'account' => 'guest',
                    'limits' => null,
                    'enabled_key' => 'tool_crop_enabled',
                    'type' => 'crop',
                ],
                'convert' => [
                    'id' => 'convert',
                    'name' => 'Convert',
                    'icon' => 'repeat',
                    'description' => 'Convert between formats: JPEG, PNG, WebP, GIF, BMP.',
                    'endpoint' => '/api/convert.php',
                    'account' => 'guest',
                    'limits' => null,
                    'enabled_key' => 'tool_convert_enabled',
                    'type' => 'convert',
                ],
                'ocr' => [
                    'id' => 'ocr',
                    'name' => 'OCR',
                    'icon' => 'scan-text',
                    'description' => 'Extract text from images. Supports multiple languages.',
                    'endpoint' => '/api/ocr.php',
                    'account' => 'user',
                    'limits' => ['free' => 5, 'premium' => 50],
                    'enabled_key' => 'tool_ocr_enabled',
                    'type' => 'ocr',
                ],
                'rembg' => [
                    'id' => 'rembg',
                    'name' => 'Remove Background',
                    'icon' => 'eraser',
                    'description' => 'AI-powered background removal in seconds. Perfect for product photos.',
                    'endpoint' => '/api/rembg.php',
                    'account' => 'user',
                    'limits' => ['free' => 3, 'premium' => 30],
                    'enabled_key' => 'tool_rembg_enabled',
                    'type' => 'rembg',
                ],
            ];
        }

        /**
         * Ambil satu definisi tool berdasarkan id, atau null bila tidak ada.
         *
         * @return array<string, mixed>|null
         */
        public static function get(string $id): ?array
        {
            return self::all()[$id] ?? null;
        }

        /**
         * Daftar id tool, terurut seperti tampilan.
         *
         * @return list<string>
         */
        public static function ids(): array
        {
            return array_keys(self::all());
        }

        /**
         * Path partial markup untuk sebuah tool, relatif terhadap root repo.
         */
        public static function partial(string $id): string
        {
            return __DIR__ . '/../templates/tools/' . $id . '.php';
        }
    }
}

if (!function_exists('ph_tool_registry')) {
    /**
     * Shorthand fungsional untuk ToolRegistry::all().
     *
     * @return array<string, array<string, mixed>>
     */
    function ph_tool_registry(): array
    {
        return ToolRegistry::all();
    }
}
