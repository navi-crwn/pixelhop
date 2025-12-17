<?php
/**
 * PixelHop - Database Configuration Example
 * Copy this to database.php and update with your credentials
 */

return [
    'driver' => 'mysql',
    'host' => getenv('DB_HOST') ?: 'localhost',
    'port' => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_NAME') ?: 'pixelhop',
    'username' => getenv('DB_USER') ?: 'your_username',
    'password' => getenv('DB_PASS') ?: 'your_password',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',

    'options' => [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        (PHP_VERSION_ID >= 80500 ? 1002 : PDO::MYSQL_ATTR_INIT_COMMAND) => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ],
];
