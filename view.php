<?php
/**
 * PixelHop - Image View Page
 * Displays individual images with sharing options
 */

// Load config
$config = require __DIR__ . '/config/s3.php';

// Get image ID from URL
$requestUri = $_SERVER['REQUEST_URI'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Check if this is a result page request
if ($path === '/result' || strpos($path, '/result') === 0) {
    include __DIR__ . '/result.php';
    exit;
}

$imageId = trim($path, '/');

// Clean the ID (remove any query strings or extensions)
$imageId = preg_replace('/[^a-zA-Z0-9]/', '', $imageId);

// Load image data
$dataFile = __DIR__ . '/data/images.json';
$images = [];

if (file_exists($dataFile)) {
    $content = file_get_contents($dataFile);
    $images = json_decode($content, true) ?: [];
}

// Check if image exists
if (empty($imageId) || !isset($images[$imageId])) {

    http_response_code(404);
    include __DIR__ . '/404.php';
    exit;
}

$image = $images[$imageId];
$siteUrl = $config['site']['url'];
$siteName = $config['site']['name'];

// Format file size
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}

// Format date
function formatDate($timestamp) {
    return date('M j, Y \a\t g:i A', $timestamp);
}

$fileSize = formatBytes($image['size']);
$uploadDate = formatDate($image['created_at']);
$dimensions = "{$image['width']} × {$image['height']}";
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View image on <?= htmlspecialchars($siteName) ?>">

    <!-- Open Graph -->
    <meta property="og:title" content="Image on <?= htmlspecialchars($siteName) ?>">
    <meta property="og:description" content="<?= $dimensions ?> • <?= $fileSize ?>">
    <meta property="og:image" content="<?= htmlspecialchars($image['urls']['large'] ?? $image['urls']['original']) ?>">
    <meta property="og:url" content="<?= $siteUrl ?>/<?= $imageId ?>">
    <meta property="og:type" content="website">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Image on <?= htmlspecialchars($siteName) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($image['urls']['large'] ?? $image['urls']['original']) ?>">

    <title>Image - <?= htmlspecialchars($siteName) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">

    <!-- Theme detection -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            const theme = savedTheme || 'dark';
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

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>

    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/css/glass.css">

    <style>
        .image-frame {
            background: var(--glass-bg);
            backdrop-filter: blur(var(--glass-blur)) saturate(180%);
            -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(180%);
            border-radius: var(--radius-2xl);
            padding: 16px;
            position: relative;
        }

        .image-frame::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(
                135deg,
                rgba(255, 255, 255, 0.15) 0%,
                rgba(255, 255, 255, 0.05) 50%,
                rgba(255, 255, 255, 0.1) 100%
            );
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .image-container {
            border-radius: var(--radius-xl);
            overflow: hidden;
            background: rgba(0, 0, 0, 0.2);
        }

        .image-container img {
            max-width: 100%;
            max-height: 70vh;
            object-fit: contain;
            display: block;
            margin: 0 auto;
        }

        .info-card {
            background: var(--glass-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--glass-border-glow);
            border-radius: var(--radius-xl);
            padding: 20px;
        }

        .link-row {
            display: flex;
            gap: 8px;
            margin-bottom: 12px;
        }

        .link-row:last-child {
            margin-bottom: 0;
        }

        .link-label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--color-text-muted);
            margin-bottom: 6px;
        }

        .link-input {
            flex: 1;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border-glow);
            border-radius: var(--radius-md);
            padding: 10px 12px;
            font-size: 12px;
            color: var(--color-text-secondary);
            outline: none;
            min-width: 0;
        }

        .link-input:focus {
            border-color: var(--color-neon-cyan);
        }

        .copy-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border-glow);
            border-radius: var(--radius-md);
            color: var(--color-text-secondary);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .copy-btn:hover {
            background: var(--color-neon-cyan);
            color: var(--color-bg-primary);
            border-color: var(--color-neon-cyan);
        }

        .copy-btn.copied {
            background: var(--color-neon-green);
            color: var(--color-bg-primary);
            border-color: var(--color-neon-green);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--color-text-tertiary);
        }

        .meta-item i {
            width: 16px;
            height: 16px;
            color: var(--color-neon-cyan);
        }

        .size-btn {
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 500;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border-glow);
            border-radius: var(--radius-md);
            color: var(--color-text-secondary);
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .size-btn:hover {
            background: rgba(255, 255, 255, 0.08);
            color: var(--color-text-primary);
            border-color: var(--glass-border-hover);
        }

        .size-btn.active {
            background: var(--color-neon-cyan);
            color: var(--color-bg-primary);
            border-color: var(--color-neon-cyan);
        }
    </style>
</head>
<body class="min-h-screen font-sans overflow-x-hidden">

    <!-- Background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)]"></div>
        <div class="blob blob-cyan" style="top: -20%; left: -15%;"></div>
        <div class="blob blob-purple" style="bottom: -20%; right: -15%;"></div>
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
                    <a href="/" class="btn-secondary text-sm">
                        <i data-lucide="upload" class="w-4 h-4"></i>
                        Upload
                    </a>
                </div>
            </div>
        </nav>
    </header>

    <!-- Main Content -->
    <main class="relative z-10 pt-24 pb-12 px-4">
        <div class="max-w-5xl mx-auto">

            <!-- Image Frame -->
            <div class="image-frame mb-6">
                <div class="image-container">
                    <img
                        src="<?= htmlspecialchars($image['urls']['large'] ?? $image['urls']['original']) ?>"
                        alt="Uploaded image"
                        id="main-image"
                        loading="eager"
                    >
                </div>
            </div>

            <!-- Info & Actions -->
            <div class="grid md:grid-cols-3 gap-4">

                <!-- Links Panel -->
                <div class="md:col-span-2 info-card">
                    <h3 class="text-sm font-semibold mb-4" style="color: var(--color-text-primary);">Share Links</h3>

                    <div class="space-y-3">
                        <div>
                            <div class="link-label">Direct Link</div>
                            <div class="link-row">
                                <input type="text" class="link-input" value="<?= htmlspecialchars($image['urls']['original']) ?>" readonly id="link-direct">
                                <button class="copy-btn" onclick="copyLink('link-direct', this)" title="Copy">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <div class="link-label">Share URL</div>
                            <div class="link-row">
                                <input type="text" class="link-input" value="<?= $siteUrl ?>/<?= $imageId ?>" readonly id="link-share">
                                <button class="copy-btn" onclick="copyLink('link-share', this)" title="Copy">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <div class="link-label">HTML Embed</div>
                            <div class="link-row">
                                <input type="text" class="link-input" value='<img src="<?= htmlspecialchars($image['urls']['original']) ?>" alt="Image">' readonly id="link-html">
                                <button class="copy-btn" onclick="copyLink('link-html', this)" title="Copy">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <div class="link-label">BBCode</div>
                            <div class="link-row">
                                <input type="text" class="link-input" value="[img]<?= htmlspecialchars($image['urls']['original']) ?>[/img]" readonly id="link-bbcode">
                                <button class="copy-btn" onclick="copyLink('link-bbcode', this)" title="Copy">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <div>
                            <div class="link-label">Markdown</div>
                            <div class="link-row">
                                <input type="text" class="link-input" value="![Image](<?= htmlspecialchars($image['urls']['original']) ?>)" readonly id="link-md">
                                <button class="copy-btn" onclick="copyLink('link-md', this)" title="Copy">
                                    <i data-lucide="copy" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Panel -->
                <div class="info-card">
                    <h3 class="text-sm font-semibold mb-4" style="color: var(--color-text-primary);">Image Info</h3>

                    <div class="space-y-3 mb-5">
                        <div class="meta-item">
                            <i data-lucide="maximize-2"></i>
                            <span><?= $dimensions ?> px</span>
                        </div>
                        <div class="meta-item">
                            <i data-lucide="hard-drive"></i>
                            <span><?= $fileSize ?></span>
                        </div>
                        <div class="meta-item">
                            <i data-lucide="file-type"></i>
                            <span><?= strtoupper($image['extension']) ?></span>
                        </div>
                        <div class="meta-item">
                            <i data-lucide="calendar"></i>
                            <span><?= $uploadDate ?></span>
                        </div>
                        <?php if (!empty($image['delete_at'])): ?>
                        <div class="meta-item text-yellow-400">
                            <i data-lucide="clock"></i>
                            <span>Deletes <?= date('M j, Y g:i A', $image['delete_at']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="border-t border-white/10 pt-4 mb-4">
                        <div class="link-label mb-2">Size Versions</div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($image['urls'] as $size => $url): ?>
                            <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="size-btn" title="Open <?= ucfirst($size) ?>">
                                <?= ucfirst($size) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <a href="<?= htmlspecialchars($image['urls']['original']) ?>" download class="btn-primary w-full justify-center">
                        <i data-lucide="download" class="w-4 h-4"></i>
                        Download
                    </a>

                    <!-- Social Share Buttons -->
                    <div class="border-t border-white/10 pt-4 mt-4">
                        <div class="link-label mb-3">Share on Social</div>
                        <div class="share-buttons">
                            <a href="https://twitter.com/intent/tweet?url=<?= urlencode($siteUrl . '/' . $imageId) ?>&text=Check%20out%20this%20image"
                               target="_blank" class="share-btn twitter" title="Share on Twitter">
                                <i data-lucide="twitter" class="w-4 h-4"></i>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($siteUrl . '/' . $imageId) ?>"
                               target="_blank" class="share-btn facebook" title="Share on Facebook">
                                <i data-lucide="facebook" class="w-4 h-4"></i>
                            </a>
                            <a href="https://wa.me/?text=<?= urlencode('Check out this image: ' . $siteUrl . '/' . $imageId) ?>"
                               target="_blank" class="share-btn whatsapp" title="Share on WhatsApp">
                                <i data-lucide="message-circle" class="w-4 h-4"></i>
                            </a>
                            <a href="https://t.me/share/url?url=<?= urlencode($siteUrl . '/' . $imageId) ?>&text=Check%20out%20this%20image"
                               target="_blank" class="share-btn telegram" title="Share on Telegram">
                                <i data-lucide="send" class="w-4 h-4"></i>
                            </a>
                            <button onclick="copyShareLink()" class="share-btn copy" title="Copy Link">
                                <i data-lucide="link" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </main>

    <script>

        lucide.createIcons();


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


        function copyLink(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;

            input.select();
            input.setSelectionRange(0, 99999);

            navigator.clipboard.writeText(input.value).then(() => {

                btn.classList.add('copied');
                const icon = btn.querySelector('i');
                icon.setAttribute('data-lucide', 'check');
                lucide.createIcons();


                setTimeout(() => {
                    btn.classList.remove('copied');
                    icon.setAttribute('data-lucide', 'copy');
                    lucide.createIcons();
                }, 2000);
            });
        }


        function copyShareLink() {
            const url = '<?= $siteUrl ?>/<?= $imageId ?>';
            navigator.clipboard.writeText(url).then(() => {
                const btn = document.querySelector('.share-btn.copy');
                if (btn) {
                    btn.style.borderColor = 'var(--color-neon-green)';
                    btn.style.color = 'var(--color-neon-green)';
                    const icon = btn.querySelector('i');
                    icon.setAttribute('data-lucide', 'check');
                    lucide.createIcons();

                    setTimeout(() => {
                        btn.style.borderColor = '';
                        btn.style.color = '';
                        icon.setAttribute('data-lucide', 'link');
                        lucide.createIcons();
                    }, 2000);
                }
            });
        }


        const mainImage = document.getElementById('main-image');
        if (mainImage) {
            mainImage.style.cursor = 'zoom-in';
            mainImage.addEventListener('click', () => {
                window.open('<?= htmlspecialchars($image['urls']['original']) ?>', '_blank');
            });
        }
    </script>
</body>
</html>
