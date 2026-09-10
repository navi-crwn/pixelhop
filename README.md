# 🐰 PixelHop

<div align="center">

<img src="https://p.hel.ink/assets/img/logo.svg" alt="PixelHop" width="120">

**Free image hosting with built-in editing tools.**

PHP 8 • Self-hostable • Open Source

[Live Demo](https://p.hel.ink) • [API Docs](https://p.hel.ink/docs) • [Report Bug](https://github.com/navi-crwn/pixelhop/issues)

![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=flat-square&logo=php&logoColor=white)
![TailwindCSS](https://img.shields.io/badge/Tailwind-3.x-38B2AC?style=flat-square&logo=tailwind-css&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-4479A1?style=flat-square&logo=mysql&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-green?style=flat-square)

![Stars](https://img.shields.io/github/stars/navi-crwn/pixelhop?style=flat-square)
![Forks](https://img.shields.io/github/forks/navi-crwn/pixelhop?style=flat-square)
![Issues](https://img.shields.io/github/issues/navi-crwn/pixelhop?style=flat-square)

</div>

---

## Overview

PixelHop is an image hosting platform with integrated image processing tools. Upload images, get permanent shareable links. Compress, resize, crop, convert formats — all in one place. Also includes AI-powered OCR and background removal.

No desktop software needed. Works entirely in the browser.

## Features

- **Image Hosting** — Upload and get shareable links instantly
- **Compress** — Reduce file size while maintaining quality
- **Resize & Crop** — Adjust dimensions with preset ratios (1:1, 4:3, 16:9) or custom
- **Convert** — Switch formats: JPEG ↔ PNG ↔ WebP ↔ GIF ↔ BMP
- **OCR** — Extract text from images using PaddleOCR
- **Remove Background** — AI-powered background removal
- **User Accounts** — Google OAuth login with personal dashboard
- **Admin Panel** — Full control, monitoring, and abuse prevention
- **Hybrid Storage** — R2 + Contabo S3 with automatic failover
- **View Analytics** — Track view counts and last viewed dates
- **Report System** — Users can report inappropriate images
- **Account Moderation** — Lock/Suspend accounts with warnings
- **Auto-Expiration** — Images auto-delete after 90 days of inactivity
- **Self-hostable** — Deploy on your own server, own your data

## User System

User accounts for tracking uploads and managing AI tool access:

| Tier | Storage | OCR/day | RemBG/day |
|------|---------|---------|-----------|
| Guest | - | - | - |
| Free | 500MB | 5x | 3x |
| Premium | Coming Soon | - | - |

> **Note:** Premium tier is currently in development.

## Tech Stack

```
Backend     : PHP 8.1+, MySQL/MariaDB
Frontend    : TailwindCSS, Vanilla JS, Lucide Icons
AI/Python   : PaddleOCR, rembg (background removal)
Storage     : S3-compatible (AWS, MinIO, Contabo, etc.)
Auth        : Google OAuth 2.0, Cloudflare Turnstile
```

## Installation

```bash
# Clone the repository
git clone https://github.com/navi-crwn/pixelhop.git
cd pixelhop

# Copy configuration files
cp config/database.example.php config/database.php
cp config/oauth.example.php config/oauth.php
cp config/s3.example.php config/s3.php

# Import database schema
mysql -u your_user -p your_database < database/schema.sql

# Set up Python environment (for OCR & RemBG)
cd python && python3 -m venv venv
source venv/bin/activate
pip install -r ../requirements.txt

# Set directory permissions
chmod 755 temp/ data/
```

Configure your web server to point to the project root, update the config files with your credentials, and you're ready to go.

## API

All tools are accessible via API, suitable for ShareX integration, custom scripts, or automation.

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/api/upload.php` | POST | Upload image |
| `/api/compress.php` | POST | Compress image |
| `/api/resize.php` | POST | Resize image |
| `/api/crop.php` | POST | Crop image |
| `/api/convert.php` | POST | Convert format |
| `/api/ocr.php` | POST | Extract text |
| `/api/rembg.php` | POST | Remove background |
| `/api/report.php` | POST | Report an image |

## Project Structure

```
pixelhop/
├── admin/          # Admin panel pages
├── api/            # API endpoints
├── assets/         # CSS, JS, images
├── auth/           # Authentication handlers
├── config/         # Configuration files
├── core/           # Core classes (Gatekeeper, AbuseGuard)
├── cron/           # Scheduled tasks
├── includes/       # PHP libraries
├── member/         # Member area pages
├── python/         # Python scripts (OCR, RemBG)
└── temp/           # Temporary files
```

## Cron Jobs

Add to crontab for automatic maintenance:

```bash
# Hourly: cleanup temp files, abuse watchdog
0 * * * * php /path/to/pixelhop/cron/maintenance.php >> /var/log/pixelhop.log 2>&1

# Daily at 2 AM: expire inactive images (90 days)
0 2 * * * php /path/to/pixelhop/cron/image_expiration.php >> /var/log/pixelhop.log 2>&1
```

## Requirements

- PHP 8.1+ with extensions: pdo_mysql, gd/imagick, curl, json, mbstring
- MySQL 5.7+ or MariaDB 10.3+
- Python 3.10+ (for OCR & RemBG)
- S3-compatible storage
- Nginx or Apache

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.

## Credits

See [LICENSE.md](LICENSE.md) for full list of libraries and tools used.

---

<div align="center">

Built by [navi-crwn](https://github.com/navi-crwn)

Part of the [HEL.ink](https://hel.ink) project

</div>
