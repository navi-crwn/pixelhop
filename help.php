<?php
/**
 * PixelHop - Help Center
 */
$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Help Center - <?= htmlspecialchars($siteName) ?></title>
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
        .faq-item { margin-bottom: 16px; }
        .faq-question {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: all 0.2s;
            color: var(--color-text-primary);
            font-weight: 500;
        }
        .faq-question:hover { background: var(--glass-bg-hover); }
        .faq-question.active { border-radius: var(--radius-lg) var(--radius-lg) 0 0; border-bottom-color: transparent; }
        .faq-answer {
            display: none;
            padding: 20px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-top: none;
            border-radius: 0 0 var(--radius-lg) var(--radius-lg);
            color: var(--color-text-secondary);
            line-height: 1.7;
        }
        .faq-answer.show { display: block; }
        .faq-icon { transition: transform 0.2s; }
        .faq-question.active .faq-icon { transform: rotate(180deg); }
        .help-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 24px;
            text-align: center;
            transition: all 0.2s;
        }
        .help-card:hover { background: var(--glass-bg-hover); transform: translateY(-4px); }
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
        <div class="max-w-4xl mx-auto">

            <div class="text-center mb-12">
                <h1 class="text-3xl md:text-4xl font-bold mb-3" style="color: var(--color-text-primary);">Help Center</h1>
                <p style="color: var(--color-text-tertiary);">Find answers to common questions</p>
            </div>

            <!-- Quick Links -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-12">
                <a href="/docs" class="help-card">
                    <i data-lucide="code" class="w-8 h-8 mx-auto mb-3 text-neon-cyan"></i>
                    <h3 class="font-semibold mb-1" style="color: var(--color-text-primary);">API Documentation</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">For developers</p>
                </a>
                <a href="/tools" class="help-card">
                    <i data-lucide="wrench" class="w-8 h-8 mx-auto mb-3 text-neon-purple"></i>
                    <h3 class="font-semibold mb-1" style="color: var(--color-text-primary);">Image Tools</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">Compress, resize, crop, OCR & more</p>
                </a>
                <a href="/contact" class="help-card">
                    <i data-lucide="mail" class="w-8 h-8 mx-auto mb-3 text-neon-pink"></i>
                    <h3 class="font-semibold mb-1" style="color: var(--color-text-primary);">Contact Us</h3>
                    <p class="text-sm" style="color: var(--color-text-tertiary);">Get in touch</p>
                </a>
            </div>

            <!-- FAQ Section -->
            <h2 class="text-2xl font-bold mb-6" style="color: var(--color-text-primary);">Frequently Asked Questions</h2>

            <div class="faq-list">
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>What file formats are supported?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        PixelHop supports JPEG, PNG, GIF, and WebP formats. Maximum file size is 15MB per image. Animated GIFs are supported but may be converted to static images for thumbnails.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>How long are images stored?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        <strong>Uploaded Images:</strong> Images uploaded through the main upload feature are stored permanently on our cloud storage as long as they don't violate our Terms of Service.<br><br>
                        <strong>Inactive Images:</strong> Public (guest) uploads that haven't been viewed for 90 days may be automatically removed to save storage space. Registered users' images are not affected by this policy.<br><br>
                        <strong>Tool Results (Temporary):</strong> Files processed through our image tools (compress, resize, convert, crop) are stored in <em>temporary storage only</em> and are <strong>automatically deleted after 6 hours</strong>. These are NOT uploaded to permanent storage. If you need to keep a processed image, download it immediately after processing or use the "Upload Result" option.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>Is there a limit on uploads?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        <strong>Guest (No Account):</strong> 100MB/day bandwidth, 100 uploads/hour, 2,000 uploads/day<br>
                        <strong>Free Users:</strong> 500MB total storage, 5 OCR/day, 3 RemBG/day<br>
                        <strong>Premium Users:</strong> 5GB storage, 50 OCR/day, 30 RemBG/day<br><br>
                        <strong>Tool Limits (Compress, Resize, Convert, Crop):</strong><br>
                        • Guests: 20/hour, 100/day per IP<br>
                        • Registered Users: 1,000/day<br><br>
                        Create an account to unlock higher limits!
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>Can I use PixelHop for commercial purposes?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        Yes, you can use PixelHop for commercial websites and projects. However, we recommend using our API for high-volume commercial use. Please don't use PixelHop as a primary CDN for high-traffic websites.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>How do I delete an uploaded image?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        When you upload an image, you receive a delete key. To delete an image, contact us with the image URL and delete key. We're working on a self-service deletion feature for the future.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>What image sizes are generated?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        Each uploaded image is automatically resized into multiple versions:
                        <ul style="margin-top: 12px; padding-left: 20px;">
                            <li><strong>Original:</strong> Full size (up to 4000px)</li>
                            <li><strong>Medium:</strong> 1200px wide</li>
                            <li><strong>Small:</strong> 600px wide</li>
                            <li><strong>Thumbnail:</strong> 300px wide</li>
                        </ul>
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>How does the OCR feature work?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        Our OCR (Optical Character Recognition) uses Tesseract, an open-source OCR engine. It supports English and Indonesian by default. For best results, upload clear images with good contrast and legible text.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>What is Background Remover (RemBG)?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        RemBG uses AI to automatically remove backgrounds from images. It works best with clear subjects like people, products, or objects. The output is a PNG with transparent background. Free users get 3 uses per day, premium users get 30 per day.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>How do I see my image view counts?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        View counts are displayed in two places:
                        <ul style="margin-top: 12px; padding-left: 20px;">
                            <li><strong>Image Page:</strong> Click the "Info" button on any image page to see view count and last viewed date</li>
                            <li><strong>Your Gallery:</strong> View counts are shown on each image in your personal gallery dashboard</li>
                        </ul>
                        View counts update once per hour per unique visitor.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>How do I report inappropriate content?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        If you find content that violates our Terms of Service, click the <strong>Report</strong> button on the image page. Select a reason (NSFW, Illegal, Privacy violation, Copyright, or Other) and submit. Our team reviews all reports within 24-72 hours.
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>Do I need an account to use PixelHop?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        No! You can use basic features as a guest. However, creating a free account gives you:
                        <ul style="margin-top: 12px; padding-left: 20px;">
                            <li>Personal dashboard to manage your uploads</li>
                            <li>Storage quota (500MB free, 5GB premium)</li>
                            <li>Higher daily limits for AI tools</li>
                            <li>Upload history and activity tracking</li>
                        </ul>
                    </div>
                </div>

                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span>Is there an API available?</span>
                        <i data-lucide="chevron-down" class="w-5 h-5 faq-icon"></i>
                    </div>
                    <div class="faq-answer">
                        Yes! We provide a free REST API for uploading images and using all our image tools. Visit our <a href="/docs" style="color: var(--color-neon-cyan);">API Documentation</a> for details on endpoints and usage.
                    </div>
                </div>
            </div>

            <!-- Still need help? -->
            <div class="mt-12 text-center glass-card p-8">
                <i data-lucide="message-circle" class="w-12 h-12 mx-auto mb-4 text-neon-cyan"></i>
                <h3 class="text-xl font-semibold mb-2" style="color: var(--color-text-primary);">Still need help?</h3>
                <p class="mb-4" style="color: var(--color-text-tertiary);">Can't find what you're looking for? Get in touch.</p>
                <a href="/contact" class="btn-primary inline-flex">
                    <i data-lucide="mail" class="w-4 h-4"></i>
                    Contact Us
                </a>
            </div>

        </div>
    </main>

    <script>
        lucide.createIcons();

        function toggleFaq(element) {
            const answer = element.nextElementSibling;
            const isActive = element.classList.contains('active');


            document.querySelectorAll('.faq-question').forEach(q => {
                q.classList.remove('active');
                q.nextElementSibling.classList.remove('show');
            });


            if (!isActive) {
                element.classList.add('active');
                answer.classList.add('show');
            }
        }
    </script>
</body>
</html>
