<?php
/**
 * PixelHop - Home Page
 * Image hosting with powerful tools
 */
session_start();
require_once __DIR__ . '/auth/middleware.php';

$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];

// Check authentication state
$isLoggedIn = isAuthenticated();
$currentUser = $isLoggedIn ? getCurrentUser() : null;
$isAdmin = $isLoggedIn && isAdmin();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="PixelHop - Free premium image hosting with powerful tools. Compress, resize, crop, and extract text from images.">
    <meta name="keywords" content="image hosting, image compressor, image resizer, OCR, free hosting">
    <meta name="author" content="PixelHop">
    <meta name="theme-color" content="#0a0f1c" id="theme-color-meta">

    <!-- Open Graph -->
    <meta property="og:title" content="PixelHop - Your Pixel is A Hop Away">
    <meta property="og:description" content="Free premium image hosting with powerful tools">
    <meta property="og:image" content="https://p.hel.ink/assets/img/og-image.png">
    <meta property="og:url" content="https://p.hel.ink">
    <meta property="og:type" content="website">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="PixelHop - Your Pixel is A Hop Away">
    <meta name="twitter:description" content="Free premium image hosting with powerful tools">

    <title>PixelHop - Your Pixel is A Hop Away</title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">

    <!-- Prevent FOUC (Flash of Unstyled Content) for theme -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || (prefersDark ? 'dark' : 'dark');
            document.documentElement.setAttribute('data-theme', theme);
            if (theme === 'light') {
                document.getElementById('theme-color-meta')?.setAttribute('content', '#f0f4f8');
            }

            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations().then(function(registrations) {
                    for (let registration of registrations) {
                        registration.unregister();
                    }
                });
            }
        })();
    </script>

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'void': 'var(--color-bg-primary)',
                        'void-light': 'var(--color-bg-secondary)',
                        'glass': 'var(--glass-bg)',
                        'glass-border': 'var(--glass-border)',
                        'glass-hover': 'var(--glass-bg-hover)',
                        'neon-cyan': '#22d3ee',
                        'neon-purple': '#a855f7',
                        'neon-pink': '#ec4899',
                    },
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'glow': 'glow 2s ease-in-out infinite alternate',
                        'blob': 'blob 7s infinite',
                    },
                    backdropBlur: {
                        'glass': '24px',
                        'glass-heavy': '48px',
                    }
                }
            }
        }
    </script>

    <!-- Google Fonts - Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/css/glass.css?v=1.1.0">
</head>
<body class="min-h-screen font-sans overflow-x-hidden transition-colors duration-500">

    <!-- Animated Background -->
    <div id="bg-container" class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <!-- Gradient Mesh Background -->
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)] transition-colors duration-500"></div>

        <!-- Floating Glow Blobs -->
        <div id="blob-1" class="blob blob-cyan"></div>
        <div id="blob-2" class="blob blob-purple"></div>
        <div id="blob-3" class="blob blob-pink"></div>
        <div id="blob-4" class="blob blob-cyan-sm"></div>
        <div id="blob-5" class="blob blob-purple-sm"></div>

        <!-- Noise Texture Overlay -->
        <div class="absolute inset-0 opacity-[0.015] bg-noise"></div>
    </div>

    <!-- Header -->
    <header id="header" class="fixed top-0 left-0 right-0 z-50 px-4 py-4">
        <nav class="max-w-7xl mx-auto">
            <div class="glass-card glass-card-header flex items-center justify-between px-6 py-3">
                <!-- Logo & Brand -->
                <a href="/" class="flex items-center gap-3 group">
                    <div class="w-10 h-10 relative">
                        <img src="/assets/img/logo.svg" alt="PixelHop" class="w-full h-full transition-transform duration-300 group-hover:scale-110">
                    </div>
                    <span class="text-xl font-bold header-brand">
                        PixelHop
                    </span>
                </a>

                <!-- Tools Navigation - Glass Pills -->
                <div class="hidden md:flex items-center gap-2">
                    <a href="/tools?open=compress" class="tool-pill group">
                        <i data-lucide="archive" class="w-4 h-4 text-neon-cyan group-hover:text-white transition-colors"></i>
                        <span>Compress</span>
                    </a>
                    <a href="/tools?open=resize" class="tool-pill group">
                        <i data-lucide="scaling" class="w-4 h-4 text-neon-purple group-hover:text-white transition-colors"></i>
                        <span>Resize</span>
                    </a>
                    <a href="/tools?open=crop" class="tool-pill group">
                        <i data-lucide="crop" class="w-4 h-4 text-neon-pink group-hover:text-white transition-colors"></i>
                        <span>Crop</span>
                    </a>
                    <a href="/tools?open=convert" class="tool-pill group">
                        <i data-lucide="repeat" class="w-4 h-4 text-neon-cyan group-hover:text-white transition-colors"></i>
                        <span>Convert</span>
                    </a>
                    <a href="/tools?open=ocr" class="tool-pill group">
                        <i data-lucide="scan-text" class="w-4 h-4 text-neon-purple group-hover:text-white transition-colors"></i>
                        <span>OCR</span>
                    </a>
                    <a href="/tools?open=rembg" class="tool-pill group">
                        <i data-lucide="eraser" class="w-4 h-4 text-neon-pink group-hover:text-white transition-colors"></i>
                        <span>Remove BG</span>
                    </a>
                </div>

                <!-- Right Side - Theme Toggle, Auth & Mobile Menu -->
                <div class="flex items-center gap-2">
                    <!-- Theme Toggle Button -->
                    <button id="theme-toggle" class="theme-toggle-btn" aria-label="Toggle theme" title="Toggle theme">
                        <i data-lucide="sun" class="theme-icon-light w-4 h-4"></i>
                        <i data-lucide="moon" class="theme-icon-dark w-4 h-4"></i>
                    </button>

                    <?php if ($isLoggedIn): ?>
                    <!-- User Menu (Logged In) -->
                    <div class="relative" id="user-menu-container">
                        <button id="user-menu-btn" class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-xl text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-all">
                            <div class="w-7 h-7 rounded-full bg-gradient-to-br from-neon-cyan to-neon-purple flex items-center justify-center text-white text-xs font-bold">
                                <?= strtoupper(substr($currentUser['email'], 0, 1)) ?>
                            </div>
                            <span class="text-sm font-medium max-w-[100px] truncate"><?= htmlspecialchars(explode('@', $currentUser['email'])[0]) ?></span>
                            <i data-lucide="chevron-down" class="w-4 h-4"></i>
                        </button>
                        <div id="user-menu-dropdown" class="absolute right-0 top-full mt-2 w-48 glass-card py-2 hidden">
                            <?php if ($isAdmin): ?>
                            <a href="/admin/dashboard.php" class="flex items-center gap-3 px-4 py-2 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-colors">
                                <i data-lucide="shield" class="w-4 h-4 text-neon-purple"></i>
                                Admin Dashboard
                            </a>
                            <?php endif; ?>
                            <a href="/dashboard.php" class="flex items-center gap-3 px-4 py-2 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-colors">
                                <i data-lucide="layout-dashboard" class="w-4 h-4 text-neon-cyan"></i>
                                My Dashboard
                            </a>
                            <a href="/my-images.php" class="flex items-center gap-3 px-4 py-2 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-colors">
                                <i data-lucide="images" class="w-4 h-4 text-neon-pink"></i>
                                My Images
                            </a>
                            <div class="border-t my-2" style="border-color: var(--glass-border);"></div>
                            <a href="/auth/logout.php" class="flex items-center gap-3 px-4 py-2 text-sm text-red-400 hover:text-red-300 hover:bg-[var(--glass-bg-hover)] transition-colors">
                                <i data-lucide="log-out" class="w-4 h-4"></i>
                                Logout
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Guest Menu -->
                    <a href="/login.php" class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-xl text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-all">
                        <i data-lucide="log-in" class="w-4 h-4"></i>
                        <span class="text-sm font-medium">Login</span>
                    </a>
                    <a href="/register.php" class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-xl bg-gradient-to-r from-neon-cyan to-neon-purple text-white hover:opacity-90 transition-all">
                        <i data-lucide="user-plus" class="w-4 h-4"></i>
                        <span class="text-sm font-medium">Register</span>
                    </a>
                    <?php endif; ?>

                    <!-- Mobile Menu Button -->
                    <button id="mobile-menu-btn" class="md:hidden glass-button p-2">
                        <i data-lucide="menu" class="w-5 h-5"></i>
                    </button>
                </div>
            </div>

            <!-- Mobile Menu -->
            <div id="mobile-menu" class="md:hidden mt-2 glass-card p-4 hidden">
                <div class="flex flex-col gap-2">
                    <a href="/tools?open=compress" class="tool-pill-mobile">
                        <i data-lucide="archive" class="w-4 h-4 text-neon-cyan"></i>
                        <span>Compress</span>
                    </a>
                    <a href="/tools?open=resize" class="tool-pill-mobile">
                        <i data-lucide="scaling" class="w-4 h-4 text-neon-purple"></i>
                        <span>Resize</span>
                    </a>
                    <a href="/tools?open=crop" class="tool-pill-mobile">
                        <i data-lucide="crop" class="w-4 h-4 text-neon-pink"></i>
                        <span>Crop</span>
                    </a>
                    <a href="/tools?open=convert" class="tool-pill-mobile">
                        <i data-lucide="repeat" class="w-4 h-4 text-neon-cyan"></i>
                        <span>Convert</span>
                    </a>
                    <a href="/tools?open=ocr" class="tool-pill-mobile">
                        <i data-lucide="scan-text" class="w-4 h-4 text-neon-purple"></i>
                        <span>OCR</span>
                    </a>
                    <a href="/tools?open=rembg" class="tool-pill-mobile">
                        <i data-lucide="eraser" class="w-4 h-4 text-neon-pink"></i>
                        <span>Remove BG</span>
                    </a>
                    <div class="border-t my-2" style="border-color: var(--glass-border);"></div>
                    <?php if ($isLoggedIn): ?>
                    <?php if ($isAdmin): ?>
                    <a href="/admin/dashboard.php" class="tool-pill-mobile">
                        <i data-lucide="shield" class="w-4 h-4 text-neon-purple"></i>
                        <span>Admin Dashboard</span>
                    </a>
                    <?php endif; ?>
                    <a href="/dashboard.php" class="tool-pill-mobile">
                        <i data-lucide="layout-dashboard" class="w-4 h-4 text-neon-cyan"></i>
                        <span>My Dashboard</span>
                    </a>
                    <a href="/auth/logout.php" class="tool-pill-mobile text-red-400">
                        <i data-lucide="log-out" class="w-4 h-4"></i>
                        <span>Logout</span>
                    </a>
                    <?php else: ?>
                    <a href="/login.php" class="tool-pill-mobile">
                        <i data-lucide="log-in" class="w-4 h-4" style="color: var(--color-text-tertiary);"></i>
                        <span>Login</span>
                    </a>
                    <a href="/register.php" class="tool-pill-mobile">
                        <i data-lucide="user-plus" class="w-4 h-4 text-neon-cyan"></i>
                        <span>Register</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="relative z-10 pt-28 pb-20 px-4">
        <div class="max-w-6xl mx-auto">

            <!-- Hero Section -->
            <section class="text-center mb-12">
                <h1 class="text-4xl md:text-6xl font-bold mb-4">
                    <span class="hero-title-primary">
                        Your Pixel is
                    </span>
                    <br>
                    <span class="bg-gradient-to-r from-neon-cyan via-neon-purple to-neon-pink bg-clip-text text-transparent">
                        A Hop Away
                    </span>
                </h1>
                <p class="text-lg md:text-xl max-w-2xl mx-auto" style="color: var(--color-text-secondary);">
                    Free premium image hosting with powerful tools.
                    Upload, compress, resize, and share your images instantly.
                </p>
            </section>

            <!-- Main Stage Container -->
            <section id="main-stage" class="glass-stage mx-auto max-w-4xl">

                <!-- Upload Zone -->
                <div id="upload-zone" class="upload-zone group">
                    <div class="upload-zone-inner">
                        <!-- Upload Icon -->
                        <div class="upload-icon-container mb-6">
                            <div class="upload-icon">
                                <i data-lucide="cloud-upload" class="w-12 h-12 text-neon-cyan"></i>
                            </div>
                            <div class="upload-icon-ring"></div>
                        </div>

                        <!-- Upload Text -->
                        <h3 class="text-xl font-semibold mb-2" style="color: var(--color-text-primary);">
                            Drop your images here
                        </h3>
                        <p class="text-sm mb-4" style="color: var(--color-text-tertiary);">
                            or click to browse from your device
                        </p>

                        <!-- File Info -->
                        <div class="flex items-center justify-center gap-4 text-xs" style="color: var(--color-text-muted);">
                            <span class="flex items-center gap-1">
                                <i data-lucide="image" class="w-3 h-3"></i>
                                JPG, PNG, GIF, WebP
                            </span>
                            <span class="flex items-center gap-1">
                                <i data-lucide="hard-drive" class="w-3 h-3"></i>
                                Max 10 MB
                            </span>
                        </div>

                        <!-- Hidden File Input -->
                        <input type="file" id="file-input" class="hidden" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                    </div>

                    <!-- Drag Active State Overlay -->
                    <div id="drag-overlay" class="drag-overlay">
                        <div class="drag-overlay-content">
                            <i data-lucide="download" class="w-16 h-16 text-neon-cyan animate-bounce"></i>
                            <span class="text-xl font-medium text-white">Release to upload</span>
                        </div>
                    </div>
                </div>

                <!-- Delete Schedule Option - Custom Glass Dropdown -->
                <div id="delete-schedule-container" class="delete-schedule-wrapper">
                    <label class="delete-schedule-label">
                        <i data-lucide="clock" class="w-4 h-4"></i>
                        <span>Auto-delete after:</span>
                    </label>
                    <div class="glass-dropdown" id="delete-dropdown">
                        <button type="button" class="glass-dropdown-trigger" id="delete-dropdown-trigger">
                            <span id="delete-dropdown-text">Never</span>
                            <i data-lucide="chevron-down" class="glass-dropdown-arrow"></i>
                        </button>
                        <div class="glass-dropdown-menu" id="delete-dropdown-menu">
                            <div class="glass-dropdown-option selected" data-value="never">
                                <i data-lucide="infinity" class="w-4 h-4"></i>
                                <span>Never</span>
                            </div>
                            <div class="glass-dropdown-option" data-value="1h">
                                <i data-lucide="clock" class="w-4 h-4"></i>
                                <span>1 hour</span>
                            </div>
                            <div class="glass-dropdown-option" data-value="24h">
                                <i data-lucide="clock-1" class="w-4 h-4"></i>
                                <span>24 hours</span>
                            </div>
                            <div class="glass-dropdown-option" data-value="7d">
                                <i data-lucide="calendar" class="w-4 h-4"></i>
                                <span>7 days</span>
                            </div>
                            <div class="glass-dropdown-option" data-value="30d">
                                <i data-lucide="calendar-days" class="w-4 h-4"></i>
                                <span>30 days</span>
                            </div>
                        </div>
                        <!-- Hidden input for form submission -->
                        <input type="hidden" id="delete-schedule" value="never">
                    </div>
                </div>

                <!-- Upload Progress (Hidden by default) -->
                <div id="upload-progress" class="upload-progress hidden">
                    <div class="upload-progress-bar">
                        <div id="progress-fill" class="upload-progress-fill"></div>
                    </div>
                    <div class="flex items-center justify-between mt-3">
                        <span id="progress-text" class="text-sm text-white/60">Uploading...</span>
                        <span id="progress-percent" class="text-sm font-medium text-neon-cyan">0%</span>
                    </div>
                </div>

                <!-- Upload Result (Hidden by default) -->
                <div id="upload-result" class="upload-result hidden">
                    <!-- Will be populated by JavaScript -->
                </div>

                <!-- Bento Grid Tools -->
                <div id="tools-grid" class="bento-grid mt-8">
                    <!-- Row 1: Compress (span 2) + Resize + Convert -->

                    <!-- Compress Tool Card - Span 2 -->
                    <a href="/tools?open=compress" class="bento-card bento-card-span-2 group" data-tool="compress">
                        <div class="bento-card-glow bento-glow-cyan"></div>
                        <div class="bento-card-content">
                            <div class="bento-icon bento-icon-cyan">
                                <i data-lucide="archive" class="w-5 h-5"></i>
                            </div>
                            <div class="bento-info">
                                <h3 class="bento-title">Compress</h3>
                                <p class="bento-desc">Reduce file size without losing quality</p>
                            </div>
                            <div class="bento-arrow">
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </div>
                        </div>
                    </a>

                    <!-- Resize Tool Card -->
                    <a href="/tools?open=resize" class="bento-card group" data-tool="resize">
                        <div class="bento-card-glow bento-glow-purple"></div>
                        <div class="bento-card-content">
                            <div class="bento-icon bento-icon-purple">
                                <i data-lucide="scaling" class="w-5 h-5"></i>
                            </div>
                            <div class="bento-info">
                                <h3 class="bento-title">Resize</h3>
                                <p class="bento-desc">Change dimensions</p>
                            </div>
                        </div>
                    </a>

                    <!-- Convert Tool Card -->
                    <a href="/tools?open=convert" class="bento-card group" data-tool="convert">
                        <div class="bento-card-glow bento-glow-cyan"></div>
                        <div class="bento-card-content">
                            <div class="bento-icon bento-icon-cyan">
                                <i data-lucide="repeat" class="w-5 h-5"></i>
                            </div>
                            <div class="bento-info">
                                <h3 class="bento-title">Convert</h3>
                                <p class="bento-desc">Change format</p>
                            </div>
                        </div>
                    </a>

                    <!-- Row 2: Crop + OCR + Remove BG -->

                    <!-- Crop Tool Card -->
                    <a href="/tools?open=crop" class="bento-card group" data-tool="crop">
                        <div class="bento-card-glow bento-glow-pink"></div>
                        <div class="bento-card-content">
                            <div class="bento-icon bento-icon-pink">
                                <i data-lucide="crop" class="w-5 h-5"></i>
                            </div>
                            <div class="bento-info">
                                <h3 class="bento-title">Crop</h3>
                                <p class="bento-desc">Trim images</p>
                            </div>
                        </div>
                    </a>

                    <!-- OCR Tool Card -->
                    <a href="/tools?open=ocr" class="bento-card group" data-tool="ocr">
                        <div class="bento-card-glow bento-glow-purple"></div>
                        <div class="bento-card-content">
                            <div class="bento-icon bento-icon-purple">
                                <i data-lucide="scan-text" class="w-5 h-5"></i>
                            </div>
                            <div class="bento-info">
                                <h3 class="bento-title">OCR</h3>
                                <p class="bento-desc">Extract text</p>
                            </div>
                        </div>
                    </a>

                    <!-- Remove BG Tool Card (NEW) -->
                    <a href="/tools?open=rembg" class="bento-card bento-card-span-2 group" data-tool="rembg">
                        <div class="bento-card-glow bento-glow-pink"></div>
                        <div class="bento-card-content">
                            <div class="bento-icon bento-icon-gradient">
                                <i data-lucide="eraser" class="w-5 h-5"></i>
                            </div>
                            <div class="bento-info">
                                <h3 class="bento-title">Remove Background</h3>
                                <p class="bento-desc">AI-powered background removal in seconds</p>
                            </div>
                            <div class="bento-badge">AI</div>
                            <div class="bento-arrow">
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </div>
                        </div>
                    </a>
                </div>

            </section>

            <!-- Features Section -->
            <section class="mt-20 grid md:grid-cols-3 gap-6">
                <div class="glass-card feature-card p-6 text-center group hover:scale-105 transition-transform duration-300">
                    <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-neon-cyan/20 to-neon-cyan/5 flex items-center justify-center">
                        <i data-lucide="zap" class="w-7 h-7 text-neon-cyan"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Lightning Fast</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">Upload and share in seconds with our optimized CDN</p>
                </div>

                <div class="glass-card feature-card p-6 text-center group hover:scale-105 transition-transform duration-300">
                    <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-neon-purple/20 to-neon-purple/5 flex items-center justify-center">
                        <i data-lucide="shield-check" class="w-7 h-7 text-neon-purple"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Secure Storage</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">Your images are stored safely on enterprise-grade servers</p>
                </div>

                <div class="glass-card feature-card p-6 text-center group hover:scale-105 transition-transform duration-300">
                    <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-neon-pink/20 to-neon-pink/5 flex items-center justify-center">
                        <i data-lucide="infinity" class="w-7 h-7 text-neon-pink"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Forever Free</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">No hidden costs, no subscriptions required</p>
                </div>
            </section>

        </div>
    </main>

    <!-- Footer -->
    <footer class="relative z-10 mt-auto">
        <div class="glass-footer">
            <div class="max-w-7xl mx-auto px-6 py-12">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-8 lg:gap-12">

                    <!-- Brand Column -->
                    <div class="lg:col-span-2">
                        <a href="/" class="flex items-center gap-3 mb-4">
                            <img src="/assets/img/logo.svg" alt="PixelHop" class="w-10 h-10">
                            <span class="text-xl font-bold" style="color: var(--color-text-primary);">PixelHop</span>
                        </a>
                        <p class="text-sm leading-relaxed max-w-sm" style="color: var(--color-text-tertiary);">
                            Free image hosting and tools. Upload, compress, resize, and share your images with the world.
                        </p>

                        <!-- Social Links -->
                        <div class="flex items-center gap-3 mt-6">
                            <a href="#" class="glass-icon-btn" aria-label="Twitter">
                                <i data-lucide="twitter" class="w-4 h-4"></i>
                            </a>
                            <a href="#" class="glass-icon-btn" aria-label="GitHub">
                                <i data-lucide="github" class="w-4 h-4"></i>
                            </a>
                            <a href="#" class="glass-icon-btn" aria-label="Discord">
                                <i data-lucide="message-circle" class="w-4 h-4"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Tools Column -->
                    <div>
                        <h4 class="text-sm font-semibold mb-4 uppercase tracking-wider" style="color: var(--color-text-primary);">Tools</h4>
                        <ul class="space-y-3">
                            <li><a href="/tools?open=compress" class="footer-link">Compressor</a></li>
                            <li><a href="/tools?open=resize" class="footer-link">Resizer</a></li>
                            <li><a href="/tools?open=convert" class="footer-link">Converter</a></li>
                            <li><a href="/tools?open=crop" class="footer-link">Cropper</a></li>
                            <li><a href="/tools?open=ocr" class="footer-link">OCR</a></li>
                            <li><a href="/tools?open=rembg" class="footer-link">Remove Background</a></li>
                        </ul>
                    </div>

                    <!-- Account Column -->
                    <div>
                        <h4 class="text-sm font-semibold mb-4 uppercase tracking-wider" style="color: var(--color-text-primary);">Account</h4>
                        <ul class="space-y-3">
                            <?php if ($isLoggedIn): ?>
                            <li><a href="/dashboard.php" class="footer-link">Dashboard</a></li>
                            <li><a href="/my-images.php" class="footer-link">My Images</a></li>
                            <?php if ($isAdmin): ?>
                            <li><a href="/admin/dashboard.php" class="footer-link">Admin Panel</a></li>
                            <?php endif; ?>
                            <li><a href="/auth/logout.php" class="footer-link">Logout</a></li>
                            <?php else: ?>
                            <li><a href="/login.php" class="footer-link">Login</a></li>
                            <li><a href="/register.php" class="footer-link">Register</a></li>
                            <?php endif; ?>
                            <li><a href="/docs" class="footer-link">API Docs</a></li>
                            <li><a href="/help" class="footer-link">Help Center</a></li>
                        </ul>
                    </div>

                    <!-- Legal Column -->
                    <div>
                        <h4 class="text-sm font-semibold mb-4 uppercase tracking-wider" style="color: var(--color-text-primary);">Legal</h4>
                        <ul class="space-y-3">
                            <li><a href="/terms.php" class="footer-link">Terms of Service</a></li>
                            <li><a href="/privacy.php" class="footer-link">Privacy Policy</a></li>
                            <li><a href="/dmca.php" class="footer-link">DMCA</a></li>
                            <li><a href="/contact.php" class="footer-link">Contact</a></li>
                        </ul>
                    </div>

                </div>

                <!-- Footer Bottom -->
                <div class="border-t mt-10 pt-6 flex flex-col sm:flex-row items-center justify-between gap-4" style="border-color: var(--glass-border);">
                    <p class="text-sm" style="color: var(--color-text-muted);">
                        © 2025 PixelHop. Your Pixel is A Hop Away.
                    </p>
                    <div class="flex items-center gap-4">
                        <span class="text-xs px-2 py-1 rounded bg-yellow-500/20 text-yellow-400">API Coming Soon</span>
                        <div class="flex items-center gap-2 text-sm" style="color: var(--color-text-muted);">
                            <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                            <span>All systems operational</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </footer>

    <!-- User Menu Script -->
    <script>

        const userMenuBtn = document.getElementById('user-menu-btn');
        const userMenuDropdown = document.getElementById('user-menu-dropdown');

        if (userMenuBtn && userMenuDropdown) {
            userMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                userMenuDropdown.classList.toggle('hidden');
            });

            document.addEventListener('click', () => {
                userMenuDropdown.classList.add('hidden');
            });
        }
    </script>

    <!-- Scripts -->
    <script src="/assets/js/app.js?v=1.0.5"></script>

    <!-- Popup Banner -->
    <?php include __DIR__ . '/includes/popup-banner.php'; ?>
</body>
</html>
