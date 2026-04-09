<?php
/**
 * Creates the admin user account.
 * Run from project root: php database/seeds/create_admin.php
 *
 * Usage:
 *   php database/seeds/create_admin.php <password>
 *
 * If no password is provided, a random one will be generated and displayed.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

require APP_ROOT . '/includes/config.php';
require APP_ROOT . '/includes/db.php';

$password = $argv[1] ?? null;

if (!$password) {
    $password = bin2hex(random_bytes(8));
    echo "Generated password: $password\n";
    echo "(Save this — it won't be shown again)\n\n";
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$db = getDB();

// Check if user already exists
$stmt = $db->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute(['ross']);

if ($stmt->fetch()) {
    // Update existing
    $stmt = $db->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE username = ?');
    $stmt->execute([$hash, 'ross']);
    echo "Updated admin user 'ross' with new password.\n";
} else {
    // Insert new
    $stmt = $db->prepare(
        'INSERT INTO users (username, password_hash, full_name, email, role, is_active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute(['ross', $hash, 'Ross', 'ross@intelligentae.co.uk', 'admin']);
    echo "Created admin user 'ross'.\n";
}

echo "Done.\n";
