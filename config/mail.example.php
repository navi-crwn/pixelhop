<?php
/**
 * PixelHop - Mail Configuration
 * Copy this file to mail.php and fill in your credentials
 */

return [
    // SMTP Settings
    'host' => 'mail.yourdomain.com',
    'port' => 465,
    'username' => 'no-reply@yourdomain.com',
    'password' => 'your-smtp-password',
    'encryption' => 'ssl', // ssl for port 465, tls for port 587
    
    // From Address
    'from_address' => 'no-reply@yourdomain.com',
    'from_name' => 'PixelHop',
    
    // Support Addresses
    'support_email' => 'support@yourdomain.com',
    'admin_email' => 'admin@yourdomain.com',

    // Admin Alert (Observability) - penerima email alert kritis dari Alerter.
    // Operator dapat mengarahkan alert ke alamat khusus (mis. milis on-call)
    // tanpa mengubah alamat admin utama. Bila dikosongkan, Alerter akan
    // fallback ke environment variable ADMIN_ALERT_EMAIL, lalu admin_email,
    // support_email, dan terakhir from_address.
    'admin_alerts_to' => 'admin@yourdomain.com',
    
    // Verification Settings
    'verification_expire_hours' => 24,
    'verification_base_url' => 'https://your-domain.com',
];
