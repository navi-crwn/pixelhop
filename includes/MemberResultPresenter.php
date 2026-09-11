<?php
/**
 * PixelHop - MemberResultPresenter
 *
 * Menyiapkan data untuk halaman hasil member (member/result.php) yang dipakai
 * untuk dua mode: hasil upload dan hasil tool.
 *
 * Catatan arsitektur (penting):
 *   Halaman ini sejak awal adalah halaman render-side. Payload hasil upload
 *   maupun hasil tool TIDAK diambil dari server pada request ini, melainkan
 *   dibaca oleh JavaScript dari sessionStorage ('uploadResults'/'toolResults')
 *   yang sebelumnya ditulis oleh member/upload.php dan member/<tool>.php.
 *   Karena itu, logika data server-side yang benar-benar ada di controller
 *   lama hanyalah penyiapan/normalisasi state render dari query string
 *   (type, count, tool) — dan itulah yang dipindahkan ke presenter ini.
 *
 * Kontrak payload tool (yang dibangun di sisi klien, untuk rujukan):
 *   { success, filename, data: { urls: {original,thumb,medium}, id, ... },
 *     view_url, size, width, height }
 *   Presenter ini menyediakan normalizeToolItem() sebagai padanan murni dari
 *   derivasi URL/download yang dipakai template, sehingga aturan tersebut
 *   terpusat dan dapat diuji; template tidak diubah.
 *
 * Kelas ini diletakkan di global namespace mengikuti konvensi repo dan TIDAK
 * menghasilkan output apa pun saat di-require.
 */

final class MemberResultPresenter
{
    /**
     * Whitelist tool. $tool di-refleksikan ke <title>, heading, dan nama file
     * ZIP, jadi nilai di luar daftar ini dibuang (bukan di-echo balik).
     * Daftar & perilaku dipertahankan identik dengan controller lama.
     */
    public const ALLOWED_TOOLS = ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg'];

    /**
     * Siapkan state render dari query string.
     *
     * @param array $query Biasanya $_GET.
     * @return array{
     *     type: string,
     *     count: int,
     *     tool: string,
     *     is_tool_result: bool,
     *     allowed_tools: string[],
     *     page_title: string,
     *     heading: string
     * }
     */
    public function load(array $query): array
    {
        $type = $query['type'] ?? 'upload';
        $count = (int) ($query['count'] ?? 0);

        // Whitelist tool (cegah reflected XSS lewat ?tool=).
        $tool = $query['tool'] ?? '';
        if ($tool !== '' && !in_array($tool, self::ALLOWED_TOOLS, true)) {
            $tool = '';
        }

        $isToolResult = ($type === 'tool' || !empty($tool));

        return [
            'type' => $type,
            'count' => $count,
            'tool' => $tool,
            'is_tool_result' => $isToolResult,
            'allowed_tools' => self::ALLOWED_TOOLS,
            'page_title' => $isToolResult
                ? ucfirst($tool) . ' Results - PixelHop'
                : 'Upload Complete - PixelHop',
            'heading' => $isToolResult
                ? ucfirst($tool) . ' Complete'
                : 'Upload Complete',
        ];
    }

    /**
     * Normalisasi satu item hasil tool menjadi field siap-render.
     *
     * Padanan murni dari derivasi yang dilakukan displayToolResults() di
     * template: menangani payload data-URL (string 'data:...') maupun record
     * ber-urls, lalu menghasilkan thumb/download/view/filename.
     *
     * @param mixed $item        Elemen dari array hasil tool.
     * @param int   $index       Posisi item (untuk fallback nama file).
     * @param int   $siteUrlBase Basis URL situs (untuk view_url relatif).
     * @return array{thumb_url:string, download_url:string, view_url:string, filename:string}
     */
    public function normalizeToolItem($item, int $index = 0, string $siteUrlBase = ''): array
    {
        $data = (is_array($item) && array_key_exists('data', $item)) ? $item['data'] : $item;

        $isDataUrl = is_string($data) && str_starts_with($data, 'data:');

        if ($isDataUrl) {
            $thumbUrl = $data;
            $downloadUrl = $data;
            $viewUrl = $data;
            $filename = 'image_' . ($index + 1);
        } else {
            $urls = is_array($data) ? ($data['urls'] ?? []) : [];
            $thumbUrl = $urls['thumb'] ?? $urls['medium'] ?? $urls['original'] ?? '';
            $downloadUrl = $urls['original'] ?? '';
            $id = is_array($data) ? ($data['id'] ?? null) : null;
            $viewUrl = (is_array($item) ? ($item['view_url'] ?? null) : null)
                ?? ($id ? '/' . $id : $downloadUrl);
            $filename = (is_array($item) ? ($item['filename'] ?? null) : null)
                ?? ($id ?: 'image_' . ($index + 1));
        }

        return [
            'thumb_url' => $thumbUrl,
            'download_url' => $downloadUrl,
            'view_url' => $viewUrl,
            'filename' => $filename,
        ];
    }
}
