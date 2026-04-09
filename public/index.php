<?php
/**
 * Front controller — all requests route through here.
 */

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/includes/config.php';
require APP_ROOT . '/includes/db.php';
require APP_ROOT . '/includes/auth.php';

$route = trim($_GET['route'] ?? '', '/');

// Public routes
switch ($route) {
    case '':
    case 'home':
        require APP_ROOT . '/templates/public/home.php';
        break;

    case 'login':
        require APP_ROOT . '/templates/auth/login.php';
        break;

    case 'logout':
        initSession();
        logout();
        header('Location: ' . APP_URL . '/login?logged_out=1');
        exit;

    case 'admin':
    case 'admin/dashboard':
        requireAuth();
        require APP_ROOT . '/templates/admin/dashboard.php';
        break;

    default:
        http_response_code(404);
        require APP_ROOT . '/templates/errors/404.php';
        break;
}
