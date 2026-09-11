# Runbook Cutover Metadata Foto — images.json → tabel `images`

**Target pembaca:** operator non-teknikal + builder.
**Tujuan:** memindahkan penyimpanan metadata foto dari file JSON ke database secara bertahap, aman, dan bisa di-rollback kapan pun.

---

## 1. Konsep: 4 Mode Penyimpanan Metadata Foto

PixelHop menyimpan metadata foto (id, hash, size, `s3_keys`, `view_count`, `delete_at`, dll.) di salah satu backend berikut.
Peralihan antar mode dilakukan bertahap agar selalu ada jalan mundur.

| Mode | Baca dari | Tulis ke | JSON authoritative? | Kapan dipakai |
|------|-----------|----------|---------------------|---------------|
| `json` | JSON (`data/images.json`) | JSON | Ya | Default / sebelum migrasi / rollback darurat |
| `dual_write` | JSON | JSON **lalu** DB (DB best-effort) | **Ya** | Fase transisi awal; DB dibangun sebagai bayangan |
| `db_primary` | DB dulu, fallback JSON | DB **lalu** JSON (JSON best-effort) | **DB** (JSON cadangan) | Setelah drift stabil 0; mulai percaya DB |
| `db_only` | DB | DB | Ya (JSON dibekukan) | Cutover final; JSON jadi arsip |

Catatan penting:
- Pada `dual_write`, **JSON tetap sumber kebenaran**. Jika DB gagal ditulis, operasi tetap sukses, tapi `Alerter` mengirim peringatan — ini normal selama DB baru dibangun.
- Pada `db_primary`, DB menjadi sumber kebenaran. Jika DB gagal, sistem **fallback ke JSON** (mode `db_primary`) atau **kembali ke mode `json`** (jika DB benar-benar down — lihat bagian rollback).
- Pada `db_only`, JSON tidak lagi dipakai membaca/menulis. Jangan masuk mode ini sebelum verifikasi fallback-hit = 0.

---

## 2. Cara Ganti Mode

Mode dibaca oleh `ImageRepository` dengan prioritas:

```
env IMAGE_STORE_MODE  >  site_settings.images_store_mode  >  default json
```

Artinya:
- Jika env `IMAGE_STORE_MODE` diisi, nilai itu yang menang.
- Jika env kosong, sistem membaca `site_settings.images_store_mode` dari database.
- Jika keduanya tidak ada, sistem memakai `json`.

### 2a. Ganti via database (`site_settings`)

```sql
-- Jika baris setting-nya belum ada:
INSERT INTO site_settings (setting_key, setting_value, setting_type, description)
VALUES ('images_store_mode', 'dual_write', 'string', 'Mode penyimpanan metadata foto')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Jika baris setting-nya sudah ada (cara paling umum):
UPDATE site_settings SET setting_value='dual_write' WHERE setting_key='images_store_mode';
```

Ganti `'dual_write'` sesuai kebutuhan: `json`, `dual_write`, `db_primary`, atau `db_only`.

### 2b. Ganti via environment variable

```bash
export IMAGE_STORE_MODE=dual_write
```

Ini berguna untuk uji cepat pada satu request/script tanpa mengubah database. Nilai invalid otomatis dianggap `json` dan dicatat di log.

> **PENTING:** Karena prioritasnya env > site_settings, pastikan env `IMAGE_STORE_MODE` di server produksi KOSONG saat Anda ingin mengendalikan mode lewat database.

---

## 3. Prosedur Cutover yang Aman (Health-Gated)

### 3a. Pastikan migrasi backfill sukses + verify 0 mismatch

```bash
# 1) Backfill data images.json ke tabel images (idempotent/resumable)
php scripts/migrate_images_to_db.php --backfill

# 2) Verifikasi hasil migrasi (field-per-field)
php scripts/migrate_images_to_db.php --verify
```

Syarat lanjut:
- `--backfill` selesai dengan `done=<total>` dan `failed=0`.
- `--verify` selesai dengan `mismatched=0` (baris DB ekstra boleh ada, tapi wajib dicatat/ditelaah).

### 3b. Set mode `dual_write`

```sql
UPDATE site_settings SET setting_value='dual_write' WHERE setting_key='images_store_mode';
```

Perilaku setelah aktif: setiap upload/view/delete menulis ke JSON dulu (sumber kebenaran), lalu ke DB. DB gagal tidak menggagalkan operasi.

### 3c. Jalankan drift checker berkala — wajib `mismatched=0`

```bash
php cron/images_drift_check.php
```

Output sehat yang diharapkan:

```
Summary: {"total_json":N,"total_db":N,"matched":N,"mismatched":0,"json_only":0,"db_only":0}
Sinkron
```

Output tidak sehat (script exit code 1 + mengirim alert):

```
MISMATCH id=abc field=size expected=100 actual=99
JSON_ONLY id=def
DB_ONLY id=ghi
Drift terdeteksi
```

Jadwalkan via cron tiap 15 menit:

```cron
*/15 * * * * php /path/ke/pixelhop/cron/images_drift_check.php >> /path/ke/pixelhop/data/logs/drift_check.log 2>&1
```

> Alternatif manual: jalankan script di atas kapan pun ingin memastikan JSON dan DB sinkron.

### 3d. Setelah drift stabil 0 (beberapa siklus + upload uji)

1. Pastikan drift checker sudah beberapa kali berturut-turut `mismatched=0`.
2. Lakukan 1–2 upload uji, lalu jalankan lagi `php cron/images_drift_check.php` — harus tetap `mismatched=0`.
3. Set mode `db_primary`:

```sql
UPDATE site_settings SET setting_value='db_primary' WHERE setting_key='images_store_mode';
```

4. Verifikasi halaman tetap render benar (lihat bagian 5). Pastikan **fallback-hit = 0** di log:
   - Pantau log `data/logs/app-*.jsonl` untuk channel `images_store_fallback`.
   - Jika ada entri fallback, itu tanda masih ada record yang tidak ditemukan di DB — jangan lanjut ke `db_only` sebelum bersih.

### 3e. Setelah yakin fallback-hit = 0, set mode `db_only` + bekukan images.json

```sql
UPDATE site_settings SET setting_value='db_only' WHERE setting_key='images_store_mode';
```

Bekukan JSON lama sebagai arsip:

```bash
mv data/images.json data/images.json.legacy.bak
```

> Setelah ini, backup harian tetap mencadangkan database (bukan hanya JSON). Jangan hapus `images.json.legacy.bak` sebelum masa transisi selesai dan diyakini aman.

---

## 4. Prosedur Rollback per Mode

### 4a. `dual_write` bermasalah → kembali ke `json`

```sql
UPDATE site_settings SET setting_value='json' WHERE setting_key='images_store_mode';
```

Alasan aman: selama `dual_write`, JSON selalu ditulis lebih dulu dan menjadi sumber kebenaran. DB hanyalah bayangan; kembali ke `json` tidak kehilangan data.

### 4b. `db_primary` bermasalah → kembali ke `dual_write` atau `json`

```sql
-- Pilihan 1: kembali ke dual_write (JSON masih dianggap benar)
UPDATE site_settings SET setting_value='dual_write' WHERE setting_key='images_store_mode';

-- Pilihan 2: langsung ke json (paling konservatif)
UPDATE site_settings SET setting_value='json' WHERE setting_key='images_store_mode';
```

Alasan aman: pada `db_primary`, JSON tetap ikut ditulis (best-effort). Jika ragu, pilih `json`.

### 4c. `db_only` bermasalah → restore dari DB ke JSON, lalu set `json`

```bash
# Jika tersedia:
php scripts/export_db_to_images_json.php

# Jika script di atas belum ada, restore dari backup:
# 1) Ambil dump DB terbaru, lalu generate images.json dari tabel images, atau
# 2) Salin data/images.json.legacy.bak + terapkan delta dari dump DB.
```

Lalu set mode kembali:

```sql
UPDATE site_settings SET setting_value='json' WHERE setting_key='images_store_mode';
```

### 4d. DB down → sistem otomatis kembali ke `json`

`ImageRepository` membaca env > `site_settings` > default `json`. Jika database mati:
- Pembacaan `site_settings.images_store_mode` gagal → resolver otomatis memakai mode `json` untuk baca/tulis.
- Pada mode `db_primary` yang sudah terlanjur terekam, fallback baca ke JSON menjaga halaman tetap render; operasi tulis DB akan melempar error dan halaman sebaiknya diarahkan ke `json` sampai DB pulih.

Saat DB pulih, jalankan kembali drift checker dan `--backfill`/`--verify` untuk memastikan tidak ada perubahan yang tertinggal.

---

## 5. Verifikasi Pasca-Cutover

Jalankan setelah setiap perpindahan mode (sesuaikan host):

```bash
# Halaman view / i (gambar terproses)
curl -o /dev/null -s -w '%{http_code} /i/\n' https://pixelhop.example/i/

# Halaman view (metadata + render)
curl -o /dev/null -s -w '%{http_code} /view.php\n' https://pixelhop.example/view.php

# Galeri publik
curl -o /dev/null -s -w '%{http_code} /gallery.php\n' https://pixelhop.example/gallery.php

# Dashboard (sesi login diperlukan — cek render bukan redirect/error)
curl -o /dev/null -s -w '%{http_code} /dashboard.php\n' https://pixelhop.example/dashboard.php

# Endpoint health
curl -o /dev/null -s -w '%{http_code} /health.php\n' https://pixelhop.example/health.php
```

Cek fungsional:
- Upload sukses (khususnya setelah mode `dual_write`/`db_primary`) — file muncul di galeri.
- View count bertambah setelah halaman view dibuka.
- Halaman view/gallery/dashboard/i.php mengembalikan HTTP 200 (atau 302 yang wajar untuk halaman login).
- `/health.php` mengembalikan 200.
- Tidak ada error di `data/logs/app-*.jsonl` dengan channel `images_store_fallback` (khusus `db_primary`).

---

## 6. Tabel Mode → Perilaku Baca/Tulis → Kapan Dipakai

| Mode | Baca | Tulis | Sumber kebenaran | Kegagalan DB | Kapan dipakai |
|------|------|-------|------------------|--------------|---------------|
| `json` | JSON | JSON | JSON | Tidak relevan (tidak menyentuh DB) | Default, sebelum migrasi, rollback |
| `dual_write` | JSON | JSON dulu, lalu DB (best-effort) | JSON | Operasi tetap sukses; log + alert | Membangun DB bayangan sambil melayani request |
| `db_primary` | DB dulu, fallback JSON | DB dulu, lalu JSON (best-effort) | DB (JSON cadangan) | Fallback baca ke JSON | Setelah drift stabil 0; masa transisi menuju DB |
| `db_only` | DB | DB | DB | Request baca/tulis DB gagal | Cutover final setelah fallback-hit = 0 |
