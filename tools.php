<?php
/**
 * PixelHop - Image Tools Page
 * All image processing tools in one place
 */
session_start();
require_once __DIR__ . '/auth/middleware.php';
require_once __DIR__ . '/core/Gatekeeper.php';

$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
$openTool = isset($_GET['open']) ? htmlspecialchars($_GET['open']) : '';

// Check authentication state
$isLoggedIn = isAuthenticated();
$currentUser = $isLoggedIn ? getCurrentUser() : null;
$isAdmin = $isLoggedIn && isAdmin();

// Get tool enabled/disabled status from Gatekeeper
$gatekeeper = new Gatekeeper();
$toolStatus = [
    'resize' => $gatekeeper->getSetting('tool_resize_enabled', 1),
    'compress' => $gatekeeper->getSetting('tool_compress_enabled', 1),
    'crop' => $gatekeeper->getSetting('tool_crop_enabled', 1),
    'convert' => $gatekeeper->getSetting('tool_convert_enabled', 1),
    'ocr' => $gatekeeper->getSetting('tool_ocr_enabled', 1),
    'rembg' => $gatekeeper->getSetting('tool_rembg_enabled', 1),
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Free image tools - Compress, Resize, Crop, Convert, OCR">

    <title>Image Tools - <?= htmlspecialchars($siteName) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">

    <!-- Theme detection -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            document.documentElement.setAttribute('data-theme', savedTheme || 'dark');
        })();
    </script>

    <!-- Auto-open tool from URL -->
    <script>
        window.autoOpenTool = '<?= $openTool ?>';
        window.toolStatus = <?= json_encode($toolStatus) ?>;
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

    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/css/glass.css?v=1.0.9">

    <style>
        .tool-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }

        @media (max-width: 992px) {
            .tool-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 576px) {
            .tool-grid {
                grid-template-columns: 1fr;
            }
        }

        .tool-card {
            background: rgba(20, 20, 40, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: var(--radius-2xl, 20px);
            padding: 28px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            opacity: 1;
        }

        [data-theme="light"] .tool-card {
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(0, 0, 0, 0.1);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }

        .tool-card::before {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(
                135deg,
                rgba(34, 211, 238, 0.3) 0%,
                rgba(168, 85, 247, 0.1) 50%,
                rgba(34, 211, 238, 0.2) 100%
            );
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .tool-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            border-color: rgba(34, 211, 238, 0.3);
        }

        [data-theme="light"] .tool-card:hover {
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            border-color: rgba(34, 211, 238, 0.5);
        }

        .tool-card:hover::before {
            background: linear-gradient(
                135deg,
                rgba(34, 211, 238, 0.5) 0%,
                rgba(168, 85, 247, 0.2) 50%,
                rgba(34, 211, 238, 0.4) 100%
            );
        }

        .tool-card.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            pointer-events: none;
        }

        .tool-card.disabled:hover {
            transform: none;
            box-shadow: none;
        }

        .tool-card .disabled-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(239, 68, 68, 0.2);
            color: #ef4444;
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tool-card .login-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(168, 85, 247, 0.2);
            color: #a855f7;
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .tool-icon {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-xl);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .tool-icon-compress { background: linear-gradient(135deg, #22d3ee, #0891b2); }
        .tool-icon-resize { background: linear-gradient(135deg, #a855f7, #7c3aed); }
        .tool-icon-crop { background: linear-gradient(135deg, #ec4899, #db2777); }
        .tool-icon-convert { background: linear-gradient(135deg, #f97316, #ea580c); }
        .tool-icon-ocr { background: linear-gradient(135deg, #10b981, #059669); }

        /* Modal Styles */
        .tool-modal {
            position: fixed;
            inset: 0;
            z-index: 100;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .tool-modal.active {
            display: flex;
        }

        .modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
        }

        .modal-content {
            position: relative;
            background: var(--glass-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: var(--radius-2xl);
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 32px;
        }

        .modal-content::before {
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

        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: var(--glass-bg);
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .drop-zone-mini {
            border: 2px dashed var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 40px 20px;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }

        .drop-zone-mini:hover,
        .drop-zone-mini.dragover {
            border-color: var(--color-accent);
            background: rgba(34, 211, 238, 0.05);
        }

        .preview-container {
            display: none;
            text-align: center;
            margin-top: 20px;
        }

        .preview-container.has-image {
            display: block;
        }

        .preview-image {
            max-width: 100%;
            max-height: 200px;
            border-radius: var(--radius-lg);
            margin: 0 auto;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            color: var(--color-text-secondary);
        }

        .form-input,
        .form-select {
            width: 100%;
            padding: 12px 16px;
            background: linear-gradient(135deg,
                rgba(34, 211, 238, 0.1) 0%,
                rgba(168, 85, 247, 0.1) 100%);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(34, 211, 238, 0.3);
            border-radius: var(--radius-lg);
            color: var(--color-text-primary);
            font-size: 14px;
            transition: all 0.3s ease;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            cursor: pointer;
        }

        .form-select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2322d3ee' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 40px;
        }

        .form-input:hover,
        .form-select:hover {
            background: linear-gradient(135deg,
                rgba(34, 211, 238, 0.15) 0%,
                rgba(168, 85, 247, 0.15) 100%);
            border-color: rgba(34, 211, 238, 0.5);
            box-shadow: 0 0 20px rgba(34, 211, 238, 0.15);
        }

        .form-input:focus,
        .form-select:focus {
            outline: none;
            border-color: var(--color-neon-cyan);
            box-shadow: 0 0 0 3px rgba(34, 211, 238, 0.2),
                        0 0 30px rgba(34, 211, 238, 0.2);
        }

        .form-select option {
            background: #0f1428;
            color: #ffffff;
            padding: 12px;
        }

        /* Light theme for form select */
        [data-theme="light"] .form-select option {
            background: #ffffff;
            color: #1a1f35;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .result-area {
            display: none;
            margin-top: 20px;
            padding: 20px;
            background: rgba(34, 211, 238, 0.05);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(34, 211, 238, 0.2);
        }

        .result-area.show {
            display: block;
        }

        .result-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 16px;
        }

        .stat-item {
            text-align: center;
            padding: 12px;
            background: var(--glass-bg);
            border-radius: var(--radius-md);
        }

        .stat-value {
            font-size: 18px;
            font-weight: 600;
            color: var(--color-accent);
        }

        .stat-label {
            font-size: 12px;
            color: var(--color-text-muted);
        }

        .ocr-result {
            background: var(--glass-bg);
            border-radius: var(--radius-lg);
            padding: 16px;
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 13px;
            max-height: 300px;
            overflow-y: auto;
            color: var(--color-text-primary);
        }

        .processing {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 20px;
        }

        .processing.show {
            display: flex;
        }

        .spinner {
            width: 24px;
            height: 24px;
            border: 2px solid var(--glass-border);
            border-top-color: var(--color-accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="min-h-screen font-sans overflow-x-hidden">

    <!-- Background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)]"></div>
        <div class="blob blob-cyan" style="top: -15%; left: -10%;"></div>
        <div class="blob blob-purple" style="bottom: -15%; right: -10%;"></div>
    </div>

    <!-- Header -->
    <header class="fixed top-0 left-0 right-0 z-50 px-4 py-4">
        <nav class="max-w-6xl mx-auto">
            <div class="glass-card glass-card-header flex items-center justify-between px-5 py-3">
                <a href="/" class="flex items-center gap-3 group">
                    <img src="/assets/img/logo.svg" alt="PixelHop" class="w-8 h-8 transition-transform group-hover:scale-110">
                    <span class="text-lg font-bold" style="color: var(--color-text-primary);">PixelHop</span>
                </a>

                <div class="flex items-center gap-3">
                    <button id="theme-toggle" class="theme-toggle-btn" aria-label="Toggle theme">
                        <i data-lucide="sun" class="theme-icon-light w-4 h-4"></i>
                        <i data-lucide="moon" class="theme-icon-dark w-4 h-4"></i>
                    </button>

                    <?php if ($isLoggedIn): ?>
                    <!-- User Menu (Logged In) -->
                    <div class="relative" id="user-menu-container">
                        <button id="user-menu-btn" class="flex items-center gap-2 px-3 py-2 rounded-xl text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-all">
                            <div class="w-7 h-7 rounded-full bg-gradient-to-br from-neon-cyan to-neon-purple flex items-center justify-center text-white text-xs font-bold">
                                <?= strtoupper(substr($currentUser['email'], 0, 1)) ?>
                            </div>
                            <span class="text-sm font-medium hidden sm:inline"><?= htmlspecialchars(explode('@', $currentUser['email'])[0]) ?></span>
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
                            <a href="/" class="flex items-center gap-3 px-4 py-2 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-colors">
                                <i data-lucide="upload" class="w-4 h-4 text-neon-pink"></i>
                                Upload
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

                    <a href="/" class="btn-primary text-sm">
                        <i data-lucide="upload" class="w-4 h-4"></i>
                        Upload
                    </a>
                </div>
            </div>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="relative z-10 pt-28 pb-16 px-4">
        <div class="max-w-5xl mx-auto">

            <!-- Page Header -->
            <div class="text-center mb-12">
                <h1 class="text-3xl md:text-4xl font-bold mb-3" style="color: var(--color-text-primary);">
                    Image Tools
                </h1>
                <p style="color: var(--color-text-tertiary);">
                    Free, fast, and privacy-focused image processing
                </p>
            </div>

            <!-- Tools Grid -->
            <div class="tool-grid">

                <!-- Compress -->
                <div class="tool-card<?= !$toolStatus['compress'] ? ' disabled' : '' ?>" <?= $toolStatus['compress'] ? 'onclick="openModal(\'compress\')"' : '' ?>>
                    <?php if (!$toolStatus['compress']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <div class="tool-icon tool-icon-compress">
                        <i data-lucide="file-minus" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Compress</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Reduce file size without losing quality. Supports JPEG, PNG, WebP.
                    </p>
                </div>

                <!-- Resize -->
                <div class="tool-card<?= !$toolStatus['resize'] ? ' disabled' : '' ?>" <?= $toolStatus['resize'] ? 'onclick="openModal(\'resize\')"' : '' ?>>
                    <?php if (!$toolStatus['resize']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <div class="tool-icon tool-icon-resize">
                        <i data-lucide="scaling" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Resize</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Change dimensions while maintaining aspect ratio or exact sizes.
                    </p>
                </div>

                <!-- Crop -->
                <div class="tool-card<?= !$toolStatus['crop'] ? ' disabled' : '' ?>" <?= $toolStatus['crop'] ? 'onclick="openModal(\'crop\')"' : '' ?>>
                    <?php if (!$toolStatus['crop']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <div class="tool-icon tool-icon-crop">
                        <i data-lucide="crop" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Crop</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Crop to standard ratios: 1:1, 4:3, 16:9, or custom dimensions.
                    </p>
                </div>

                <!-- Convert -->
                <div class="tool-card<?= !$toolStatus['convert'] ? ' disabled' : '' ?>" <?= $toolStatus['convert'] ? 'onclick="openModal(\'convert\')"' : '' ?>>
                    <?php if (!$toolStatus['convert']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <div class="tool-icon tool-icon-convert">
                        <i data-lucide="repeat" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">Convert</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Convert between formats: JPEG, PNG, WebP, GIF, BMP.
                    </p>
                </div>

                <!-- OCR -->
                <div class="tool-card<?= !$toolStatus['ocr'] ? ' disabled' : '' ?>" <?= $toolStatus['ocr'] ? ($isLoggedIn ? 'onclick="openModal(\'ocr\')"' : 'onclick="requireLogin(\'OCR\')"') : '' ?>>
                    <?php if (!$toolStatus['ocr']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <?php if (!$isLoggedIn && $toolStatus['ocr']): ?><span class="login-badge">Login Required</span><?php endif; ?>
                    <div class="tool-icon tool-icon-ocr">
                        <i data-lucide="scan-text" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">OCR</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Extract text from images. Supports multiple languages.
                    </p>
                </div>

                <!-- Remove Background (NEW) -->
                <div class="tool-card<?= !$toolStatus['rembg'] ? ' disabled' : '' ?>" <?= $toolStatus['rembg'] ? ($isLoggedIn ? 'onclick="openModal(\'rembg\')"' : 'onclick="requireLogin(\'Remove Background\')"') : '' ?>>
                    <?php if (!$toolStatus['rembg']): ?><span class="disabled-badge">Disabled</span><?php endif; ?>
                    <?php if (!$isLoggedIn && $toolStatus['rembg']): ?><span class="login-badge">Login Required</span><?php endif; ?>
                    <div class="tool-icon" style="background: linear-gradient(135deg, #8b5cf6, #ec4899);">
                        <i data-lucide="eraser" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">
                        Remove Background
                        <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">AI</span>
                    </h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        AI-powered background removal in seconds. Perfect for product photos.
                    </p>
                </div>

            </div>

        </div>
    </main>

    <!-- Compress Modal -->
    <div id="modal-compress" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('compress')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('compress')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="file-minus" class="w-6 h-6 inline-block mr-2 text-cyan-400"></i>
                Compress Image
            </h2>

            <form id="compress-form" onsubmit="handleCompress(event)">
                <div class="drop-zone-mini" id="compress-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="compress-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="compress-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="compress-filename"></p>
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Quality: <span id="compress-quality-value">80</span>%</label>
                    <input type="range" min="10" max="100" value="80" class="w-full" id="compress-quality"
                           oninput="document.getElementById('compress-quality-value').textContent = this.value">
                </div>

                <div class="form-group">
                    <label class="form-label">Output Format</label>
                    <select class="form-select" id="compress-format">
                        <option value="auto">Auto (WebP for best compression)</option>
                        <option value="original">Keep Original Format</option>
                    </select>
                </div>

                <div class="processing" id="compress-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Compressing...</span>
                </div>

                <div class="result-area" id="compress-result">
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="compress-original-size">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="compress-new-size">-</div>
                            <div class="stat-label">Compressed</div>
                        </div>
                    </div>
                    <p class="text-center mb-4" style="color: var(--color-accent);" id="compress-savings"></p>
                    <button type="button" class="btn-primary w-full" id="compress-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="compress-submit">
                    <i data-lucide="zap" class="w-4 h-4"></i>
                    Compress Image
                </button>
            </form>
        </div>
    </div>

    <!-- Resize Modal -->
    <div id="modal-resize" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('resize')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('resize')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="scaling" class="w-6 h-6 inline-block mr-2 text-purple-400"></i>
                Resize Image
            </h2>

            <form id="resize-form" onsubmit="handleResize(event)">
                <div class="drop-zone-mini" id="resize-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="resize-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="resize-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="resize-dimensions"></p>
                </div>

                <div class="form-row mt-6">
                    <div class="form-group">
                        <label class="form-label">Width (px)</label>
                        <div class="number-input-inline">
                            <button type="button" onclick="adjustNumber('resize-width', -10)">−</button>
                            <input type="number" class="form-input" id="resize-width" placeholder="Auto" min="1">
                            <button type="button" onclick="adjustNumber('resize-width', 10)">+</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Height (px)</label>
                        <div class="number-input-inline">
                            <button type="button" onclick="adjustNumber('resize-height', -10)">−</button>
                            <input type="number" class="form-input" id="resize-height" placeholder="Auto" min="1">
                            <button type="button" onclick="adjustNumber('resize-height', 10)">+</button>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Mode</label>
                    <select class="form-select" id="resize-mode">
                        <option value="fit">Fit (maintain aspect ratio)</option>
                        <option value="fill">Fill (crop to fit)</option>
                        <option value="exact">Exact (may distort)</option>
                    </select>
                </div>

                <div class="processing" id="resize-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Resizing...</span>
                </div>

                <div class="result-area" id="resize-result">
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="resize-original-dims">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="resize-new-dims">-</div>
                            <div class="stat-label">New Size</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full" id="resize-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="resize-submit">
                    <i data-lucide="scaling" class="w-4 h-4"></i>
                    Resize Image
                </button>
            </form>
        </div>
    </div>

    <!-- Crop Modal -->
    <div id="modal-crop" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('crop')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('crop')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="crop" class="w-6 h-6 inline-block mr-2 text-pink-400"></i>
                Crop Image
            </h2>

            <form id="crop-form" onsubmit="handleCrop(event)">
                <div class="drop-zone-mini" id="crop-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="crop-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="crop-preview">
                    <img src="" alt="Preview" class="preview-image">
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Aspect Ratio</label>
                    <select class="form-select" id="crop-aspect">
                        <option value="">Free (Custom)</option>
                        <option value="1:1">1:1 (Square)</option>
                        <option value="4:3">4:3 (Standard)</option>
                        <option value="3:4">3:4 (Portrait)</option>
                        <option value="16:9">16:9 (Widescreen)</option>
                        <option value="9:16">9:16 (Stories)</option>
                        <option value="3:2">3:2 (Photo)</option>
                        <option value="2:3">2:3 (Portrait Photo)</option>
                    </select>
                </div>

                <div class="processing" id="crop-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Cropping...</span>
                </div>

                <div class="result-area" id="crop-result">
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="crop-original-dims">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="crop-new-dims">-</div>
                            <div class="stat-label">Cropped</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full" id="crop-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="crop-submit">
                    <i data-lucide="crop" class="w-4 h-4"></i>
                    Crop Image
                </button>
            </form>
        </div>
    </div>

    <!-- Convert Modal -->
    <div id="modal-convert" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('convert')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('convert')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="repeat" class="w-6 h-6 inline-block mr-2 text-orange-400"></i>
                Convert Format
            </h2>

            <form id="convert-form" onsubmit="handleConvert(event)">
                <div class="drop-zone-mini" id="convert-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="convert-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="convert-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="convert-source-format"></p>
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Convert to</label>
                    <select class="form-select" id="convert-format">
                        <option value="webp">WebP (Modern, small size)</option>
                        <option value="jpg">JPEG (Universal)</option>
                        <option value="png">PNG (Lossless, transparency)</option>
                        <option value="gif">GIF (Animation support)</option>
                        <option value="bmp">BMP (Uncompressed)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Quality: <span id="convert-quality-value">90</span>%</label>
                    <input type="range" min="10" max="100" value="90" class="w-full" id="convert-quality"
                           oninput="document.getElementById('convert-quality-value').textContent = this.value">
                </div>

                <div class="processing" id="convert-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Converting...</span>
                </div>

                <div class="result-area" id="convert-result">
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="convert-original-size">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="convert-new-size">-</div>
                            <div class="stat-label">Converted</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full" id="convert-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="convert-submit">
                    <i data-lucide="repeat" class="w-4 h-4"></i>
                    Convert Image
                </button>
            </form>
        </div>
    </div>

    <!-- OCR Modal -->
    <div id="modal-ocr" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('ocr')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('ocr')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="scan-text" class="w-6 h-6 inline-block mr-2 text-emerald-400"></i>
                Extract Text (OCR)
            </h2>

            <form id="ocr-form" onsubmit="handleOCR(event)">
                <div class="drop-zone-mini" id="ocr-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <input type="file" id="ocr-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="ocr-preview">
                    <img src="" alt="Preview" class="preview-image">
                </div>

                <div class="form-group mt-6">
                    <label class="form-label">Language</label>
                    <select class="form-select" id="ocr-language">
                        <option value="eng">English</option>
                        <option value="ind">Indonesian</option>
                        <option value="jpn">Japanese</option>
                        <option value="chi_sim">Chinese (Simplified)</option>
                        <option value="chi_tra">Chinese (Traditional)</option>
                        <option value="kor">Korean</option>
                        <option value="eng+ind">English + Indonesian</option>
                        <option value="eng+jpn">English + Japanese</option>
                    </select>
                </div>

                <div class="processing" id="ocr-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Extracting text...</span>
                </div>

                <div class="result-area" id="ocr-result">
                    <div class="result-stats mb-4">
                        <div class="stat-item">
                            <div class="stat-value" id="ocr-word-count">-</div>
                            <div class="stat-label">Words</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="ocr-line-count">-</div>
                            <div class="stat-label">Lines</div>
                        </div>
                    </div>
                    <div class="ocr-result" id="ocr-text"></div>
                    <button type="button" class="btn-primary w-full mt-4" id="ocr-copy">
                        <i data-lucide="copy" class="w-4 h-4"></i>
                        Copy Text
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="ocr-submit">
                    <i data-lucide="scan-text" class="w-4 h-4"></i>
                    Extract Text
                </button>
            </form>
        </div>
    </div>

    <!-- Remove Background Modal -->
    <div id="modal-rembg" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('rembg')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('rembg')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="eraser" class="w-6 h-6 inline-block mr-2 text-purple-400"></i>
                Remove Background
                <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">AI</span>
            </h2>

            <form id="rembg-form" onsubmit="handleRembg(event)">
                <div class="drop-zone-mini" id="rembg-drop">
                    <i data-lucide="image-plus" class="w-10 h-10 mx-auto mb-3" style="color: var(--color-text-muted);"></i>
                    <p style="color: var(--color-text-secondary);">Drop image here or click to select</p>
                    <p class="text-xs mt-2" style="color: var(--color-text-muted);">Best for: portraits, products, objects</p>
                    <input type="file" id="rembg-file" accept="image/*" class="hidden">
                </div>

                <div class="preview-container" id="rembg-preview">
                    <img src="" alt="Preview" class="preview-image">
                    <p class="text-sm mt-2" style="color: var(--color-text-muted);" id="rembg-filename"></p>
                </div>

                <div class="processing" id="rembg-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Removing background... This may take a moment.</span>
                </div>

                <div class="result-area" id="rembg-result">
                    <div class="text-center mb-4">
                        <img src="" alt="Result" id="rembg-result-image" class="preview-image" style="max-height: 250px; background: repeating-conic-gradient(#808080 0% 25%, transparent 0% 50%) 50% / 16px 16px; border-radius: 8px;">
                    </div>
                    <div class="result-stats">
                        <div class="stat-item">
                            <div class="stat-value" id="rembg-original-size">-</div>
                            <div class="stat-label">Original</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value" id="rembg-new-size">-</div>
                            <div class="stat-label">Result (PNG)</div>
                        </div>
                    </div>
                    <button type="button" class="btn-primary w-full mt-4" id="rembg-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download PNG
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="rembg-submit">
                    <i data-lucide="eraser" class="w-4 h-4"></i>
                    Remove Background
                </button>
            </form>
        </div>
    </div>

    <!-- Footer -->
    <footer class="relative z-10 text-center py-8 px-4">
        <p class="text-sm" style="color: var(--color-text-muted);">
            © 2025 PixelHop. All processing happens on our secure servers.
        </p>
    </footer>

    <script>
        lucide.createIcons();


        function adjustNumber(inputId, delta) {
            const input = document.getElementById(inputId);
            if (!input) return;
            let value = parseInt(input.value) || 0;
            value = Math.max(1, value + delta);
            input.value = value;
            input.dispatchEvent(new Event('input'));
        }


        const themeToggle = document.getElementById('theme-toggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                const html = document.documentElement;
                const currentTheme = html.getAttribute('data-theme');
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                html.setAttribute('data-theme', newTheme);
                localStorage.setItem('pixelhop-theme', newTheme);
                lucide.createIcons();
            });
        }


        const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
        const aiTools = ['ocr', 'rembg'];

        function openModal(tool) {

            if (window.toolStatus && window.toolStatus[tool] === 0) {
                alert('This tool is currently disabled for maintenance.');
                return;
            }


            if (aiTools.includes(tool) && !isLoggedIn) {
                requireLogin(tool === 'ocr' ? 'OCR' : 'Remove Background');
                return;
            }

            document.getElementById('modal-' + tool).classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
        }

        function closeModal(tool) {
            document.getElementById('modal-' + tool).classList.remove('active');
            document.body.style.overflow = '';
        }


        function requireLogin(toolName) {
            if (confirm(toolName + ' requires a free account. Would you like to register now?')) {
                window.location.href = '/register.php';
            }
        }


        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.tool-modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = '';
            }
        });


        const tools = ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg'];
        const fileData = {};

        tools.forEach(tool => {
            const dropZone = document.getElementById(tool + '-drop');
            const fileInput = document.getElementById(tool + '-file');
            const preview = document.getElementById(tool + '-preview');

            dropZone.addEventListener('click', () => fileInput.click());

            dropZone.addEventListener('dragover', (e) => {
                e.preventDefault();
                dropZone.classList.add('dragover');
            });

            dropZone.addEventListener('dragleave', () => {
                dropZone.classList.remove('dragover');
            });

            dropZone.addEventListener('drop', (e) => {
                e.preventDefault();
                dropZone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    handleFile(tool, e.dataTransfer.files[0]);
                }
            });

            fileInput.addEventListener('change', () => {
                if (fileInput.files.length) {
                    handleFile(tool, fileInput.files[0]);
                }
            });
        });

        function handleFile(tool, file) {
            if (!file.type.startsWith('image/')) {
                alert('Please select an image file');
                return;
            }

            fileData[tool] = file;

            const preview = document.getElementById(tool + '-preview');
            const img = preview.querySelector('img');

            const reader = new FileReader();
            reader.onload = (e) => {
                img.src = e.target.result;
                preview.classList.add('has-image');


                if (document.getElementById(tool + '-filename')) {
                    document.getElementById(tool + '-filename').textContent =
                        file.name + ' (' + formatSize(file.size) + ')';
                }
                if (document.getElementById(tool + '-source-format')) {
                    document.getElementById(tool + '-source-format').textContent =
                        'Current format: ' + file.type.split('/')[1].toUpperCase();
                }


                if (tool === 'resize') {
                    const tempImg = new Image();
                    tempImg.onload = () => {
                        document.getElementById('resize-dimensions').textContent =
                            `${tempImg.width} × ${tempImg.height}px`;
                    };
                    tempImg.src = e.target.result;
                }
            };
            reader.readAsDataURL(file);


            document.getElementById(tool + '-result').classList.remove('show');
        }

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        }


        async function handleCompress(e) {
            e.preventDefault();
            if (!fileData.compress) {
                alert('Please select an image first');
                return;
            }

            const processing = document.getElementById('compress-processing');
            const result = document.getElementById('compress-result');
            const submit = document.getElementById('compress-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            const formData = new FormData();
            formData.append('image', fileData.compress);
            formData.append('quality', document.getElementById('compress-quality').value);
            formData.append('format', document.getElementById('compress-format').value);
            formData.append('return', 'json');

            try {
                const response = await fetch('/api/compress.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                document.getElementById('compress-original-size').textContent = formatSize(data.original_size || 0);
                document.getElementById('compress-new-size').textContent = formatSize(data.new_size || 0);
                document.getElementById('compress-savings').textContent =
                    `Saved ${data.savings_percent || 0}% (${formatSize(data.savings || 0)})`;


                document.getElementById('compress-download').onclick = () => {
                    downloadDataUrl(data.data, data.filename);
                };

                result.classList.add('show');
            } catch (err) {
                alert('Error: ' + err.message);
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleResize(e) {
            e.preventDefault();
            if (!fileData.resize) {
                alert('Please select an image first');
                return;
            }

            const width = document.getElementById('resize-width').value;
            const height = document.getElementById('resize-height').value;

            if (!width && !height) {
                alert('Please specify width or height');
                return;
            }

            const processing = document.getElementById('resize-processing');
            const result = document.getElementById('resize-result');
            const submit = document.getElementById('resize-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            const formData = new FormData();
            formData.append('image', fileData.resize);
            if (width) formData.append('width', width);
            if (height) formData.append('height', height);
            formData.append('mode', document.getElementById('resize-mode').value);
            formData.append('return', 'json');

            try {
                const response = await fetch('/api/resize.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                document.getElementById('resize-original-dims').textContent =
                    `${data.original_width}×${data.original_height}`;
                document.getElementById('resize-new-dims').textContent =
                    `${data.new_width}×${data.new_height}`;

                document.getElementById('resize-download').onclick = () => {
                    downloadDataUrl(data.data, data.filename);
                };

                result.classList.add('show');
            } catch (err) {
                alert('Error: ' + err.message);
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleCrop(e) {
            e.preventDefault();
            if (!fileData.crop) {
                alert('Please select an image first');
                return;
            }

            const processing = document.getElementById('crop-processing');
            const result = document.getElementById('crop-result');
            const submit = document.getElementById('crop-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            const formData = new FormData();
            formData.append('image', fileData.crop);
            formData.append('aspect', document.getElementById('crop-aspect').value);
            formData.append('return', 'json');

            try {
                const response = await fetch('/api/crop.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                document.getElementById('crop-original-dims').textContent =
                    `${data.original_width}×${data.original_height}`;
                document.getElementById('crop-new-dims').textContent =
                    `${data.crop_width}×${data.crop_height}`;

                document.getElementById('crop-download').onclick = () => {
                    downloadDataUrl(data.data, data.filename);
                };

                result.classList.add('show');
            } catch (err) {
                alert('Error: ' + err.message);
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleConvert(e) {
            e.preventDefault();
            if (!fileData.convert) {
                alert('Please select an image first');
                return;
            }

            const processing = document.getElementById('convert-processing');
            const result = document.getElementById('convert-result');
            const submit = document.getElementById('convert-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            const formData = new FormData();
            formData.append('image', fileData.convert);
            formData.append('format', document.getElementById('convert-format').value);
            formData.append('quality', document.getElementById('convert-quality').value);
            formData.append('return', 'json');

            try {
                const response = await fetch('/api/convert.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                document.getElementById('convert-original-size').textContent = formatSize(data.original_size);
                document.getElementById('convert-new-size').textContent = formatSize(data.new_size);

                document.getElementById('convert-download').onclick = () => {
                    downloadDataUrl(data.data, data.filename);
                };

                result.classList.add('show');
            } catch (err) {
                alert('Error: ' + err.message);
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleOCR(e) {
            e.preventDefault();
            if (!fileData.ocr) {
                alert('Please select an image first');
                return;
            }

            const processing = document.getElementById('ocr-processing');
            const result = document.getElementById('ocr-result');
            const submit = document.getElementById('ocr-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            const formData = new FormData();
            formData.append('image', fileData.ocr);
            formData.append('language', document.getElementById('ocr-language').value);

            try {
                const response = await fetch('/api/ocr.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                document.getElementById('ocr-word-count').textContent = data.word_count;
                document.getElementById('ocr-line-count').textContent = data.line_count;
                document.getElementById('ocr-text').textContent = data.text || '(No text detected)';

                document.getElementById('ocr-copy').onclick = () => {
                    navigator.clipboard.writeText(data.text);
                    const btn = document.getElementById('ocr-copy');
                    btn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Copied!';
                    lucide.createIcons();
                    setTimeout(() => {
                        btn.innerHTML = '<i data-lucide="copy" class="w-4 h-4"></i> Copy Text';
                        lucide.createIcons();
                    }, 2000);
                };

                result.classList.add('show');
            } catch (err) {
                alert('Error: ' + err.message);
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleRembg(e) {
            e.preventDefault();
            if (!fileData.rembg) {
                alert('Please select an image first');
                return;
            }

            const processing = document.getElementById('rembg-processing');
            const result = document.getElementById('rembg-result');
            const submit = document.getElementById('rembg-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            const formData = new FormData();
            formData.append('image', fileData.rembg);
            formData.append('return', 'json');

            try {
                const response = await fetch('/api/rembg.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                document.getElementById('rembg-original-size').textContent = formatSize(data.original_size);
                document.getElementById('rembg-new-size').textContent = formatSize(data.new_size);
                document.getElementById('rembg-result-image').src = data.data;

                document.getElementById('rembg-download').onclick = () => {
                    downloadDataUrl(data.data, data.filename);
                };

                result.classList.add('show');
            } catch (err) {
                alert('Error: ' + err.message);
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        function downloadDataUrl(dataUrl, filename) {
            const link = document.createElement('a');
            link.href = dataUrl;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }


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


        if (typeof gsap !== 'undefined') {
            gsap.fromTo('.tool-card',
                { opacity: 0.3, y: 20 },
                { opacity: 1, y: 0, duration: 0.4, stagger: 0.08, ease: 'power2.out' }
            );
        }


        if (window.autoOpenTool && ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg'].includes(window.autoOpenTool)) {
            setTimeout(() => {
                openModal(window.autoOpenTool);
            }, 300);
        }
    </script>
</body>
</html>