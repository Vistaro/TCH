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

// Error-display discipline — prevents BUG-0035 style leaks regardless of
// php.ini or future .htaccess edits. In production we log but never
// display (PHP errors with stack + SQL snippets can expose credentials).
if (APP_ENV === 'production') {
    error_reporting(E_ALL);
    ini_set('display_errors',        '0');
    ini_set('display_startup_errors','0');
    ini_set('log_errors',            '1');
} else {
    // Dev: show errors so we catch things fast.
    error_reporting(E_ALL);
    ini_set('display_errors',        '1');
    ini_set('display_startup_errors','1');
    ini_set('log_errors',            '1');
}

// Nexus Hub integration — in-app Bug/FR reporter proxies submissions here.
// The token is generated in the Hub web UI (Super Admin > Tokens > Create)
// and MUST be scoped to the 'tch' project for safety. Never commit the
// real token — .env is gitignored.
define('NEXUS_HUB_URL',          $_ENV['NEXUS_HUB_URL']          ?? 'https://hub.intelligentae.co.uk');
define('NEXUS_HUB_PROJECT_SLUG', $_ENV['NEXUS_HUB_PROJECT_SLUG'] ?? 'tch');
define('NEXUS_HUB_TOKEN',        $_ENV['NEXUS_HUB_TOKEN']        ?? '');
