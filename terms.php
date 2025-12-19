<?php
/**
 * PixelHop - Terms of Service
 */
$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Terms of Service - <?= htmlspecialchars($siteName) ?></title>
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
        .legal-content { max-width: 800px; margin: 0 auto; }
        .legal-content h2 { font-size: 1.5rem; font-weight: 600; margin: 2rem 0 1rem; color: var(--color-text-primary); }
        .legal-content h3 { font-size: 1.125rem; font-weight: 600; margin: 1.5rem 0 0.75rem; color: var(--color-text-primary); }
        .legal-content p, .legal-content li { color: var(--color-text-secondary); line-height: 1.7; margin-bottom: 1rem; }
        .legal-content ul { list-style: disc; padding-left: 1.5rem; margin-bottom: 1rem; }
        .legal-content a { color: var(--color-neon-cyan); text-decoration: underline; }
    </style>
</head>
<body class="min-h-screen font-sans">
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)]"></div>
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
        <div class="legal-content">
            <div class="glass-card p-8 md:p-12">
                <h1 class="text-3xl font-bold mb-2" style="color: var(--color-text-primary);">Terms of Service</h1>
                <p class="text-sm mb-8" style="color: var(--color-text-muted);">Last updated: December 2025</p>

                <h2>1. Acceptance of Terms</h2>
                <p>By accessing and using PixelHop ("the Service"), you agree to be bound by these Terms of Service. If you do not agree to these terms, please do not use the Service.</p>

                <h2>2. Description of Service</h2>
                <p>PixelHop provides free image hosting and processing services including:</p>
                <ul>
                    <li>Image uploading and hosting</li>
                    <li>Image compression, resizing, cropping, and format conversion</li>
                    <li>OCR (Optical Character Recognition) for text extraction</li>
                    <li>AI Background Removal (RemBG)</li>
                    <li>User accounts with personal dashboards</li>
                    <li>Shareable links for uploaded images</li>
                </ul>

                <h2>3. User Accounts</h2>
                <p>Account registration is optional but provides additional benefits:</p>
                <ul>
                    <li><strong>Free accounts:</strong> 500MB storage, 5 OCR/day, 3 RemBG/day</li>
                    <li><strong>Premium accounts:</strong> 5GB storage, 50 OCR/day, 30 RemBG/day</li>
                </ul>
                <p>You are responsible for maintaining the security of your account credentials. We support Google OAuth for convenient sign-in.</p>

                <h2>4. User Conduct</h2>
                <p>You agree NOT to upload, share, or distribute content that:</p>
                <ul>
                    <li>Is illegal, harmful, threatening, abusive, or harassing</li>
                    <li>Contains nudity, pornography, or sexually explicit material</li>
                    <li>Infringes on intellectual property rights of others</li>
                    <li>Contains malware, viruses, or malicious code</li>
                    <li>Promotes violence, discrimination, or illegal activities</li>
                    <li>Violates the privacy or publicity rights of others</li>
                </ul>

                <h2>4. Content Ownership</h2>
                <p>You retain all ownership rights to content you upload. By uploading, you grant PixelHop a non-exclusive license to store, display, and process your images as necessary to provide the Service.</p>

                <h2>5. Content Removal & Reporting</h2>
                <p>We reserve the right to remove any content that violates these terms without notice. Users can report inappropriate content using the Report button on any image page.</p>
                <p><strong>Report reasons include:</strong></p>
                <ul>
                    <li>NSFW/Adult content</li>
                    <li>Illegal content</li>
                    <li>Privacy violation</li>
                    <li>Copyright infringement</li>
                    <li>Other violations</li>
                </ul>
                <p>All reports are reviewed within 24-72 hours. Repeated violations may result in account restrictions or permanent bans.</p>

                <h2>6. Account Status & Enforcement</h2>
                <p>Accounts may be subject to the following enforcement actions:</p>
                <ul>
                    <li><strong>Warning:</strong> A notice will be displayed on your next login for minor violations</li>
                    <li><strong>Locked:</strong> Account access suspended pending review. Contact support@hel.ink to appeal</li>
                    <li><strong>Suspended:</strong> Account and all images become inaccessible. Suspended accounts and their content are permanently deleted after 30 days</li>
                </ul>

                <h2>7. Data Retention & Temporary Files</h2>
                <p><strong>Permanent Storage:</strong> Images uploaded through the main upload feature are stored on our cloud infrastructure indefinitely, subject to our content policies.</p>
                <p><strong>Inactive Image Policy:</strong> Public (guest) uploads that have not been viewed for 90 consecutive days may be automatically removed to optimize storage. Registered users' images are exempt from this policy as long as they are viewed periodically.</p>
                <p><strong>Temporary Processing Files:</strong> Files processed through our image tools (compress, resize, convert, crop, OCR, RemBG) are stored in <strong>temporary storage only</strong> and are <strong>automatically deleted after 6 hours</strong>. These processed files are NOT uploaded to permanent storage. Users must download processed files immediately or use the "Upload Result" feature to save them permanently.</p>
                <p><strong>No Backup Guarantee:</strong> While we strive to maintain reliable storage, we do not guarantee data preservation. Users are encouraged to keep personal backups of important images.</p>

                <h2>7. No Warranty</h2>
                <p>The Service is provided "as is" without warranties of any kind. We do not guarantee uninterrupted access, data preservation, or fitness for any particular purpose.</p>

                <h2>8. Limitation of Liability</h2>
                <p>PixelHop shall not be liable for any indirect, incidental, special, or consequential damages arising from your use of the Service.</p>

                <h2>9. Changes to Terms</h2>
                <p>We may update these terms at any time. Continued use of the Service after changes constitutes acceptance of the new terms.</p>

                <h2>10. Contact</h2>
                <p>For questions about these terms, please visit our <a href="/contact">Contact page</a>.</p>
            </div>
        </div>
    </main>

    <script>lucide.createIcons();</script>
</body>
</html>
