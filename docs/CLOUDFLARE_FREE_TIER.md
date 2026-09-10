# 🎁 Panduan Cloudflare Free Tier untuk PixelHop

## ✅ Fitur yang Sudah Aktif

| Fitur | Status | Kegunaan |
|-------|--------|----------|
| **Turnstile** | ✅ Aktif | Bot protection di login/register |
| **DNS Proxy** | ✅ Aktif | Hide origin IP |
| **DDoS Protection** | ✅ Otomatis | Layer 3/4/7 protection |

---

## 🆓 Fitur Free Tier yang Bisa Diaktifkan

### 1. Cache Rules (Ganti Page Rules)
**Lokasi:** Dashboard > Rules > Cache Rules

Buat rule untuk cache images:
```
Field: URI Path
Operator: starts with
Value: /i/

Then:
- Cache eligibility: Eligible for cache
- Edge TTL: 1 day (86400 seconds, maksimum)
- Browser TTL: 1 year (31536000 seconds)
```

**Manfaat:** Reduce request ke Contabo, images di-cache di 300+ edge locations.

> **Catatan penting:** Karena edge TTL dibatasi 1 hari, konten yang dihapus atau disuspend bisa tetap tersaji dari cache edge hingga TTL habis. Setelah menghapus/mensuspend gambar, lakukan **purge** agar perubahan langsung berlaku — lewat **Cloudflare Purge API** (purge by URL, mis. `https://p.hel.ink/i/...`) atau di dashboard **Caching > Configuration > Purge Everything** (cukup untuk zone kecil). Purge by URL lebih hemat kuota cache ketimbang Purge Everything.

---

### 2. Hotlink Protection
**Lokasi:** Scrape Shield > Hotlink Protection

- Toggle: **ON**
- Ini akan mencegah website lain embed image Anda tanpa izin.

---

### 3. Bot Fight Mode
**Lokasi:** Security > Bots > Bot Fight Mode

- Toggle: **ON**
- Secara otomatis block known bad bots.

---

### 4. Browser Integrity Check
**Lokasi:** Security > Settings

- Toggle: **ON**
- Block requests yang tidak punya valid browser headers.

---

### 5. Firewall Rules (5 Rules Gratis)
**Lokasi:** Security > WAF > Custom Rules

**Contoh Rule 1: Block high-risk countries**
```
(ip.geoip.country in {"CN" "RU" "KP"}) and (http.request.uri.path contains "/api/upload")
Action: Block
```

**Contoh Rule 2: Rate limit uploads**
```
(http.request.uri.path eq "/api/upload.php") and (cf.threat_score gt 30)
Action: Managed Challenge
```

---

### 6. Speed Optimizations
**Lokasi:** Speed > Optimization

- ✅ Auto Minify: CSS, JavaScript
- ✅ Brotli Compression: ON
- ✅ Early Hints: ON
- ✅ Rocket Loader: Optional (test dulu)

---

### 7. SSL/TLS Settings
**Lokasi:** SSL/TLS

- Mode: **Full (Strict)** ← Recommended
- Always Use HTTPS: **ON**
- Automatic HTTPS Rewrites: **ON**
- Minimum TLS Version: **TLS 1.2**

---

### 8. Email Routing (Gratis!)
**Lokasi:** Email > Email Routing

Setup free email forwarding:
- `contact@p.hel.ink` → personal email
- `abuse@p.hel.ink` → personal email
- `support@p.hel.ink` → personal email

---

### 9. Web Analytics (Privacy-Friendly)
**Lokasi:** Analytics > Web Analytics

- Gratis, no JS required
- Privacy-friendly (tidak pakai cookies)
- Server-side analytics

---

### 10. R2 Object Storage
**Lokasi:** R2 Object Storage

Free Tier:
- 10 GB storage
- 1M write operations/month
- 10M read operations/month
- **ZERO egress cost!**

Setup di PixelHop: `/admin/storage.php`

---

## 🔧 Recommended Cloudflare Configuration for PixelHop

### Cache-Control Headers (Add to NGINX)
```nginx
location /i/ {
    # Let Cloudflare cache aggressively
    add_header Cache-Control "public, max-age=31536000, immutable";
    add_header X-Content-Type-Options "nosniff";
}
```

### Page Rules Priority (Legacy, use Cache Rules instead)
1. `/i/*` → Cache Everything, Edge TTL 1 day (86400 seconds)
2. `/api/*` → Bypass Cache
3. `/admin/*` → Bypass Cache

---

## 📊 Free Tier Limits Summary

| Feature | Free Limit |
|---------|-----------|
| DNS Queries | Unlimited |
| SSL Certificates | Unlimited |
| Page Rules | 3 rules |
| Cache Rules | 10 rules |
| Firewall Rules | 5 rules |
| Transform Rules | 10 rules |
| R2 Storage | 10 GB |
| R2 Class A ops | 1M/month |
| R2 Class B ops | 10M/month |
| R2 Egress | Unlimited |
| Workers | 100K requests/day |
| Pages | Unlimited sites |
| Email Routing | 25 addresses |

---

## 🚀 Quick Setup Checklist

- [ ] Enable Hotlink Protection
- [ ] Enable Bot Fight Mode  
- [ ] Enable Browser Integrity Check
- [ ] Create Cache Rule for `/i/*`
- [ ] Enable Auto Minify
- [ ] Enable Brotli
- [ ] Set SSL to Full (Strict)
- [ ] Setup Email Routing (optional)
- [ ] Setup R2 for thumbnails (optional)
- [ ] Add custom Firewall Rules (optional)

---

## ⚠️ Jangan Aktifkan (Bisa Bermasalah)

| Feature | Issue |
|---------|-------|
| Under Attack Mode | Hanya saat benar-benar diserang |
| Rocket Loader | Test dulu, bisa break JavaScript |
| Email Obfuscation | Tidak perlu untuk image hosting |
| Mirage | Pro plan only |
| Polish | Pro plan only |
