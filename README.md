# PixelHop

A modern, fast, and privacy-focused image hosting platform with powerful image processing tools.

![PixelHop](https://p.hel.ink/assets/img/logo.svg)

## Features

### Image Hosting
- Fast image upload with drag & drop support
- Multiple image sizes automatically generated (thumb, medium, large, original)
- WebP conversion for optimized delivery
- Permanent hosting with shareable links

### Image Tools
- **Compress** - Reduce file size without losing quality
- **Resize** - Change dimensions with aspect ratio lock
- **Crop** - Crop to standard ratios (1:1, 4:3, 16:9) or custom
- **Convert** - Convert between JPEG, PNG, WebP, GIF, BMP
- **OCR** - Extract text from images (AI-powered)
- **Remove Background** - AI-powered background removal

### User System
- Free & Premium accounts
- Google OAuth integration
- Personal dashboard with upload history
- Storage quotas (500MB free, 5GB premium)
- Daily limits for AI tools

### Admin Panel
- Dashboard with system monitoring
- User management
- Tool enable/disable controls
- Gallery view of all uploads
- Abuse prevention & IP blocking
- SEO settings

## Requirements

- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.3+
- Nginx or Apache
- Python 3.10+ (for OCR and RemBG)
- S3-compatible object storage

### PHP Extensions
- pdo_mysql
- gd or imagick
- curl
- json
- mbstring

### Python Dependencies
```
pytesseract
rembg
pillow
```

## Installation

1. Clone the repository:
```bash
git clone https://github.com/yourusername/pixelhop.git
cd pixelhop
```

2. Copy config files and update with your credentials:
```bash
cp config/database.example.php config/database.php
cp config/oauth.example.php config/oauth.php
cp config/s3.example.php config/s3.php
```

3. Import the database schema:
```bash
mysql -u your_user -p your_database < database/schema.sql
```

4. Set up Python virtual environment:
```bash
cd python
python3 -m venv venv
source venv/bin/activate
pip install -r ../requirements.txt
```

5. Configure your web server to point to the project root.

6. Set proper permissions:
```bash
chmod 755 temp/
chmod 755 data/
```

## Configuration

### Database (config/database.php)
- MySQL/MariaDB connection settings

### OAuth (config/oauth.php)
- Google OAuth credentials
- SMTP settings for email
- Cloudflare Turnstile keys

### S3 Storage (config/s3.php)
- S3-compatible storage endpoint
- Bucket and credentials
- Upload limits and image sizes

## API Endpoints

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/upload.php` | POST | Upload image |
| `/api/compress.php` | POST | Compress image |
| `/api/resize.php` | POST | Resize image |
| `/api/crop.php` | POST | Crop image |
| `/api/convert.php` | POST | Convert format |
| `/api/ocr.php` | POST | Extract text |
| `/api/rembg.php` | POST | Remove background |

## Directory Structure

```
pixelhop/
├── admin/          # Admin panel pages
├── api/            # API endpoints
├── assets/         # CSS, JS, images
├── auth/           # Authentication handlers
├── config/         # Configuration files
├── core/           # Core classes
├── cron/           # Scheduled tasks
├── data/           # Data storage
├── database/       # SQL schema
├── includes/       # PHP libraries
├── member/         # Member area pages
├── nginx/          # Nginx config
├── python/         # Python scripts
└── temp/           # Temporary files
```

## Cron Jobs

Add to crontab for automatic maintenance:
```bash
# Hourly maintenance (cleanup, abuse watchdog)
0 * * * * php /path/to/pixelhop/cron/maintenance.php >> /var/log/pixelhop-cron.log 2>&1
```

## License

MIT License - See LICENSE file for details.

## Credits

- Built with PHP 8, TailwindCSS, and Lucide Icons
- OCR powered by Tesseract
- Background removal powered by rembg
- Object storage with S3-compatible providers
