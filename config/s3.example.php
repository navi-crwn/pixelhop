<?php
/**
 * S3 Object Storage Configuration Example
 * Copy this to s3.php and update with your credentials
 */

return [
    's3' => [
        'endpoint' => 'https://your-s3-endpoint.com',
        'region' => 'default',
        'bucket' => 'your-bucket-name',
        'access_key' => 'your-access-key',
        'secret_key' => 'your-secret-key',
        'use_path_style' => true,
        'public_url' => 'https://your-s3-endpoint.com/your-bucket',
    ],

    'upload' => [
        'max_size' => 10 * 1024 * 1024,
        'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
    ],

    'image' => [
        'quality' => 85,
        'sizes' => [
            'thumb' => ['width' => 150, 'height' => 150],
            'medium' => ['width' => 600, 'height' => 600],
            'large' => ['width' => 1200, 'height' => 1200],
        ],
    ],

    'site' => [
        'name' => 'PixelHop',
        'domain' => 'your-domain.com',
        'url' => 'https://your-domain.com',
    ],
];
