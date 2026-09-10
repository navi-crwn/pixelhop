# LAPORAN AUDIT KEAMANAN & ARSITEKTUR — PIXELHOP

**Tanggal:** 2026-09-10
**Target:** codebase PixelHop (PHP 8.1+ tanpa framework, MySQL/PDO, storage R2+Contabo S3, AI via Python venv)
**Metode:** 13 subtask audit paralel → 2 auditor independen (DeepSeek, GLM) → resolve loop (11 sengketa diverifikasi langsung ke repo) → dual sign-off
**Standar bukti:** setiap temuan berbasis `file:line` verbatim; 53+ spot-check gabungan kedua auditor; **nol bukti fiktif ditemukan**

---

## 1. EXECUTIVE SUMMARY

### Skor Kesehatan Sistem: **2.5 / 10 — KRITIS (tidak production-ready)**

| Dimensi | Bobot | Auditor 1 (DeepSeek) | Auditor 2 (GLM) | Konsensus |
|---|---|---|---|---|
| Security | 40% | 2.5 | 2.0 | **2.2** |
| Reliability & Data Integrity | 30% | 2.8 | 2.5 | **2.6** |
| Arsitektur | 20% | 2.5 | 2.5 | **2.5** |
| Observability | 10% | 3.0 | 3.0 | **3.0** |
| **Total** | | 2.6 | 2.5 | **≈2.5** |

**Justifikasi:** fondasi kripto benar (Argon2id, `random_bytes`, `hash_equals`, `escapeshellarg`, prepared statements hampir di mana-mana), tetapi kontrol keamanan *dilengkapi namun tidak terhubung*: Gatekeeper tidak pernah dipanggil di jalur upload, `canRunHeavyTool` nol pemanggil, tabel `user_sessions` mati, dan seluruh kontrol berbasis DB **fail-open** saat error. Ditambah satu **CRITICAL deployability blocker**: aplikasi tidak bisa diinstall dari README.

### 3 (+1) Risiko Paling Fatal

1. **D5-23 — Fresh install rusak (CRITICAL).** Aplikasi tidak dapat dijalankan mengikuti README: register & Google OAuth gagal di *semua* kombinasi SQL repo; login gagal bila hanya `schema.sql` yang diimpor. Kolom yang dipakai kode tidak pernah didefinisikan di DDL manapun.
2. **D3-01 + D3-02 — Rantai SSRF (HIGH).** `isPublicIp()` meloloskan CGNAT `100.64.0.0/10` (metadata Alibaba `100.100.100.200`), `198.18.0.0/15`, NAT64 `64:ff9b::/96`, 6to4; cek IP dilakukan **setelah** `curl_exec`/file tertulis; redirect tidak divalidasi per-hop → metadata cloud/internal dapat dibaca server-side.
3. **D2-01 + D3-06 — Kontrol keamanan mati & fail-open berlapis (HIGH).** Maintenance mode, kill-switch, dan global storage limit mati total di jalur upload; firewall, AbuseGuard, R2RateLimiter, dan login rate-limit semuanya `catch → allow` saat DB error.
4. **D1-01 + D1-02 — Status akun & OAuth gap (HIGH).** Sesi user suspended/locked tetap hidup ≤7–30 hari; OAuth login hanya cek `is_blocked`, tanpa `email_verified`, linking `WHERE email = ? OR google_id = ?`.

### Statistik Temuan

**84 temuan kanonik** (dari ~130 temuan mentah ter-dedup):

| Severity | Jumlah |
|---|---|
| CRITICAL | **1** |
| HIGH | **19** |
| MEDIUM | **36** |
| LOW | **26** |
| INFO | **2** |

| Dimensi | CRIT | HIGH | MED | LOW | INFO |
|---|---|---|---|---|---|
| D1 Auth/Sesi/Akses | 0 | 4 | 5 | 5 | 1 |
| D2 Upload/Serving/Storage | 0 | 6 | 6 | 2 | 0 |
| D3 SSRF/Firewall/Rate-limit | 0 | 4 | 6 | 2 | 0 |
| D4 Data Integrity/JSON/Cron | 0 | 1 | 5 | 3 | 0 |
| D5 Schema/Arsitektur/Kode | 1 | 3 | 10 | 9 | 1 |
| D6 Observability/Privasi/Prod | 0 | 1 | 4 | 5 | 0 |

---

## 2. METODOLOGI & BATASAN

- **Static-only**: semua verdict berbasis pembacaan kode; tidak ada fuzzing terhadap instance hidup.
- **Refutation-first**: setiap hipotesis mandat dicari dulu bukti pembantahnya. Hasilnya: **18 hipotesis REFUTED** (§6).
- **Verdict 5-nilai**: SOLID (≥2 referensi / call chain lengkap) · PLAUSIBLE (indikasi kuat, runtime-dependent) · REFUTED (dibantah kode) · UNVERIFIABLE (butuh akses di luar repo) · OUT_OF_SCOPE.
- **Batasan**: konfigurasi nginx aktif (`/etc/nginx`), php.ini, Cloudflare rules, isi `config/*.php` produksi, dan DB live **tidak ada di repo** → dinyatakan UNVERIFIABLE.

---

## 3. TABEL TEMUAN MASTER (84 temuan, 6 dimensi)

### D1 — Auth, Sesi & Access Control (15)

| ID | Temuan | Severity | Verdict | Evidence utama |
|---|---|---|---|---|
| **D1-01** | Status akun tidak ditegakkan per-request; sesi suspended/locked hidup terus; OAuth & upload bypass suspend | **HIGH** | SOLID | `middleware.php:33-48`; `google-callback.php:124-128`; `api/upload.php:65-66,220-227` |
| **D1-02** | OAuth tanpa cek `email_verified` + linking `WHERE email=? OR google_id=?` | **HIGH** | SOLID | `google-callback.php:104-112,119-121,131-136` |
| **D1-03** | CSRF di-skip total untuk request JSON (`!empty($_POST)` gate) | MEDIUM | SOLID | `auth/login.php:39-42,260-269`; `auth/register.php:36-39` |
| **D1-04** | Turnstile fail-open saat tak terkonfigurasi + hostname respons tak diverifikasi | **HIGH** | SOLID | `Turnstile.php:65-73,136-140` |
| **D1-05** | Cookie `secure=>isset($_SERVER['HTTPS'])` tanpa X-Forwarded-Proto + 34 file `session_start()` telanjang men-bypass hardening middleware | **HIGH** | SOLID | `middleware.php:11-20`; `index.php:6`, `dashboard.php:7`, dst. |
| **D1-06** | Login timing side-channel (dummy hash malformed) + status akun dicek sebelum password → enumerasi | MEDIUM | SOLID | `auth/login.php:73-78,86-107` |
| **D1-07** | resend-verification: rate-limit di `$_SESSION` pakai `REMOTE_ADDR` → bypass tanpa cookie; enumerasi email; email bombing | MEDIUM | SOLID | `resend-verification.php:13-20,51-53,73` |
| D1-08 | Tabel `user_sessions` mati (nol INSERT/SELECT; hanya DELETE cron) | LOW | SOLID | `schema.sql:45-58`; `cron/maintenance.php:150` |
| D1-09 | CORS `*` di endpoint auth + 8 API (dampak terbatas: tanpa Allow-Credentials + SameSite=Lax) | LOW | SOLID | `auth/login.php:10`, `auth/register.php:10`, `api/*.php` |
| D1-10 | Password reset flow tidak ada; link mati; `sendPasswordResetEmail` dead code | LOW | SOLID | `login.php:155`; `Mailer.php:84-89` |
| D1-11 | OAuth state pakai `!==` bukan `hash_equals`; redirect_after_login path-only (bukan open redirect) | LOW | SOLID | `google-callback.php:28-34`; `middleware.php:104-111` |
| D1-12 | `verify.php` auto-login: `session_start()` sebelum middleware → cookie default php.ini (regenerate benar) | LOW | SOLID | `verify.php:7,56-64`; `middleware.php:140-150` |
| D1-13 | Tidak ada super-admin: admin bisa demote/delete/suspend admin lain; `change_type` tanpa self-protect | MEDIUM | SOLID | `admin/users.php:45-77,57-66,124-130` |
| D1-14 | Password policy hanya min 8 + upper/lower/digit (tanpa breach-list) | INFO | SOLID | `auth/register.php:65,74-84` |
| **D1-15** | Reflected XSS `?tool=` di member/result.php (antar-user, auth-gated) | MEDIUM | SOLID | `member/result.php:13,21,32,475,953` |

### D2 — Upload, Serving & Storage Pipeline (14)

| ID | Temuan | Severity | Verdict | Evidence utama |
|---|---|---|---|---|
| **D2-01** | **Gatekeeper bypass total**: `api/upload.php` tidak memanggil `canUpload` → maintenance mode, kill-switch, global storage limit mati; `global_storage_used` tak pernah naik | **HIGH** | SOLID | `api/upload.php:39-69`; `Gatekeeper.php:116-132,563`; grep `canUpload(` = 1 definisi |
| D2-02 | Limit storage 4-arah kontradiktif: 250MB (Gatekeeper) vs 500MB (upload.php, ditegakkan) vs 1GB (schema) vs 500MB (README) | MEDIUM | SOLID | `Gatekeeper.php:72`; `upload.php:225-233`; `schema.sql:23`; `README.md:56` |
| D2-03 | CSRF token upload mati — dikirim UI, tak pernah diverifikasi endpoint | LOW | SOLID | grep `validateCsrf` di `api/upload.php` = 0; `member/upload.php:528` |
| D2-04 | Dedup oracle lintas-user: hash sama → kembalikan id/URL gambar milik siapa pun | MEDIUM | SOLID | `api/upload.php:843-857,259-267` |
| **D2-05** | Lost-update `images.json`: semua penulis selain `saveImageData` baca-penuh-tanpa-lock → tulis `LOCK_EX` di akhir; `gallery.php:80` tanpa lock sama sekali | **HIGH** | SOLID | `cron/image_expiration.php:37+141`; `api/cleanup.php:36+68`; `view.php:29-31+112`; `admin/abuse.php:104+112`; `gallery.php:50+80` |
| **D2-06** | Nol transaksi ACID: upload S3→JSON→DB tanpa kompensasi → orphan S3 / kuota undercount / metadata hilang | **HIGH** | SOLID | `api/upload.php:355-364,407-419`; grep `beginTransaction` = hanya `Database.php:111-129` |
| D2-07 | Dead code SigV4 ~148 baris di `api/upload.php` (`uploadToS3`/`_doS3Upload` nol pemanggil eksternal) | LOW | SOLID | `api/upload.php:611-754`; jalur nyata `R2StorageManager.php:250` |
| D2-08 | Varian `original` disalin mentah (no re-encode); tanpa batas dimensi (decompression bomb); Imagick tanpa resource limit di jalur upload; UI 15MB vs server 10MB | MEDIUM | SOLID | `api/upload.php:311-316,519,541`; `assets/js/app.js:459-461` |
| D2-09 | Body error S3 mentah (`$e->getMessage()`) dikirim ke klien saat failover gagal | MEDIUM | SOLID | `api/upload.php:446-455`; `api/convert.php:194-198` |
| **D2-10** | `i.php` buffer seluruh objek S3 ke RAM PHP per request (termasuk Range 1 byte) — amplifikasi ~10MB:1B, tanpa auth/rate-limit | **HIGH** | SOLID | `i.php:39-46,61-63,91-95` |
| **D2-11** | Bypass suspended/expired via `/i/`: `view.php` menegakkan 451, `i.php` tidak membaca DB/`images.json` sama sekali; `delete_at` tak ditegakkan di serving | **HIGH** | SOLID (kode) / PLAUSIBLE (route nginx luar repo) | `i.php:8-35`; `view.php:44-58,522-527` |
| **D2-12** | Triple-whammy: objek di-upload `x-amz-acl: public-read` + raw URL S3 dicetak di view.php + `Cache-Control: immutable` 1 tahun tanpa purge → konten terhapus/suspended tersaji ≤1 tahun | **HIGH** | SOLID | `R2StorageManager.php:282,326`; `view.php:533-541`; `i.php:76` |
| D2-13 | Jalur `$_POST['url']` di upload.php: unduh body penuh ke RAM sebelum cek ukuran; tanpa HEAD/MIME pre-check (open proxy terbatas ≤10MB) | MEDIUM | SOLID | `api/upload.php:116-177,198-205` |
| D2-14 | Security headers absen total dari PHP (CSP/HSTS/X-Frame-Options/Referrer-Policy); hanya `nosniff` di 2 file | MEDIUM | SOLID (PHP) / UNVERIFIABLE (edge) | grep `*.php` = 0; `i.php:77`; `view-temp.php:60` |

### D3 — SSRF, Firewall & Rate-Limit (12)

| ID | Temuan | Severity | Verdict | Evidence utama |
|---|---|---|---|---|
| **D3-01** | `isPublicIp` hanya `NO_PRIV_RANGE`+`NO_RES_RANGE` → lolos CGNAT `100.64/10`, `198.18/15`, `224/4`, `240/4`, NAT64, 6to4 (metadata Alibaba `100.100.100.200` terverifikasi lolos) | **HIGH** | SOLID | `ImageHandler.php:179-183` |
| **D3-02** | SSRF TOCTOU: `dns_get_record` ≠ resolve curl; `CURLINFO_PRIMARY_IP` dicek **setelah** transfer/file tertulis; redirect 5 hop tanpa validasi ulang | **HIGH** | SOLID | `ImageHandler.php:154-167,262-270,286-300`; `api/upload.php:117-140` |
| D3-03 | `strtolower($scheme)` tanpa cast di ImageHandler (laten; jalur tak terjangkau saat ini karena `filter_var` lebih dulu menolak) | LOW | PLAUSIBLE | `ImageHandler.php:90-91` (kontras `upload.php:103` sudah cast) |
| D3-04 | BAD_BOTS blokir curl/wget/python-requests/UA-kosong — kontradiksi README ShareX/automation (ShareX UA custom lolos) | MEDIUM | SOLID | `SecurityFirewall.php:21-27,250-265`; `README.md:99-110` |
| D3-05 | SUSPICIOUS_PATTERNS substring naif: false positive (`/eval`, `../`, `concat(`) + bypass encoding (`%2e%2e%2f`); auto-block setelah 10 event | MEDIUM | SOLID | `SecurityFirewall.php:30-36,272-290,391-414` |
| **D3-06** | **Fail-open sistematis**: semua kontrol DB-backed `catch → allow` (firewall, AbuseGuard, R2RateLimiter, login rate-limit, Gatekeeper) | **HIGH** | SOLID | `SecurityFirewall.php:242-244,311-313,337-339`; `AbuseGuard.php:104-107`; `R2RateLimiter.php:86-88,120-123`; `auth/login.php:237-239` |
| D3-07 | Rate-limit upload 3-arah: migrasi 20/jam vs default kode 300 vs help.php klaim 100 + off-by-one (efektif 19) | MEDIUM | SOLID | `r2_security_migration.sql:95`; `SecurityFirewall.php:117,178-204`; `help.php:154` |
| D3-08 | `api/report.php` tanpa firewall; `api/cleanup.php` CRON_KEY via `$_GET` (bocor di access log) | MEDIUM | SOLID | `api/report.php:1-45`; `api/cleanup.php:11-19` |
| D3-09 | RateLimiter berbasis file: read-modify-write tanpa lock → burst paralel lolos | MEDIUM | SOLID | `RateLimiter.php:42-60,134-165` |
| **D3-10** | `cron/security_cleanup.php` tanpa guard apapun (CLI/CRON_KEY) — request web publik bisa memicu penghapusan `ip_requests`/`security_events`/`blocked_ips` (hapus jejak serangan) | **HIGH** | SOLID | `cron/security_cleanup.php:1-34` (grep guard = 0) |
| D3-11 | `checkUpload` tetap menegakkan limit saat `firewall_enabled=0`/`rate_limit_enabled=0` (perilaku tak konsisten) | LOW | SOLID | `SecurityFirewall.php:143-145,196-204,319-340` |
| D3-12 | `trackRequest` 1 INSERT/request; cleanup hanya di cron `security_cleanup.php` yang tak terdokumentasi di README → `ip_requests` tumbuh tak terbatas | MEDIUM | SOLID | `SecurityFirewall.php:188,345-360,560`; `README.md:129-139` |

### D4 — Data Integrity, JSON Store & Cron (9)

| ID | Temuan | Severity | Verdict | Evidence utama |
|---|---|---|---|---|
| D4-01 | Urutan delete S3-dulu-baru-metadata → crash di tengah = orphan metadata (record ada, file hilang) | **HIGH** | SOLID | `cron/image_expiration.php:106-137` |
| D4-02 | Delete S3 return-`false` hanya di-echo Warning; `$deleteSuccess=false` hanya pada Exception → metadata tetap dihapus → **orphan storage massal**; `api/cleanup.php` mengabaikan hasil sepenuhnya | MEDIUM | SOLID | `cron/image_expiration.php:117-135`; `api/cleanup.php:49-62` |
| D4-03 | `storage_stats` tidak dikurangi cron ekspirasi/cleanup-orphans (panggil `deleteFromR2/Contabo` langsung, bukan `deleteImage`); `api/cleanup.php` panggil `deleteObject` tanpa size | MEDIUM | SOLID | `R2StorageManager.php:469-484,526-554`; `api/cleanup.php:51` |
| D4-04 | `deleteImage` mengurangi pakai estimasi 5%/95% dari ukuran *original* — padahal upload mencatat 4 varian aktual → drift akuntansi dua arah (komentar kode bahkan bilang 10/90) | LOW | SOLID | `R2StorageManager.php:509-516` vs `162-196` |
| D4-05 | Threshold AbuseGuard 3-arah: constructor 50/200 vs `checkUpload` 100/2000 vs watchdog 50/200 → fresh install tanpa seed: watchdog 4× lebih ketat dari gate | MEDIUM | SOLID | `AbuseGuard.php:71-79,215-226,244-245,372-373` |
| D4-06 | `AbuseGuard::recordUpload()` no-op → counting selalu full-scan `images.json`; 4 full-scan per upload | LOW | SOLID | `AbuseGuard.php:264-267,199,215,225,244` |
| D4-07 | Nol backup/arsip sebelum delete permanen di semua jalur (satu-satunya backup = rotasi maintenance.log) | MEDIUM | SOLID | `image_expiration.php:129-141`; `admin/gallery.php:68-73`; dst. |
| D4-08 | Watchdog membandingkan hitungan 24 jam dengan *hourly* threshold (label salah, ambang tak pernah dievaluasi per jam) | LOW | SOLID | `AbuseGuard.php:340-394` |
| D4-09 | `api/report.php`: append `abuse_reports.json` non-atomik + race dedup + rate-limit file tanpa lock; laporan untuk image tak-ada diterima bila images.json hilang | MEDIUM | SOLID | `api/report.php:26-44,72-94,113-119` |

### D5 — Schema, Arsitektur & Kode (24)

| ID | Temuan | Severity | Verdict | Evidence utama |
|---|---|---|---|---|
| **D5-23** | **FRESH INSTALL RUSAK**: (i) schema.sql saja → login rusak (`account_status` dsb. tak ada); (ii) +user_status_migration → register tetap rusak (`email_verification_token/expires` tak ada di SQL manapun); (iii) Google OAuth selalu rusak (`google_id`/`account_type`/`avatar_url`/`email_verified_at` tak ada di DDL manapun). README hanya menyuruh import schema.sql | **CRITICAL** | SOLID | `schema.sql:17-40`; `user_status_migration.sql:8-14`; `auth/login.php:67`; `auth/register.php:140`; `google-callback.php:120,133,154`; `README.md:83-84` |
| D5-01 | `usage_logs`, `temp_files`, `site_settings` tanpa CREATE TABLE di repo, dipakai jalur kritis | HIGH | SOLID | grep = 0; dipakai `Gatekeeper.php:41,236,437`; `ocr.php:80`; `view-temp.php:22` |
| D5-02 | `r2_security_migration.sql:90` INSERT ke `site_settings` yang tak pernah dibuatnya → migrasi error; firewall settings tak pernah ter-seed | MEDIUM | SOLID | `r2_security_migration.sql:87-90` |
| D5-03 | DDL inline runtime di 3 kelas dengan catch ditelan → DB user minimal-privilege → tabel tak ada → kontrol **fail-open senyap** | HIGH | SOLID | `SecurityFirewall.php:63-106`; `AbuseGuard.php:42-58`; `R2RateLimiter.php:47-62` |
| D5-04 | 8+ kolom `users` dipakai kode tapi tanpa DDL (account_type, google_id, avatar_url, email_verified_at, email_verification_token/expires, daily_*_count, daily_reset_at) | HIGH | SOLID | `register.php:140`; `google-callback.php:154`; `Gatekeeper.php:753-754` |
| D5-05 | Duplikasi DDL: `storage_stats`+`image_storage` di 2 file migrasi; `blocked_ips`/`security_events`/`ip_requests` migrasi vs inline (inline beda COLLATE) | LOW | SOLID | `r2_storage.sql:7,23` = `r2_security_migration.sql:7,23`; `SecurityFirewall.php:64,78,94` |
| D5-06 | `image_storage` didefinisikan 2× tapi **nol referensi PHP** (dead schema) | LOW | SOLID | grep `image_storage` di `*.php` = 0 |
| D5-07 | Drift penamaan PicHost vs PixelHop di 13 lokasi (termasuk PYTHON_BIN & path cron) | LOW | SOLID | `AiService.php:16`; `api/upload.php:3,286`; `requirements.txt:5-6`; dll. |
| D5-08 | God-files: tools.php 1601 (PHP 29 baris), result.php 1052, admin/gallery.php 1016, member/result.php 966, dashboard.php 808, api/upload.php 861 | MEDIUM | SOLID | outline per file + proposal §10 |
| D5-09 | Docs klaim "Tesseract", implementasi PaddleOCR (requirements.txt berat ~GB) | LOW | SOLID | `README.md:38,66`; `help.php:206` vs `ocr_engine.py:3` |
| D5-10 | `admin.php` root (457 baris) orphan — dashboard lama, tidak tertaut nav, tetap reachable & **target XSS aktif** (filename innerHTML) | MEDIUM | SOLID | `admin.php:259,369-380`; `admin/includes/header.php:12` |
| D5-11 | Kolom dead di schema (`verification_token`, `reset_token`, `reset_expires` — nol pemakaian); `user_status_migration.sql` non-idempotent & tak terdokumentasi | LOW | SOLID | `schema.sql:27-29`; grep = 0 |
| D5-12 | Interpolasi SQL mentah (saat ini aman: int session/whitelist — pola berbahaya) | LOW | SOLID | `dashboard.php:65`; `admin/tools.php:55` |
| D5-13 | `recordToolUsage` menaikkan `daily_removebg_count` untuk SEMUA tool non-OCR (compress/resize/crop/convert ikut menaikkan); reset counter hanya di jalur mati | MEDIUM | SOLID | `Gatekeeper.php:231`; `api/convert.php:158` dll. |
| D5-14 | Kuota AI TOCTOU: COUNT `usage_logs` sebelum proses, INSERT setelah sukses, tanpa unique constraint → N request paralel lolos kuota | MEDIUM | SOLID | `api/ocr.php:79-86,153`; `api/rembg.php:79-86,152`; `Gatekeeper.php:235-239` |
| D5-15 | Tiga sumber kebenaran kuota: `usage_logs` (enforcement) vs `users.daily_*_count` (write-only) vs limit di `site_settings` | MEDIUM | SOLID | `ocr.php:80`; `Gatekeeper.php:231-234,753-754` |
| D5-16 | `canRunHeavyTool()` **nol pemanggil** → CPU threshold & max_concurrent_processes mati; mitigasi parsial: `AiService::checkSystemLoad` hardcode 3.0 | MEDIUM | SOLID | grep = hanya `Gatekeeper.php:168`; `AiService.php:31-41,70,124` |
| D5-17 | Dead code cluster: `recordUpload` no-op, cabang kosong `findDuplicateImage`, 4 helper ImageHandler tanpa pemanggil, `canUpload` tak terpakai | LOW | SOLID | `AbuseGuard.php:264-267`; `upload.php:855-857`; `ImageHandler.php:501-526` |
| D5-18 | `generateId` 6-char (62^6≈5.7×10^10) tanpa cek kolisi → birthday 50% ≈ 240rb gambar; kolisi **menimpa** entri lama diam-diam | LOW | SOLID | `api/upload.php:460-467,271,784` |
| D5-19 | Catch menelan error lalu respons tetap `success` (mis. update storage_used gagal) | MEDIUM | SOLID | `api/upload.php:416-419,435` |
| D5-20 | `PYTHON_BIN` hardcode `/var/www/pichost/python/venv/bin/python3` — deployment lain → AI mati total, tanpa fallback, stderr dibuang. **Catatan: di server produksi path ini BENAR (proyek memang di /var/www/pichost)** | MEDIUM | PLAUSIBLE | `AiService.php:16,98-105` |
| D5-21 | rembg `return=json` meng-base64 seluruh PNG → puncak RAM >60–80MB/request untuk input 15MB | MEDIUM | SOLID | `api/rembg.php:134-135,163`; `member/rembg.php:226` |
| D5-22 | (Positif) AI fail-closed (timeout→504, output kosong/invalid→500); admin bebas kuota disengaja; member pages hanya UI pre-check | INFO | SOLID | `AiService.php:198-231`; `ocr.php:69-71` |
| D5-24 | `api/stats.php` baca penuh `images.json` + 3 pass (foreach/usort/foreach) tanpa pagination (admin-only) | LOW | SOLID | `api/stats.php:24-26,36-44,57-60,79-82` |

### D6 — Observability, Privasi & Produksi (10)

| ID | Temuan | Severity | Verdict | Evidence utama |
|---|---|---|---|---|
| D6-01 | Debug log PII di docroot: `upload_debug.log` (IP+user_id+URL), `oauth_debug.log` (email + token response mentah); retensi 6 jam via cleanup temp | MEDIUM | SOLID | `api/upload.php:17-26,86-94`; `google-callback.php:15-19,78,116` |
| D6-02 | Raw `$e->getMessage()` ke klien di 6 endpoint (bocorkan pesan Imagick/cURL/S3/path) | MEDIUM | SOLID | `api/upload.php:454`; `convert.php:194-198`; pola sama di resize/ocr/rembg/crop/compress |
| D6-03 | 70+ `error_log` tak terstruktur di 20+ file; nol metrik/alerting/monitoring | LOW | SOLID | grep `prometheus/sentry/...` = 0 |
| D6-04 | **Secret Turnstile live di git history** (`SECRET_KEY 0x4AAAA...`); commit 2339a1a mengakui & minta rotasi; `.gitignore` pakai daftar eksplisit, bukan glob `config/*.php` | HIGH | SOLID (via `git show`); **perlu rotasi segera** | `git show 2339a1a~1:includes/Turnstile.php`; `.gitignore:3-8` |
| D6-05 | `data/contacts.json` **tidak di-gitignore** + simpan nama/email/pesan/IP mentah + tulis tanpa lock → risiko PII ter-commit ke repo publik | MEDIUM | SOLID | `.gitignore:34-38` (contacts.json absen); `contact.php:24-36` |
| D6-06 | Docs membocorkan R2 `account_id` nyata + endpoint + path `/var/www/pichost` | LOW | SOLID | `docs/R2_SETUP_GUIDE.md:64-70` |
| D6-07 | Privacy policy kontradiksi praktik: klaim "retained indefinitely" vs auto-delete guest 60→90 hari; klaim IP hanya untuk rate-limit vs IP mentah di 9 lokasi | LOW | SOLID | `privacy.php:82,113-119` vs `cron/image_expiration.php:75-89`; `api/upload.php:404` |
| D6-08 | `display_errors` hanya diset di 2 file (0 di upload.php; **1 di admin/cleanup-orphans.php** — dimitigasi CLI guard) | LOW | SOLID (inkonsistensi) / UNVERIFIABLE (php.ini produksi) | `api/upload.php:8-9`; `admin/cleanup-orphans.php:11-17` |
| D6-09 | admin/firewall `unblock_ip` tanpa validasi IP + tanpa audit log; `admin/abuse.php` pakai `$currentUser` tak terdefinisi → `reviewed_by` selalu 'admin' | LOW | SOLID | `admin/firewall.php:32-39`; `admin/abuse.php:65-69,91` |
| D6-10 | **EXIF/GPS retention**: varian `original` adalah `copy()` mentah tanpa `stripMetadata` (strip hanya di tool convert/resize/crop/compress) → foto publik bawa metadata lokasi | MEDIUM | SOLID | `api/upload.php:311-316`; grep `stripMetadata` = hanya 4 tool + `ImageHandler.php:552` |

---

## 4. DETAIL TEMUAN CRITICAL & HIGH (20 temuan)

### D5-23 — Fresh install rusak (deployability blocker) [CRITICAL | SOLID]
**Deskripsi:** Schema repo tidak dapat mereproduksi kolom/tabel yang dipakai kode auth. Tiga skenario rusak:
- **(i)** Import hanya `schema.sql` (sesuai README) → `auth/login.php:67` me-SELECT `account_status, status_reason, warning_message, warning_shown` yang tidak ada → PDOException → login 500.
- **(ii)** Import `schema.sql` + `user_status_migration.sql` → login OK, tapi `auth/register.php:140` me-INSERT `email_verification_token, email_verification_expires` yang **tidak didefinisikan di file SQL manapun** → register selalu gagal.
- **(iii)** Google OAuth **selalu rusak** — `google-callback.php:154` INSERT `google_id, avatar_url, account_type, email_verified_at`; tak satu pun ada di DDL repo.

**Bukti:** `schema.sql:17-40` (16 kolom saja); `user_status_migration.sql:8-14` (hanya 7 kolom status); `README.md:83-84`.
**Dampak:** 100% fresh install mengikuti dokumentasi = auth tidak berfungsi.
**Fix:** migrasi idempotent lengkap (`ALTER ... ADD COLUMN IF NOT EXISTS` / migrasi berurutan), seed `site_settings`, perbarui README urutan import. **Effort: M.**

### D2-01 — Gatekeeper bypass total di jalur upload [HIGH | SOLID]
**Bukti:** `api/upload.php:39-69` hanya me-require `SecurityFirewall` + `R2StorageManager` + `AbuseGuard`; grep `Gatekeeper|canUpload` di file itu = 0. Kontrol mati: `maintenance_mode`, `kill_switch_active`, emergency global threshold (`Gatekeeper.php:119-132`). Satu-satunya pemanggil `updateGlobalStorage` adalah `cleanupExpiredTempFiles` dengan delta **negatif** (`Gatekeeper.php:563`) → kill-switch otomatis tak pernah terpicu.
**Dampak:** admin mengaktifkan maintenance/kill-switch tapi upload publik tetap jalan.
**Fix:** panggil `Gatekeeper::canUpload($fileSize, $userId)` di awal `api/upload.php`; jadikan Gatekeeper satu-satunya sumber kuota. **Effort: M.**

### D3-01 — Blokir IP internal tidak lengkap (SSRF) [HIGH | SOLID]
**Bukti:** `ImageHandler.php:179-183` — hanya `filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)`. Terverifikasi lolos: `100.64.0.1` (CGNAT — termasuk metadata Alibaba `100.100.100.200`), `198.18.0.1`, `224.0.0.1`, `240.0.0.1`, `64:ff9b::7f00:1` (NAT64 → 127.0.0.1), `2002:7f00:1::` (6to4).
**Fix:** explicit CIDR blocklist (tambah 100.64/10, 198.18/15, 224/4, 240/4, 64:ff9b::/96, 2002::/16) + pin `CURLOPT_RESOLVE` ke IP tervalidasi. **Effort: M.**

### D3-02 — SSRF TOCTOU + redirect tanpa validasi per-hop [HIGH | SOLID]
**Bukti:** resolusi `dns_get_record` (`ImageHandler.php:154-167`) terpisah dari resolve internal curl (`L262`); cek `CURLINFO_PRIMARY_IP` baru berjalan **setelah** `curl_exec` selesai dan file tertulis (`L286-300`); `CURLOPT_FOLLOWLOCATION=true, MAXREDIRS=5` (`L262-270`) tanpa re-validasi tiap hop. Sama di `api/upload.php:117-140`.
**Dampak:** URL publik → redirect ke `169.254.169.254` dieksekusi penuh; body respons internal sudah diterima sebelum ditolak.
**Fix:** `FOLLOWLOCATION=false` + loop manual dengan `assertPublicUrl` tiap hop; pin DNS via `CURLOPT_RESOLVE`. **Effort: M.**

### D3-06 — Fail-open sistematis pada semua kontrol DB [HIGH | SOLID]
**Bukti (verbatim diverifikasi):** `SecurityFirewall.php:242-244` (`isIPBlocked` catch→false), `:311-313` (`isRateLimited`→false), `:337-339` (`isUploadRateLimited`→false); `AbuseGuard.php:104-107` (`isBlocked`→false); `R2RateLimiter.php:86-88,120-123` (→**true**/allow); `auth/login.php:237-239` (login rate-limit→false); `Gatekeeper.php:355-357` (→0).
**Dampak:** DB error/kelelahan koneksi — persis saat serangan — mematikan seluruh lapisan anti-abuse secara senyap.
**Fix:** fail-closed untuk kontrol keamanan (atau mode darurat allowlist); pisahkan kegagalan infra dari keputusan allow. **Effort: M.**

### D3-10 — cron/security_cleanup.php tanpa guard [HIGH | SOLID]
**Bukti:** 34 baris tanpa `php_sapi_name`/`CRON_KEY` (grep = 0), langsung `require` + jalankan `SecurityFirewall::cleanup()` + `R2RateLimiter::cleanup()`. Bandingkan `api/cleanup.php:11-19` (benar, default-deny) dan `cron/maintenance.php:19-22` (CLI-only).
**Dampak:** request web siapa pun memicu penghapusan `ip_requests`, `security_events`, `blocked_ips` kedaluwarsa → penyerang bisa menghapus jejaknya.
**Fix:** guard CLI-only + CRON_KEY. **Effort: S.**

### D1-01 — Status akun tidak ditegakkan per-request [HIGH | SOLID]
**Bukti:** `isAuthenticated()`/`isAdmin()` hanya baca `$_SESSION` (`middleware.php:33-48`); admin suspend/lock/demote hanya UPDATE DB (`admin/users.php:37-99`); ganti password tak menyentuh sesi (`member/settings.php:78-81`); OAuth login hanya cek `is_blocked` (`google-callback.php:124-128`); upload tidak cek status (`api/upload.php:65-66,220-227`).
**Dampak:** user suspended tetap upload/akses ≤7–30 hari; mantan admin tetap admin sampai login ulang.
**Fix:** re-query status minimal di middleware per request (atau `session_version` di users yang di-increment saat suspend/lock/ganti-password). **Effort: M.**

### D1-02 — OAuth tanpa `email_verified` + linking `email OR google_id` [HIGH | SOLID]
**Bukti:** `google-callback.php:104-112` hanya `isset($userInfo['email'])` — klaim `email_verified` Google tidak pernah dicek; `L119-121` `WHERE email = ? OR google_id = ?`; `L131-136` menautkan `google_id` + set `email_verified=1` ke akun yang ketemu via email.
**Dampak:** siapa pun yang mengontrol alamat email (forwarder, domain expired, dsb.) bisa menautkan Google identity ke akun PixelHop korban.
**Fix:** wajib `email_verified === true`; resolusi via `google_id` dulu; linking by email hanya bila akun lokal sudah `email_verified=1`. **Effort: S–M.**

### D1-04 — Turnstile fail-open + hostname tak diverifikasi [HIGH | SOLID]
**Bukti:** `Turnstile.php:68-73` return `success=true` bila tidak terkonfigurasi; `L136-140` `hostname` dari respons siteverify tidak dibandingkan dengan host yang diizinkan.
**Dampak:** anti-bot mati total bila config absen; token yang diselesaikan di domain attacker tetap diterima.
**Fix:** fail-closed bila tak terkonfigurasi (produksi); verifikasi `hostname`. **Effort: S.**

### D1-05 — Cookie hardening di-bypass `session_start()` telanjang [HIGH | SOLID]
**Bukti:** `middleware.php:10-20` hanya menyetel cookie params bila `PHP_SESSION_NONE`; tetapi 34 file memanggil `session_start()` lebih dulu (`index.php:6`, `dashboard.php:7`, seluruh `admin/*`, `member/*`, `api/stats.php:10`, `google-callback.php:7`, dst.) → params di-skip. Plus `'secure' => isset($_SERVER['HTTPS'])` tanpa handling `X-Forwarded-Proto` (grep = 0) → di belakang Cloudflare Flexible SSL cookie tanpa Secure.
**Fix:** satu bootstrap sesi terpusat yang dipanggil pertama di semua entrypoint; `secure=>true` eksplisit di produksi. **Effort: M.**

### D2-05 — Lost-update `images.json` (6 penulis) [HIGH | SOLID]
**Bukti pola (presisi):** `cron/image_expiration.php:37` baca penuh **tanpa lock** → mutasi RAM → `:141` tulis `LOCK_EX` (lock hanya saat tulis). Pola identik: `api/cleanup.php:36+68`, `view.php:29-31+112`, `admin/abuse.php:104+112`, `admin/gallery.php:50+73/122`. `gallery.php:80` **tanpa lock sama sekali**. Satu-satunya RMW yang benar: `saveImageData` (`api/upload.php:769-790`, `flock` sepanjang baca-tulis).
**Dampak:** upload yang commit selama cron/delete berjalan **hilang dari metadata** (file tetap di S3 → orphan tak terlacak).
**Fix:** satu helper `ImageRepository` RMW ber-`flock` untuk semua penulis; jangka menengah: migrasi ke SQLite/MySQL. **Effort: M.**

### D2-06 — Nol transaksi ACID pada sekuens multi-langkah [HIGH | SOLID]
**Bukti:** grep `beginTransaction|commit|rollBack` = hanya definisi helper (`Database.php:111-129`), nol pemanggil. Sekuens upload: 4 varian S3 (`upload.php:355-364`) → `saveImageData` (`:407`, gagal hanya error_log) → `UPDATE storage_used` (`:414`, catch ditelan `:416-419`).
**Dampak:** varian awal sukses lalu gagal → orphan S3; metadata gagal → URL 404 tapi kuota tak terpotong; dst.
**Fix:** kompensasi delete untuk varian yang sudah sukses saat gagal; status + throw di `saveImageData`; pola saga. **Effort: L.**

### D2-10 — i.php buffer objek penuh ke RAM [HIGH | SOLID]
**Bukti:** `i.php:39-46` `CURLOPT_RETURNTRANSFER=true`; `:61-63` `substr($response, $headerSize)` (duplikasi body penuh); `:91-95` range dilayani `substr($body, ...)`. Tanpa auth/rate-limit. Request `Range: bytes=0-0` terhadap objek 10MB → server menarik 10MB dari S3 & menahan 20MB RAM untuk mengirim 1 byte.
**Fix:** streaming (`CURLOPT_FILE`/`WRITEFUNCTION`), teruskan Range ke S3 (`CURLOPT_RANGE`), rate-limit per IP. **Effort: M.**

### D2-11 — Bypass suspended/expired via /i/ [HIGH | SOLID kode; PLAUSIBLE route]
**Bukti:** `view.php:44-58` menegakkan 451 untuk pemilik suspended; `i.php` (99 baris) tidak me-require Database/tidak membaca `images.json` — config → regex (`:18`) → curl. `delete_at` hanya ditampilkan (`view.php:522-527`), tidak ditegakkan. Klaim CHANGELOG "images inaccessible" tidak berlaku di lapisan aset. Route nginx aktif ada di `/etc/nginx` (luar repo) → end-to-end PLAUSIBLE.
**Fix:** `i.php` wajib lookup key → image → cek `account_status`, `delete_at`, `marked_for_deletion` sebelum proxy. **Effort: M.**

### D2-12 — public-read + raw URL + immutable cache [HIGH | SOLID]
**Bukti:** upload menyetel `'x-amz-acl' => 'public-read'` (`R2StorageManager.php:282,326`); `view.php:533-541` mencetak raw URL S3/R2 sebagai link "Size Versions"/Download; `i.php:76` `Cache-Control: public, max-age=31536000, immutable`; nol mekanisme purge CDN. Dokumentasi menganjurkan cache edge `/i/` 1 bulan (`docs/CLOUDFLARE_FREE_TIER.md:20-27`).
**Dampak:** konten yang dihapus/disuspend tetap tersaji dari bucket langsung + cache hingga 1 tahun — masalah kepatuhan (DMCA/GDPR erasure).
**Fix:** hapus public-read, sajikan semua via `/i/` ber-ACL, TTL pendek atau purge API saat delete/suspend. **Effort: L.**

### D4-01 — Delete S3 dulu, metadata belakangan [HIGH | SOLID]
`cron/image_expiration.php:106-137` menghapus objek S3 per varian, baru `unset($images[$imageId])` di `:130`. Crash di tengah → record ada, file hilang (halaman view hidup tapi gambar 404 permanen).
**Fix:** soft-delete marker → hapus S3 → hard-delete + retry. **Effort: M.**

### D5-01 — Tabel inti tanpa DDL [HIGH | SOLID]
`usage_logs`, `temp_files`, `site_settings` nol `CREATE TABLE` di repo, dipakai jalur kritis (`Gatekeeper.php:41,236,437`; `ocr.php:80`; `view-temp.php:22`). Bila DB produksi mengikuti repo: `recordToolUsage` melempar PDOException yang **tertangkap** (`Gatekeeper.php:249-251`) sebelum INSERT `usage_logs` → COUNT enforcement selalu 0 → **kuota AI fail-open**.
**Fix:** migrasi lengkap + seed. **Effort: M.**

### D5-03 — DDL inline runtime + catch ditelan [HIGH | SOLID]
`SecurityFirewall.php:63-106`, `AbuseGuard.php:42-58`, `R2RateLimiter.php:47-62` menjalankan `CREATE TABLE IF NOT EXISTS` saat runtime dan menelan exception ("Tables might already exist"). DB user minimal-privilege → tabel tak pernah ada → semua kontrol yang bergantung padanya fail-open senyap (terkait D3-06).
**Fix:** DDL ke migrasi versi; preflight check tabel wajib. **Effort: M.**

### D5-04 — 8+ kolom users tanpa DDL [HIGH | SOLID]
`account_type, google_id, avatar_url, email_verified_at, email_verification_token/expires, daily_ocr_count, daily_removebg_count, daily_reset_at` — dipakai di register/login/OAuth/Gatekeeper, nol di DDL. (Bagian dari D5-23.)
**Fix:** migrasi kolom lengkap. **Effort: M.**

### D6-04 — Secret Turnstile di git history [HIGH | perlu rotasi]
`git show 2339a1a~1:includes/Turnstile.php` menampilkan `SITE_KEY`/`SECRET_KEY` live; pesan commit mengakui dan meminta rotasi. File kini bersih, tetapi secret masih dapat diambil dari history. `.gitignore:3-8` memakai daftar eksplisit (config baru tak otomatis ter-ignore).
**Fix:** rotasi secret di dashboard Cloudflare (wajib, segera — aksi operator), bersihkan history (filter-repo/BFG), ubah `.gitignore` → `config/*.php` + `!config/*.example.php`. **Effort: M.**

---

## 5. MATRIKS VERDICT HIPOTESIS MANDAT

| Dimensi mandat | Hipotesis kunci | Verdict final |
|---|---|---|
| 1. Human flow | IDOR admin dari non-admin | **REFUTED** (semua file admin gate isAdmin sebelum dispatch) |
| | CSRF JSON bypass | **CONFIRMED** (D1-03) |
| | Session revocation tidak ada | **CONFIRMED** (D1-01, D1-08) |
| | Cookie secure di belakang CF | **CONFIRMED** (D1-05) |
| | Timing-safe login | **REFUTED** (D1-06 — dummy hash malformed) |
| | OAuth state valid | **CONFIRMED-aman** (random 256-bit; `!==` minor) |
| | ATO via email tak terverifikasi | **CONFIRMED** (D1-02, HIGH) |
| 2. Bots/edge | BAD_BOTS vs ShareX | **CONFIRMED** (D3-04, nuansa UA custom) |
| | RCE via AiService exec | **REFUTED** (escapeshellarg penuh) |
| | SSRF proteksi cukup | **CONFIRMED-tidak cukup** (D3-01, D3-02) |
| | i.php buffering | **CONFIRMED** (D2-10) |
| | `max_concurrent_processes` dipanggil | **REFUTED** (D5-16 — nol pemanggil) |
| 3. Kualitas kode | Null-safety ImageHandler | **PLAUSIBLE-laten** (D3-03) |
| | Turnstile fail-open | **CONFIRMED** (D1-04) |
| | Dua SigV4 / dead code | **CONFIRMED** (D2-07, D5-17) |
| 4. Struktural | Limit storage tidak konsisten | **CONFIRMED 4-arah** (D2-02) |
| | Gatekeeper di-bypass upload | **CONFIRMED** (D2-01) |
| | Kill-switch tak pernah terpicu | **CONFIRMED** (D2-01) |
| | AbuseGuard threshold tidak konsisten | **CONFIRMED 3-arah** (D4-05) |
| 5. Bisnis | Kuota TOCTOU | **CONFIRMED** (D5-14) |
| | Dedup leak lintas-user | **CONFIRMED** (D2-04) |
| | Suspended tak dapat diakses (CHANGELOG) | **CONFIRMED-tidak ditegakkan** di /i/ (D2-11) |
| | AI fail-open | **REFUTED** (fail-closed, D5-22) |
| 6. Global | Lost writes cron | **CONFIRMED** (D2-05, framing lost-update presisi) |
| | storage_stats drift | **CONFIRMED** (D4-03, D4-04) |
| | CORS * di auth | **CONFIRMED** (dampak terbatas, D1-09) |
| | Tidak ada secret hardcode | **REFUTED** — ada, di git history (D6-04) |

---

## 6. DAFTAR REFUTED (sudah aman / klaim terbantah — dengan bukti)

| Klaim | Bukti pembantah |
|---|---|
| RCE via `exec()` AiService | semua argumen `escapeshellarg` (`AiService.php:98-105,170-179`); path server-generated; language/model whitelist |
| Command injection `shell_exec` | hanya string konstan (`Gatekeeper.php:785` pgrep) |
| IP spoofing via CF/XFF header | `ClientIp.php:51-66` — header hanya dihormati bila `REMOTE_ADDR` di CIDR trusted Cloudflare |
| IDOR panel admin dari non-admin | gate `isAuthenticated && isAdmin` sebelum dispatch di semua `admin/*.php` |
| CSRF hilang di panel admin | semua POST handler admin memvalidasi token (users.php:25, settings.php:25, dst.) |
| `api/stats.php` publik | `api/stats.php:13-17` gate isAdmin → 403 |
| Delete gambar user lain (member) | `gallery.php:60-64` permission denied non-owner non-admin |
| `api/cleanup.php` terbuka bila CRON_KEY kosong | `cleanup.php:15` — `$expectedKey===''` → selalu 403 (default-deny benar) |
| Open redirect via redirect_after_login | `REQUEST_URI` path-only; tak bisa cross-domain (`middleware.php:104-111`) |
| Path traversal i.php | regex anchored whitelist (`i.php:18`) — uji mental %2f/null-byte/.. gagal semua |
| Path traversal view-temp.php | regex 32-hex + `realpath` containment + `is_file` (`view-temp.php:12-17,40-47`) |
| XSS metadata di view.php/result/gallery | tak ada render mentah; `htmlspecialchars` dipakai (`result.php:728`, `gallery.php:454`) |
| XSS via SVG/HTML di i.php | whitelist raster + finfo + `nosniff` |
| Raw SQL injectable | `$userId` int session; `$tool` whitelist; LIMIT cast int (`dashboard.php:65`, `admin/tools.php:55`) |
| Python engine tidak aman | tanpa eval/pickle; model whitelist (`ocr_engine.py`, `rembg_engine.py`) |
| 404/500 bocor stack trace | halaman statis, tanpa `getMessage`/trace |
| Logout tidak benar | `destroyUserSession` benar (`middleware.php:155-173`) |
| AI fail-open saat timeout | fail-closed: 124→504, output kosong/invalid→500, kuota tak tercatat |

---

## 7. NEW FINDINGS (di luar mandat asli)

1. **Stored XSS admin** via filename upload mentah → `admin.php:369-380` innerHTML (guest → browser admin). HIGH.
2. **Stored XSS `status_reason`** → halaman login via innerHTML (admin → korban login). MEDIUM.
3. **Reflected XSS `?tool=`** member/result.php antar-user. MEDIUM. (D1-15)
4. **EXIF/GPS retention** di varian original publik. MEDIUM. (D6-10)
5. **contacts.json tak di-gitignore + PII mentah** → risiko ter-commit ke repo publik. MEDIUM. (D6-05)
6. **Secret Turnstile di git history.** HIGH. (D6-04)
7. **security_cleanup.php tanpa guard** (hapus jejak serangan via web). HIGH. (D3-10)
8. **Watchdog salah jendela threshold** (24 jam vs hourly). LOW. (D4-08)
9. **recordToolUsage kolom salah** (semua tool → daily_removebg_count). MEDIUM. (D5-13)
10. **canRunHeavyTool nol pemanggil** (concurrency cap mati). MEDIUM. (D5-16)
11. **checkUpload enforce saat firewall disabled + off-by-one.** LOW. (D3-11)
12. **Rate-limit 20/300/100 kontradiksi 3 sumber.** MEDIUM. (D3-07)
13. **Fresh-install rusak (deployability).** CRITICAL. (D5-23)
14. **Password reset flow tidak ada.** LOW. (D1-10)
15. **admin.php root orphan + target XSS aktif.** MEDIUM. (D5-10)
16. **Docs bocorkan R2 account_id + path server.** LOW. (D6-06)
17. **Privacy policy vs praktik (retensi & IP).** LOW. (D6-07)
18. **api/stats.php memory multi-pass.** LOW. (D5-24)
19. **Docs klaim Tesseract vs PaddleOCR.** LOW. (D5-09)
20. **upload.php URL-fetch tanpa HEAD/MIME pre-check (open proxy terbatas).** MEDIUM. (D2-13)

---

## 8. UNVERIFIABLE (jujur — butuh akses di luar repo)

| Item | Yang dibutuhkan untuk verifikasi |
|---|---|
| Route nginx `/i/` → `i.php` & header keamanan produksi | `/etc/nginx/sites-available/` aktif |
| `display_errors` efektif di produksi | `php -i` / php.ini server |
| Isi `site_settings` & schema DB produksi (apakah mengikuti repo) | akses DB live |
| `session.use_strict_mode` | php.ini |
| Eksposur publik `temp/*.log`, `data/*.json` | konfigurasi web server aktif (deny rules) |
| Rotasi secret Turnstile sudah dilakukan atau belum | dashboard Cloudflare |
| Konfigurasi bucket R2/Contabo (policy, versioning, lifecycle) | dashboard provider |
| Eksploitabilitas runtime race (TOCTOU kuota/upload) | uji konkurensi terhadap instance hidup |

---

## 9. PRIORITAS PERBAIKAN (Impact × Ease)

| # | ID | Aksi | Impact | Ease | Skor | Effort |
|---|---|---|---|---|---|---|
| P0 | **D5-23** | Migrasi idempotent lengkap + seed site_settings + perbarui README urutan import | 5 | 5 | **25** | M |
| P0 | **D2-01** | Panggil `Gatekeeper::canUpload()` di `api/upload.php` (maintenance, kill-switch, global threshold) | 5 | 5 | **25** | M |
| P0 | **D3-06** | Fail-closed semua kontrol keamanan DB (stop `catch→allow`) | 5 | 4 | **20** | M |
| P0 | **D3-10** | Guard CLI/CRON_KEY di `cron/security_cleanup.php` | 4 | 5 | **20** | S |
| P0 | **D1-05** | Bootstrap sesi terpusat + `secure` eksplisit (X-Forwarded-Proto) | 4 | 4 | **16** | M |
| P0 | **D1-01** | Cek status akun per request / session_version; blokir upload untuk suspended | 5 | 3 | **15** | M |
| P1 | **D1-02** | OAuth: wajib `email_verified===true`; resolusi by `google_id` dulu | 5 | 3 | **15** | S–M |
| P1 | **D3-01** | CIDR blocklist lengkap + `CURLOPT_RESOLVE` pinning | 4 | 4 | **16** | M |
| P1 | **D3-02** | Redirect manual + validasi per-hop; cek IP sebelum body | 5 | 2 | **10** | M |
| P1 | **D1-04** | Turnstile fail-closed + verifikasi hostname | 3 | 5 | **15** | S |
| P1 | **D2-05** | Satu helper RMW ber-flock untuk semua penulis JSON | 4 | 3 | **12** | M |
| P1 | **D2-12** | Hapus public-read; proxy semua aset via i.php; purge saat delete/suspend | 4 | 3 | **12** | L |
| P1 | **D6-04** | Rotasi secret Turnstile + bersihkan git history + `.gitignore` glob | 4 | 3 | **12** | M |
| P2 | **D2-10** | Streaming i.php + range passthrough + rate-limit | 3 | 3 | **9** | M |
| P2 | **D2-11** | Lookup status/delete_at di i.php sebelum serve | 4 | 2 | **8** | M |
| P2 | **D4-01/02** | Delete metadata hanya bila semua delete S3 sukses; jurnal retry | 4 | 2 | **8** | M |
| P2 | **D5-14** | Kuota atomik: `INSERT ... WHERE (COUNT)<limit` atau unique constraint | 3 | 3 | **9** | S–M |
| P2 | **XSS cluster** (D5-10/D1-15/status_reason) | escape/textContent + sanitasi filename saat upload | 4 | 4 | **16** | S |
| P3 | D5-08 | Refactor god-files → Controller–Service–Repository | 2 | 1 | **2** | L |

---

## 10. ROADMAP ARSITEKTUR

**Fase 0 — Stop the bleeding (1–2 minggu):** P0 di atas (migrasi lengkap, Gatekeeper di upload, fail-closed, guard cron, bootstrap sesi, status check, Turnstile).
**Fase 1 — Hardening (2–6 minggu):** tulis ulang SSRF guard; JSON store terpusat ber-lock + backup sebelum delete; OAuth linking aman; kuota atomik; hapus public-read + purge CDN; XSS cluster fix; error handler terpusat (stop `getMessage` ke klien).
**Fase 2 — Refactor & observability (1–3 bulan):** pecah god-files (`tools.php`, `result.php`, `admin/gallery.php`, `member/result.php`, `dashboard.php`, `api/upload.php`) ke Controller–Service–Repository (`ImageRepository` menggantikan 21 pembaca/8 penulis `images.json` langsung — atau migrasi metadata ke DB dengan index `id/hash/ip/created_at`); hapus dead code (SigV4 duplikat, `user_sessions`, `image_storage`, 4 helper ImageHandler); metrics/alerting + structured logging; SRI untuk CDN front-end + CSP; regression test SSRF & race.

---

## 11. COVERAGE & BATASAN PROSES

- **Cakupan:** 84 file PHP, 4 file SQL, 2 engine Python, docs, git history, aset JS utama. 13 subtask, 2 auditor, 1 agen resolve; 53+ spot-check langsung ke repo; **0 bukti fiktif ditemukan**.
- **Belum diaudit penuh:** `docs.php`, `dmca.php`, `terms.php`, `changelog.php`, `admin/includes/*.css/js`, sebagian `assets/js/app.js`, dependency supply-chain (`composer.lock`, `vendor/`), pengujian dinamis terhadap instance hidup.
- **Konflik auditor yang terselesaikan:** skor 2.6 (DeepSeek) vs 2.5 (GLM) → konsensus **2.5**; severity OAuth CRITICAL→HIGH (diterima kedua pihak); "triple-whammy" tetap HIGH (bukan CRITICAL per kriteria yang disepakati); klaim GLM yang terbantah bukti (2 butir) ditarik.

**Sign-off:** Auditor 1 (DeepSeek): **PASS**. Auditor 2 (GLM): **PASS-DENGAN-CATATAN** untuk kompilasi; **FAIL** untuk kode dalam state saat ini.

---

*Laporan ini dihasilkan oleh workflow multi-agen: 3 planner → debat → 13 subtask auditor paralel → auditor-deepseek (kompilasi) → auditor-glm (review silang) → resolve loop → final check.*
