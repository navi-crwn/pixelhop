# 🚀 Panduan Setup Cloudflare R2 untuk PixelHop

## Status Saat Ini

✅ **Sudah Selesai:**
- R2StorageManager class dengan safety limit 9.5GB
- R2RateLimiter untuk pembatasan operasi (stay within free tier)
- SecurityFirewall untuk proteksi API
- Database tables untuk tracking
- Admin pages: `/admin/storage.php` dan `/admin/firewall.php`
- Cron jobs untuk cleanup

⚠️ **Perlu Anda Lakukan:**
1. Buat R2 bucket di Cloudflare
2. Generate R2 API Token
3. Update config dengan credentials

---

## Step 1: Buat R2 Bucket

1. Login ke [Cloudflare Dashboard](https://dash.cloudflare.com)
2. Klik **R2 Object Storage** di sidebar
3. Klik **Create bucket**
4. Nama bucket: `pichost-thumbs`
5. Location: **Automatic** (atau pilih Asia Pacific jika ada)
6. Klik **Create bucket**

---

## Step 2: Enable Public Access (Opsional tapi Recommended)

### Opsi A: Custom Domain (Recommended)
1. Di bucket settings, klik **Settings**
2. Scroll ke **Custom Domains**
3. Klik **Connect Domain**
4. Masukkan: `img.p.hel.ink` atau `r2.p.hel.ink`
5. Cloudflare akan auto-setup DNS

### Opsi B: R2.dev Subdomain (Simpler)
1. Di bucket settings, klik **Settings**
2. Scroll ke **R2.dev subdomain**
3. Klik **Allow Access**
4. URL akan jadi: `https://pub-XXXXXXXX.r2.dev`

---

## Step 3: Generate R2 API Token

1. Di R2 dashboard, klik **Manage R2 API Tokens**
2. Klik **Create API token**
3. Settings:
   - Token name: `PixelHop Upload`
   - Permissions: **Object Read & Write**
   - Specify bucket: `pichost-thumbs`
   - TTL: **Forever** (atau set expiry)
4. Klik **Create API Token**
5. **PENTING:** Copy Access Key ID dan Secret Access Key (hanya ditampilkan sekali!)

---

## Step 4: Update Config

Edit file `/var/www/pixelhop/config/s3.php`:

```php
'r2' => [
    'enabled' => true,  // ← Ubah ke true
    'account_id' => 'YOUR_ACCOUNT_ID',
    'endpoint' => 'https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com',
    'region' => 'auto',
    'bucket' => 'pichost-thumbs',
    'access_key' => 'YOUR_ACCESS_KEY_ID',      // ← Paste dari Step 3
    'secret_key' => 'YOUR_SECRET_ACCESS_KEY',  // ← Paste dari Step 3
    'public_url' => 'https://img.p.hel.ink',   // ← Atau R2.dev URL
    
    // Safety limits sudah di-set
    'max_storage_bytes' => 9.5 * 1024 * 1024 * 1024,
    'warning_threshold' => 8 * 1024 * 1024 * 1024,
],
```

---

## Step 5: Test Upload

Test dengan upload gambar kecil. Cek di:
- `/admin/storage.php` - Monitor R2 usage
- Cloudflare R2 dashboard - Lihat files

---

## 🛡️ Safety Features yang Sudah Active

### Storage Limit
```
Max: 9.5 GB (buffer dari 10GB free tier)
Warning: 8 GB
Auto-fallback ke Contabo jika penuh
```

### Rate Limiting (Operations)
```
Daily:
- Class A (write): 30,000 ops/day
- Class B (read): 300,000 ops/day

Monthly:
- Class A: 900,000 ops (dari 1M limit)
- Class B: 9,000,000 ops (dari 10M limit)

Jika limit tercapai → Auto fallback ke Contabo
```

### Security Firewall
```
- Bad bot blocking
- Suspicious pattern detection
- IP rate limiting: 100 req/min, 20 uploads/hour
- Auto-block setelah 10 suspicious events
```

---

## 📊 Monitoring

### Admin Pages
- `/admin/storage.php` - R2 + Contabo usage, rate limits
- `/admin/firewall.php` - Security events, blocked IPs

### Cloudflare Dashboard
- R2 → Analytics → Usage metrics
- R2 → Bucket → Objects (lihat files)

---

## 🔧 Troubleshooting

### R2 Upload Failed
1. Cek credentials di config
2. Cek bucket name benar
3. Cek endpoint URL benar
4. Lihat error di `/var/log/nginx/error.log`

### Rate Limit Reached
Normal behavior - uploads otomatis pindah ke Contabo.
Cek `/admin/storage.php` untuk stats.

### Storage Full
Jika R2 mencapai 9.5GB:
- Thumbnails baru → Contabo
- Tidak ada charge tambahan
- Bisa hapus old files di R2 dashboard

---

## 💰 Cost Summary

| Item | Limit | Cost |
|------|-------|------|
| R2 Storage | 10 GB | FREE |
| R2 Class A ops | 1M/month | FREE |
| R2 Class B ops | 10M/month | FREE |
| R2 Egress | Unlimited | FREE |
| Contabo S3 | Unlimited | (Sudah bayar) |

**Total Additional Cost: $0**

---

## Files Created/Modified

```
includes/
├── R2StorageManager.php    # Hybrid storage manager
├── R2RateLimiter.php       # R2 operations rate limiter
└── SecurityFirewall.php    # API security

admin/
├── storage.php             # Storage monitoring
└── firewall.php            # Security monitoring

config/
└── s3.php                  # R2 config added

database/
├── r2_storage.sql          # Original migration
└── r2_security_migration.sql # Complete migration

cron/
└── security_cleanup.php    # Cleanup job

docs/
├── CLOUDFLARE_FREE_TIER.md # All free tier features
└── EMAIL_ROUTING_SETUP.md  # Email routing guide
```
