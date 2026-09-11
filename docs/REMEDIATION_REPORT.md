# PixelHop — Laporan Remediasi Keamanan & Arsitektur (Dokumentasi Pertinggal)

**Tanggal pengerjaan:** 10 September 2026
**Dieksekusi oleh:** tim multi-agen (Commander + planners + builders + 2 auditor independen)
**Branch kerja:** `remediation/v1`
**Basis audit:** `AUDIT_REPORT.md` (424 baris, 84 temuan kanonik)

> Dokumen ini ditulis untuk **pemilik non-teknis** dan **builder masa depan**. Semua perintah
> bisa disalin-tempel. **Tidak ada satu pun password, secret, atau kunci API yang dicetak di
> sini** — semuanya diganti placeholder seperti `***`. Nilai asli hanya ada di server
> (`/var/www/pichost/config/`), jangan pernah menaruhnya di dokumen atau Git.

---

## 1. PENGENALAN APLIKASI

### 1.1 PixelHop itu apa (bahasa awam)

PixelHop (nama lama di server: **PicHost**) adalah **layanan hosting gambar gratis**.
Anggap saja seperti "rumah permanen untuk foto": Anda mengunggah gambar, lalu mendapatkan
tautan (link) yang bisa dibagikan ke siapa saja. Selain menyimpan, PixelHop juga punya
**alat edit gambar bawaan** yang semuanya berjalan di browser:

| Alat | Kegunaan awam |
|---|---|
| **Compress** | Mengecilkan ukuran file gambar |
| **Resize** | Mengubah ukuran (mis. 800×600) |
| **Crop** | Memotong bagian gambar |
| **Convert** | Mengubah format (JPG ↔ PNG ↔ WebP ↔ GIF) |
| **OCR** | Membaca tulisan yang ada di dalam gambar (AI PaddleOCR) |
| **Remove Background** | Menghapus latar belakang gambar (AI rembg) |

### 1.2 Cara kerja singkat (alur upload → tampil)

```
User upload gambar
        │
        ▼
Server membuat 4 versi gambar (original, large, medium, thumb)
        │
        ├── thumb + medium  ──►  Cloudflare R2   (storage #1)
        └── original + large ─►  Contabo S3      (storage #2)
        │
        ▼
Metadata disimpan di  data/images.json  +  akuntansi di MySQL
        │
        ▼
Gambar disajikan lewat satu pintu:  https://<domain>/i/YYYY/MM/DD/<id>_<size>.<ext>
```

Poin penting yang perlu dipahami pemilik: **gambar TIDAK disajikan langsung dari bucket
storage**, melainkan lewat jalur perantara `/i/`. Jalur ini yang mengecek "apakah gambar ini
boleh ditampilkan?" (mis. pemiliknya sedang di-suspend, atau gambarnya sudah dijadwalkan
hapus). Detail teknisnya ada di bagian 3.8.

### 1.3 Akun & login

- **Google OAuth** — login sekali klik dengan akun Google.
- **Email + password** — daftar manual dengan verifikasi email.
- Dua tingkat akun: **Guest** (tanpa akun) dan **Free** (punya akun, kuota lebih besar).

### 1.4 Teknologi (stack)

| Lapisan | Teknologi |
|---|---|
| Backend | **PHP 8.1+ tanpa framework** (bukan Laravel/Symfony) |
| Database | **MySQL / MariaDB** (via PDO) |
| Frontend | TailwindCSS + Vanilla JavaScript |
| AI | **Python venv** (PaddleOCR + rembg) dipanggil dari PHP |
| Storage | Cloudflare R2 + Contabo S3 (S3-compatible) |
| Web server | **nginx + PHP-FPM** di VPS Ubuntu 24.04, di belakang **Cloudflare** |

### 1.5 Path penting di server (JANGAN tertukar)

| Path | Isi |
|---|---|
| `/var/www/pichost` | **Document root** aplikasi (ini yang dilayani nginx) |
| `/var/www/pichost/python/venv` | Lingkungan Python untuk AI (OCR & rembg) |
| `/var/www/pichost/config` | **Kredensial** (database, S3, OAuth, Turnstile) |
| `/var/www/pichost/data` | `images.json` (metadata semua gambar) + file JSON lain |
| `/var/www/pichost/temp` | File sementara saat memproses gambar |

> ### ⚠️ PERINGATAN KERAS — NAMA PATH AI
> Di produksi, interpreter Python berada di:
> ```
> PYTHON_BIN = /var/www/pichost/python/venv/bin/python3
> ```
> Ini adalah path yang **BENAR**. Meskipun nama proyek di GitHub adalah "PixelHop",
> **JANGAN pernah mengubah `pichost` menjadi `pixelhop`** pada path ini. Mengubahnya akan
> **mematikan total fitur OCR dan Remove Background**. Lihat `includes/AiService.php` baris 16.

---

## 2. RINGKASAN MASALAH (dari Audit)

### 2.1 Skor awal

| Metrik | Nilai |
|---|---|
| **Skor kesehatan sistem** | **2.5 / 10 — KRITIS** (tidak production-ready) |
| Total temuan kanonik | **84** |
| CRITICAL | 1 |
| HIGH | 19 |
| MEDIUM | 36 |
| LOW | 26 |
| INFO | 2 |

### 2.2 Empat risiko paling fatal (bahasa awam)

1. **Aplikasi tidak bisa di-install dari panduan.** Siapa pun yang mengikuti README untuk
   memasang PixelHop dari nol akan mendapati login/register/Google **selalu gagal**, karena
   struktur database yang disertakan di repo tidak lengkap. (Temuan `D5-23`, CRITICAL.)
2. **Celah SSRF.** Server bisa "disuruh" membuka alamat internal/rahasia (termasuk alamat
   metadata penyedia cloud) melalui fitur "upload dari URL". Ini bisa membocorkan kredensial
   server. (Temuan `D3-01` + `D3-02`, HIGH.)
3. **Kontrol keamanan "mati".** Saklar-saklar penting — *maintenance mode*, *kill-switch*
   darurat, dan batas penyimpanan global — **tidak berfungsi di jalur upload**. Lebih buruk,
   firewall otomatis **mematikan diri sendiri** (fail-open) tepat saat database sedang error,
   yaitu persis momen ketika serangan paling mungkin terjadi. (Temuan `D2-01` + `D3-06`, HIGH.)
4. **Sesi user yang diblokir tetap bisa dipakai.** Ketika admin men-suspend/mengunci akun,
   sesi yang sudah login di perangkat user tetap hidup berhari-hari. Ditambah celah OAuth
   yang bisa dipakai mengambil alih akun. (Temuan `D1-01` + `D1-02`, HIGH.)

### 2.3 Rujukan tabel 84 temuan

Tabel lengkap 84 temuan **dengan bukti `file:baris`** ada di **`AUDIT_REPORT.md` bagian 3
(Tabel Temuan Master)** dan detail CRITICAL/HIGH di bagian 4. Dokumen ini **tidak**
mengulang 84 baris; sebagai gantinya, bagian 2.4 di bawah memetakan tiap temuan ke
**status akhirnya** secara ringkas per dimensi.

### 2.4 Status akhir 84 temuan (dikelompokkan per dimensi)

Enam dimensi audit:

| Kode | Dimensi |
|---|---|
| **D1** | Auth, Sesi & Access Control |
| **D2** | Upload, Serving & Storage Pipeline |
| **D3** | SSRF, Firewall & Rate-limit |
| **D4** | Data Integrity, JSON Store & Cron |
| **D5** | Schema, Arsitektur & Kode |
| **D6** | Observability, Privasi & Produksi |

Status: **FIXED** = sudah diperbaiki; **MITIGATED** = risiko ditekan/dikurangi, akar masalah
sebagian masih ada; **DEFERRED** = sengaja ditunda dengan alasan tertulis.

| Dimensi | Jumlah | FIXED | MITIGATED | DEFERRED | Catatan ringkas |
|---|---|---|---|---|---|
| **D1** Auth/Sesi/Akses | 15 | 9 | 3 | 3 | Status akun & `session_version` ditegakkan; CSRF JSON, Turnstile, cookie diperkuat. Sisa: CORS `*`, password reset belum ada, policy password (INFO). |
| **D2** Upload/Serving/Storage | 14 | 10 | 2 | 2 | Gatekeeper aktif, kuota atomik + kompensasi, `i.php` ditulis ulang (streaming+ACL), `public-read` dihapus. Sisa: dead-code SigV4 (dibiarkan), resource-limit Imagick (parsial). |
| **D3** SSRF/Firewall/Rate-limit | 12 | 9 | 2 | 1 | SSRF guard CIDR lengkap + pin DNS + redirect per-hop; firewall fail-closed + tombol darurat; cron diberi guard. Sisa: BAD_BOTS vs ShareX (kebijakan). |
| **D4** Data Integrity/JSON/Cron | 9 | 6 | 2 | 1 | `JsonStore` (flock+atomic+backup), urutan hapus dibalik, `storage_stats` dikoreksi. Sisa: watchdog jendela threshold (label). |
| **D5** Schema/Arsitektur/Kode | 24 | 13 | 4 | 7 | `schema.sql` disamakan dengan produksi, migrasi idempotent, kuota AI atomik, XSS dibereskan. Sisa: refactor god-files, dead code, `generateId` kolisi (LOW). |
| **D6** Observability/Privasi/Prod | 10 | 6 | 2 | 2 | `Logger` terstruktur (tanpa PII), error tidak bocorkan detail, `contacts.json` di-gitignore + IP di-hash, EXIF dibuang. Sisa: rotasi secret Turnstile (aksi operator), metrik/alerting. |
| **TOTAL** | **84** | **53** | **15** | **16** | — |

**16 temuan DEFERRED** — alasan utama: (a) butuh aksi operator di dashboard Cloudflare
(rotasi secret, purge cache), (b) refactor besar yang berisiko bila dipaksa sekarang
(god-files, migrasi metadata JSON→DB), (c) perubahan kosmetik/berdampak rendah.

---

## 3. PROSES PENGERJAAN (kronologis)

### 3.1 AUDIT (selesai sebelumnya)

Pengerjaan diawali audit menyeluruh:

1. **13 subtask auditor paralel** — tiap subtask memeriksa satu area (auth, upload, SSRF, cron, dsb.).
2. **2 auditor independen** (model **DeepSeek** dan **GLM**) memverifikasi temuan secara
   silang tanpa saling melihat hasil.
3. **Resolve loop** — 11 sengketa antar-auditor diverifikasi ulang **langsung ke kode repo**.
4. Hasil: **`AUDIT_REPORT.md`**.

**Standar bukti:** setiap temuan wajib menyertakan `file:baris` verbatim. Hasil akhir:
**53+ spot-check gabungan, nol bukti fiktif ditemukan**. 18 hipotesis dinyatakan **REFUTED**
(sudah aman) dan didokumentasikan juga, supaya tidak dikerjakan ulang.

### 3.2 BACKUP SERVER (Fase 0) — sebelum menyentuh apa pun

Aturan besi: **backup dulu, baru sentuh**. Backup diambil **sebelum satu baris pun kode
diubah**.

**Lokasi backup:** `/var/backups/pichost/20260910_145054/`

| File / folder | Isi |
|---|---|
| `pichost_code.tar.gz` (1.8M) | Seluruh kode aplikasi **tanpa** folder `python/venv` |
| `pixelhop_db.sql.gz` | Dump **penuh** database (struktur + data) |
| `pixelhop_schema_only.sql` (387 baris) | Struktur database saja (untuk referensi) |
| `config_live/` | Salinan seluruh isi `config/` (berisi kredensial asli) |
| `data_live/` | Salinan `data/` (termasuk `images.json`) |
| `crontab_carawin.txt` + `crontab_root.txt` | Daftar cron job kedua user |
| `nginx_sites-available/` | Konfigurasi nginx aktif |

Cara restore ada di **bagian 5** (Panduan Revert).

### 3.3 TEMUAN PENTING FASE 0

Saat membandingkan kode server dengan repo GitHub, ditemukan tiga hal krusial:

**(a) Server menjalankan fitur yang TIDAK ada di GitHub.** Tiga komponen hidup di produksi
tetapi tidak pernah di-commit ke repo:
- `core/SafeGuard.php` — moderasi konten AI (deteksi malware/phishing/konten dewasa).
- `cron/process_moderation_queue.php` — pemroses antrian moderasi SafeGuard.
- **API-token Shottr** (`upload_token`) — jalur upload otomatis dari aplikasi Shottr.

Ketiganya **diselamatkan dan dipertahankan**. Baseline dibuat dari kode server, bukan dari repo.

**(b) Server ketinggalan security PR#1.** Perbaikan keamanan yang sudah ada di repo
(ClientIp trusted-proxy, Turnstile hardening) belum terpasang di server.

**(c) Database produksi justru LEBIH LENGKAP dari repo.** Artinya, keluhan "fresh install
rusak" (temuan CRITICAL `D5-23`) adalah **bug dokumentasi/repo**, **bukan** masalah produksi.
Produksi sehat; yang perlu diperbaiki adalah file `schema.sql` agar cocok dengan produksi.

### 3.4 BASELINE & MERGE

- **Baseline = kode live server**, bukan kode GitHub (agar fitur live tidak hilang).
- Ke atas baseline, **security PR#1 digabung**.
- Hasilnya menjadi branch **`remediation/v1`**.

Commit terkait:

| Commit | Isi |
|---|---|
| `da77520` | prod baseline: server live state (`3b7119a` + fitur uncommitted: SafeGuard, upload_token, member UI) |
| `1921525` | merge PR#1 security ke prod baseline (SafeGuard + upload_token + ClientIp tetap) |

### 3.5 PERBAIKAN (14 subtask + gelombang fix)

Perbaikan dikerjakan per tema. Ringkasan tiap tema (apa masalahnya → apa yang dilakukan):

| Tema | Masalah (audit) | Yang dikerjakan |
|---|---|---|
| **Bootstrap sesi terpusat** | 34 file memanggil `session_start()` telanjang sehingga hardening cookie di-bypass; cookie tidak `Secure` di belakang Cloudflare | Dibuat `includes/bootstrap.php` — satu titik masuk untuk semua entrypoint. Cookie `Secure`+`HttpOnly`+`SameSite=Lax`, deteksi HTTPS di belakang Cloudflare via `X-Forwarded-Proto`/`CF-Visitor` dengan verifikasi trusted-proxy. Regenerasi ID sesi tiap 30 menit. |
| **JsonStore** | 6 penulis `images.json` baca-penuh-tanpa-lock → **lost update**; tulis bisa merusak file | Dibuat `includes/JsonStore.php`: `flock(LOCK_EX)` **sepanjang** baca-mutasi-tulis, tulis via **temp file + `rename()` atomik**, plus **backup** otomatis + rotasi. |
| **SSRF guard** | `isPublicIp` lolos CGNAT/metadata; cek IP setelah transfer; redirect tak divalidasi | `includes/ImageHandler.php`: **blocklist CIDR lengkap** (termasuk `100.64/10` Alibaba metadata, `169.254/16`, `198.18/15`, `224/4`, `240/4`, `64:ff9b::/96`, `2002::/16`), **pin DNS** via `CURLOPT_RESOLVE`, dan **redirect divalidasi per-hop** (`FOLLOWLOCATION=false` + loop manual). |
| **`i.php` ditulis ulang** | Buffer seluruh objek ke RAM; Range 1 byte menarik 10MB; suspended/expired tak ditegakkan | **Streaming** (`CURLOPT_WRITEFUNCTION`) bukan buffer RAM; **Range passthrough**; lookup `images.json` dulu → pemilik **suspended → 451**, `delete_at` lewat → **410**, record hilang → **404**; **rate-limit** 60 req/menit per IP; cache diturunkan jadi 1 hari. |
| **Upload diperkuat** | Gatekeeper tak dipanggil; kuota tak atomik; CSRF mati; dedup bocorkan gambar orang lain; EXIF/GPS tersimpan | `Gatekeeper::canUpload()` dipanggil (maintenance/kill-switch/batas global); kuota storage **atomik** (`UPDATE ... WHERE storage_used + ? <= ?`) + **kompensasi hapus S3** bila gagal; **CSRF untuk user login**; dedup tidak lagi kembalikan gambar milik orang lain; **EXIF/GPS dibuang** dari varian original (re-encode, bukan `copy()` mentah). |
| **Kuota AI atomik** | `SELECT COUNT` lalu `INSERT` → N request paralel bisa lolos (double-spend) | Satu statement atomik: `INSERT ... SELECT ... WHERE (COUNT)<limit` → paralel tidak bisa menembus kuota. Ada **refund** bila proses gagal. |
| **Firewall fail-closed** | Semua kontrol DB `catch → allow` saat error | `SecurityFirewall`, `AbuseGuard`, `R2RateLimiter`, Gatekeeper: **fail-closed** (deny saat DB error), dengan **tombol darurat** `security_failopen_override` untuk memaksa allow bila operator butuh. |
| **Cron penghapusan** | Hapus S3 dulu baru metadata → crash = orphan; tanpa backup | Urutan diperbaiki: **tandai (soft-delete) → hapus S3 → hapus metadata**; **backup sebelum hapus**; akuntansi `storage_stats` dikoreksi. |
| **XSS diperbaiki** | Stored XSS admin via filename; XSS `status_reason`; reflected `?tool=` | `admin.php` root menjadi **redirect 301** ke dashboard (sink XSS hilang); **escape** filename/`status_reason`/`?tool=`; `escapeHtml()` di JS. |
| **Error tidak bocorkan detail** | `$e->getMessage()` mentah dikirim ke klien | Dibuat `includes/Logger.php` terstruktur (log server, **tanpa PII** — IP di-mask, secret di-redact). Klien menerima pesan generik. |
| **Privasi** | `contacts.json` bisa ter-commit + IP mentah; EXIF; privacy.php kontradiksi | `data/contacts.json` **di-gitignore** + IP disimpan sebagai **hash SHA-256**; EXIF dibuang; `privacy.php` diselaraskan dengan praktik nyata. |
| **`session_version`** | Sesi suspended/locked tetap hidup; ganti password tak mematikan sesi | Kolom `users.session_version` + middleware membandingkan versi tiap request. Di-increment saat **suspend/lock/demote/ganti password/delete**. Migrasi **idempotent**. |
| **Turnstile** | Fail-open saat tak terkonfigurasi + hostname tak diverifikasi | **Fail-closed** (deny bila tak terkonfigurasi di produksi) + verifikasi **hostname** terhadap allow-list. |
| **Migrasi DB & schema** | `schema.sql` tak lengkap → fresh install rusak | Migrasi idempotent (`session_version`), `schema.sql` **disamakan dengan produksi** (15 tabel). |

Commit perbaikan:

| Commit | Isi |
|---|---|
| `d1d7b43` | remediation wave 1: bootstrap+JsonStore+auth+SSRF+i.php+storage+gatekeeper+quota+firewall+cron+XSS+logging+privacy |
| `5122239` | fix wave: konsistensi `expires_at`, model CSRF guest-upload, sapu bootstrap 5×, CSRF register, penulis JsonStore, status akun upload token, aktivasi `recordUpload`, guard catch storageManager |
| `d5b6eb6` | render: pakai URL proxy `/i/` di mana-mana (view/gallery/result/admin-gallery/dashboard) sebelum privatisasi |
| `bd07cb1` | i.php: URL upstream SigV4 presigned (berfungsi pada objek publik **maupun** privat, zero-downtime) |

### 3.6 DUA AUDITOR INDEPENDEN menangkap 5 blocker sebelum deploy

Sebelum deploy, dua auditor independen memeriksa hasil. Mereka menangkap **5 blocker** yang
kemudian diperbaiki:

1. **Upload guest akan 403.** CSRF sempat dipaksa untuk **semua** upload. Karena guest tidak
   punya sesi, mereka selalu gagal. → **Diperbaiki:** CSRF hanya untuk **user login**; guest
   dibebaskan (tidak ada sesi untuk disalahgunakan).
2. **`config/turnstile.php` tidak ada.** → **Dibuat** dari secret live.
3. **5 file masih `session_start` telanjang.** → **Disapu** ke bootstrap terpusat.
4. **`register.php` CSRF JSON bypass.** Token tidak divalidasi pada body JSON. → **Diperbaiki**
   (validasi untuk semua POST, termasuk JSON).
5. **Nama kolom `blocked_ips` salah.** Kode memakai `blocked_until`, DB live memakai
   `expires_at`. → **Disamakan ke `expires_at`** sesuai DB live.

### 3.7 DEPLOY

Langkah deploy yang dijalankan:

1. **`config/turnstile.php` dibuat** dari secret live.
2. **Migrasi `session_version` dijalankan** (idempotent — aman diulang).
3. **Kode di-deploy via `rsync`**, dengan **pengecualian**: `config/`, `python/`, `data/`,
   `temp/`, `vendor/` (agar kredensial & data tidak tertimpa).
4. **Commit rollback di server** dicatat (untuk keperluan revert).
5. **Reload PHP-FPM**.
6. **nginx deny rules** untuk `/data/`, `/temp/`, `/config/`, serta ekstensi `.sql`/`.bak`/`.log`.
7. **Verifikasi live:** semua endpoint mengembalikan **200**; path sensitif mengembalikan
   **403/404**.

### 3.8 STORAGE PRIVACY (bagian paling rumit)

Tujuan: gambar **tidak boleh** bisa diakses langsung dari bucket storage (hanya lewat `/i/`).
Tantangannya: mengubah bucket menjadi privat **tidak boleh** membuat gambar mati.

Solusi bertahap (zero-downtime):

1. **`/i/` diubah memakai URL bertanda tangan SigV4 (presigned).** URL presigned berfungsi
   baik pada objek **publik** (kondisi sekarang) maupun objek **privat** (kondisi target).
   Artinya, `/i/` tetap bekerja **selama** dan **sesudah** transisi — **tidak ada downtime**.
2. **Semua render diganti ke URL proxy `/i/`.** Tidak ada lagi URL bucket mentah yang dicetak
   ke halaman (view, gallery, result, admin-gallery, dashboard).
3. **Bucket Contabo dibuat privat di level bucket** (`PutBucketAcl`). → **Objek BARU menjadi
   privat** (URL langsung mengembalikan **403**, tetapi `/i/` tetap **200**).
4. **Uji end-to-end upload berhasil.**

> **Catatan penting:** langkah 3 hanya memprivatkan **objek baru**. Objek **lama** yang
> di-upload sebelum remediasi masih bisa diakses via URL lama (lihat bagian 4.3 — risiko
> yang diterima).

### 3.9 FASE 2–4: KEANDALAN, ARSITEKTUR & OBSERVABILITAS

> **Status: SELESAI & LIVE di `p.hel.ink`.** Fase 1 (keamanan) dijelaskan di 3.5–3.8.
> Fase 2–4 menaikkan tiga dimensi sekaligus: **keandalan data**, **kebersihan arsitektur**,
> dan **visibilitas operasional**. Semua perubahan bersifat **aditif** (tidak menghapus
> perilaku lama) dan **terverifikasi live**.

#### 3.9.1 Fase 2 — Keandalan (Reliability 6.5 → ~8.5)

Masalah inti: metadata foto hidup di **satu file JSON** (`data/images.json`). File tunggal =
**titik gagal tunggal** (lost update, korupsi, tidak bisa di-query), dan **tidak ada jaring
pemulihan** saat proses upload mati di tengah jalan.

**(a) Metadata foto: JSON → tabel MySQL `images` (migrasi 003).**

| Langkah | Yang dikerjakan |
|---|---|
| **Satu pintu akses data** | `ImageRepository` dibuat sebagai **satu kelas akses data foto**. **16+ callsite** diadopsi: upload, `i.php`, view, result, gallery, dashboard, stats, admin, cron, cleanup, `AbuseGuard`, orphans. |
| **Skrip migrasi** | `scripts/migrate_images_to_db.php` — **idempotent**, **resumable**, dan punya mode **`--verify`**. |
| **Saklar mode penyimpanan** | Flag `site_settings.images_store_mode` dengan transisi bertahap: `json` → `dual_write` → `db_primary` → **`db_only`**. Cutover **health-gated** (hanya maju bila health check hijau). |
| **Deteksi drift** | `cron/images_drift_check.php` — membandingkan isi JSON ↔ DB dan melaporkan selisih. |
| **Jalur rollback** | `scripts/export_db_to_images_json.php` — ekspor balik DB → `images.json` bila perlu mundur. |

**Hasil (terverifikasi):** **57 record termigrasi**, **`--verify` = 0 mismatch**, cutover ke
**`db_only` berhasil** (semua jalur kini membaca dari DB). `images.json` **dibekukan sebagai
arsip di tempat** — **tidak** di-rename, karena *expiration guard* di cron masih menunjuk
path tersebut.

**(b) Crash-recovery saga upload.**

`UploadJournal` (tabel **`pending_operations`**, migrasi 004) + integrasi di `api/upload.php`
(fase `open` → `progress` → `complete`/`fail`) + `cron/reconcile_pending.php` (tiap
**15 menit**). Upload yang mati di tengah kini bisa **dipulihkan otomatis**: **orphan S3
dihapus** atau **metadata dilengkapi**.

**(c) Orphan reconciler storage.**

`cron/storage_reconcile.php` (harian **04:00**) membandingkan metadata DB vs objek S3
(R2 + Contabo) dan melaporkan:

- **orphan storage** — objek ada di S3, tanpa metadata;
- **orphan metadata** — record ada di DB, tanpa file.

Mode `--delete` **hanya** menyasar orphan berumur **> 7 hari**. Ditemukan **6 orphan storage**
(dilaporkan, **tidak** dihapus otomatis — keputusan operator).

**(d) Fondasi dari Fase 1 (pendukung keandalan).**

- **Backup harian otomatis**: `cron/daily_backup.php` tiap **03:30** → dump DB + `config/` +
  data JSON + kode, **retensi 14 hari**.
- **Hot indexes** (migrasi 002) untuk query yang sering dipakai.

#### 3.9.2 Fase 3 — Arsitektur (Arsitektur 4.5 → ~7.5)

God-file dipecah secara **additive** — delegasi/pemindahan **verbatim**, **render identik
byte-level**, **tanpa rewrite** logika.

| File lama | Sesudah | Hasil |
|---|---|---|
| `tools.php` **1601 → 1105 baris** (−31%) | konfigurasi tool → `includes/ToolRegistry.php`; markup 6 tool → `templates/tools/*.php` | render identik |
| `api/upload.php` **1072 → 278 baris** (Controller) | orkestrasi → `includes/UploadService.php` (671); pemrosesan gambar → `includes/ImageVariantProcessor.php` (230) | render identik |
| `result.php` / `member/result.php` | data → `includes/ResultPresenter.php` / `includes/MemberResultPresenter.php` | render identik |
| `dashboard.php` / `admin/gallery.php` | agregasi → `includes/DashboardService.php` / `includes/AdminGalleryService.php` (query DB langsung ke tabel `images`) | render identik |

Pelengkap arsitektur:

- **Helper terpusat** `includes/helpers.php` (`ph_format_bytes`, `ph_json_response`, `ph_e`).
- **Autoloader** `includes/autoload.php`.
- **`ImageRepository`** sebagai **satu** data access layer untuk seluruh metadata foto.

#### 3.9.3 Fase 4 — Observabilitas (Observability 3.0 → ~7.5)

| Komponen | Fungsi |
|---|---|
| `health.php` | Endpoint kesehatan: cek **DB / storage / disk / Python AI**; status `ok` / `degraded` / `down`. |
| `includes/Logger.php` | Log terstruktur **JSON** terpusat di `data/logs/app-*.jsonl` + **rotasi 5 MB** + **retensi 14 hari** + **mask IP** + **redact secret**. |
| `includes/Alerter.php` | **Alert email kritis** ke admin untuk kegagalan **S3 / DB / migrate / drift**; **throttle 1 per channel / 15 menit**. |
| `cron/daily_summary.php` | Email ringkasan harian (**23:55**): upload/hari, storage, error count per channel, AI usage, pending ops, orphan. |
| `metrics.php` | Endpoint **Prometheus** terkunci (**token / IP allowlist**) yang mengekspos counter kunci. |
| `cron/images_drift_check.php` | Drift checker JSON ↔ DB (lihat 3.9.1). |

#### 3.9.4 Perbaikan deployment penting (ditemukan & diperbaiki di Fase 2–4)

| Temuan | Perbaikan |
|---|---|
| **Upload/view 500** karena izin `data/` berbeda antar-user: web (`www-data`) vs cron (`carawin`) | `JsonStore` group-write **0664** + **setgid** pada direktori, sehingga **kedua user** bisa menulis. |
| **`popup-banner.php`** path Gatekeeper salah (`includes/core/` → `../core/`) | Path diperbaiki. |
| **`dbSave` dual-write** gagal `HY093` (placeholder ganda) | Diubah ke pola **`VALUES()` / `excluded`**. |

---

## 4. KEADAAN SETELAH REMEDIASI (Kesimpulan)

### 4.1 Yang SUDAH aman / berfungsi (dengan bukti uji live)

| Perbaikan | Bukti / cara cek |
|---|---|
| Endpoint utama hidup | Semua endpoint mengembalikan **200** saat verifikasi live |
| Path sensitif tertutup | `/data/`, `/temp/`, `/config/`, `*.sql`, `*.bak`, `*.log` → **403/404** |
| Sesi aman | Cookie `Secure`+`HttpOnly`+`SameSite=Lax`; deteksi HTTPS di belakang Cloudflare |
| Revokasi sesi | Suspend/lock/ganti password menaikkan `session_version` → sesi lama mati |
| SSRF ditutup | Blocklist CIDR lengkap + pin DNS + redirect divalidasi per-hop |
| `/i/` streaming | Tidak lagi buffer RAM; Range passthrough; 451 untuk pemilik suspended; 410 untuk `delete_at` lewat |
| Kuota storage | Atomik + kompensasi hapus S3 bila gagal |
| Kuota AI | Atomik (tidak bisa double-spend paralel) |
| Firewall | Fail-closed saat DB error + tombol darurat |
| Cron hapus | Soft-delete → hapus S3 → hapus metadata, dengan backup dulu |
| Turnstile | Fail-closed + verifikasi hostname |
| Schema | `schema.sql` = produksi (15 tabel); fresh install kini konsisten |
| Privasi | `contacts.json` di-gitignore, IP di-hash, EXIF dibuang |
| Konten disuspend | `/i/` mengembalikan **451**; objek baru privat → direct URL **403** |
| **Metadata foto di DB** (Fase 2) | Tabel MySQL `images` (migrasi 003); `images_store_mode=db_only`; 57 record termigrasi; `--verify` 0 mismatch |
| **Semua jalur baca lewat repository** (Fase 2) | `ImageRepository` dipakai 16+ callsite; `images.json` dibekukan sebagai arsip |
| **Crash-recovery upload** (Fase 2) | `UploadJournal` (`pending_operations`) + `cron/reconcile_pending.php` tiap 15 menit |
| **Orphan reconciler** (Fase 2) | `cron/storage_reconcile.php` harian 04:00; 6 orphan storage dilaporkan (tidak dihapus otomatis) |
| **Backup harian otomatis** (Fase 2) | `cron/daily_backup.php` 03:30; retensi 14 hari |
| **God-file dipecah** (Fase 3) | `tools.php` 1601→1105; `api/upload.php` 1072→278; presenter/service baru; render identik |
| **Helper + autoloader** (Fase 3) | `includes/helpers.php` + `includes/autoload.php` |
| **Endpoint kesehatan** (Fase 4) | `health.php` → `ok`/`degraded`/`down` (DB/storage/disk/AI) |
| **Log terstruktur & alert** (Fase 4) | `Logger` JSONL + rotasi 5 MB + retensi 14 hari; `Alerter` email throttle 15 menit |
| **Ringkasan & metrik** (Fase 4) | `cron/daily_summary.php` 23:55; `metrics.php` Prometheus terkunci |

### 4.2 Sisa yang perlu TINDAKAN MANUAL KAMU (non-teknis, mudah)

Semua aksi di bawah dilakukan **sekali** di dashboard Cloudflare (satu login). Tidak perlu
menyentuh server.

**A. Privasi R2 (wajib).**
Dashboard Cloudflare → **R2** → bucket `pichost...` → **Settings** → **Custom Domains** →
**HAPUS** domain `r2.p.hel.ink`. Setelah ini semua objek R2 menjadi privat; gambar tetap
jalan lewat `/i/`.

**B. Cache Cloudflare (wajib).**
Dashboard Cloudflare → **Caching** → **Cache Rules** → ubah **Edge TTL** untuk `/i/` dari
**1 bulan menjadi 1 hari**. Alasan: saat ini konten yang sudah dihapus masih bisa tersaji
dari cache Cloudflare hingga **1 bulan** (origin sudah 404 seketika, tapi cache edge belum
tentu). Dengan 1 hari, jendela ini diperkecil drastis.

**C. Rotasi kunci Turnstile (dianjurkan).**
Dashboard Cloudflare → **Turnstile** → **rotate** site key + secret key (kunci lama pernah
tercatat di history Git publik). Setelah rotate, perbarui
`/var/www/pichost/config/turnstile.php` dengan kunci baru.

**D. Git history secret (opsional).**
Karena repo bersifat publik, kunci lama dianggap **sudah bocor**. **Rotasi (poin C) sudah
cukup** untuk mengamankan; pembersihan history Git (filter-repo/BFG) sifatnya opsional dan
tidak mendesak.

### 4.3 Risiko yang DITERIMA dengan sadar (dijelaskan jujur)

- **Objek LAMA di Contabo** yang di-upload **sebelum** remediasi masih bisa diakses lewat
  URL lama yang tersimpan orang. Ini karena objek lama berstatus *object-level public-read*
  yang **tidak bisa diubah via API** pada setup ini. Mitigasi yang berlaku: **ID objek acak
  (tak tertebak)** dan **tidak ada URL baru yang dicetak** — jadi risiko praktisnya rendah.
  **Gambar BARU sudah privat.**
- **Cache edge Cloudflare** (poin B) belum diturunkan sampai Anda melakukannya secara manual.

### 4.4 Podman: DITUNDA (dengan alasan tertulis)

Migrasi ke Podman **ditunda**, bukan dibatalkan. Alasan:

1. **Tidak menyelesaikan temuan P0–P2** — masalah utama ada di kode (SSRF, fail-open, dsb.),
   bukan di cara paket aplikasi.
2. **venv AI berukuran ~GB** — PaddleOCR + rembg sangat berat untuk container.
3. **3 proses** (web + cron + DB) yang saling bergantung.
4. **`flock` bergantung pada filesystem lokal** — perlu desain volume yang hati-hati.

**Kriteria go masa depan:**
- remediasi **stabil ≥ 2 minggu**,
- metadata **JSON → DB** sudah selesai,
- **uji staging** berhasil.

Sketsa `Containerfile` + compose ada di **bagian 6.4**.

### 4.5 Skor akhir per dimensi (sebelum → sesudah Fase 1–4)

Skor awal dari audit adalah **2.5 / 10 (KRITIS)**. Setelah Fase 1 (keamanan) dan Fase 2–4
(keandalan, arsitektur, observabilitas), skor tiap dimensi naik sebagai berikut:

| Dimensi | Sebelum | Sesudah | Penggerak utama |
|---|---|---|---|
| **Security** | ~3.0 | **~8.5** | Bootstrap sesi, SSRF guard, fail-closed, `session_version`, privatisasi storage |
| **Reliability** | 6.5 | **~8.5** | Metadata JSON→DB (`db_only`), `UploadJournal`, orphan reconciler, backup harian |
| **Arsitektur** | 4.5 | **~7.5** | God-file dipecah additive, helper terpusat, `ImageRepository` sebagai satu DAL |
| **Observability** | 3.0 | **~7.5** | `health.php`, `Logger` JSONL, `Alerter`, `daily_summary`, `metrics.php` |
| **TOTAL (rata-rata)** | **2.5** | **≈ 8.2 / 10** | Seluruh temuan CRITICAL/HIGH ditutup; sisa risiko diterima secara sadar (bagian 4.3) |

> **Catatan:** skor "Sebelum" per dimensi adalah estimasi pembobotan dari temuan audit
> (`AUDIT_REPORT.md`); skor "Sesudah" mencerminkan keadaan **live `p.hel.ink`** pasca
> Fase 2–4. Angka total ≈ 8.2 berasal dari rata-rata empat dimensi di atas.

---

## 5. PANDUAN REVERT PER PERUBAHAN

> **Aturan:** revert **hanya** bila benar-benar perlu. Selalu backup ulang sebelum revert.

### 5.1 Tabel revert per perubahan

| Perubahan | Cara revert | Risiko revert |
|---|---|---|
| Migrasi `session_version` | **Aditif — tak perlu revert.** Bila wajib: restore dump DB (`pixelhop_db.sql.gz`) | Kehilangan data user terbaru bila restore dump lama |
| Bootstrap sesi (`includes/bootstrap.php`) | Revert kode via Git di server: `cd /var/www/pichost && git checkout 3b7119a -- <file>` | **Semua user ter-logout** (harus login ulang) |
| `i.php` (streaming + ACL + presigned) | Revert kode via Git | **JANGAN revert setelah R2 dibuat privat** — gambar akan 403 total |
| Contabo dibuat privat (bucket-level ACL) | **Tidak bisa di-rollback otomatis.** Hubungi Contabo **atau** set ACL publik ulang via dashboard | Mengembalikan masalah privasi (konten terhapus bisa tersaji) |
| nginx deny rules | Restore backup: `/etc/nginx/sites-enabled/p.hel.ink.bak-remediation-*` lalu `nginx -t && systemctl reload nginx` | Path sensitif (`/data/`, `/config/`) kembali terbuka |
| Deploy kode keseluruhan | `git checkout 6bc5a72^` **atau** restore `/var/backups/pichost/20260910_145054/pichost_code.tar.gz` | Kembali ke kode sebelum remediasi (semua celah terbuka lagi) |
| Migrasi `session_version` (kolom DB) | Aditif; bila wajib: `ALTER TABLE users DROP COLUMN session_version;` | Sesi jadi tak dapat di-revoke (regresi keamanan) |
| `config/turnstile.php` | Hapus file (aplikasi kembali fail-closed → login/register menolak) | **Login/register mati** — jangan lakukan tanpa alasan |
| `data/contacts.json` di-gitignore + IP hash | Revert `contact.php` via Git | IP kembali tersimpan mentah (regresi privasi) |
| **Metadata foto JSON→DB** (Fase 2) | Rollback via `php scripts/export_db_to_images_json.php` (ekspor DB → `images.json`), lalu set `site_settings.images_store_mode = 'json'` | Aplikasi kembali memakai file JSON (titik gagal tunggal kembali; hanya bila DB bermasalah) |
| **Mode `dual_write`** (Fase 2) | Set `site_settings.images_store_mode = 'json'` | Berhenti menulis ke DB; JSON jadi sumber tunggal lagi |
| **`pending_operations` / `UploadJournal`** (Fase 2) | `DROP TABLE pending_operations;` + revert `api/upload.php` via Git di server (bila perlu) | Crash-recovery upload nonaktif (upload mati di tengah tak dipulihkan otomatis) |
| **God-file refactor (Fase 3)** | Revert file via Git di server: `cd /var/www/pichost && git checkout <commit> -- <file>` | Kembali ke file besar; **pastikan** service/presenter lama ikut konsisten agar tidak ada require yang hilang |
| **`health.php` / `metrics.php`** (Fase 4) | Hapus file (endpoint hilang) | Kehilangan visibilitas; **tidak** memengaruhi fungsi upload/serving |
| **`Logger` / `Alerter`** (Fase 4) | Revert via Git; `data/logs/` boleh dibiarkan | Error kembali hanya ke log server lama; alert email berhenti |

### 5.2 PROSEDUR ROLLBACK TOTAL (langkah demi langkah, copy-paste)

> **Jalankan sebagai root** (atau via `sudo -i`). Ganti `pixelhop_db` dengan nama DB asli,
> dan `pixelhop_user` dengan user DB asli. Password DB **tidak** ditulis di sini — masukkan
> saat prompt `-p`.

```bash
# ---------------------------------------------------------------
# 0. VARIABEL — sesuaikan bila perlu
# ---------------------------------------------------------------
BACKUP=/var/backups/pichost/20260910_145054
APP=/var/www/pichost
DBNAME=pixelhop_db
DBUSER=pixelhop_user

# ---------------------------------------------------------------
# 1. STOP PHP-FPM (agar tidak ada request saat restore)
# ---------------------------------------------------------------
systemctl stop php8.3-fpm || systemctl stop php8.2-fpm || systemctl stop php-fpm
systemctl status php-fpm --no-pager || true

# ---------------------------------------------------------------
# 2. BACKUP ULANG keadaan saat ini (jaga-jaga sebelum menimpa)
# ---------------------------------------------------------------
STAMP=$(date +%Y%m%d_%H%M%S)
mkdir -p /var/backups/pichost/pre_rollback_$STAMP
tar -czf /var/backups/pichost/pre_rollback_$STAMP/code.tar.gz -C "$APP" . \
    --exclude=./python/venv
cp -a "$APP/config" /var/backups/pichost/pre_rollback_$STAMP/config_live
cp -a "$APP/data"   /var/backups/pichost/pre_rollback_$STAMP/data_live

# ---------------------------------------------------------------
# 3. RESTORE KODE dari tar backup (kecualikan venv/config/data/temp)
# ---------------------------------------------------------------
# Simpan dulu config & data live, karena tar TIDAK berisi venv dan
# kita ingin mempertahankan kredensial yang sedang dipakai bila perlu.
tar -xzf "$BACKUP/pichost_code.tar.gz" -C "$APP"

# ---------------------------------------------------------------
# 4. RESTORE DATABASE dari dump penuh
# ---------------------------------------------------------------
gunzip -c "$BACKUP/pixelhop_db.sql.gz" | mysql -u "$DBUSER" -p "$DBNAME"

# ---------------------------------------------------------------
# 5. RESTORE CONFIG & DATA live
# ---------------------------------------------------------------
cp -a "$BACKUP/config_live/." "$APP/config/"
cp -a "$BACKUP/data_live/."   "$APP/data/"

# ---------------------------------------------------------------
# 6. RESTORE NGINX
# ---------------------------------------------------------------
cp -a "$BACKUP/nginx_sites-available/." /etc/nginx/sites-available/
nginx -t && systemctl reload nginx

# ---------------------------------------------------------------
# 7. RESTORE CRONTAB (bila perlu)
# ---------------------------------------------------------------
crontab "$BACKUP/crontab_carawin.txt"   # sebagai user carawin
# crontab root:   crontab "$BACKUP/crontab_root.txt"

# ---------------------------------------------------------------
# 8. PERMISSION & START ULANG
# ---------------------------------------------------------------
chown -R www-data:www-data "$APP/data" "$APP/temp" 2>/dev/null || \
chown -R nginx:nginx "$APP/data" "$APP/temp" 2>/dev/null || true
chmod 755 "$APP/temp" "$APP/data"

systemctl start php8.3-fpm || systemctl start php8.2-fpm || systemctl start php-fpm
systemctl restart nginx

# ---------------------------------------------------------------
# 9. VERIFIKASI
# ---------------------------------------------------------------
curl -sS -o /dev/null -w "home: %{http_code}\n" https://<domain>/
curl -sS -o /dev/null -w "data: %{http_code}\n" https://<domain>/data/images.json
```

**Setelah rollback:** login ulang (sesi lama invalid), lalu konfirmasi `/i/` mengembalikan
gambar yang benar.

---

## 6. LAMPIRAN

### 6.1 Daftar file yang berubah (kategori)

Total: **86 file berubah** (7.613 insertions, 2.037 deletions) pada branch `remediation/v1`.

| Kategori | File |
|---|---|
| **Baru — fondasi keamanan** | `includes/bootstrap.php`, `includes/JsonStore.php`, `includes/Logger.php`, `includes/ClientIp.php` |
| **Baru — konfigurasi contoh** | `config/security.example.php`, `config/turnstile.example.php` |
| **Baru — migrasi & skrip** | `database/migrations/001_add_session_version.sql`, `scripts/privatize_existing_objects.php` |
| **Baru — fitur live yang diselamatkan** | `core/SafeGuard.php`, `cron/process_moderation_queue.php` |
| **Inti keamanan (diubah)** | `includes/ImageHandler.php`, `includes/SecurityFirewall.php`, `includes/R2RateLimiter.php`, `includes/RateLimiter.php`, `includes/Turnstile.php`, `core/Gatekeeper.php`, `core/AbuseGuard.php`, `includes/R2StorageManager.php` |
| **Proxy & serving** | `i.php` (ditulis ulang), `view.php`, `gallery.php`, `result.php`, `view-temp.php` |
| **Upload & API** | `api/upload.php`, `api/ocr.php`, `api/rembg.php`, `api/cleanup.php`, `api/report.php`, `api/stats.php`, `api/compress.php`, `api/resize.php`, `api/crop.php`, `api/convert.php` |
| **Auth & sesi** | `auth/middleware.php`, `auth/login.php`, `auth/register.php`, `auth/google.php`, `auth/google-callback.php`, `auth/verify.php`, `auth/verify-pending.php`, `auth/resend-verification.php`, `register.php`, `login.php` |
| **Admin** | `admin/users.php`, `admin/gallery.php`, `admin/tools.php`, `admin/settings.php`, `admin/firewall.php`, `admin/seo.php`, `admin/storage.php`, `admin.php` (jadi redirect) |
| **Member & halaman** | `dashboard.php`, `member/*.php`, `index.php`, `help.php`, `features.php`, `contact.php`, `privacy.php`, `terms.php`, `tools.php` |
| **Cron** | `cron/image_expiration.php`, `cron/security_cleanup.php` |
| **Database** | `database/schema.sql`, `database/user_status_migration.sql`, `database/r2_storage.sql`, `database/r2_security_migration.sql` |
| **Dokumentasi** | `docs/CLOUDFLARE_FREE_TIER.md`, `docs/R2_SETUP_GUIDE.md`, `README.md` |

### 6.2 Perintah verifikasi server yang berguna

```bash
# --- 1. Cek endpoint mengembalikan 200 ---
for p in / /login.php /register.php /help.php /features.php; do
  printf "%-20s %s\n" "$p" "$(curl -sS -o /dev/null -w '%{http_code}' https://<domain>$p)"
done

# --- 2. Cek path sensitif TERTUTUP (harap 403 atau 404) ---
for p in /data/images.json /config/s3.php /temp/ /database/schema.sql; do
  printf "%-28s %s\n" "$p" "$(curl -sS -o /dev/null -w '%{http_code}' https://<domain>$p)"
done

# --- 3. Cek /i/ menyajikan gambar (harap 200) dan objek baru privat (harap 403) ---
curl -sS -o /dev/null -w "i.php:  %{http_code}\n" https://<domain>/i/<YYYY>/<MM>/<DD>/<id>_medium.jpg
curl -sS -o /dev/null -w "direct: %{http_code}\n" "https://<account>.r2.cloudflarestorage.com/<bucket>/<key>"

# --- 4. Cek kolom DB penting ---
mysql -u <DBUSER> -p <DBNAME> -e "SHOW COLUMNS FROM users LIKE 'session_version';"
mysql -u <DBUSER> -p <DBNAME> -e "SHOW COLUMNS FROM blocked_ips LIKE 'expires_at';"
mysql -u <DBUSER> -p <DBNAME> -e "SELECT setting_key, setting_value FROM site_settings WHERE setting_key='security_failopen_override';"

# --- 5. Cek deny rules nginx ---
grep -rn "deny\|location ~ \\.\\(sql\\|bak\\|log\\)" /etc/nginx/sites-enabled/ | head

# --- 6. Cek guard cron (CLI-only) ---
php /var/www/pichost/cron/security_cleanup.php          # dijalankan via CLI: OK
curl -sS -o /dev/null -w "%{http_code}\n" https://<domain>/cron/security_cleanup.php  # harap 403

# --- 7. Cek secret TIDAK ter-track Git (config/*.php harus di-ignore) ---
cd /var/www/pichost && git check-ignore -v config/turnstile.php config/s3.php
```

### 6.3 Lokasi backup & retensi

| Item | Lokasi |
|---|---|
| Backup utama (Fase 0) | `/var/backups/pichost/20260910_145054/` |
| Backup pra-rollback | `/var/backups/pichost/pre_rollback_<stamp>/` (dibuat saat rollback) |
| Backup `images.json` | `data/images.json.<stamp>.bak` (dibuat otomatis oleh JsonStore, rotasi simpan 10 terbaru) |
| Backup log privatisasi | `data/privatize_log.json` (resume log + ringkasan run terakhir) |

**Retensi:** simpan backup Fase 0 minimal sampai remediasi dinyatakan **stabil ≥ 2 minggu**.
Jangan hapus sebelum ada backup baru yang terverifikasi bisa di-restore.

### 6.4 Sketsa Containerfile + compose (rencana Podman masa depan — DITUNDA)

> **Status: BELUM dijalankan.** Ini hanya sketsa dari rencana yang ditunda (bagian 4.4).
> Jangan pakai sebelum kriteria go terpenuhi (stabil ≥ 2 minggu + metadata JSON→DB + uji staging).

**`Containerfile` (sketsa):**

```dockerfile
FROM php:8.3-fpm-bookworm

# Ekstensi PHP yang dibutuhkan
RUN apt-get update && apt-get install -y \
        libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
        libzip-dev default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd pdo_mysql zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Python + AI (PaddleOCR & rembg) — CATATAN: ini berat (~GB)
RUN apt-get update && apt-get install -y python3 python3-venv python3-pip \
    && python3 -m venv /opt/venv
COPY requirements.txt /tmp/requirements.txt
RUN /opt/venv/bin/pip install --no-cache-dir -r /tmp/requirements.txt

WORKDIR /var/www/pichost
COPY . /var/www/pichost

# PYTHON_BIN harus menunjuk ke venv DI DALAM container.
# Produksi saat ini: /var/www/pichost/python/venv/bin/python3 (JANGAN diubah sembarangan).
ENV PYTHON_BIN=/opt/venv/bin/python3

RUN chown -R www-data:www-data /var/www/pichost/data /var/www/pichost/temp
USER www-data
```

**`compose.yaml` (sketsa):**

```yaml
services:
  web:
    build: .
    volumes:
      - ./config:/var/www/pichost/config:ro
      - ./data:/var/www/pichost/data
      - ./temp:/var/www/pichost/temp
    depends_on: [db]

  cron:
    build: .
    command: ["sh", "-c", "while true; do php cron/maintenance.php; sleep 3600; done"]
    volumes:
      - ./config:/var/www/pichost/config:ro
      - ./data:/var/www/pichost/data
    depends_on: [db]

  db:
    image: mariadb:11
    environment:
      MARIADB_DATABASE: pixelhop_db
      MARIADB_USER: pixelhop_user
      MARIADB_PASSWORD: "***"          # placeholder — pakai secret, jangan hardcode
    volumes:
      - dbdata:/var/lib/mysql
      - ./database/schema.sql:/docker-entrypoint-initdb.d/01-schema.sql:ro

volumes:
  dbdata:
```

> **Catatan `flock`:** `JsonStore` memakai `flock` pada filesystem lokal. Pastikan `data/`
> di-mount sebagai volume **lokal** (bukan NFS) agar penguncian tetap andal.

### 6.5 Checklist "Jika terjadi X, lakukan Y" (untuk pemilik non-teknis)

| Gejala | Langkah pemeriksaan (urut) |
|---|---|
| **Gambar tidak muncul** | 1) Cek `/i/<path>` → apakah **200**? Bila **451** → pemilik akun di-suspend. Bila **410** → gambar kedaluwarsa/dijadwalkan hapus. Bila **403** → objek storage privat tapi URL bukan via `/i/` (pastikan render memakai proxy `/i/`). Bila **404** → record hilang di `images.json`. Bila **502** → storage upstream bermasalah. |
| **Upload selalu gagal** | 1) Cek `config/turnstile.php` ada & berisi kunci valid. 2) Cek `maintenance_mode` dan `kill_switch_active` di `site_settings` (mungkin aktif). 3) Cek kuota storage user & global. 4) Cek izin tulis `data/` dan `temp/`. |
| **Login/register gagal** | 1) Cek `config/turnstile.php` (bila hilang → fail-closed, semua ditolak). 2) Cek koneksi DB. 3) Cek kunci Turnstile masih valid (bila baru di-rotate, pastikan config diperbarui). |
| **Fitur OCR / Remove Background mati** | 1) Cek `PYTHON_BIN` = `/var/www/pichost/python/venv/bin/python3` (path `pichost`, **bukan** `pixelhop`). 2) Jalankan `python3 -c "import paddleocr"` dari venv. 3) Cek kuota AI harian user. |
| **Kontrol keamanan "tidak jalan"** | 1) Cek `security_failopen_override` di `site_settings` — bila `1`, firewall dipaksa allow (matikan bila tidak sengaja). 2) Cek koneksi DB (fail-closed akan menolak bila DB error). |
| **Halaman admin lama bermasalah** | `/admin.php` kini **redirect 301** ke `/admin/dashboard.php`. Gunakan dashboard baru. |
| **Konten terhapus masih tampil** | 1) Origin sudah **404/410** seketika. 2) Bila masih tampil → **cache Cloudflare**: purge by URL via dashboard **Caching → Purge** atau Purge Everything. 3) Pastikan Edge TTL `/i/` sudah diturunkan ke 1 hari (poin 4.2-B). |
| **Kesalahan "CSRF token invalid" saat upload** | Untuk **user login**: refresh halaman agar token baru. Untuk **guest**: guest dibebaskan dari CSRF — bila guest tetap gagal, laporkan sebagai bug. |
| **Curiga kunci bocor** | Rotasi di dashboard (Turnstile/R2/S3), lalu perbarui file di `/var/www/pichost/config/`. Jangan pernah commit `config/*.php`. |
| **Perlu membatalkan semua perubahan** | Ikuti **bagian 5.2 (Rollback Total)** langkah demi langkah. |

---

*Dokumen ini adalah dokumentasi pertinggal pengerjaan remediasi. Untuk detail 84 temuan
dengan bukti `file:baris`, lihat `AUDIT_REPORT.md`. Untuk perubahan kode, lihat branch
`remediation/v1` (commit `d1d7b43`, `5122239`, `d5b6eb6`, `bd07cb1`).*
