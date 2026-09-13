<?php
/**
 * PixelHop - Image Tools Page
 * All image processing tools in one place
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/auth/middleware.php';
require_once __DIR__ . '/core/Gatekeeper.php';
require_once __DIR__ . '/includes/ToolRegistry.php';

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
    'upscale' => $gatekeeper->getSetting('tool_upscale_enabled', 1),
    'erase' => $gatekeeper->getSetting('tool_erase_enabled', 1),
    'faceblur' => $gatekeeper->getSetting('tool_faceblur_enabled', 1),
    'palette' => $gatekeeper->getSetting('tool_palette_enabled', 1),
    'qr' => $gatekeeper->getSetting('tool_qr_enabled', 1),
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

    <!-- Google Fonts - Inter & Space Grotesk -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

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
            padding: 0;
        }

        .modal-content h2 {
            position: sticky;
            top: 0;
            margin: 0;
            padding: 20px 24px;
            background: var(--glass-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-bottom: 1px solid var(--glass-border);
            z-index: 20;
        }

        .modal-content > form {
            padding: 24px;
        }

        .modal-content form button[type="submit"] {
            position: sticky;
            bottom: 0;
            margin-top: 20px;
            z-index: 20;
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

        .before-after-wrap {
            position: relative;
            width: 100%;
            overflow: hidden;
            border-radius: var(--radius-xl);
            border: 1px solid var(--glass-border);
            background: var(--color-bg-secondary);
            isolation: isolate;
            touch-action: none;
        }

        .before-after-wrap img {
            display: block;
            width: 100%;
            height: auto;
            user-select: none;
            -webkit-user-drag: none;
        }

        .before-after-wrap .before-layer,
        .before-after-wrap .after-layer {
            position: absolute;
            inset: 0;
            overflow: hidden;
        }

        .before-after-wrap .before-layer img,
        .before-after-wrap .after-layer img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .before-after-slider {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(to bottom, rgba(34,211,238,0.85), rgba(168,85,247,0.85));
            transform: translateX(-50%);
            cursor: ew-resize;
            z-index: 10;
            box-shadow: 0 0 15px rgba(34,211,238,0.4);
        }

        .before-after-handle {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 40px;
            height: 40px;
            transform: translate(-50%, -50%);
            border-radius: 50%;
            background: rgba(15, 22, 41, 0.85);
            border: 2px solid rgba(255,255,255,0.25);
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            pointer-events: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
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
                            <a href="/admin/dashboard" class="flex items-center gap-3 px-4 py-2 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-colors">
                                <i data-lucide="shield" class="w-4 h-4 text-neon-purple"></i>
                                Admin Dashboard
                            </a>
                            <?php endif; ?>
                            <a href="/dashboard" class="flex items-center gap-3 px-4 py-2 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-colors">
                                <i data-lucide="layout-dashboard" class="w-4 h-4 text-neon-cyan"></i>
                                My Dashboard
                            </a>
                            <a href="/" class="flex items-center gap-3 px-4 py-2 text-sm text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-colors">
                                <i data-lucide="upload" class="w-4 h-4 text-neon-pink"></i>
                                Upload
                            </a>
                            <div class="border-t my-2" style="border-color: var(--glass-border);"></div>
                            <a href="/auth/logout" class="flex items-center gap-3 px-4 py-2 text-sm text-red-400 hover:text-red-300 hover:bg-[var(--glass-bg-hover)] transition-colors">
                                <i data-lucide="log-out" class="w-4 h-4"></i>
                                Logout
                            </a>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Guest Menu -->
                    <a href="/login" class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-xl text-[var(--color-text-secondary)] hover:text-[var(--color-text-primary)] hover:bg-[var(--glass-bg-hover)] transition-all">
                        <i data-lucide="log-in" class="w-4 h-4"></i>
                        <span class="text-sm font-medium">Login</span>
                    </a>
                    <a href="/register" class="hidden sm:flex items-center gap-2 px-3 py-2 rounded-xl bg-gradient-to-r from-neon-cyan to-neon-purple text-white hover:opacity-90 transition-all">
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
            <div class="text-center mb-8">
                <h1 class="text-3xl md:text-4xl font-bold mb-3" style="color: var(--color-text-primary);">
                    Image Tools
                </h1>
                <p style="color: var(--color-text-tertiary);">
                    Free, fast, and privacy-focused image processing
                </p>
            </div>
            
            <!-- Info Notice -->
            <div class="mb-8 p-4 rounded-lg" style="background: rgba(34, 211, 238, 0.1); border: 1px solid rgba(34, 211, 238, 0.3);">
                <div class="flex items-start gap-3">
                    <i data-lucide="info" class="w-5 h-5 mt-0.5 flex-shrink-0" style="color: #22d3ee;"></i>
                    <div class="text-sm" style="color: var(--color-text-secondary);">
                        <strong style="color: #22d3ee;">Temporary Storage:</strong> 
                        Processed images are stored temporarily for <strong>6 hours</strong> and then automatically deleted. 
                        Download your results before they expire. Files are NOT uploaded to permanent storage.
                        <?php if (!$isLoggedIn): ?>
                        <a href="/register" class="ml-1 underline" style="color: #22d3ee;">Register</a> to save images permanently.
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- AI Tools Section -->
            <div class="mb-10">
                <h2 class="text-xl font-bold mb-5 flex items-center gap-2" style="color: var(--color-text-primary);">
                    <i data-lucide="sparkles" class="w-5 h-5 text-neon-purple"></i>
                    AI Tools
                </h2>
                <div class="tool-grid">
<?php foreach (ph_tool_registry() as $phTool): if (($phTool['section'] ?? 'quick') !== 'ai') continue; $phToolSection = 'card'; $partial = ToolRegistry::partial($phTool['id']); if (file_exists($partial)) require $partial; endforeach; ?>
                </div>
            </div>

            <!-- Quick Tools Section -->
            <div class="mb-10">
                <h2 class="text-xl font-bold mb-5 flex items-center gap-2" style="color: var(--color-text-primary);">
                    <i data-lucide="zap" class="w-5 h-5 text-neon-cyan"></i>
                    Quick Tools
                </h2>
                <div class="tool-grid">
<?php foreach (ph_tool_registry() as $phTool):
    if (($phTool['section'] ?? 'quick') !== 'quick') continue;
    if ($phTool['id'] === 'qr'):
        /* QR tool is client-side and has no server partial */
?>
                <!-- QR Code -->
                <div class="tool-card" onclick="openModal('qr')">
                    <div class="tool-icon" style="background: linear-gradient(135deg, #22d3ee, #10b981);">
                        <i data-lucide="qr-code" class="w-7 h-7 text-white"></i>
                    </div>
                    <h3 class="text-lg font-semibold mb-2" style="color: var(--color-text-primary);">
                        QR Code
                        <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">Instant</span>
                    </h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">
                        Generate a shareable QR code for any image URL in your browser.
                    </p>
                </div>
<?php else:
    $phToolSection = 'card';
    $partial = ToolRegistry::partial($phTool['id']);
    if (file_exists($partial)) require $partial;
    endif;
endforeach; ?>
                </div>
            </div>

        </div>
    </main>

<?php foreach (ph_tool_registry() as $phTool):
    $phToolSection = 'modal';
    $partial = ToolRegistry::partial($phTool['id']);
    if (file_exists($partial)) require $partial;
endforeach; ?>

    <!-- QR Code Modal (client-side, no server endpoint) -->
    <div id="modal-qr" class="tool-modal">
        <div class="modal-backdrop" onclick="closeModal('qr')"></div>
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('qr')">
                <i data-lucide="x" class="w-5 h-5" style="color: var(--color-text-secondary);"></i>
            </button>

            <h2 class="text-xl font-bold mb-6" style="color: var(--color-text-primary);">
                <i data-lucide="qr-code" class="w-6 h-6 inline-block mr-2 text-cyan-400"></i>
                QR Code
                <span class="inline-block px-2 py-0.5 text-xs font-medium bg-gradient-to-r from-neon-cyan to-neon-purple text-white rounded-full ml-2">Instant</span>
            </h2>

            <form id="qr-form" onsubmit="handleQR(event)">
                <div class="form-group">
                    <label class="form-label">Image URL</label>
                    <input type="url" id="qr-url" class="form-input" placeholder="https://p.hel.ink/i/abc123" required>
                </div>

                <div class="processing" id="qr-processing">
                    <div class="spinner"></div>
                    <span style="color: var(--color-text-secondary);">Generating...</span>
                </div>

                <div class="result-area" id="qr-result">
                    <div class="text-center mb-4 p-4" style="background: white; border-radius: 12px; display: inline-block;">
                        <canvas id="qr-canvas" style="display:block; margin:0 auto;"></canvas>
                    </div>
                    <p class="text-center text-sm mb-4" style="color: var(--color-text-secondary);" id="qr-dimensions"></p>
                    <button type="button" class="btn-primary w-full" id="qr-download">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download PNG
                    </button>
                </div>

                <button type="submit" class="btn-primary w-full mt-4" id="qr-submit">
                    <i data-lucide="qr-code" class="w-4 h-4"></i>
                    Generate QR
                </button>
            </form>
        </div>
    </div>

    <script src="/assets/js/qrcode-button.js"></script>

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
        const aiTools = ['ocr', 'rembg', 'upscale', 'erase', 'faceblur'];

        function openModal(tool) {

            if (window.toolStatus && window.toolStatus[tool] === 0) {
                showToast('This tool is currently disabled for maintenance.', 'error');
                return;
            }


            if (aiTools.includes(tool) && !isLoggedIn) {
                const names = {
                    ocr: 'OCR',
                    rembg: 'Remove Background',
                    upscale: 'AI HD Upscale',
                    erase: 'Magic Eraser',
                    faceblur: 'Face Blur'
                };
                requireLogin(names[tool] || tool);
                return;
            }

            const modal = document.getElementById('modal-' + tool);
            if (!modal) {
                showToast('Tool modal not found.', 'error');
                return;
            }

            modal.classList.add('active');
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


        const tools = ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg', 'upscale', 'erase', 'palette'];
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
                showToast('Please select an image file', 'error');
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

                if (tool === 'erase' && typeof window.setupEraseEditor === 'function') {
                    window.setupEraseEditor(e.target.result);
                }
            };
            reader.readAsDataURL(file);


            const resultArea = document.getElementById(tool + '-result');
            if (resultArea) resultArea.classList.remove('show');
        }

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        }


        async function handleCompress(e) {
            e.preventDefault();
            if (!fileData.compress) {
                showToast('Please select an image first', 'error');
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
                showToast('Error: ' + err.message, 'error');
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleResize(e) {
            e.preventDefault();
            if (!fileData.resize) {
                showToast('Please select an image first', 'error');
                return;
            }

            const width = document.getElementById('resize-width').value;
            const height = document.getElementById('resize-height').value;

            if (!width && !height) {
                showToast('Please specify width or height', 'error');
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
                showToast('Error: ' + err.message, 'error');
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleCrop(e) {
            e.preventDefault();
            if (!fileData.crop) {
                showToast('Please select an image first', 'error');
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
                showToast('Error: ' + err.message, 'error');
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleConvert(e) {
            e.preventDefault();
            if (!fileData.convert) {
                showToast('Please select an image first', 'error');
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
                showToast('Error: ' + err.message, 'error');
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleOCR(e) {
            e.preventDefault();
            if (!fileData.ocr) {
                showToast('Please select an image first', 'error');
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
                showToast('Error: ' + err.message, 'error');
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleRembg(e) {
            e.preventDefault();
            if (!fileData.rembg) {
                showToast('Please select an image first', 'error');
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
            const modelEl = document.getElementById('rembg-model');
            if (modelEl) {
                formData.append('model', modelEl.value);
            }
            const alphaEl = document.getElementById('rembg-alpha-matting');
            if (alphaEl) {
                formData.append('alpha_matting', alphaEl.checked ? '1' : '0');
            }
            formData.append('return', 'json');
            formData.append('include_data', '1');

            try {
                const response = await fetch('/api/rembg.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                document.getElementById('rembg-original-size').textContent = formatSize(data.original_size);
                document.getElementById('rembg-new-size').textContent = formatSize(data.new_size);

                const resultUrl = data.data || data.view_url;
                const originalSrc = document.querySelector('#rembg-preview img')?.src || '';
                buildBeforeAfter('rembg', originalSrc, resultUrl, data.filename);

                document.getElementById('rembg-download').onclick = () => {
                    if (data.data) {
                        downloadDataUrl(data.data, data.filename);
                    } else if (data.view_url) {
                        const a = document.createElement('a');
                        a.href = data.view_url;
                        a.download = data.filename || 'nobg.png';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    }
                };

                result.classList.add('show');
            } catch (err) {
                showToast('Error: ' + err.message, 'error');
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleUpscale(e) {
            e.preventDefault();
            if (!fileData.upscale) {
                showToast('Please select an image first', 'error');
                return;
            }

            const processing = document.getElementById('upscale-processing');
            const result = document.getElementById('upscale-result');
            const submit = document.getElementById('upscale-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            const formData = new FormData();
            formData.append('image', fileData.upscale);
            formData.append('scale', document.getElementById('upscale-scale').value);
            formData.append('include_data', '1');
            formData.append('return', 'json');

            try {
                const response = await fetch('/api/upscale.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                document.getElementById('upscale-original-size').textContent = formatSize(data.original_size || 0);
                document.getElementById('upscale-new-size').textContent = formatSize(data.new_size || 0);
                document.getElementById('upscale-dimensions').textContent =
                    `${data.original_width || '?'}×${data.original_height || '?'} → ${data.new_width || '?'}×${data.new_height || '?'}`;

                const resultUrl = data.data || data.view_url;
                const originalSrc = document.querySelector('#upscale-preview img')?.src || '';
                buildBeforeAfter('upscale', originalSrc, resultUrl, data.filename);

                document.getElementById('upscale-download').onclick = () => {
                    if (data.data) {
                        downloadDataUrl(data.data, data.filename);
                    } else if (data.view_url) {
                        const a = document.createElement('a');
                        a.href = data.view_url;
                        a.download = data.filename || 'upscaled.png';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    }
                };

                result.classList.add('show');
            } catch (err) {
                showToast('Error: ' + err.message, 'error');
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        async function handleErase(e) {
            e.preventDefault();
            if (!fileData.erase) {
                showToast('Please select an image first', 'error');
                return;
            }
            if (typeof window.hasEraseMask === 'function' && !window.hasEraseMask()) {
                showToast('Please paint the area to erase first', 'error');
                return;
            }

            const processing = document.getElementById('erase-processing');
            const result = document.getElementById('erase-result');
            const submit = document.getElementById('erase-submit');

            processing.classList.add('show');
            result.classList.remove('show');
            submit.disabled = true;

            const formData = new FormData();
            formData.append('image', fileData.erase);
            // Send mask as a POST string (data URL) — backend reads $_POST['mask']
            if (typeof window.renderEraseMask === 'function') {
                const maskDataUrl = window.renderEraseMask();
                if (maskDataUrl) {
                    formData.append('mask', maskDataUrl);
                }
            }
            formData.append('return', 'json');
            formData.append('include_data', '1');

            try {
                const response = await fetch('/api/erase.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.error) throw new Error(data.error);

                document.getElementById('erase-original-size').textContent = formatSize(data.original_size || 0);
                document.getElementById('erase-new-size').textContent = formatSize(data.new_size || 0);

                const resultUrl = data.data || data.view_url;
                const originalSrc = document.querySelector('#erase-preview img')?.src || '';
                buildBeforeAfter('erase', originalSrc, resultUrl, data.filename);

                document.getElementById('erase-download').onclick = () => {
                    if (data.data) {
                        downloadDataUrl(data.data, data.filename);
                    } else if (data.view_url) {
                        const a = document.createElement('a');
                        a.href = data.view_url;
                        a.download = data.filename || 'erased.png';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                    }
                };

                result.classList.add('show');
            } catch (err) {
                showToast('Error: ' + err.message, 'error');
            }

            processing.classList.remove('show');
            submit.disabled = false;
        }


        function handleQR(e) {
            e.preventDefault();

            const urlInput = document.getElementById('qr-url');
            const text = urlInput ? urlInput.value.trim() : '';

            if (!text) {
                if (typeof showToast === 'function') {
                    showToast('Please enter an image URL or text', 'error');
                }
                return;
            }

            const processing = document.getElementById('qr-processing');
            const result = document.getElementById('qr-result');
            const submit = document.getElementById('qr-submit');
            const canvasPlaceholder = document.getElementById('qr-canvas');
            const dimensionsEl = document.getElementById('qr-dimensions');
            const downloadBtn = document.getElementById('qr-download');

            if (processing) processing.classList.add('show');
            if (result) result.classList.remove('show');
            if (submit) submit.disabled = true;

            try {
                if (typeof generateQR !== 'function') {
                    throw new Error('QR generator library is not loaded');
                }

                const canvasParent = canvasPlaceholder ? canvasPlaceholder.parentElement : null;
                if (!canvasParent) {
                    throw new Error('QR container not found');
                }

                const canvas = generateQR(text, canvasParent, {
                    size: 220,
                    level: 'M',
                    fgColor: '#000000',
                    bgColor: '#ffffff'
                });
                canvas.id = 'qr-canvas';
                canvas.style.display = 'block';
                canvas.style.margin = '0 auto';

                if (dimensionsEl) {
                    dimensionsEl.textContent = `${canvas.width} × ${canvas.height} px`;
                }

                if (downloadBtn) {
                    downloadBtn.onclick = () => {
                        const dataUrl = canvas.toDataURL('image/png');
                        downloadDataUrl(dataUrl, 'pixelhop-qr.png');
                    };
                }

                if (result) result.classList.add('show');
                lucide.createIcons();
            } catch (err) {
                if (typeof showToast === 'function') {
                    showToast('Error: ' + err.message, 'error');
                }
            } finally {
                if (processing) processing.classList.remove('show');
                if (submit) submit.disabled = false;
            }
        }

        window.handleQR = handleQR;

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


        function initBeforeAfter(container) {
            if (!container) return;

            const beforeLayer = container.querySelector('.before-layer');
            const slider = container.querySelector('.before-after-slider');
            const beforeImg = container.querySelector('.before-layer img');
            const afterImg = container.querySelector('.after-layer img');
            if (!beforeLayer || !slider || !beforeImg || !afterImg) return;

            let isDragging = false;

            function updatePosition(clientX) {
                const rect = container.getBoundingClientRect();
                let x = clientX - rect.left;
                x = Math.max(0, Math.min(x, rect.width));
                const percent = (x / rect.width) * 100;
                beforeLayer.style.width = percent + '%';
                slider.style.left = percent + '%';
            }

            slider.addEventListener('mousedown', (e) => {
                isDragging = true;
                e.preventDefault();
            });
            slider.addEventListener('touchstart', (e) => {
                isDragging = true;
            }, { passive: true });

            window.addEventListener('mousemove', (e) => {
                if (!isDragging) return;
                updatePosition(e.clientX);
            });
            window.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                updatePosition(e.touches[0].clientX);
            }, { passive: true });

            window.addEventListener('mouseup', () => isDragging = false);
            window.addEventListener('touchend', () => isDragging = false);

            container.addEventListener('click', (e) => {
                if (e.target === slider || slider.contains(e.target)) return;
                updatePosition(e.clientX);
            });
        }

        window.initBeforeAfter = initBeforeAfter;

        function buildBeforeAfter(tool, originalUrl, resultUrl, filename) {
            const resultArea = document.getElementById(tool + '-result');
            const beforeContainer = resultArea ? (resultArea.querySelector('.before-after-wrap') || resultArea.querySelector('.text-center')) : null;
            if (!beforeContainer || !originalUrl || !resultUrl) return;

            const safeFilename = (filename || 'result.png').replace(/[^A-Za-z0-9._-]/g, '_') || 'result.png';

            const wrap = document.createElement('div');
            wrap.className = 'before-after-wrap';
            wrap.style.maxHeight = '320px';

            const afterLayer = document.createElement('div');
            afterLayer.className = 'after-layer';

            const afterImg = document.createElement('img');
            afterImg.src = resultUrl;
            afterImg.alt = 'Result: ' + safeFilename;
            afterImg.style.objectFit = 'contain';
            afterImg.style.background = 'repeating-conic-gradient(#808080 0% 25%, transparent 0% 50%) 50% / 16px 16px';
            afterLayer.appendChild(afterImg);

            const beforeLayer = document.createElement('div');
            beforeLayer.className = 'before-layer';
            beforeLayer.style.width = '50%';

            const beforeImg = document.createElement('img');
            beforeImg.src = originalUrl;
            beforeImg.alt = 'Original';
            beforeImg.style.objectFit = 'contain';
            beforeLayer.appendChild(beforeImg);

            const slider = document.createElement('div');
            slider.className = 'before-after-slider';
            slider.style.left = '50%';

            const handle = document.createElement('div');
            handle.className = 'before-after-handle';

            const icon = document.createElement('i');
            icon.setAttribute('data-lucide', 'move-horizontal');
            icon.className = 'w-4 h-4';
            handle.appendChild(icon);
            slider.appendChild(handle);

            wrap.appendChild(afterLayer);
            wrap.appendChild(beforeLayer);
            wrap.appendChild(slider);

            beforeContainer.replaceChildren(wrap);

            lucide.createIcons();
            initBeforeAfter(wrap);
        }

        window.buildBeforeAfter = buildBeforeAfter;

        if (window.autoOpenTool && ['compress', 'resize', 'crop', 'convert', 'ocr', 'rembg', 'upscale', 'erase', 'faceblur', 'palette', 'qr'].includes(window.autoOpenTool)) {
            setTimeout(() => {
                openModal(window.autoOpenTool);
            }, 300);
        }
    </script>
</body>
</html>