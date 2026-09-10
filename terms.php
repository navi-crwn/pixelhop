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
                <div class="flex items-center gap-3">
                    <button id="theme-toggle" class="theme-toggle-btn" aria-label="Toggle theme" title="Toggle theme">
                        <svg class="theme-icon-light w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
                        <svg class="theme-icon-dark w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </button>
                    <a href="/" class="btn-primary text-sm">
                        <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        Home
                    </a>
                </div>
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

                <div class="p-4 rounded-lg mb-6" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3);">
                    <h3 class="text-red-400 flex items-center gap-2 mb-2" style="margin-top: 0;">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        ZERO TOLERANCE POLICY
                    </h3>
                    <p style="margin-bottom: 0.5rem;"><strong>We maintain an absolute ZERO TOLERANCE policy for:</strong></p>
                    <ul style="margin-bottom: 0;">
                        <li><strong>Child Sexual Abuse Material (CSAM)</strong> - Any content depicting minors in sexual situations</li>
                        <li><strong>Child Exploitation</strong> - Any content that sexualizes, exploits, or endangers children</li>
                        <li><strong>Non-Consensual Intimate Images</strong> - Revenge porn or intimate images shared without consent</li>
                    </ul>
                    <p class="mt-3 text-sm" style="color: var(--color-text-muted); margin-bottom: 0;">
                        Violations will result in <strong>immediate account termination</strong>, content removal, IP banning, and <strong>reporting to law enforcement agencies</strong> including NCMEC (National Center for Missing & Exploited Children).
                    </p>
                </div>

                <h2>5. AI-Powered Content Moderation</h2>
                <p>PixelHop employs advanced AI-powered content moderation systems to protect our platform and users:</p>
                <ul>
                    <li><strong>Automated Scanning:</strong> All uploaded content is automatically scanned using AI and industry-standard safety tools</li>
                    <li><strong>Real-Time Protection:</strong> Content is analyzed before being made publicly accessible</li>
                    <li><strong>Continuous Monitoring:</strong> Existing content is periodically re-scanned for policy compliance</li>
                    <li><strong>False Positive Appeals:</strong> If your content is incorrectly flagged, contact support@hel.ink with your image ID</li>
                </ul>
                <p>Our moderation system uses Google Safe Browsing, VirusTotal, and Gemini AI to detect malware, phishing, CSAM, adult content, violence, and other prohibited material.</p>

                <h2>6. Content Ownership</h2>
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

    <script src="/assets/js/app.js?v=1.0.6"></script>
</body>
</html>
