<?php
/**
 * PixelHop - Mailer Service
 * Handles all email sending with PHPMailer
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class Mailer
{
    private array $config;
    private ?PHPMailer $mailer = null;
    
    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/mail.php';
    }
    
    /**
     * Get configured PHPMailer instance
     */
    private function getMailer(): PHPMailer
    {
        if ($this->mailer === null) {
            $this->mailer = new PHPMailer(true);
            
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->config['host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->config['username'];
            $this->mailer->Password = $this->config['password'];
            $this->mailer->SMTPSecure = $this->config['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port = $this->config['port'];
            
            // Default from
            $this->mailer->setFrom($this->config['from_address'], $this->config['from_name']);
            
            // Encoding
            $this->mailer->CharSet = 'UTF-8';
        }
        
        // Clear previous recipients
        $this->mailer->clearAddresses();
        $this->mailer->clearAttachments();
        
        return $this->mailer;
    }
    
    /**
     * Send verification email
     */
    public function sendVerificationEmail(string $email, string $token): bool
    {
        try {
            $mailer = $this->getMailer();
            
            $verifyUrl = $this->config['verification_base_url'] . '/auth/verify.php?token=' . urlencode($token);
            $expireHours = $this->config['verification_expire_hours'];
            
            $mailer->addAddress($email);
            $mailer->isHTML(true);
            $mailer->Subject = 'Verify your PixelHop account';
            
            $mailer->Body = $this->getVerificationEmailHtml($email, $verifyUrl, $expireHours);
            $mailer->AltBody = $this->getVerificationEmailText($verifyUrl, $expireHours);
            
            $mailer->send();
            return true;
            
        } catch (Exception $e) {
            error_log('Mailer Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send password reset email
     */
    public function sendPasswordResetEmail(string $email, string $token): bool
    {
        try {
            $mailer = $this->getMailer();
            
            $resetUrl = $this->config['verification_base_url'] . '/auth/reset-password.php?token=' . urlencode($token);
            
            $mailer->addAddress($email);
            $mailer->isHTML(true);
            $mailer->Subject = 'Reset your PixelHop password';
            
            $mailer->Body = $this->getPasswordResetEmailHtml($resetUrl);
            $mailer->AltBody = $this->getPasswordResetEmailText($resetUrl);
            
            $mailer->send();
            return true;
            
        } catch (Exception $e) {
            error_log('Mailer Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send welcome email after verification
     */
    public function sendWelcomeEmail(string $email): bool
    {
        try {
            $mailer = $this->getMailer();
            
            $mailer->addAddress($email);
            $mailer->isHTML(true);
            $mailer->Subject = 'Welcome to PixelHop! 🎉';
            
            $mailer->Body = $this->getWelcomeEmailHtml($email);
            $mailer->AltBody = "Welcome to PixelHop! Your account is now verified. Start uploading at https://p.hel.ink";
            
            $mailer->send();
            return true;
            
        } catch (Exception $e) {
            error_log('Mailer Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send admin alert email (critical failure notification).
     *
     * Jalur sampingan untuk Alerter: method ini tidak boleh melempar
     * exception ke pemanggil. Semua kegagalan SMTP ditangkap di sini,
     * dicatat ke error_log, dan dikembalikan sebagai false.
     *
     * Recipient mengikuti urutan:
     *   1. config/mail.php key 'admin_alerts_to'
     *   2. environment variable ADMIN_ALERT_EMAIL
     *   3. config/mail.php key 'admin_email'
     *   4. config/mail.php key 'support_email'
     *   5. config/mail.php key 'from_address' (fallback terakhir)
     */
    public function sendAdminAlert(string $subject, string $htmlBody): bool
    {
        try {
            $mailer = $this->getMailer();

            $mailer->addAddress($this->getAdminAlertRecipient());
            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $htmlBody;
            $mailer->AltBody = trim(strip_tags($htmlBody));

            $mailer->send();
            return true;

        } catch (Throwable $e) {
            error_log('Mailer Error (admin alert): ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Resolve recipient email untuk admin alert.
     */
    private function getAdminAlertRecipient(): string
    {
        if (!empty($this->config['admin_alerts_to'])) {
            return (string) $this->config['admin_alerts_to'];
        }

        $env = getenv('ADMIN_ALERT_EMAIL');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        if (!empty($this->config['admin_email'])) {
            return (string) $this->config['admin_email'];
        }

        if (!empty($this->config['support_email'])) {
            return (string) $this->config['support_email'];
        }

        return (string) $this->config['from_address'];
    }

    /**
     * Verification email HTML template
     */
    private function getVerificationEmailHtml(string $email, string $verifyUrl, int $expireHours): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #0a0a0f; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
    <div style="max-width: 600px; margin: 0 auto; padding: 40px 20px;">
        <!-- Header -->
        <div style="text-align: center; margin-bottom: 32px;">
            <h1 style="color: #22d3ee; font-size: 28px; margin: 0;">🐰 PixelHop</h1>
            <p style="color: #888; font-size: 14px; margin-top: 8px;">Free Image Hosting</p>
        </div>
        
        <!-- Main Card -->
        <div style="background: linear-gradient(135deg, rgba(20, 20, 35, 0.95), rgba(30, 30, 50, 0.95)); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 32px;">
            <h2 style="color: #fff; font-size: 22px; margin: 0 0 16px 0;">Verify your email address</h2>
            
            <p style="color: #aaa; font-size: 15px; line-height: 1.6; margin: 0 0 24px 0;">
                Thanks for signing up! Please click the button below to verify your email address and activate your account.
            </p>
            
            <!-- Button -->
            <div style="text-align: center; margin: 32px 0;">
                <a href="{$verifyUrl}" style="display: inline-block; padding: 16px 48px; background: linear-gradient(135deg, #22d3ee, #a855f7); color: #fff; text-decoration: none; font-weight: 600; font-size: 16px; border-radius: 12px;">
                    Verify Email Address
                </a>
            </div>
            
            <p style="color: #666; font-size: 13px; margin: 24px 0 0 0;">
                This link will expire in {$expireHours} hours. If you didn't create an account, you can safely ignore this email.
            </p>
            
            <!-- Link fallback -->
            <div style="margin-top: 24px; padding: 16px; background: rgba(255, 255, 255, 0.05); border-radius: 8px;">
                <p style="color: #888; font-size: 12px; margin: 0 0 8px 0;">If the button doesn't work, copy this link:</p>
                <p style="color: #22d3ee; font-size: 11px; word-break: break-all; margin: 0;">{$verifyUrl}</p>
            </div>
        </div>
        
        <!-- Footer -->
        <div style="text-align: center; margin-top: 32px;">
            <p style="color: #555; font-size: 12px; margin: 0;">
                © 2025 PixelHop · <a href="https://p.hel.ink" style="color: #22d3ee; text-decoration: none;">p.hel.ink</a>
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Verification email plain text
     */
    private function getVerificationEmailText(string $verifyUrl, int $expireHours): string
    {
        return <<<TEXT
PixelHop - Verify your email address

Thanks for signing up! Please click the link below to verify your email address:

{$verifyUrl}

This link will expire in {$expireHours} hours.

If you didn't create an account, you can safely ignore this email.

---
PixelHop · https://p.hel.ink
TEXT;
    }
    
    /**
     * Password reset email HTML template
     */
    private function getPasswordResetEmailHtml(string $resetUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #0a0a0f; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
    <div style="max-width: 600px; margin: 0 auto; padding: 40px 20px;">
        <div style="text-align: center; margin-bottom: 32px;">
            <h1 style="color: #22d3ee; font-size: 28px; margin: 0;">🐰 PixelHop</h1>
        </div>
        
        <div style="background: linear-gradient(135deg, rgba(20, 20, 35, 0.95), rgba(30, 30, 50, 0.95)); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 32px;">
            <h2 style="color: #fff; font-size: 22px; margin: 0 0 16px 0;">Reset your password</h2>
            
            <p style="color: #aaa; font-size: 15px; line-height: 1.6; margin: 0 0 24px 0;">
                We received a request to reset your password. Click the button below to create a new password.
            </p>
            
            <div style="text-align: center; margin: 32px 0;">
                <a href="{$resetUrl}" style="display: inline-block; padding: 16px 48px; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; text-decoration: none; font-weight: 600; font-size: 16px; border-radius: 12px;">
                    Reset Password
                </a>
            </div>
            
            <p style="color: #666; font-size: 13px; margin: 24px 0 0 0;">
                This link will expire in 1 hour. If you didn't request this, you can safely ignore this email.
            </p>
        </div>
        
        <div style="text-align: center; margin-top: 32px;">
            <p style="color: #555; font-size: 12px;">© 2025 PixelHop</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Password reset email plain text
     */
    private function getPasswordResetEmailText(string $resetUrl): string
    {
        return <<<TEXT
PixelHop - Reset your password

We received a request to reset your password. Click the link below:

{$resetUrl}

This link will expire in 1 hour. If you didn't request this, ignore this email.

---
PixelHop · https://p.hel.ink
TEXT;
    }
    
    /**
     * Welcome email HTML template
     */
    private function getWelcomeEmailHtml(string $email): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #0a0a0f; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
    <div style="max-width: 600px; margin: 0 auto; padding: 40px 20px;">
        <div style="text-align: center; margin-bottom: 32px;">
            <h1 style="color: #22d3ee; font-size: 28px; margin: 0;">🎉 Welcome to PixelHop!</h1>
        </div>
        
        <div style="background: linear-gradient(135deg, rgba(20, 20, 35, 0.95), rgba(30, 30, 50, 0.95)); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 16px; padding: 32px;">
            <h2 style="color: #fff; font-size: 22px; margin: 0 0 16px 0;">Your account is verified!</h2>
            
            <p style="color: #aaa; font-size: 15px; line-height: 1.6; margin: 0 0 24px 0;">
                You're all set! Here's what you can do with PixelHop:
            </p>
            
            <ul style="color: #ccc; font-size: 14px; line-height: 2; padding-left: 20px;">
                <li>📸 Upload unlimited images</li>
                <li>🔗 Share direct links anywhere</li>
                <li>🔧 Use image tools (compress, resize, OCR, remove BG)</li>
                <li>📁 Organize your gallery</li>
            </ul>
            
            <div style="text-align: center; margin: 32px 0;">
                <a href="https://p.hel.ink/dashboard" style="display: inline-block; padding: 16px 48px; background: linear-gradient(135deg, #22d3ee, #a855f7); color: #fff; text-decoration: none; font-weight: 600; font-size: 16px; border-radius: 12px;">
                    Go to Dashboard
                </a>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 32px;">
            <p style="color: #555; font-size: 12px;">© 2025 PixelHop</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
