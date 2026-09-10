<?php
/**
 * PixelHop - Features/Products Page
 * Showcase all PixelHop features and tools
 */
require_once __DIR__ . '/includes/bootstrap.php';
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
    <meta name="description" content="PixelHop Features - Free image hosting with powerful tools: compress, resize, crop, convert, OCR, and AI background removal.">
    <meta name="keywords" content="image hosting, image compressor, image resizer, OCR, remove background, free hosting">
    <meta name="author" content="PixelHop">
    <meta name="theme-color" content="#0a0f1c" id="theme-color-meta">

    <!-- Open Graph -->
    <meta property="og:title" content="PixelHop Features - Image Hosting & Processing Tools">
    <meta property="og:description" content="Free premium image hosting with powerful tools">
    <meta property="og:image" content="https://p.hel.ink/assets/img/og-image.png">
    <meta property="og:url" content="https://p.hel.ink/features">
    <meta property="og:type" content="website">

    <title>Features - PixelHop</title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">

    <!-- Prevent FOUC -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || (prefersDark ? 'dark' : 'dark');
            document.documentElement.setAttribute('data-theme', theme);
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
                        'neon-cyan': '#22d3ee',
                        'neon-purple': '#a855f7',
                        'neon-pink': '#ec4899',
                    },
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    },
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

    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/css/glass.css?v=1.1.0">
</head>
<body class="min-h-screen font-sans overflow-x-hidden transition-colors duration-500">

    <!-- Animated Background -->
    <div id="bg-container" class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)] transition-colors duration-500"></div>
        <div id="blob-1" class="blob blob-cyan"></div>
        <div id="blob-2" class="blob blob-purple"></div>
        <div id="blob-3" class="blob blob-pink"></div>
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
                    <span class="text-xl font-bold header-brand">PixelHop</span>
                </a>

                <!-- Right Side -->
                <div class="flex items-center gap-2">
                    <button id="theme-toggle" class="theme-toggle-btn" aria-label="Toggle theme">
                        <i data-lucide="sun" class="theme-icon-light w-4 h-4"></i>
                        <i data-lucide="moon" class="theme-icon-dark w-4 h-4"></i>
                    </button>
                    <a href="/" class="glass-button px-4 py-2 text-sm">
                        <i data-lucide="arrow-left" class="w-4 h-4"></i>
                        <span>Back to Home</span>
                    </a>
                </div>
            </div>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="relative z-10 pt-28 pb-20 px-4">
        <div class="max-w-6xl mx-auto">

            <!-- Page Header -->
            <section class="text-center mb-16">
                <p class="text-xs uppercase tracking-[0.4em] mb-4" style="color: var(--color-text-muted);">Features & Tools</p>
                <h1 class="text-4xl md:text-5xl font-bold mb-4" style="color: var(--color-text-primary);">
                    Everything You Need for
                    <br>
                    <span class="bg-gradient-to-r from-neon-cyan via-neon-purple to-neon-pink bg-clip-text text-transparent">
                        Image Hosting & Processing
                    </span>
                </h1>
                <p class="text-lg max-w-2xl mx-auto" style="color: var(--color-text-secondary);">
                    PixelHop provides free premium image hosting with a complete suite of image processing tools. 
                    All features included, no subscriptions required.
                </p>
            </section>

            <!-- Image Hosting Feature -->
            <section class="glass-card p-8 mb-8">
                <div class="flex items-start gap-6">
                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-neon-cyan/20 to-neon-cyan/5 flex items-center justify-center flex-shrink-0">
                        <i data-lucide="cloud-upload" class="w-8 h-8 text-neon-cyan"></i>
                    </div>
                    <div class="flex-1">
                        <h2 class="text-2xl font-semibold mb-3" style="color: var(--color-text-primary);">Image Hosting</h2>
                        <p class="text-base mb-4" style="color: var(--color-text-secondary);">
                            Upload and share your images instantly with our lightning-fast CDN. Support for JPG, PNG, GIF, and WebP formats 
                            up to 10MB per file. Get permanent links or set auto-delete timers for temporary sharing.
                        </p>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                            <div class="flex items-center gap-2" style="color: var(--color-text-tertiary);">
                                <i data-lucide="check" class="w-4 h-4 text-neon-cyan"></i>
                                <span>Instant uploads</span>
                            </div>
                            <div class="flex items-center gap-2" style="color: var(--color-text-tertiary);">
                                <i data-lucide="check" class="w-4 h-4 text-neon-cyan"></i>
                                <span>Global CDN</span>
                            </div>
                            <div class="flex items-center gap-2" style="color: var(--color-text-tertiary);">
                                <i data-lucide="check" class="w-4 h-4 text-neon-cyan"></i>
                                <span>Auto-delete option</span>
                            </div>
                            <div class="flex items-center gap-2" style="color: var(--color-text-tertiary);">
                                <i data-lucide="check" class="w-4 h-4 text-neon-cyan"></i>
                                <span>Direct links</span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Image Processing Tools -->
            <section class="mb-16">
                <h2 class="text-xl font-semibold mb-6 text-center" style="color: var(--color-text-primary);">Image Processing Tools</h2>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">

                    <!-- Compress -->
                    <a href="/tools?open=compress" class="glass-card p-6 group hover:scale-105 transition-transform duration-300">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-neon-cyan/20 to-neon-cyan/5 flex items-center justify-center mb-4">
                            <i data-lucide="archive" class="w-6 h-6 text-neon-cyan"></i>
                        </div>
                        <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Compress</h3>
                        <p class="text-sm mb-3" style="color: var(--color-text-tertiary);">
                            Reduce image file size without visible quality loss. Smart compression algorithms 
                            preserve visual fidelity while shrinking files up to 80%.
                        </p>
                        <span class="text-xs text-neon-cyan group-hover:underline">Try Compress →</span>
                    </a>

                    <!-- Resize -->
                    <a href="/tools?open=resize" class="glass-card p-6 group hover:scale-105 transition-transform duration-300">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-neon-purple/20 to-neon-purple/5 flex items-center justify-center mb-4">
                            <i data-lucide="scaling" class="w-6 h-6 text-neon-purple"></i>
                        </div>
                        <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Resize</h3>
                        <p class="text-sm mb-3" style="color: var(--color-text-tertiary);">
                            Change image dimensions to any size. Perfect for social media, thumbnails, 
                            or fitting images to specific requirements. Maintain aspect ratio or set custom sizes.
                        </p>
                        <span class="text-xs text-neon-purple group-hover:underline">Try Resize →</span>
                    </a>

                    <!-- Convert -->
                    <a href="/tools?open=convert" class="glass-card p-6 group hover:scale-105 transition-transform duration-300">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-neon-cyan/20 to-neon-cyan/5 flex items-center justify-center mb-4">
                            <i data-lucide="repeat" class="w-6 h-6 text-neon-cyan"></i>
                        </div>
                        <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Convert</h3>
                        <p class="text-sm mb-3" style="color: var(--color-text-tertiary);">
                            Transform images between formats: JPG, PNG, WebP, and GIF. Convert to WebP 
                            for smaller files or PNG for transparency support.
                        </p>
                        <span class="text-xs text-neon-cyan group-hover:underline">Try Convert →</span>
                    </a>

                    <!-- Crop -->
                    <a href="/tools?open=crop" class="glass-card p-6 group hover:scale-105 transition-transform duration-300">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-neon-pink/20 to-neon-pink/5 flex items-center justify-center mb-4">
                            <i data-lucide="crop" class="w-6 h-6 text-neon-pink"></i>
                        </div>
                        <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Crop</h3>
                        <p class="text-sm mb-3" style="color: var(--color-text-tertiary);">
                            Trim and frame your images precisely. Use preset aspect ratios (1:1, 16:9, 4:3) 
                            or freeform selection. Interactive preview for perfect results.
                        </p>
                        <span class="text-xs text-neon-pink group-hover:underline">Try Crop →</span>
                    </a>

                    <!-- OCR -->
                    <a href="/tools?open=ocr" class="glass-card p-6 group hover:scale-105 transition-transform duration-300">
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-neon-purple/20 to-neon-purple/5 flex items-center justify-center mb-4">
                            <i data-lucide="scan-text" class="w-6 h-6 text-neon-purple"></i>
                        </div>
                        <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">OCR (Text Extraction)</h3>
                        <p class="text-sm mb-3" style="color: var(--color-text-tertiary);">
                            Extract text from images using optical character recognition. 
                            Works with screenshots, documents, signs, and handwritten notes.
                        </p>
                        <span class="text-xs text-neon-purple group-hover:underline">Try OCR →</span>
                    </a>

                    <!-- Remove Background -->
                    <a href="/tools?open=rembg" class="glass-card p-6 group hover:scale-105 transition-transform duration-300 relative overflow-hidden">
                        <div class="absolute top-4 right-4 px-2 py-1 rounded-full bg-gradient-to-r from-neon-cyan to-neon-purple text-xs text-white font-medium">
                            AI Powered
                        </div>
                        <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-neon-pink/20 to-neon-purple/20 flex items-center justify-center mb-4">
                            <i data-lucide="eraser" class="w-6 h-6 text-neon-pink"></i>
                        </div>
                        <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Remove Background</h3>
                        <p class="text-sm mb-3" style="color: var(--color-text-tertiary);">
                            AI-powered background removal in seconds. Perfect for product photos, 
                            portraits, and creating transparent PNG images.
                        </p>
                        <span class="text-xs text-neon-pink group-hover:underline">Try Remove BG →</span>
                    </a>

                </div>
            </section>

            <!-- Why PixelHop -->
            <section class="glass-card p-8 mb-8">
                <h2 class="text-2xl font-semibold mb-6 text-center" style="color: var(--color-text-primary);">Why Choose PixelHop?</h2>
                <div class="grid md:grid-cols-3 gap-6">
                    <div class="text-center">
                        <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-neon-cyan/20 to-neon-cyan/5 flex items-center justify-center">
                            <i data-lucide="zap" class="w-7 h-7 text-neon-cyan"></i>
                        </div>
                        <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Lightning Fast</h3>
                        <p class="text-sm" style="color: var(--color-text-tertiary);">Optimized CDN delivers your images instantly worldwide</p>
                    </div>
                    <div class="text-center">
                        <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-neon-purple/20 to-neon-purple/5 flex items-center justify-center">
                            <i data-lucide="shield-check" class="w-7 h-7 text-neon-purple"></i>
                        </div>
                        <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Secure & Private</h3>
                        <p class="text-sm" style="color: var(--color-text-tertiary);">Enterprise-grade storage with optional auto-delete</p>
                    </div>
                    <div class="text-center">
                        <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-gradient-to-br from-neon-pink/20 to-neon-pink/5 flex items-center justify-center">
                            <i data-lucide="infinity" class="w-7 h-7 text-neon-pink"></i>
                        </div>
                        <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Forever Free</h3>
                        <p class="text-sm" style="color: var(--color-text-tertiary);">No hidden costs, no subscriptions, no premium tiers</p>
                    </div>
                </div>
            </section>

            <!-- AI-Powered Safety Section -->
            <section class="glass-card p-8 mb-8" style="background: linear-gradient(135deg, rgba(34, 197, 94, 0.1) 0%, rgba(34, 211, 238, 0.1) 100%); border: 1px solid rgba(34, 197, 94, 0.2);">
                <div class="flex items-center justify-center gap-3 mb-6">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-green-500/20 to-green-500/5 flex items-center justify-center">
                        <i data-lucide="shield-check" class="w-6 h-6 text-green-400"></i>
                    </div>
                    <h2 class="text-2xl font-semibold" style="color: var(--color-text-primary);">AI-Powered Safety</h2>
                    <span class="px-3 py-1 rounded-full bg-green-500/20 text-green-400 text-xs font-medium border border-green-500/30">SafeGuard</span>
                </div>
                
                <p class="text-center text-base mb-8 max-w-3xl mx-auto" style="color: var(--color-text-secondary);">
                    PixelHop employs cutting-edge AI and industry-standard safety tools to keep our platform clean and safe. 
                    Every image is automatically scanned before being made public.
                </p>

                <div class="grid md:grid-cols-3 gap-6 mb-8">
                    <div class="glass-card p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-lg bg-blue-500/20 flex items-center justify-center">
                                <i data-lucide="scan" class="w-5 h-5 text-blue-400"></i>
                            </div>
                            <h4 class="font-semibold" style="color: var(--color-text-primary);">Google Safe Browsing</h4>
                        </div>
                        <p class="text-sm" style="color: var(--color-text-tertiary);">Real-time URL checking against Google's threat database to block malware and phishing.</p>
                    </div>
                    
                    <div class="glass-card p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-lg bg-red-500/20 flex items-center justify-center">
                                <i data-lucide="bug" class="w-5 h-5 text-red-400"></i>
                            </div>
                            <h4 class="font-semibold" style="color: var(--color-text-primary);">VirusTotal Integration</h4>
                        </div>
                        <p class="text-sm" style="color: var(--color-text-tertiary);">Multi-engine scanning using 70+ antivirus engines to detect malicious content.</p>
                    </div>
                    
                    <div class="glass-card p-5">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-lg bg-purple-500/20 flex items-center justify-center">
                                <i data-lucide="sparkles" class="w-5 h-5 text-purple-400"></i>
                            </div>
                            <h4 class="font-semibold" style="color: var(--color-text-primary);">Gemini AI Vision</h4>
                        </div>
                        <p class="text-sm" style="color: var(--color-text-tertiary);">Advanced AI analyzes image content for CSAM, violence, and other prohibited material.</p>
                    </div>
                </div>

                <div class="text-center">
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-lg" style="background: rgba(34, 197, 94, 0.15); border: 1px solid rgba(34, 197, 94, 0.3);">
                        <i data-lucide="check-circle-2" class="w-5 h-5 text-green-400"></i>
                        <span class="text-sm" style="color: var(--color-text-secondary);">Zero Tolerance Policy for CSAM & Abuse — <a href="/terms" class="text-green-400 hover:underline">Read our Terms</a></span>
                    </div>
                </div>
            </section>

            <!-- Part of HEL.ink Family -->
            <section class="glass-card p-8 text-center" style="background: linear-gradient(135deg, rgba(34, 211, 238, 0.1) 0%, rgba(168, 85, 247, 0.1) 100%);">
                <p class="text-xs uppercase tracking-[0.4em] mb-4" style="color: var(--color-text-muted);">Part of the HEL.ink Family</p>
                <h2 class="text-2xl font-semibold mb-4" style="color: var(--color-text-primary);">
                    PixelHop is a <a href="https://hel.ink" class="text-neon-cyan hover:underline">HEL.ink</a> Product
                </h2>
                <p class="text-base max-w-2xl mx-auto mb-6" style="color: var(--color-text-secondary);">
                    PixelHop (p.hel.ink) is part of the HEL.ink ecosystem. HEL.ink (Hop Easy Link) is a modern URL shortener 
                    and Link in Bio platform with analytics, QR codes, and more. Both tools are free and designed 
                    to make sharing easier.
                </p>
                <div class="flex flex-wrap items-center justify-center gap-4">
                    <a href="https://hel.ink" target="_blank" class="glass-button px-6 py-3 text-white hover:opacity-90 transition-all inline-flex items-center gap-2">
                        <i data-lucide="link" class="w-4 h-4"></i>
                        <span>Visit HEL.ink</span>
                    </a>
                    <a href="/" class="glass-button-outline px-6 py-3 inline-flex items-center gap-2">
                        <i data-lucide="upload" class="w-4 h-4"></i>
                        <span>Start Uploading</span>
                    </a>
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
                        <div class="flex items-center gap-3 mt-6">
                            <a href="#" class="glass-icon-btn" aria-label="Twitter">
                                <i data-lucide="twitter" class="w-4 h-4"></i>
                            </a>
                            <a href="#" class="glass-icon-btn" aria-label="GitHub">
                                <i data-lucide="github" class="w-4 h-4"></i>
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

                    <!-- HEL.ink Family Column -->
                    <div>
                        <h4 class="text-sm font-semibold mb-4 uppercase tracking-wider" style="color: var(--color-text-primary);">HEL.ink Family</h4>
                        <ul class="space-y-3">
                            <li><a href="https://hel.ink" target="_blank" class="footer-link">HEL.ink - URL Shortener</a></li>
                            <li><a href="https://hel.ink/products" target="_blank" class="footer-link">Link in Bio</a></li>
                            <li><a href="/features" class="footer-link">PixelHop Features</a></li>
                        </ul>
                    </div>

                    <!-- Legal Column -->
                    <div>
                        <h4 class="text-sm font-semibold mb-4 uppercase tracking-wider" style="color: var(--color-text-primary);">Legal</h4>
                        <ul class="space-y-3">
                            <li><a href="/terms" class="footer-link">Terms of Service</a></li>
                            <li><a href="/privacy" class="footer-link">Privacy Policy</a></li>
                            <li><a href="/dmca" class="footer-link">DMCA</a></li>
                            <li><a href="/contact" class="footer-link">Contact</a></li>
                        </ul>
                    </div>

                </div>

                <!-- Footer Bottom -->
                <div class="border-t mt-10 pt-6 flex flex-col sm:flex-row items-center justify-between gap-4" style="border-color: var(--glass-border);">
                    <p class="text-sm" style="color: var(--color-text-muted);">
                        © 2025 PixelHop. Part of the <a href="https://hel.ink" class="text-neon-cyan hover:underline">HEL.ink</a> family.
                    </p>
                    <div class="flex items-center gap-2 text-sm" style="color: var(--color-text-muted);">
                        <span class="w-2 h-2 rounded-full bg-green-500 animate-pulse"></span>
                        <span>All systems operational</span>
                    </div>
                </div>

            </div>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="/assets/js/app.js?v=1.0.6"></script>
</body>
</html>
