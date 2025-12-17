<?php
/**
 * PixelHop - DMCA Policy
 */
$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DMCA Policy - <?= htmlspecialchars($siteName) ?></title>
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
        .legal-content {
            color: var(--color-text-secondary);
            line-height: 1.8;
        }
        .legal-content h2 {
            color: var(--color-text-primary);
            font-size: 1.5rem;
            font-weight: 600;
            margin: 2rem 0 1rem 0;
        }
        .legal-content h3 {
            color: var(--color-text-primary);
            font-size: 1.125rem;
            font-weight: 600;
            margin: 1.5rem 0 0.75rem 0;
        }
        .legal-content p { margin-bottom: 1rem; }
        .legal-content ul, .legal-content ol {
            padding-left: 1.5rem;
            margin-bottom: 1rem;
        }
        .legal-content li { margin-bottom: 0.5rem; }
        .legal-content a { color: var(--color-neon-cyan); text-decoration: underline; }
        .legal-content strong { color: var(--color-text-primary); }
        .warning-box {
            background: rgba(245, 158, 11, 0.15);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            margin: 20px 0;
            display: flex;
            gap: 12px;
        }
        .warning-box i { color: #f59e0b; flex-shrink: 0; }
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
        <div class="max-w-3xl mx-auto">

            <div class="text-center mb-10">
                <h1 class="text-3xl md:text-4xl font-bold mb-3" style="color: var(--color-text-primary);">DMCA Policy</h1>
                <p style="color: var(--color-text-tertiary);">Digital Millennium Copyright Act Compliance</p>
            </div>

            <div class="glass-card p-6 md:p-10 legal-content">

                <p><strong>Last updated:</strong> January 2025</p>

                <p>PixelHop (accessible at p.hel.ink) respects the intellectual property rights of others and expects users to do the same. In accordance with the Digital Millennium Copyright Act of 1998 ("DMCA"), we will respond expeditiously to claims of copyright infringement.</p>

                <h2>1. Notification of Copyright Infringement</h2>

                <p>If you believe that your copyrighted work has been copied in a way that constitutes copyright infringement and is accessible on this site, please notify our designated DMCA agent. For your complaint to be valid under the DMCA, you must provide the following information:</p>

                <ol>
                    <li><strong>Identification of the copyrighted work:</strong> A description of the copyrighted work that you claim has been infringed. If multiple copyrighted works are covered by a single notification, provide a representative list.</li>
                    <li><strong>Identification of the infringing material:</strong> Identification of the material that is claimed to be infringing and where it is located on the Service. Please provide the specific URL(s) where the material is located.</li>
                    <li><strong>Your contact information:</strong> Your name, address, telephone number, and email address.</li>
                    <li><strong>Statement of good faith belief:</strong> A statement that you have a good faith belief that use of the material in the manner complained of is not authorized by the copyright owner, its agent, or the law.</li>
                    <li><strong>Statement of accuracy:</strong> A statement that the information in the notification is accurate, and under penalty of perjury, that you are authorized to act on behalf of the owner of an exclusive right that is allegedly infringed.</li>
                    <li><strong>Signature:</strong> An electronic or physical signature of the person authorized to act on behalf of the owner of the copyright or other intellectual property interest.</li>
                </ol>

                <h2>2. DMCA Agent</h2>

                <p>Please send your DMCA takedown notice to:</p>

                <div class="glass-card" style="padding: 20px; margin: 20px 0;">
                    <p style="margin-bottom: 8px;"><strong>Email:</strong> support@hel.ink</p>
                    <p style="margin-bottom: 8px;"><strong>Subject Line:</strong> DMCA Takedown Request</p>
                    <p style="margin-bottom: 0;"><strong>Alternative:</strong> Use the <a href="/contact">contact form</a> with subject "DMCA / Content Removal"</p>
                </div>

                <h2>3. Counter-Notification</h2>

                <p>If you believe that content was removed or disabled as a result of mistake or misidentification, you may submit a counter-notification containing the following:</p>

                <ol>
                    <li>Your physical or electronic signature.</li>
                    <li>Identification of the material that has been removed or to which access has been disabled, and the location at which the material appeared before it was removed or access to it was disabled.</li>
                    <li>A statement under penalty of perjury that you have a good faith belief that the material was removed or disabled as a result of mistake or misidentification.</li>
                    <li>Your name, address, and telephone number, and a statement that you consent to the jurisdiction of the federal district court for the judicial district in which your address is located.</li>
                </ol>

                <h2>4. Repeat Infringers</h2>

                <p>In accordance with the DMCA, we have adopted a policy of terminating, in appropriate circumstances, users who are deemed to be repeat infringers. We may also at our sole discretion limit access to the Service and/or terminate the accounts of any users who infringe any intellectual property rights of others.</p>

                <div class="warning-box">
                    <i data-lucide="alert-triangle" class="w-5 h-5 mt-0.5"></i>
                    <div>
                        <strong style="color: #f59e0b;">Warning:</strong> Under Section 512(f) of the DMCA, any person who knowingly materially misrepresents that material is infringing may be subject to liability for damages, including costs and attorneys' fees.
                    </div>
                </div>

                <h2>5. Response Timeline</h2>

                <p>Upon receipt of a valid DMCA notice:</p>

                <ul>
                    <li>We will remove or disable access to the allegedly infringing content within <strong>24-72 hours</strong>.</li>
                    <li>We will make a good faith attempt to contact the user who uploaded the content.</li>
                    <li>If a valid counter-notification is received, we will restore the content within 10-14 business days unless the copyright owner files a court action.</li>
                </ul>

                <h2>6. False Claims</h2>

                <p>Please note that under Section 512(f) of the DMCA, any person who knowingly materially misrepresents that material or activity is infringing may be subject to liability for damages. Don't make false claims!</p>

                <h2>7. Non-Copyright Violations</h2>

                <p>For content that violates our Terms of Service but is not a copyright issue (e.g., illegal content, harassment), please use our <a href="/contact">contact form</a> with the appropriate subject line.</p>

                <h2>8. Contact</h2>

                <p>For any questions about this DMCA policy, please contact us at:</p>
                <ul>
                    <li>Email: support@hel.ink</li>
                    <li>Contact Form: <a href="/contact">p.hel.ink/contact</a></li>
                </ul>

            </div>

        </div>
    </main>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
