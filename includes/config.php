<?php
/**
 * Application configuration.
 * Loads settings from .env file in project root.
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

// Load .env file
$envFile = APP_ROOT . '/.env';
if (!file_exists($envFile)) {
    die('Missing .env file. Copy .env.example to .env and configure it.');
}

$envLines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($envLines as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

// Database configuration
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'tch_placements');
define('DB_USER', $_ENV['DB_USER'] ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// Application settings
define('APP_ENV', $_ENV['APP_ENV'] ?? 'production');
define('APP_URL', $_ENV['APP_URL'] ?? 'https://tch.intelligentae.co.uk');
define('APP_NAME', $_ENV['APP_NAME'] ?? 'TCH Placements');
define('SESSION_LIFETIME', (int)($_ENV['SESSION_LIFETIME'] ?? 3600));
