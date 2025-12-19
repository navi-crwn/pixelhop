<?php
/**
 * PixelHop - Public Changelog
 * What's new and improved for users
 */
$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Changelog - <?= htmlspecialchars($siteName) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            document.documentElement.setAttribute('data-theme', savedTheme || 'dark');
        })();
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
    <link rel="stylesheet" href="/assets/css/glass.css">
    <style>
        .changelog-content { max-width: 800px; margin: 0 auto; }
        .version-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 24px 28px;
            margin-bottom: 24px;
        }
        .version-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .version-badge {
            background: linear-gradient(135deg, var(--color-neon-cyan), var(--color-neon-purple));
            color: #fff;
            font-weight: 700;
            font-size: 14px;
            padding: 4px 12px;
            border-radius: 20px;
        }
        .version-date {
            color: var(--color-text-tertiary);
            font-size: 14px;
        }
        .version-title {
            color: var(--color-text-primary);
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .change-list { list-style: none; padding: 0; margin: 0; }
        .change-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
            color: var(--color-text-secondary);
            line-height: 1.6;
        }
        .change-item:not(:last-child) {
            border-bottom: 1px solid var(--glass-border);
        }
        .change-icon {
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            margin-top: 2px;
        }
        .change-new { color: #22c55e; }
        .change-improved { color: #3b82f6; }
        .change-fixed { color: #f59e0b; }
        .tag {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
            margin-right: 8px;
        }
        .tag-new { background: rgba(34, 197, 94, 0.15); color: #22c55e; }
        .tag-improved { background: rgba(59, 130, 246, 0.15); color: #3b82f6; }
        .tag-fixed { background: rgba(245, 158, 11, 0.15); color: #f59e0b; }
    </style>
</head>
<body class="min-h-screen font-sans">
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)]"></div>
        <div class="blob blob-cyan" style="top: -15%; left: -10%;"></div>
        <div class="blob blob-purple" style="bottom: -15%; right: -10%;"></div>
    </div>

    <header class="fixed top-0 left-0 right-0 z-50 px-4 py-4">
        <nav class="max-w-6xl mx-auto">
            <div class="glass-card glass-card-header flex items-center justify-between px-5 py-3">
                <a href="/" class="flex items-center gap-3">
                    <img src="/assets/img/logo.svg" alt="PixelHop" class="w-8 h-8">
                    <span class="text-lg font-bold" style="color: var(--color-text-primary);">PixelHop</span>
                </a>
                <a href="/" class="btn-primary text-sm">
                    <i data-lucide="home" class="w-4 h-4"></i>
                    Home
                </a>
            </div>
        </nav>
    </header>

    <main class="relative z-10 pt-28 pb-16 px-4">
        <div class="changelog-content">

            <div class="text-center mb-12">
                <h1 class="text-3xl md:text-4xl font-bold mb-3" style="color: var(--color-text-primary);">What's New</h1>
                <p style="color: var(--color-text-tertiary);">Latest updates and improvements to PixelHop</p>
            </div>

            <!-- December 2025 - v2.2 -->
            <div class="version-card">
                <div class="version-header">
                    <span class="version-badge">v2.2</span>
                    <span class="version-date">December 19, 2025</span>
                </div>
                <h2 class="version-title">View Analytics & Content Safety</h2>
                <ul class="change-list">
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>View count tracking — see how many times your images have been viewed</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Report button — help keep PixelHop safe by reporting inappropriate content</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Image info panel — view count, upload date, and last viewed time on image pages</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Sort gallery by "Most Views" to find your popular images</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="zap" class="change-icon change-improved"></i>
                        <span><span class="tag tag-improved">IMPROVED</span>Faster image loading with optimized storage</span>
                    </li>
                </ul>
            </div>

            <!-- December 2025 - v2.1 -->
            <div class="version-card">
                <div class="version-header">
                    <span class="version-badge">v2.1</span>
                    <span class="version-date">December 18, 2025</span>
                </div>
                <h2 class="version-title">Faster & More Reliable</h2>
                <ul class="change-list">
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Hybrid storage system — images load faster with global CDN</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Storage dashboard — see your storage usage at a glance</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="zap" class="change-icon change-improved"></i>
                        <span><span class="tag tag-improved">IMPROVED</span>Better security with enhanced abuse protection</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="zap" class="change-icon change-improved"></i>
                        <span><span class="tag tag-improved">IMPROVED</span>Server performance optimizations</span>
                    </li>
                </ul>
            </div>

            <!-- December 2025 - v2.0 -->
            <div class="version-card">
                <div class="version-header">
                    <span class="version-badge">v2.0</span>
                    <span class="version-date">December 17, 2025</span>
                </div>
                <h2 class="version-title">New Look & Feel</h2>
                <ul class="change-list">
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Beautiful new design with glassmorphism UI</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Dark and Light theme toggle — switch anytime from the header</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="zap" class="change-icon change-improved"></i>
                        <span><span class="tag tag-improved">IMPROVED</span>Responsive design — works great on mobile, tablet, and desktop</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="zap" class="change-icon change-improved"></i>
                        <span><span class="tag tag-improved">IMPROVED</span>User dashboard with better organization</span>
                    </li>
                </ul>
            </div>

            <!-- December 2025 - v1.0 -->
            <div class="version-card">
                <div class="version-header">
                    <span class="version-badge">v1.0</span>
                    <span class="version-date">December 16, 2025</span>
                </div>
                <h2 class="version-title">Initial Launch 🎉</h2>
                <ul class="change-list">
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Free image hosting with instant shareable links</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Image tools: Compress, Resize, Crop, Convert formats</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>AI-powered OCR for text extraction from images</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>AI Background Removal (RemBG)</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>User accounts with Google OAuth sign-in</span>
                    </li>
                    <li class="change-item">
                        <i data-lucide="plus-circle" class="change-icon change-new"></i>
                        <span><span class="tag tag-new">NEW</span>Personal dashboard to manage your uploads</span>
                    </li>
                </ul>
            </div>

            <!-- Subscribe/Follow section -->
            <div class="text-center glass-card p-8 mt-8">
                <i data-lucide="bell" class="w-10 h-10 mx-auto mb-4 text-neon-cyan"></i>
                <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Stay Updated</h3>
                <p class="mb-4" style="color: var(--color-text-tertiary);">Follow us for the latest updates and features</p>
                <div class="flex justify-center gap-3">
                    <a href="https://github.com/navi-crwn/pixelhop" target="_blank" class="btn-secondary text-sm">
                        <i data-lucide="github" class="w-4 h-4"></i>
                        GitHub
                    </a>
                    <a href="/help" class="btn-primary text-sm">
                        <i data-lucide="help-circle" class="w-4 h-4"></i>
                        Help Center
                    </a>
                </div>
            </div>

        </div>
    </main>

    <?php include 'includes/popup-banner.php'; ?>
    <script>lucide.createIcons();</script>
</body>
</html>
