<?php
/**
 * PixelHop - Privacy Policy
 */
$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - <?= htmlspecialchars($siteName) ?></title>
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
                <h1 class="text-3xl font-bold mb-2" style="color: var(--color-text-primary);">Privacy Policy</h1>
                <p class="text-sm mb-8" style="color: var(--color-text-muted);">Last updated: December 2025</p>

                <h2>1. Information We Collect</h2>
                <h3>Account Information</h3>
                <p>When you create an account, we collect:</p>
                <ul>
                    <li>Email address (for login and communication)</li>
                    <li>Username (for display purposes)</li>
                    <li>Profile information from OAuth providers (if using Google sign-in)</li>
                </ul>

                <h3>Uploaded Images</h3>
                <p>When you upload images, we store the image files on our servers. Images are assigned a unique ID for access.</p>

                <h3>Automatically Collected Information</h3>
                <p>We may collect:</p>
                <ul>
                    <li>IP address (for rate limiting and abuse prevention)</li>
                    <li>Browser type and version</li>
                    <li>Upload timestamps</li>
                    <li>Image metadata (dimensions, file size, format)</li>
                </ul>

                <h2>2. How We Use Information</h2>
                <p>We use collected information to:</p>
                <ul>
                    <li>Provide and maintain the Service</li>
                    <li>Process and deliver your uploaded images</li>
                    <li>Manage your user account and preferences</li>
                    <li>Track usage quotas (storage, OCR, RemBG limits)</li>
                    <li>Prevent abuse and enforce our Terms of Service</li>
                    <li>Improve our Service through analytics</li>
                </ul>

                <h2>3. Abuse Prevention</h2>
                <p>To maintain service quality, we implement automated abuse prevention:</p>
                <ul>
                    <li>Rate limiting based on IP address</li>
                    <li>Automatic blocking of suspicious activity</li>
                    <li>Monitoring upload patterns for abuse detection</li>
                </ul>
                <p>This data is used solely for security purposes and is not shared with third parties.</p>

                <h2>4. Data Storage</h2>
                <p>Images are stored on secure cloud infrastructure. We implement industry-standard security measures to protect your data.</p>

                <h2>5. Data Retention</h2>
                <p>Uploaded images are retained indefinitely unless:</p>
                <ul>
                    <li>You request deletion</li>
                    <li>The content violates our Terms of Service</li>
                    <li>Required by law to remove</li>
                </ul>

                <h2>6. Cookies</h2>
                <p>We use minimal cookies for:</p>
                <ul>
                    <li>Theme preference (dark/light mode)</li>
                    <li>Session management</li>
                    <li>User authentication</li>
                </ul>
                <p>We do not use tracking cookies or third-party advertising cookies.</p>

                <h2>7. Third-Party Services</h2>
                <p>We use reputable third-party services for:</p>
                <ul>
                    <li><strong>Cloud Storage:</strong> Secure image storage</li>
                    <li><strong>CDN:</strong> Content delivery and protection</li>
                    <li><strong>Google OAuth:</strong> Optional sign-in (if you choose)</li>
                    <li><strong>Cloudflare Turnstile:</strong> Bot protection</li>
                </ul>

                <h2>8. Data Sharing</h2>
                <p>We do not sell, trade, or rent your personal information. We may share data only:</p>
                <ul>
                    <li>When required by law</li>
                    <li>To protect our rights or safety</li>
                    <li>With service providers who assist in operating our Service</li>
                </ul>

                <h2>9. Your Rights</h2>
                <p>You have the right to:</p>
                <ul>
                    <li>Request deletion of your uploaded images</li>
                    <li>Delete your account and associated data</li>
                    <li>Access information we have about you</li>
                    <li>Opt-out of any future communications</li>
                </ul>

                <h2>10. Children's Privacy</h2>
                <p>Our Service is not intended for children under 13. We do not knowingly collect information from children.</p>

                <h2>11. Changes to This Policy</h2>
                <p>We may update this Privacy Policy periodically. Changes will be posted on this page with an updated date.</p>

                <h2>12. Contact Us</h2>
                <p>For privacy-related questions, please visit our <a href="/contact">Contact page</a>.</p>
            </div>
        </div>
    </main>

    <script>lucide.createIcons();</script>
</body>
</html>
