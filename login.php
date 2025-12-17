<?php
/**
 * PixelHop - Login Page
 * Glassmorphism UI Design
 */
require_once __DIR__ . '/auth/middleware.php';
require_once __DIR__ . '/includes/Turnstile.php';
redirectIfAuthenticated('/');

$config = require __DIR__ . '/config/s3.php';
$siteName = $config['site']['name'];
$csrfToken = generateCsrfToken();

// Get error message from session if any
$errorMessage = $_SESSION['auth_error'] ?? null;
unset($_SESSION['auth_error']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Login to <?= htmlspecialchars($siteName) ?> - Free premium image hosting">

    <title>Login - <?= htmlspecialchars($siteName) ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/img/logo.svg">

    <!-- Theme Detection -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('pixelhop-theme');
            document.documentElement.setAttribute('data-theme', savedTheme || 'dark');
        })();
    </script>

    <!-- Tailwind CSS -->
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

    <!-- Turnstile -->
    <?= Turnstile::getScript() ?>

    <!-- Custom Styles -->
    <link rel="stylesheet" href="/assets/css/glass.css?v=1.1.0">
</head>
<body class="min-h-screen font-sans overflow-x-hidden">

    <!-- Animated Background -->
    <div id="bg-container" class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute inset-0 bg-gradient-to-br from-[var(--color-bg-primary)] via-[var(--color-bg-secondary)] to-[var(--color-bg-primary)]"></div>
        <div id="blob-1" class="blob blob-cyan"></div>
        <div id="blob-2" class="blob blob-purple"></div>
        <div id="blob-3" class="blob blob-pink"></div>
    </div>

    <!-- Main Content -->
    <main class="relative z-10 min-h-screen flex items-center justify-center p-4">
        <div class="w-full max-w-md">

            <!-- Logo & Title -->
            <div class="text-center mb-8">
                <a href="/" class="inline-flex items-center gap-3 mb-4">
                    <img src="/assets/img/logo.svg" alt="<?= htmlspecialchars($siteName) ?>" class="w-12 h-12">
                    <span class="text-2xl font-bold text-gradient-cyan"><?= htmlspecialchars($siteName) ?></span>
                </a>
                <h1 class="text-xl font-semibold text-[var(--color-text-primary)]">Welcome back</h1>
                <p class="text-sm text-[var(--color-text-tertiary)] mt-1">Sign in to your account</p>
            </div>

            <!-- Login Card -->
            <div class="auth-card">

                <!-- Error Message -->
                <?php if ($errorMessage): ?>
                <div class="auth-alert auth-alert-error mb-6">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    <span><?= htmlspecialchars($errorMessage) ?></span>
                </div>
                <?php endif; ?>

                <!-- Login Form -->
                <form id="loginForm" class="space-y-5" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <!-- Email Field -->
                    <div class="auth-field">
                        <label for="email" class="auth-label">Email</label>
                        <div class="auth-input-wrapper">
                            <i data-lucide="mail" class="auth-input-icon"></i>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="auth-input"
                                placeholder="you@example.com"
                                autocomplete="email"
                                required
                            >
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div class="auth-field">
                        <label for="password" class="auth-label">Password</label>
                        <div class="auth-input-wrapper">
                            <i data-lucide="lock" class="auth-input-icon"></i>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="auth-input"
                                placeholder="••••••••"
                                autocomplete="current-password"
                                required
                            >
                            <button type="button" class="auth-toggle-password" onclick="togglePassword('password')">
                                <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Remember Me & Forgot Password -->
                    <div class="flex items-center justify-between">
                        <label class="auth-checkbox">
                            <input type="checkbox" name="remember" id="remember">
                            <span class="checkmark"></span>
                            <span class="text-sm text-[var(--color-text-secondary)]">Remember me</span>
                        </label>
                        <a href="#" class="text-sm text-neon-cyan hover:underline">Forgot password?</a>
                    </div>

                    <!-- Turnstile Widget -->
                    <div class="flex justify-center">
                        <?= Turnstile::renderWidget('dark') ?>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="auth-btn-primary w-full" id="submitBtn">
                        <span class="btn-text">Sign In</span>
                        <span class="btn-loader hidden">
                            <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </span>
                    </button>
                </form>

                <!-- Divider -->
                <div class="auth-divider">
                    <span>or continue with</span>
                </div>

                <!-- Social Login -->
                <div class="flex justify-center">
                    <a href="/auth/google.php" class="inline-flex items-center gap-3 px-6 py-3 bg-white/10 hover:bg-white/20 rounded-xl text-[var(--color-text-primary)] font-medium transition-all hover:-translate-y-0.5">
                        <svg viewBox="0 0 24 24" class="w-5 h-5">
                            <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                            <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                            <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                            <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                        </svg>
                        Continue with Google
                    </a>
                </div>

                <!-- Register Link -->
                <p class="text-center text-sm text-[var(--color-text-secondary)]">
                    Don't have an account?
                    <a href="/register.php" class="text-neon-cyan hover:underline font-medium">Create one</a>
                </p>
            </div>

            <!-- Back to Home -->
            <div class="text-center mt-6">
                <a href="/" class="inline-flex items-center gap-2 text-sm text-[var(--color-text-tertiary)] hover:text-[var(--color-text-primary)] transition-colors">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                    Back to home
                </a>
            </div>
        </div>
    </main>

    <script>

        lucide.createIcons();


        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const btn = field.parentElement.querySelector('.auth-toggle-password i');

            if (field.type === 'password') {
                field.type = 'text';
                btn.setAttribute('data-lucide', 'eye-off');
            } else {
                field.type = 'password';
                btn.setAttribute('data-lucide', 'eye');
            }
            lucide.createIcons();
        }


        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();

            const form = e.target;
            const submitBtn = document.getElementById('submitBtn');
            const btnText = submitBtn.querySelector('.btn-text');
            const btnLoader = submitBtn.querySelector('.btn-loader');


            submitBtn.disabled = true;
            btnText.classList.add('hidden');
            btnLoader.classList.remove('hidden');

            try {
                const formData = new FormData(form);


                const data = Object.fromEntries(formData);


                console.log('Login data:', data);
                console.log('Turnstile response:', data['cf-turnstile-response']);

                const response = await fetch('/auth/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {

                    showNotification('Login successful! Redirecting...', 'success');


                    setTimeout(() => {
                        window.location.href = result.redirect || '/';
                    }, 500);
                } else {
                    showNotification(result.message || 'Login failed', 'error');
                    submitBtn.disabled = false;
                    btnText.classList.remove('hidden');
                    btnLoader.classList.add('hidden');
                }
            } catch (error) {
                console.error('Login error:', error);
                showNotification('An error occurred. Please try again.', 'error');
                submitBtn.disabled = false;
                btnText.classList.remove('hidden');
                btnLoader.classList.add('hidden');
            }
        });


        function showNotification(message, type = 'info') {

            document.querySelectorAll('.notification-toast').forEach(n => n.remove());

            const notification = document.createElement('div');
            notification.className = `notification-toast notification-${type}`;

            const icons = {
                success: 'check-circle',
                error: 'x-circle',
                info: 'info'
            };

            notification.innerHTML = `
                <i data-lucide="${icons[type]}" class="w-5 h-5"></i>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);
            lucide.createIcons();

            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }, 4000);
        }
    </script>
</body>
</html>
