<?php
/**
 * PixelHop - Environment Check Script
 * Verifies all required PHP extensions are installed
 *
 * Usage: php check_env.php (CLI) or visit /check_env.php (browser)
 */

// Security: Only allow from CLI or localhost
$isCli = php_sapi_name() === 'cli';
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);

if (!$isCli && !$isLocalhost) {
    http_response_code(403);
    die('Access denied. Run from CLI or localhost only.');
}

// Required extensions
$requiredExtensions = [
    'imagick' => [
        'name' => 'ImageMagick',
        'description' => 'Advanced image processing (resize, crop, convert, animated GIF)',
        'critical' => true,
    ],
    'gd' => [
        'name' => 'GD Library',
        'description' => 'Basic image processing (fallback)',
        'critical' => false,
    ],
    'pdo_mysql' => [
        'name' => 'PDO MySQL',
        'description' => 'Database connectivity for users/sessions',
        'critical' => true,
    ],
    'curl' => [
        'name' => 'cURL',
        'description' => 'HTTP requests for S3 uploads and URL fetching',
        'critical' => true,
    ],
    'json' => [
        'name' => 'JSON',
        'description' => 'JSON encoding/decoding for API responses',
        'critical' => true,
    ],
    'fileinfo' => [
        'name' => 'Fileinfo',
        'description' => 'MIME type detection for uploads',
        'critical' => true,
    ],
    'mbstring' => [
        'name' => 'Multibyte String',
        'description' => 'Unicode/UTF-8 string handling',
        'critical' => false,
    ],
    'openssl' => [
        'name' => 'OpenSSL',
        'description' => 'SSL/TLS encryption for HTTPS',
        'critical' => true,
    ],
];

// Optional but recommended
$optionalExtensions = [
    'redis' => [
        'name' => 'Redis',
        'description' => 'Session storage and rate limiting (optional)',
    ],
    'apcu' => [
        'name' => 'APCu',
        'description' => 'In-memory caching (optional)',
    ],
    'exif' => [
        'name' => 'EXIF',
        'description' => 'Image metadata extraction',
    ],
];

// System checks
$systemChecks = [
    'php_version' => [
        'name' => 'PHP Version',
        'check' => fn() => PHP_VERSION,
        'required' => '8.0.0',
        'validator' => fn($v) => version_compare($v, '8.0.0', '>='),
    ],
    'memory_limit' => [
        'name' => 'Memory Limit',
        'check' => fn() => ini_get('memory_limit'),
        'required' => '256M',
        'validator' => fn($v) => convertToBytes($v) >= convertToBytes('256M'),
    ],
    'max_upload_size' => [
        'name' => 'Upload Max Filesize',
        'check' => fn() => ini_get('upload_max_filesize'),
        'required' => '15M',
        'validator' => fn($v) => convertToBytes($v) >= convertToBytes('15M'),
    ],
    'post_max_size' => [
        'name' => 'POST Max Size',
        'check' => fn() => ini_get('post_max_size'),
        'required' => '20M',
        'validator' => fn($v) => convertToBytes($v) >= convertToBytes('20M'),
    ],
    'max_execution_time' => [
        'name' => 'Max Execution Time',
        'check' => fn() => ini_get('max_execution_time') . 's',
        'required' => '60s',
        'validator' => fn($v) => (int)$v >= 60 || (int)$v === 0,
    ],
];

// External tools
$externalTools = [
    'python3' => [
        'name' => 'Python 3',
        'command' => 'python3 --version 2>&1',
        'required' => true,
    ],
    'convert' => [
        'name' => 'ImageMagick CLI',
        'command' => 'convert --version 2>&1 | head -1',
        'required' => false,
    ],
];

function convertToBytes($value) {
    $value = trim($value);
    $unit = strtolower(substr($value, -1));
    $num = (int)$value;

    switch ($unit) {
        case 'g': return $num * 1024 * 1024 * 1024;
        case 'm': return $num * 1024 * 1024;
        case 'k': return $num * 1024;
        default: return $num;
    }
}

// Results
$results = [
    'extensions' => [],
    'optional' => [],
    'system' => [],
    'tools' => [],
    'summary' => [
        'passed' => 0,
        'failed' => 0,
        'warnings' => 0,
    ],
];

// Check required extensions
foreach ($requiredExtensions as $ext => $info) {
    $loaded = extension_loaded($ext);
    $results['extensions'][$ext] = [
        'name' => $info['name'],
        'description' => $info['description'],
        'status' => $loaded ? 'OK' : ($info['critical'] ? 'FAIL' : 'WARN'),
        'loaded' => $loaded,
        'critical' => $info['critical'],
    ];

    if ($loaded) {
        $results['summary']['passed']++;
    } elseif ($info['critical']) {
        $results['summary']['failed']++;
    } else {
        $results['summary']['warnings']++;
    }
}

// Check optional extensions
foreach ($optionalExtensions as $ext => $info) {
    $loaded = extension_loaded($ext);
    $results['optional'][$ext] = [
        'name' => $info['name'],
        'description' => $info['description'],
        'status' => $loaded ? 'OK' : 'SKIP',
        'loaded' => $loaded,
    ];
}

// Check system settings
foreach ($systemChecks as $key => $check) {
    $value = $check['check']();
    $valid = $check['validator']($value);
    $results['system'][$key] = [
        'name' => $check['name'],
        'value' => $value,
        'required' => $check['required'],
        'status' => $valid ? 'OK' : 'FAIL',
    ];

    if ($valid) {
        $results['summary']['passed']++;
    } else {
        $results['summary']['failed']++;
    }
}

// Check external tools
foreach ($externalTools as $key => $tool) {
    $output = shell_exec($tool['command']);
    $available = !empty($output) && stripos($output, 'not found') === false;
    $results['tools'][$key] = [
        'name' => $tool['name'],
        'output' => trim($output ?? 'Not found'),
        'status' => $available ? 'OK' : ($tool['required'] ? 'FAIL' : 'SKIP'),
        'available' => $available,
    ];

    if ($available) {
        $results['summary']['passed']++;
    } elseif ($tool['required']) {
        $results['summary']['failed']++;
    }
}

// Output
if ($isCli) {

    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════╗\n";
    echo "║           PixelHop Environment Check                        ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";

    echo "📦 PHP EXTENSIONS (Required)\n";
    echo str_repeat('─', 60) . "\n";
    foreach ($results['extensions'] as $ext => $info) {
        $icon = $info['status'] === 'OK' ? '✅' : ($info['status'] === 'FAIL' ? '❌' : '⚠️');
        printf("  %s %-15s %-10s %s\n", $icon, $ext, "({$info['status']})", $info['description']);
    }

    echo "\n📦 PHP EXTENSIONS (Optional)\n";
    echo str_repeat('─', 60) . "\n";
    foreach ($results['optional'] as $ext => $info) {
        $icon = $info['status'] === 'OK' ? '✅' : '⬜';
        printf("  %s %-15s %-10s %s\n", $icon, $ext, "({$info['status']})", $info['description']);
    }

    echo "\n⚙️  SYSTEM SETTINGS\n";
    echo str_repeat('─', 60) . "\n";
    foreach ($results['system'] as $key => $info) {
        $icon = $info['status'] === 'OK' ? '✅' : '❌';
        printf("  %s %-20s %-12s (required: %s)\n", $icon, $info['name'], $info['value'], $info['required']);
    }

    echo "\n🔧 EXTERNAL TOOLS\n";
    echo str_repeat('─', 60) . "\n";
    foreach ($results['tools'] as $key => $info) {
        $icon = $info['status'] === 'OK' ? '✅' : ($info['status'] === 'FAIL' ? '❌' : '⬜');
        printf("  %s %-20s %s\n", $icon, $info['name'], $info['output']);
    }

    echo "\n" . str_repeat('═', 60) . "\n";
    echo "SUMMARY: ";
    echo "✅ Passed: {$results['summary']['passed']} | ";
    echo "❌ Failed: {$results['summary']['failed']} | ";
    echo "⚠️ Warnings: {$results['summary']['warnings']}\n";

    if ($results['summary']['failed'] > 0) {
        echo "\n⛔ CRITICAL: Some required extensions/settings are missing!\n";
        echo "   Run the following to install missing extensions:\n";
        echo "   sudo apt install php-imagick php-mysql php-curl php-gd\n";
        echo "   sudo systemctl restart php-fpm\n";
    } else {
        echo "\n✅ All critical requirements satisfied!\n";
    }
    echo "\n";

} else {

    header('Content-Type: application/json');
    echo json_encode($results, JSON_PRETTY_PRINT);
}
