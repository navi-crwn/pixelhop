<?php
/**
 * PixelHop - 404 Error Page
 * Cracked Glass Design
 */
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>404 - Image Not Found | PixelHop</title>

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

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/css/glass.css">

    <style>
        .crack-container {
            position: relative;
            width: 320px;
            height: 320px;
            margin: 0 auto;
        }

        .glass-panel {
            position: absolute;
            inset: 0;
            background: var(--glass-bg);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border-radius: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .glass-panel::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.2) 0%,
                rgba(255, 255, 255, 0.05) 50%,
                rgba(255, 255, 255, 0.15) 100%
            );
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        /* Crack effect using SVG */
        .crack-overlay {
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.7;
        }

        .crack-line {
            stroke: rgba(255, 255, 255, 0.6);
            stroke-width: 1.5;
            fill: none;
            filter: drop-shadow(0 0 4px rgba(255, 255, 255, 0.4));
        }

        .crack-line-thin {
            stroke: rgba(255, 255, 255, 0.3);
            stroke-width: 0.8;
        }

        .error-code {
            font-size: 100px;
            font-weight: 800;
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.9) 0%,
                rgba(255, 255, 255, 0.4) 100%
            );
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            position: relative;
            z-index: 1;
        }

        .shatter-piece {
            position: absolute;
            background: inherit;
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
        }

        /* Floating glass shards */
        .shard {
            position: absolute;
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.15) 0%,
                rgba(255, 255, 255, 0.05) 100%
            );
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .shard-1 {
            width: 40px;
            height: 50px;
            clip-path: polygon(50% 0%, 100% 40%, 80% 100%, 0% 80%, 20% 30%);
            top: -30px;
            right: 40px;
            animation: float-shard 4s ease-in-out infinite;
        }

        .shard-2 {
            width: 30px;
            height: 35px;
            clip-path: polygon(30% 0%, 100% 20%, 70% 100%, 0% 60%);
            bottom: 20px;
            left: -20px;
            animation: float-shard 5s ease-in-out infinite 0.5s;
        }

        .shard-3 {
            width: 25px;
            height: 40px;
            clip-path: polygon(0% 20%, 80% 0%, 100% 70%, 40% 100%);
            top: 60px;
            left: -30px;
            animation: float-shard 3.5s ease-in-out infinite 1s;
        }

        .shard-4 {
            width: 35px;
            height: 30px;
            clip-path: polygon(20% 0%, 100% 30%, 80% 100%, 0% 70%);
            bottom: -20px;
            right: 60px;
            animation: float-shard 4.5s ease-in-out infinite 0.8s;
        }

        @keyframes float-shard {
            0%, 100% {
                transform: translateY(0) rotate(0deg);
                opacity: 0.6;
            }
            50% {
                transform: translateY(-15px) rotate(5deg);
                opacity: 1;
            }
        }

        .pulse-ring {
            position: absolute;
            inset: -20px;
            border: 2px solid rgba(236, 72, 153, 0.2);
            border-radius: 60px;
            animation: pulse-ring 3s ease-out infinite;
        }

        @keyframes pulse-ring {
            0% {
                transform: scale(0.9);
                opacity: 0.8;
            }
            100% {
                transform: scale(1.2);
                opacity: 0;
            }
        }
    </style>
</head>
<body class="min-h-screen font-sans flex flex-col">

    <!-- Background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)]"></div>
        <div class="blob blob-pink" style="top: 20%; left: 10%; opacity: 0.3;"></div>
        <div class="blob blob-purple" style="bottom: 10%; right: 10%; opacity: 0.3;"></div>
    </div>

    <!-- Main Content -->
    <main class="relative z-10 flex-1 flex items-center justify-center px-4 py-16">
        <div class="text-center">

            <!-- Cracked Glass Panel -->
            <div class="crack-container mb-8">
                <div class="pulse-ring"></div>
                <div class="pulse-ring" style="animation-delay: 1s;"></div>

                <!-- Floating shards -->
                <div class="shard shard-1"></div>
                <div class="shard shard-2"></div>
                <div class="shard shard-3"></div>
                <div class="shard shard-4"></div>

                <div class="glass-panel">
                    <span class="error-code">404</span>

                    <!-- Crack SVG overlay -->
                    <svg class="crack-overlay" viewBox="0 0 320 320" xmlns="http://www.w3.org/2000/svg">
                        <!-- Main crack from impact point -->
                        <path class="crack-line" d="M160 160 L120 80 L100 40" />
                        <path class="crack-line" d="M160 160 L200 60 L230 20" />
                        <path class="crack-line" d="M160 160 L80 140 L20 150" />
                        <path class="crack-line" d="M160 160 L240 180 L300 200" />
                        <path class="crack-line" d="M160 160 L140 240 L120 300" />
                        <path class="crack-line" d="M160 160 L200 220 L250 280" />

                        <!-- Secondary cracks -->
                        <path class="crack-line crack-line-thin" d="M120 80 L80 90" />
                        <path class="crack-line crack-line-thin" d="M120 80 L140 60" />
                        <path class="crack-line crack-line-thin" d="M200 60 L180 30" />
                        <path class="crack-line crack-line-thin" d="M80 140 L60 180" />
                        <path class="crack-line crack-line-thin" d="M240 180 L260 140" />
                        <path class="crack-line crack-line-thin" d="M140 240 L100 260" />
                        <path class="crack-line crack-line-thin" d="M200 220 L220 260" />

                        <!-- Impact point -->
                        <circle cx="160" cy="160" r="8" fill="none" stroke="rgba(255,255,255,0.5)" stroke-width="2" />
                        <circle cx="160" cy="160" r="3" fill="rgba(255,255,255,0.8)" />
                    </svg>
                </div>
            </div>

            <!-- Text -->
            <h1 class="text-2xl md:text-3xl font-bold mb-3" style="color: var(--color-text-primary);">
                Image Not Found
            </h1>
            <p class="text-base mb-8 max-w-md mx-auto" style="color: var(--color-text-tertiary);">
                Oops! The image you're looking for seems to have shattered into the void.
                It may have been deleted or the link might be incorrect.
            </p>

            <!-- Actions -->
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <a href="/" class="btn-primary">
                    <i data-lucide="upload" class="w-4 h-4"></i>
                    Upload New Image
                </a>
                <a href="/" class="btn-secondary">
                    <i data-lucide="home" class="w-4 h-4"></i>
                    Back to Home
                </a>
            </div>

        </div>
    </main>

    <!-- Footer -->
    <footer class="relative z-10 text-center py-6 px-4">
        <a href="/" class="inline-flex items-center gap-2 opacity-50 hover:opacity-100 transition-opacity">
            <img src="/assets/img/logo.svg" alt="PixelHop" class="w-5 h-5">
            <span class="text-sm font-medium" style="color: var(--color-text-secondary);">PixelHop</span>
        </a>
    </footer>

    <script>
        lucide.createIcons();


        gsap.to('.crack-line', {
            strokeOpacity: 0.4,
            duration: 2,
            repeat: -1,
            yoyo: true,
            stagger: 0.2
        });
    </script>
</body>
</html>
