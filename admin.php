<?php
/**
 * PixelHop - Legacy Admin Dashboard (root /admin.php)
 *
 * DEPRECATED / REMOVED.
 *
 * This orphan page duplicated the maintained admin dashboard at
 * /admin/dashboard.php and was no longer linked from any navigation.
 * It rendered admin statistics client-side via innerHTML, including the raw
 * upload filename coming from /api/stats.php, which formed a stored XSS sink
 * (guest-controlled filename -> executed in an admin's browser).
 *
 * The page is now permanently redirected to the maintained dashboard, which
 * closes the XSS sink because this file no longer renders any output.
 */
header('Location: /admin/dashboard.php', true, 301);
exit;
