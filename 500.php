<?php
/**
 * PixelHop - 500 Error Page
 * Shattered Glass Design
 */
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>500 - Server Error | PixelHop</title>

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
        .shatter-container {
            position: relative;
            width: 320px;
            height: 320px;
            margin: 0 auto;
            perspective: 1000px;
        }

        /* Shattered glass pieces */
        .glass-shard {
            position: absolute;
            background: var(--glass-bg);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            transform-style: preserve-3d;
            animation: float-piece 4s ease-in-out infinite;
        }

        .shard-1 {
            width: 100px;
            height: 120px;
            clip-path: polygon(20% 0%, 100% 10%, 80% 100%, 0% 80%);
            top: 40px;
            left: 20px;
            animation-delay: 0s;
        }

        .shard-2 {
            width: 90px;
            height: 100px;
            clip-path: polygon(0% 20%, 90% 0%, 100% 70%, 30% 100%);
            top: 30px;
            right: 30px;
            animation-delay: 0.5s;
        }

        .shard-3 {
            width: 110px;
            height: 90px;
            clip-path: polygon(10% 0%, 100% 30%, 80% 100%, 0% 70%);
            bottom: 60px;
            left: 40px;
            animation-delay: 1s;
        }

        .shard-4 {
            width: 80px;
            height: 110px;
            clip-path: polygon(30% 0%, 100% 20%, 70% 100%, 0% 80%);
            bottom: 40px;
            right: 50px;
            animation-delay: 0.7s;
        }

        .shard-5 {
            width: 70px;
            height: 70px;
            clip-path: polygon(50% 0%, 100% 50%, 50% 100%, 0% 50%);
            top: 120px;
            left: 120px;
            animation-delay: 0.3s;
        }

        .shard-small {
            width: 30px;
            height: 40px;
            opacity: 0.6;
        }

        .shard-6 {
            clip-path: polygon(20% 0%, 100% 30%, 60% 100%, 0% 60%);
            top: 10px;
            left: 100px;
            animation-delay: 1.2s;
        }

        .shard-7 {
            clip-path: polygon(0% 20%, 80% 0%, 100% 80%, 30% 100%);
            bottom: 20px;
            left: 10px;
            animation-delay: 0.9s;
        }

        .shard-8 {
            clip-path: polygon(40% 0%, 100% 40%, 60% 100%, 0% 50%);
            top: 80px;
            right: 10px;
            animation-delay: 1.5s;
        }

        @keyframes float-piece {
            0%, 100% {
                transform: translateY(0) rotateX(0deg) rotateY(0deg);
            }
            25% {
                transform: translateY(-8px) rotateX(2deg) rotateY(-2deg);
            }
            75% {
                transform: translateY(-4px) rotateX(-1deg) rotateY(1deg);
            }
        }

        .error-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 10;
        }

        .error-code {
            font-size: 80px;
            font-weight: 800;
            background: linear-gradient(
                135deg,
                rgba(248, 113, 113, 0.9) 0%,
                rgba(236, 72, 153, 0.8) 100%
            );
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1;
            text-shadow: 0 0 60px rgba(248, 113, 113, 0.3);
        }

        /* Glitch effect */
        .glitch {
            position: relative;
        }

        .glitch::before,
        .glitch::after {
            content: '500';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(248, 113, 113, 0.9) 0%, rgba(236, 72, 153, 0.8) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .glitch::before {
            animation: glitch-1 2s infinite linear;
            clip-path: polygon(0 0, 100% 0, 100% 35%, 0 35%);
        }

        .glitch::after {
            animation: glitch-2 2s infinite linear;
            clip-path: polygon(0 65%, 100% 65%, 100% 100%, 0 100%);
        }

        @keyframes glitch-1 {
            0%, 94%, 100% { transform: translate(0); }
            95% { transform: translate(-4px, 1px); }
            96% { transform: translate(4px, -1px); }
        }

        @keyframes glitch-2 {
            0%, 94%, 100% { transform: translate(0); }
            95% { transform: translate(4px, 1px); }
            96% { transform: translate(-4px, -1px); }
        }

        /* Sparks */
        .spark {
            position: absolute;
            width: 4px;
            height: 4px;
            background: #f87171;
            border-radius: 50%;
            box-shadow: 0 0 10px #f87171, 0 0 20px #f87171;
            animation: spark-fly 1.5s ease-out infinite;
        }

        .spark-1 { top: 50%; left: 50%; animation-delay: 0s; }
        .spark-2 { top: 50%; left: 50%; animation-delay: 0.3s; }
        .spark-3 { top: 50%; left: 50%; animation-delay: 0.6s; }
        .spark-4 { top: 50%; left: 50%; animation-delay: 0.9s; }

        @keyframes spark-fly {
            0% {
                opacity: 1;
                transform: translate(0, 0) scale(1);
            }
            100% {
                opacity: 0;
                transform: translate(var(--tx, 50px), var(--ty, -50px)) scale(0);
            }
        }

        .spark-1 { --tx: -60px; --ty: -40px; }
        .spark-2 { --tx: 50px; --ty: -60px; }
        .spark-3 { --tx: -40px; --ty: 50px; }
        .spark-4 { --tx: 60px; --ty: 40px; }

        /* Warning stripes */
        .warning-stripe {
            position: absolute;
            bottom: -40px;
            left: 50%;
            transform: translateX(-50%);
            width: 200px;
            height: 8px;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 10px,
                rgba(248, 113, 113, 0.3) 10px,
                rgba(248, 113, 113, 0.3) 20px
            );
            border-radius: 4px;
        }
    </style>
</head>
<body class="min-h-screen font-sans flex flex-col">

    <!-- Background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)]"></div>
        <div class="blob" style="width: 400px; height: 400px; background: radial-gradient(circle, rgba(248,113,113,0.4) 0%, transparent 70%); top: 20%; left: 20%; filter: blur(100px);"></div>
        <div class="blob" style="width: 350px; height: 350px; background: radial-gradient(circle, rgba(236,72,153,0.3) 0%, transparent 70%); bottom: 20%; right: 20%; filter: blur(100px);"></div>
    </div>

    <!-- Main Content -->
    <main class="relative z-10 flex-1 flex items-center justify-center px-4 py-16">
        <div class="text-center">

            <!-- Shattered Glass Effect -->
            <div class="shatter-container mb-10">
                <!-- Shards -->
                <div class="glass-shard shard-1"></div>
                <div class="glass-shard shard-2"></div>
                <div class="glass-shard shard-3"></div>
                <div class="glass-shard shard-4"></div>
                <div class="glass-shard shard-5"></div>
                <div class="glass-shard shard-small shard-6"></div>
                <div class="glass-shard shard-small shard-7"></div>
                <div class="glass-shard shard-small shard-8"></div>

                <!-- Sparks -->
                <div class="spark spark-1"></div>
                <div class="spark spark-2"></div>
                <div class="spark spark-3"></div>
                <div class="spark spark-4"></div>

                <!-- Error Code -->
                <div class="error-text">
                    <span class="error-code glitch">500</span>
                </div>

                <!-- Warning stripe -->
                <div class="warning-stripe"></div>
            </div>

            <!-- Text -->
            <h1 class="text-2xl md:text-3xl font-bold mb-3" style="color: var(--color-text-primary);">
                Server Error
            </h1>
            <p class="text-base mb-8 max-w-md mx-auto" style="color: var(--color-text-tertiary);">
                Something went wrong on our end. Our glass servers have shattered!
                We're working to piece things back together.
            </p>

            <!-- Actions -->
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <button onclick="location.reload()" class="btn-primary">
                    <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                    Try Again
                </button>
                <a href="/" class="btn-secondary">
                    <i data-lucide="home" class="w-4 h-4"></i>
                    Back to Home
                </a>
            </div>

            <!-- Status -->
            <div class="mt-10 inline-flex items-center gap-2 px-4 py-2 rounded-full" style="background: rgba(248, 113, 113, 0.1); border: 1px solid rgba(248, 113, 113, 0.2);">
                <span class="w-2 h-2 rounded-full bg-red-400 animate-pulse"></span>
                <span class="text-sm" style="color: rgba(248, 113, 113, 0.8);">System experiencing issues</span>
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


        document.querySelectorAll('.glass-shard').forEach((shard, i) => {
            gsap.to(shard, {
                x: `random(-10, 10)`,
                y: `random(-15, 15)`,
                rotation: `random(-5, 5)`,
                duration: 3 + (i * 0.5),
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut'
            });
        });
    </script>
</body>
</html>
