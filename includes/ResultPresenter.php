<?php
/**
 * PixelHop - ResultPresenter
 *
 * Menyiapkan data untuk halaman hasil upload batch (result.php).
 *
 * Logika pengambilan & penyiapan data dipindahkan dari result.php (controller)
 * agar controller hanya menangani bootstrap, redirect request-level, dan
 * render. Perilaku pengambilan data dipertahankan SEBYTE-IDENTIK dengan
 * implementasi lama:
 *   - lookup per-id via ImageRepository::find() (satu-satunya akses data foto);
 *   - bangun proxy_urls dari s3_keys via /i/<key>;
 *   - fallback ke urls mentah hanya untuk record warisan tanpa s3_keys;
 *   - hitung total_images, total_size, dan earliest_expiry.
 *
 * Kelas ini diletakkan di global namespace mengikuti konvensi repo (tanpa
 * namespace), dan TIDAK menghasilkan output apa pun saat di-require.
 */

require_once __DIR__ . '/ImageRepository.php';

final class ResultPresenter
{
    /**
     * @var array Konfigurasi situs (config/s3.php), minimal blok 'site.url'.
     */
    private array $config;

    /**
     * @param array|null $config Konfigurasi siap-pakai dari controller. Bila
     *                           null, presenter memuat config/s3.php sendiri.
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require __DIR__ . '/../config/s3.php';
    }

    /**
     * Ambil & siapkan data gambar untuk dirender.
     *
     * @param string[] $imageIds Daftar id gambar (hasil explode dari ?id=).
     * @return array{
     *     images: array<string, array>,
     *     total_images: int,
     *     total_size: int,
     *     earliest_expiry: int|null
     * }
     */
    public function load(array $imageIds): array
    {
        $repo = new ImageRepository();
        $images = [];

        // Lookup per-id (bukan baca penuh) — id dipertahankan sebagai key,
        // identik dengan kode lama di result.php.
        if (!empty($imageIds)) {
            foreach ($imageIds as $id) {
                $id = trim($id);
                $image = $repo->find($id);
                if ($image !== null) {
                    $images[$id] = $image;
                }
            }
        }

        $siteUrl = $this->config['site']['url'];

        // Bangun proxy URL + view URL untuk semua gambar.
        foreach ($images as $id => &$img) {
            $s3Keys = $img['s3_keys'] ?? [];
            $proxyUrls = [];
            foreach ($s3Keys as $sizeName => $key) {
                $proxyUrls[$sizeName] = $this->getProxyUrl($key, $siteUrl);
            }
            // Fallback terakhir: hanya record lama tanpa s3_keys. URL mentah S3
            // di $img['urls'] akan 403 setelah bucket diprivatkan; ini murni
            // jaring pengaman render untuk data warisan. Upload baru selalu
            // punya s3_keys.
            $img['proxy_urls'] = !empty($proxyUrls) ? $proxyUrls : ($img['urls'] ?? []);

            // URL halaman view kanonik (dipakai template; sama dengan
            // $config['site']['url'] . '/' . $id pada kode lama).
            $img['view_url'] = $siteUrl . '/' . $id;
        }
        unset($img); // Break reference

        // Hitung total.
        $totalSize = 0;
        $totalImages = count($images);
        $earliestExpiry = null;

        foreach ($images as $img) {
            $totalSize += $img['size'] ?? 0;
            if (isset($img['delete_at']) && $img['delete_at']) {
                if ($earliestExpiry === null || $img['delete_at'] < $earliestExpiry) {
                    $earliestExpiry = $img['delete_at'];
                }
            }
        }

        return [
            'images' => $images,
            'total_images' => $totalImages,
            'total_size' => $totalSize,
            'earliest_expiry' => $earliestExpiry,
        ];
    }

    /**
     * Bangun URL proxy /i/<s3_key>.
     */
    private function getProxyUrl($s3Key, $siteUrl): string
    {
        return $siteUrl . '/i/' . $s3Key;
    }
}
