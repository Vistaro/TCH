<?php
/**
 * Database connection using PDO with prepared statements only.
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    die('Direct access not permitted.');
}

function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            if (APP_ENV === 'development') {
                die('Database connection failed: ' . $e->getMessage());
            }
            die('Database connection failed. Please contact the administrator.');
        }
    }

    return $pdo;
}
