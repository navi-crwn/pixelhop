<?php
/**
 * PixelHop - Contact Us
 */
$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
$success = false;
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');


    if (empty($name) || empty($email) || empty($message)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {

        $contact = [
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'timestamp' => date('c')
        ];

        $contactsFile = __DIR__ . '/data/contacts.json';
        $contacts = file_exists($contactsFile) ? json_decode(file_get_contents($contactsFile), true) : [];
        $contacts[] = $contact;
        file_put_contents($contactsFile, json_encode($contacts, JSON_PRETTY_PRINT));

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - <?= htmlspecialchars($siteName) ?></title>
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
        .form-input {
            width: 100%;
            padding: 12px 16px;
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-lg);
            color: var(--color-text-primary);
            font-size: 14px;
            transition: all 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--color-neon-cyan);
            box-shadow: 0 0 0 3px rgba(0, 245, 212, 0.1);
        }
        .form-input::placeholder { color: var(--color-text-tertiary); }
        textarea.form-input { resize: vertical; min-height: 150px; }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--color-text-secondary);
            font-size: 14px;
        }
        .form-group { margin-bottom: 20px; }
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #10b981;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
        }
        .contact-card {
            background: var(--glass-bg);
            border: 1px solid var(--glass-border);
            border-radius: var(--radius-xl);
            padding: 24px;
            text-align: center;
            transition: all 0.2s;
        }
        .contact-card:hover { background: var(--glass-bg-hover); }
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
                <h1 class="text-3xl md:text-4xl font-bold mb-3" style="color: var(--color-text-primary);">Contact Us</h1>
                <p style="color: var(--color-text-tertiary);">Have questions? We'd love to hear from you.</p>
            </div>

            <!-- Contact Info Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-10">
                <div class="contact-card">
                    <i data-lucide="mail" class="w-8 h-8 mx-auto mb-3 text-neon-cyan"></i>
                    <h3 class="font-semibold mb-1" style="color: var(--color-text-primary);">Email</h3>
                    <p style="color: var(--color-text-secondary);">support@hel.ink</p>
                </div>
                <div class="contact-card">
                    <i data-lucide="clock" class="w-8 h-8 mx-auto mb-3 text-neon-purple"></i>
                    <h3 class="font-semibold mb-1" style="color: var(--color-text-primary);">Response Time</h3>
                    <p style="color: var(--color-text-secondary);">Within 24-48 hours</p>
                </div>
            </div>

            <!-- Contact Form -->
            <div class="glass-card p-6 md:p-8">
                <?php if ($success): ?>
                <div class="alert alert-success">
                    <i data-lucide="check-circle" class="w-5 h-5"></i>
                    <span>Thank you! Your message has been sent. We'll get back to you soon.</span>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-error">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <form method="POST" action="/contact">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="form-group">
                            <label class="form-label">Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" class="form-input" placeholder="Your name" required
                                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email <span class="text-red-500">*</span></label>
                            <input type="email" name="email" class="form-input" placeholder="you@example.com" required
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Subject</label>
                        <select name="subject" class="form-input">
                            <option value="general">General Inquiry</option>
                            <option value="support">Technical Support</option>
                            <option value="bug">Bug Report</option>
                            <option value="feature">Feature Request</option>
                            <option value="dmca">DMCA / Content Removal</option>
                            <option value="api">API Access</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Message <span class="text-red-500">*</span></label>
                        <textarea name="message" class="form-input" placeholder="Your message..." required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary w-full justify-center">
                        <i data-lucide="send" class="w-4 h-4"></i>
                        Send Message
                    </button>
                </form>
            </div>

            <!-- Links -->
            <div class="mt-8 text-center">
                <p style="color: var(--color-text-tertiary);" class="text-sm">
                    Before contacting, check our
                    <a href="/help" style="color: var(--color-neon-cyan);">Help Center</a>
                    for answers to common questions.
                </p>
            </div>

        </div>
    </main>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>
