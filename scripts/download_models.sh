#!/usr/bin/env bash
#
# PixelHop - Unduh model AI SEKALI ke cache lokal
# ==============================================
#
# Tujuan:
#   Mengunduh semua model AI (BiRefNet, Real-ESRGAN, LaMa) ke cache lokal
#   SEKALI saja, agar tool AI tidak perlu mengunduh dari internet saat
#   runtime pertama (lambat dan bisa terblokir).
#
# Sifat:
#   - Idempotent: aman dijalankan berulang. Model yang sudah ada (dan
#     ukurannya wajar) akan DILEWATI, tidak diunduh ulang.
#   - Gagal -> berhenti dengan pesan jelas dan exit code non-zero.
#
# Cara pakai (biasanya sekali saat setup server, sebagai root via SSH):
#   bash scripts/download_models.sh
#
# Override lokasi cache bila perlu (opsional, lewat environment variable):
#   U2NET_HOME=/path/.u2net \
#   REALESRGAN_MODEL=/path/RealESRGAN_x2plus.onnx \
#   LAMA_MODEL=/path/lama.onnx \
#   bash scripts/download_models.sh
#   (bisa juga: REALESRGAN_MODEL_DIR=/dir dan LAMA_MODEL_DIR=/dir)
#
# Override SUMBER unduhan (mirror operator sendiri), opsional:
#   REALESRGAN_URL=https://mirror.internal/real_esrgan_x2.onnx \
#   REALESRGAN_SHA256=<sha256-hex> \
#   LAMA_URL=https://mirror.internal/lama.onnx \
#   LAMA_SHA256=<sha256-hex> \
#   bash scripts/download_models.sh
#
#   - Bila REALESRGAN_URL / LAMA_URL di-override, checksum default (hash
#     artefak kanonik) TETAP dipakai. Unduhan akan GAGAL bila mirror tidak
#     identik bit-for-bit. Set REALESRGAN_SHA256 / LAMA_SHA256 ke hash
#     mirror-mu bila isinya berbeda.
#   - Checksum TIDAK PERNAH dilewati secara default (lihat bagian 2 helper).
#   - BiRefNet memakai rilis resmi rembg dan tidak punya override URL.
#
# ============================================================
# PENTING: RUNTIME ENVIRONMENT VARIABLES
# ============================================================
# Jika kamu menggunakan override path (bukan default), variabel yang
# SAMA PERSIS harus juga di-export ke environment saat menjalankan
# aplikasi (PHP/Apache/systemd), karena engine Python membacanya di
# runtime:
#
#   U2NET_HOME        -> dibaca oleh python/rembg_engine.py
#                        Default: /var/www/.u2net
#                        Engine menggunakan os.environ.setdefault,
#                        sehingga nilai yang kamu set di sini akan
#                        dihormati selama variabel di-export ke proses.
#
#   REALESRGAN_MODEL  -> path PENUH ke file .onnx
#                        dibaca oleh python/upscale_engine.py
#                        Default: /var/www/.cache/realesrgan/RealESRGAN_x2plus.onnx
#                        HARUS berupa path penuh (bukan direktori).
#
#   LAMA_MODEL        -> path PENUH ke file .onnx
#                        dibaca oleh python/erase_engine.py
#                        Default: /var/www/.cache/lama/lama.onnx
#                        HARUS berupa path penuh (bukan direktori).
#
# Contoh di .env / systemd / php-fpm pool.d:
#   env[U2NET_HOME]       = /data/models/.u2net
#   env[REALESRGAN_MODEL] = /data/models/realesrgan/RealESRGAN_x2plus.onnx
#   env[LAMA_MODEL]       = /data/models/lama/lama.onnx
#
# Jika hanya menggunakan path default (/var/www/...) tidak perlu set
# variabel apapun di runtime — engine akan menggunakan default yang sama.
# ============================================================
#
# Catatan path (HARUS cocok dengan yang dibaca engine di repo ini):
#   - python/rembg_engine.py menggunakan os.environ.setdefault sehingga
#     menghormati U2NET_HOME yang sudah di-set operator. Default fallback
#     /var/www/.u2net. rembg mencari model di $U2NET_HOME/<nama>.onnx
#     (layout lama) ATAU $U2NET_HOME/models/<nama>/<nama>.onnx (layout baru).
#     Script ini menaruh file di layout lama: $U2NET_HOME/<nama>.onnx.
#   - python/upscale_engine.py membaca $REALESRGAN_MODEL (path penuh) ATAU
#     $HOME/.cache/realesrgan/RealESRGAN_x2plus.onnx (HOME=/var/www).
#   - python/erase_engine.py membaca $LAMA_MODEL (path penuh) ATAU
#     $HOME/.cache/lama/lama.onnx (HOME=/var/www).

set -euo pipefail
umask 022

# ---------------------------------------------------------------------------
# 1. Lokasi cache (bisa dioverride lewat environment variable)
#
# Default di bawah SAMA PERSIS dengan yang dibaca engine saat runtime:
#   rembg_engine.py  -> $U2NET_HOME               (default /var/www/.u2net)
#   upscale_engine.py-> $REALESRGAN_MODEL         (default /var/www/.cache/realesrgan/RealESRGAN_x2plus.onnx)
#   erase_engine.py  -> $LAMA_MODEL               (default /var/www/.cache/lama/lama.onnx)
# ---------------------------------------------------------------------------
REMBG_CACHE="${U2NET_HOME:-/var/www/.u2net}"

# Real-ESRGAN: utamakan REALESRGAN_MODEL (path penuh, persis yang dibaca engine),
# lalu REALESRGAN_MODEL_DIR (direktori), lalu default ~/.cache/realesrgan.
REALESRGAN_CACHE="${REALESRGAN_MODEL_DIR:-/var/www/.cache/realesrgan}"
REALESRGAN_DEST="${REALESRGAN_MODEL:-$REALESRGAN_CACHE/RealESRGAN_x2plus.onnx}"

# LaMa: utamakan LAMA_MODEL (path penuh, persis yang dibaca engine),
# lalu LAMA_MODEL_DIR (direktori), lalu default ~/.cache/lama.
LAMA_CACHE="${LAMA_MODEL_DIR:-/var/www/.cache/lama}"
LAMA_DEST="${LAMA_MODEL:-$LAMA_CACHE/lama.onnx}"

# ---------------------------------------------------------------------------
# 1b. Sumber unduhan + checksum terpaku (pinned)
#
# PROVENANCE / ASAL-USUL ARTEFAK — baca sebelum mengubah nilai default:
#
#   BiRefNet (birefnet-general / birefnet-portrait)
#     Sumber RESMI: rilis resmi library rembg (danielgatis/rembg), tag
#     v0.0.0. Ini artefak resmi yang direkomendasikan rembg untuk kedua
#     session BiRefNet. Checksum = md5 resmi dari halaman rilis rembg.
#     Tidak disediakan override URL untuk kedua model ini.
#
#   Real-ESRGAN x2 (real_esrgan_x2.onnx -> RealESRGAN_x2plus.onnx)
#     Bukan rilis resmi xinntao/Real-ESRGAN: repo itu HANYA menerbitkan
#     bobot .pth dan paket ncnn-vulkan (tidak ada ONNX resmi; sudah
#     diperiksa pada semua tag rilis). Artefak di bawah adalah KONVERSI
#     ONNX dari bobot kanonik RealESRGAN_x2plus.pth milik xinntao
#     (aset rilis xinntao v0.2.1), di-host di mirror komunitas
#     SceneWorks/real-esrgan-onnx di Hugging Face. SHA256 dipaku
#     (pinned) ke artefak konversi tersebut.
#     Lisensi bobot asal: BSD-3-Clause (xinntao/Real-ESRGAN).
#     Untuk memakai mirror operator sendiri, set REALESRGAN_URL (+
#     REALESRGAN_SHA256 bila isinya berbeda dari hash terpaku).
#
#   LaMa (lama.onnx)
#     Bukan rilis resmi advimman/lama (repo tidak menerbitkan ONNX).
#     Artefak di bawah adalah KONVERSI ONNX dari bobot kanonik LaMa
#     (big-lama), di-host di mirror komunitas Carve/LaMa-ONNX di
#     Hugging Face. SHA256 dipaku ke artefak konversi tersebut.
#     Lisensi bobot asal: Apache-2.0 (advimman/lama).
#     Untuk memakai mirror operator sendiri, set LAMA_URL (+
#     LAMA_SHA256 bila isinya berbeda dari hash terpaku).
#
# CATATAN KEAMANAN: checksum TIDAK PERNAH dilewati secara default. Bila
# override URL diberikan tanpa override checksum, verifikasi tetap
# memakai hash terpaku di bawah. Ganti hash hanya bila kamu benar-benar
# memverifikasi isi mirror-mu; jangan melemahkan verifikasi default.
# ---------------------------------------------------------------------------
REALESRGAN_URL="${REALESRGAN_URL:-https://huggingface.co/SceneWorks/real-esrgan-onnx/resolve/main/real_esrgan_x2.onnx}"
REALESRGAN_SHA256="${REALESRGAN_SHA256:-7115ba92e8a1bfa63d68558ef006ef3d91273a068d321b1439f8bb1c9179002c}"

LAMA_URL="${LAMA_URL:-https://huggingface.co/Carve/LaMa-ONNX/resolve/main/lama.onnx}"
LAMA_SHA256="${LAMA_SHA256:-351e481e287f345b7fbfd026068cfb9ec0c7f24b440e6501458ebe54a833d1a1}"

# ---------------------------------------------------------------------------
# 2. Helper
# ---------------------------------------------------------------------------

# Ukuran file dalam byte (portabel: Linux `stat -c`, macOS `stat -f`).
file_size() {
    local file="$1"
    if stat -c%s "$file" >/dev/null 2>&1; then
        stat -c%s "$file"
    else
        stat -f%z "$file"
    fi
}

# Hitung checksum. Argumen: <file> <algo> (sha256|md5).
# Mengembalikan hex lowercase, atau string kosong bila tool tidak tersedia.
calc_checksum() {
    local file="$1" algo="$2" out=""

    case "$algo" in
        sha256)
            if command -v sha256sum >/dev/null 2>&1; then
                out="$(sha256sum "$file" | awk '{print $1}')"
            elif command -v shasum >/dev/null 2>&1; then
                out="$(shasum -a 256 "$file" | awk '{print $1}')"
            fi
            ;;
        md5)
            if command -v md5sum >/dev/null 2>&1; then
                out="$(md5sum "$file" | awk '{print $1}')"
            elif command -v md5 >/dev/null 2>&1; then
                out="$(md5 -q "$file")"
            fi
            ;;
        *)
            echo "PERINGATAN: algoritma checksum tidak dikenal: $algo" >&2
            return 1
            ;;
    esac

    printf '%s' "$out"
}

# Unduh url -> out. Pakai curl, fallback wget. Return non-zero bila gagal.
fetch() {
    local url="$1" out="$2"

    if command -v curl >/dev/null 2>&1; then
        curl -fL --retry 3 --retry-delay 2 --connect-timeout 30 -C - -o "$out" "$url"
    elif command -v wget >/dev/null 2>&1; then
        wget --tries=3 --timeout=30 -c -O "$out" "$url"
    else
        echo "  GAGAL: tidak ada 'curl' maupun 'wget' di server ini." >&2
        return 1
    fi
}

# download <url> <dest> <min_size_byte> [<checksum_spec>]
#   checksum_spec format "algo:hex", mis. "md5:7a35..." atau "sha256:7115...".
#   Kosongkan bila ingin verifikasi ukuran saja.
#
# Perilaku:
#   - Bila file ada dan ukurannya >= min_size  -> LEWATI (idempotent).
#   - Bila belum ada / terlalu kecil -> unduh ke <dest>.part, verifikasi
#     ukuran + checksum, lalu pindahkan ke <dest> secara atomik.
#   - Bila verifikasi gagal -> hapus file parsial, exit non-zero.
download() {
    local url="$1" dest="$2" min_size="$3" checksum_spec="${4:-}"
    local part="${dest}.part"
    local algo="" expected="" actual="" size=0

    if [[ -f "$dest" ]]; then
        size="$(file_size "$dest")"
        if [[ "$size" -ge "$min_size" ]]; then
            echo "  [LEWATI] Sudah ada: $(basename "$dest") (${size} byte). Tidak diunduh ulang."
            FOUND+=("$dest|$size")
            return 0
        fi
        echo "  [ULANG]  File ada tapi ukurannya kurang (${size} < ${min_size} byte). Unduh ulang."
        rm -f "$dest"
    fi

    echo "  [UNDUH]  $(basename "$dest")"
    echo "           dari: $url"

    rm -f "$part"
    if ! fetch "$url" "$part"; then
        echo "  GAGAL: unduhan gagal untuk $(basename "$dest"). Periksa koneksi internet server." >&2
        rm -f "$part"
        exit 1
    fi

    # Verifikasi ukuran.
    size="$(file_size "$part")"
    if [[ "$size" -lt "$min_size" ]]; then
        echo "  GAGAL: ukuran file tidak sesuai setelah unduh (${size} < ${min_size} byte)." >&2
        rm -f "$part"
        exit 1
    fi

    # Verifikasi checksum (bila dispesifikasikan).
    if [[ -n "$checksum_spec" ]]; then
        algo="${checksum_spec%%:*}"
        expected="${checksum_spec#*:}"
        actual="$(calc_checksum "$part" "$algo" || true)"

        if [[ -z "$actual" ]]; then
            echo "  PERINGATAN: tool checksum ($algo) tidak tersedia; verifikasi checksum dilewati." >&2
        elif [[ "$actual" != "$expected" ]]; then
            echo "  GAGAL: checksum tidak cocok untuk $(basename "$dest")." >&2
            echo "         diharapkan: $expected" >&2
            echo "         hasil     : $actual" >&2
            rm -f "$part"
            exit 1
        else
            echo "           checksum $algo OK."
        fi
    fi

    mv -f "$part" "$dest"
    size="$(file_size "$dest")"
    chmod 644 "$dest" 2>/dev/null || true
    echo "  [OK]     Tersimpan: $dest (${size} byte)."
    FOUND+=("$dest|$size")
}

# ---------------------------------------------------------------------------
# 3. Mulai
# ---------------------------------------------------------------------------
FOUND=()

echo "============================================================"
echo " PixelHop - Unduh Model AI ke Cache Lokal"
echo "============================================================"
echo ""
echo "Model akan disimpan di:"
echo "  - rembg / BiRefNet : $REMBG_CACHE"
echo "  - Real-ESRGAN      : $REALESRGAN_DEST"
echo "  - LaMa             : $LAMA_DEST"
echo ""

mkdir -p "$REMBG_CACHE" "$(dirname "$REALESRGAN_DEST")" "$(dirname "$LAMA_DEST")"
chmod 755 "$REMBG_CACHE" "$(dirname "$REALESRGAN_DEST")" "$(dirname "$LAMA_DEST")" 2>/dev/null || true

# ---------------------------------------------------------------------------
# 4. Model BiRefNet (untuk rembg / hapus latar belakang)
#    Nama file HARUS sesuai yang dicari rembg: birefnet-general.onnx,
#    birefnet-portrait.onnx (rembg menurunkan nama file dari nama session).
# ---------------------------------------------------------------------------
echo "--- BiRefNet (rembg) ---"

download \
    "https://github.com/danielgatis/rembg/releases/download/v0.0.0/BiRefNet-general-epoch_244.onnx" \
    "$REMBG_CACHE/birefnet-general.onnx" \
    972666916 \
    "md5:7a35a0141cbbc80de11d9c9a28f52697"

download \
    "https://github.com/danielgatis/rembg/releases/download/v0.0.0/BiRefNet-portrait-epoch_150.onnx" \
    "$REMBG_CACHE/birefnet-portrait.onnx" \
    972666916 \
    "md5:c3a64a6abf20250d090cd055f12a3b67"

echo ""

# ---------------------------------------------------------------------------
# 5. Model Real-ESRGAN x2 (upscale 2x)
#    ONNX dikonversi dari bobot kanonik RealESRGAN_x2plus.pth milik
#    xinntao/Real-ESRGAN (BSD-3-Clause). xinntao TIDAK menerbitkan ONNX
#    resmi; artefak di-host di mirror komunitas SceneWorks/real-esrgan-onnx.
#    SHA256 dipaku (pinned). URL + checksum dapat dioverride operator
#    lewat REALESRGAN_URL / REALESRGAN_SHA256 (lihat header & bagian 1b).
#    Disimpan dengan nama yang dibaca engine: RealESRGAN_x2plus.onnx.
# ---------------------------------------------------------------------------
echo "--- Real-ESRGAN x2 ---"

download \
    "$REALESRGAN_URL" \
    "$REALESRGAN_DEST" \
    67073434 \
    "sha256:$REALESRGAN_SHA256"

echo ""

# ---------------------------------------------------------------------------
# 6. Model LaMa (inpainting / hapus objek)
#    ONNX dikonversi dari bobot kanonik LaMa (big-lama) milik advimman/lama
#    (Apache-2.0). advimman TIDAK menerbitkan ONNX resmi; artefak di-host di
#    mirror komunitas Carve/LaMa-ONNX. SHA256 dipaku (pinned). URL + checksum
#    dapat dioverride operator lewat LAMA_URL / LAMA_SHA256 (lihat header &
#    bagian 1b). Disimpan sebagai lama.onnx.
# ---------------------------------------------------------------------------
echo "--- LaMa (inpainting) ---"

download \
    "$LAMA_URL" \
    "$LAMA_DEST" \
    207479252 \
    "sha256:$LAMA_SHA256"

echo ""

# ---------------------------------------------------------------------------
# 7. Ringkasan
# ---------------------------------------------------------------------------
echo "============================================================"
echo " Ringkasan Model"
echo "============================================================"
for entry in "${FOUND[@]}"; do
    path="${entry%%|*}"
    size="${entry##*|}"
    printf '  OK  %-45s %s byte\n' "$(basename "$path")" "$size"
done
echo ""
echo "Selesai. Semua model tersedia di cache lokal."
echo "Tool AI (BiRefNet, Real-ESRGAN, LaMa) tidak perlu mengunduh"
echo "dari internet saat dipakai nanti."
echo ""
echo "============================================================"
echo " PENTING: Variabel Runtime (jika pakai override path)"
echo "============================================================"
echo ""
echo "Jika kamu mengunduh ke path non-default, pastikan variabel"
echo "berikut juga di-export ke environment runtime (PHP-FPM, Apache,"
echo "systemd, .env):"
echo ""
echo "  U2NET_HOME        = $REMBG_CACHE"
echo "  REALESRGAN_MODEL  = $REALESRGAN_DEST"
echo "  LAMA_MODEL        = $LAMA_DEST"
echo ""
echo "Lihat header script ini untuk contoh konfigurasi."
echo ""
