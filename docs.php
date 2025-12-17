<?php
/**
 * PixelHop - API Documentation Page (Coming Soon)
 */
$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
$siteUrl = $config['site']['url'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="PixelHop API Documentation - Coming Soon">

    <title>API Documentation - <?= htmlspecialchars($siteName) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">

    <!-- Theme detection -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            document.documentElement.setAttribute('data-theme', savedTheme || 'dark');
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
                        'neon-cyan': '#22d3ee',
                        'neon-purple': '#a855f7',
                        'neon-pink': '#ec4899',
                    },
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                        'mono': ['JetBrains Mono', 'Fira Code', 'monospace'],
                    },
                }
            }
        }
    </script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/css/glass.css">
    <style>
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: linear-gradient(135deg, #22d3ee, #a855f7);
            border-radius: 14px;
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 40px rgba(34, 211, 238, 0.3);
        }
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 14px;
            color: white;
            font-weight: 600;
            font-size: 14px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }
        .nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .nav-btn:hover {
            background: rgba(255, 255, 255, 0.15);
            color: white;
        }
    </style>
</head>
<body class="min-h-screen font-sans">
    <!-- Background Effects -->
    <div class="fixed inset-0 pointer-events-none overflow-hidden">
        <div class="aurora-orb cyan"></div>
        <div class="aurora-orb purple"></div>
        <div class="aurora-orb pink"></div>
    </div>

    <!-- Navigation -->
    <nav class="glass-nav sticky top-0 z-50 px-6 py-4">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <a href="/" class="flex items-center gap-3 group">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-neon-cyan via-neon-purple to-neon-pink flex items-center justify-center group-hover:scale-110 transition-transform">
                    <i data-lucide="image-plus" class="w-5 h-5 text-white"></i>
                </div>
                <span class="text-xl font-semibold bg-gradient-to-r from-neon-cyan to-neon-purple bg-clip-text text-transparent">
                    <?= htmlspecialchars($siteName) ?>
                </span>
            </a>

            <div class="flex items-center gap-4">
                <a href="/" class="nav-btn">
                    <i data-lucide="home" class="w-4 h-4"></i>
                    <span>Home</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-6 py-20">
        <div class="glass-panel p-16 text-center">
            <!-- Icon -->
            <div class="w-24 h-24 rounded-3xl bg-gradient-to-br from-neon-cyan/20 via-neon-purple/20 to-neon-pink/20 border border-white/10 flex items-center justify-center mx-auto mb-8">
                <i data-lucide="book-open" class="w-12 h-12 text-neon-cyan"></i>
            </div>

            <!-- Title -->
            <h1 class="text-4xl md:text-5xl font-bold mb-6">
                <span class="bg-gradient-to-r from-neon-cyan via-neon-purple to-neon-pink bg-clip-text text-transparent">
                    API Documentation
                </span>
            </h1>

            <!-- Coming Soon Badge -->
            <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-neon-purple/20 border border-neon-purple/30 text-neon-purple mb-8">
                <i data-lucide="clock" class="w-4 h-4"></i>
                <span class="font-medium">Coming Soon</span>
            </div>

            <!-- Description -->
            <p class="text-lg text-white/60 max-w-2xl mx-auto mb-10 leading-relaxed">
                We're working hard to bring you comprehensive API documentation.
                Soon you'll be able to integrate PixelHop's powerful image processing
                capabilities directly into your applications.
            </p>

            <!-- Features Preview -->
            <div class="grid md:grid-cols-3 gap-6 mb-12">
                <div class="glass-card p-6 text-left">
                    <div class="w-10 h-10 rounded-lg bg-neon-cyan/20 flex items-center justify-center mb-4">
                        <i data-lucide="upload" class="w-5 h-5 text-neon-cyan"></i>
                    </div>
                    <h3 class="font-semibold text-white mb-2">Upload API</h3>
                    <p class="text-sm text-white/50">Upload images via API with automatic optimization and hosting.</p>
                </div>

                <div class="glass-card p-6 text-left">
                    <div class="w-10 h-10 rounded-lg bg-neon-purple/20 flex items-center justify-center mb-4">
                        <i data-lucide="wand-2" class="w-5 h-5 text-neon-purple"></i>
                    </div>
                    <h3 class="font-semibold text-white mb-2">Transform API</h3>
                    <p class="text-sm text-white/50">Resize, crop, convert, and compress images programmatically.</p>
                </div>

                <div class="glass-card p-6 text-left">
                    <div class="w-10 h-10 rounded-lg bg-neon-pink/20 flex items-center justify-center mb-4">
                        <i data-lucide="sparkles" class="w-5 h-5 text-neon-pink"></i>
                    </div>
                    <h3 class="font-semibold text-white mb-2">AI Tools API</h3>
                    <p class="text-sm text-white/50">Background removal and OCR powered by machine learning.</p>
                </div>
            </div>

            <!-- CTA -->
            <div class="flex flex-col sm:flex-row gap-4 justify-center">
                <a href="/tools" class="btn-primary">
                    <i data-lucide="wrench" class="w-4 h-4"></i>
                    <span>Try Our Tools</span>
                </a>
                <a href="/register" class="btn-secondary">
                    <i data-lucide="user-plus" class="w-4 h-4"></i>
                    <span>Create Account</span>
                </a>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="py-8 px-6 mt-12 border-t border-white/5">
        <div class="max-w-7xl mx-auto text-center text-white/40 text-sm">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?>. All rights reserved.</p>
        </div>
    </footer>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
