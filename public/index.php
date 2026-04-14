<?php
/**
 * Front controller — all requests route through here.
 *
 * Routes are matched in two passes:
 *   1. Parametric routes (e.g. /admin/users/123) via preg_match
 *   2. Static routes via switch
 *
 * Permission gating: existing pages still call requireAuth() (legacy shim);
 * new admin pages call requirePagePermission($pageCode, $action) which
 * also calls requireAuth() internally.
 */

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/includes/config.php';
require APP_ROOT . '/includes/db.php';
require APP_ROOT . '/includes/auth.php';

$route = trim($_GET['route'] ?? '', '/');

// ─── Parametric admin routes ────────────────────────────────────────────
// Matched before the static switch so /admin/users/123 doesn't fall through.

if (preg_match('#^admin/users/(\d+)$#', $route, $m)) {
    $_GET['user_id'] = (int)$m[1];
    requirePagePermission('users', 'read');
    require APP_ROOT . '/templates/admin/users_detail.php';
    exit;
}

if (preg_match('#^admin/users/(\d+)/impersonate$#', $route, $m)) {
    $_GET['user_id'] = (int)$m[1];
    requirePagePermission('users', 'edit');
    require APP_ROOT . '/templates/admin/users_impersonate.php';
    exit;
}

if (preg_match('#^admin/roles/(\d+)/permissions$#', $route, $m)) {
    $_GET['role_id'] = (int)$m[1];
    requirePagePermission('roles', 'edit');
    require APP_ROOT . '/templates/admin/roles_permissions.php';
    exit;
}

if (preg_match('#^admin/email-log/(\d+)$#', $route, $m)) {
    $_GET['email_id'] = (int)$m[1];
    requirePagePermission('email_log', 'read');
    require APP_ROOT . '/templates/admin/email_log_detail.php';
    exit;
}

if (preg_match('#^admin/reports/client-profitability/(\d+)$#', $route, $m)) {
    $_GET['client_id'] = (int)$m[1];
    requirePagePermission('reports_client_profitability', 'read');
    require APP_ROOT . '/templates/admin/reports/client_profitability_detail.php';
    exit;
}

if (preg_match('#^admin/activity/(\d+)$#', $route, $m)) {
    $_GET['activity_id'] = (int)$m[1];
    requirePagePermission('activity_log', 'read');
    require APP_ROOT . '/templates/admin/activity_detail.php';
    exit;
}

if ($route === 'admin/students/new') {
    requirePagePermission('student_view', 'create');
    require APP_ROOT . '/templates/admin/student_create.php';
    exit;
}

if ($route === 'admin/clients/new') {
    requirePagePermission('client_view', 'create');
    require APP_ROOT . '/templates/admin/client_create.php';
    exit;
}

if ($route === 'admin/patients/new') {
    requirePagePermission('patient_view', 'create');
    require APP_ROOT . '/templates/admin/patient_create.php';
    exit;
}

if (preg_match('#^admin/clients/(\d+)$#', $route, $m)) {
    $_GET['client_id'] = (int)$m[1];
    requirePagePermission('client_view', 'read');
    require APP_ROOT . '/templates/admin/client_view.php';
    exit;
}

if (preg_match('#^admin/patients/(\d+)$#', $route, $m)) {
    $_GET['patient_id'] = (int)$m[1];
    requirePagePermission('patient_view', 'read');
    require APP_ROOT . '/templates/admin/patient_view.php';
    exit;
}

if ($route === 'admin/config/fx-rates') {
    requirePagePermission('config_fx_rates', 'read');
    require APP_ROOT . '/templates/admin/config_fx_rates.php';
    exit;
}

if ($route === 'admin/config/aliases') {
    requirePagePermission('config_aliases', 'read');
    require APP_ROOT . '/templates/admin/config_aliases.php';
    exit;
}

if ($route === 'admin/whats-new') {
    requirePagePermission('whats_new', 'read');
    require APP_ROOT . '/templates/admin/whats_new.php';
    exit;
}

if ($route === 'admin/releases') {
    requirePagePermission('releases_admin', 'read');
    require APP_ROOT . '/templates/admin/releases_admin.php';
    exit;
}

if (preg_match('#^admin/students/(\d+)/print$#', $route, $m)) {
    $_GET['student_id'] = (int)$m[1];
    requirePagePermission('student_view', 'read');
    require APP_ROOT . '/templates/admin/student_print.php';
    exit;
}

if (preg_match('#^admin/students/(\d+)$#', $route, $m)) {
    $_GET['student_id'] = (int)$m[1];
    requirePagePermission('student_view', 'read');
    require APP_ROOT . '/templates/admin/student_view.php';
    exit;
}

// ─── Static routes ──────────────────────────────────────────────────────

switch ($route) {
    case '':
    case 'home':
        require APP_ROOT . '/templates/public/home.php';
        break;

    case 'login':
        require APP_ROOT . '/templates/auth/login.php';
        break;

    case 'forgot-password':
        require APP_ROOT . '/templates/auth/forgot_password.php';
        break;

    case 'reset-password':
        require APP_ROOT . '/templates/auth/reset_password.php';
        break;

    case 'setup-password':
        require APP_ROOT . '/templates/auth/setup_password.php';
        break;

    case 'logout':
        initSession();
        logout();
        header('Location: ' . APP_URL . '/login?logged_out=1');
        exit;

    case 'admin':
    case 'admin/dashboard':
        requirePagePermission('dashboard', 'read');
        require APP_ROOT . '/templates/admin/dashboard.php';
        break;

    case 'admin/reports/caregiver-earnings':
        requirePagePermission('reports_caregiver_earnings', 'read');
        require APP_ROOT . '/templates/admin/reports/caregiver_earnings.php';
        break;

    case 'admin/reports/client-billing':
        requirePagePermission('reports_client_billing', 'read');
        require APP_ROOT . '/templates/admin/reports/client_billing.php';
        break;

    case 'admin/reports/client-profitability':
        requirePagePermission('reports_client_profitability', 'read');
        require APP_ROOT . '/templates/admin/reports/client_profitability.php';
        break;

    case 'admin/reports/days-worked':
        requirePagePermission('reports_days_worked', 'read');
        require APP_ROOT . '/templates/admin/reports/days_worked.php';
        break;

    // ─── Entity list pages ────────────────────────────────────────────
    case 'admin/caregivers':
        requirePagePermission('caregivers_list', 'read');
        require APP_ROOT . '/templates/admin/caregivers_list.php';
        break;

    case 'admin/clients':
        requirePagePermission('clients_list', 'read');
        require APP_ROOT . '/templates/admin/clients_list.php';
        break;

    case 'admin/patients':
        requirePagePermission('patients_list', 'read');
        require APP_ROOT . '/templates/admin/patients_list.php';
        break;

    // ─── Engagements + Roster ──────────────────────────────────────────
    case 'admin/engagements':
        requirePagePermission('engagements', 'read');
        require APP_ROOT . '/templates/admin/engagements.php';
        break;

    case 'admin/roster/input':
        requirePagePermission('roster_input', 'read');
        require APP_ROOT . '/templates/admin/roster_input.php';
        break;

    // ─── Student tracking ──────────────────────────────────────────────
    case 'admin/students':
        requirePagePermission('student_tracking', 'read');
        require APP_ROOT . '/templates/admin/student_tracking.php';
        break;

    // ─── Config pages ──────────────────────────────────────────────────
    case 'admin/products':
        requirePagePermission('products', 'read');
        require APP_ROOT . '/templates/admin/products.php';
        break;

    case 'admin/config/activity-types':
        requirePagePermission('config_activity_types', 'read');
        require APP_ROOT . '/templates/admin/activity_types_config.php';
        break;

    // Name reconciliation retired (v0.9.15) — all names normalised
    // via the 2026-04-12 spreadsheet exercise. Zero orphan roster rows.
    // The name_lookup table and /admin/names page are historical artefacts.
    // Redirect to dashboard if anyone bookmarked the old URL.
    case 'admin/names':
    case 'admin/names/assign':
        header('Location: ' . APP_URL . '/admin/dashboard');
        exit;

    case 'admin/people/review':
        requirePagePermission('people_review', 'read');
        require APP_ROOT . '/templates/admin/people_review.php';
        break;

    case 'admin/enquiries':
        requirePagePermission('enquiries', 'read');
        require APP_ROOT . '/templates/admin/enquiries.php';
        break;

    // ─── User management ───────────────────────────────────────────────
    case 'admin/users':
        requirePagePermission('users', 'read');
        require APP_ROOT . '/templates/admin/users_list.php';
        break;

    case 'admin/users/invite':
        requirePagePermission('users', 'create');
        require APP_ROOT . '/templates/admin/users_invite.php';
        break;

    case 'admin/impersonate/stop':
        requireAuth();
        stopImpersonation();
        header('Location: ' . APP_URL . '/admin');
        exit;

    // ─── Roles + permissions matrix ────────────────────────────────────
    case 'admin/roles':
        requirePagePermission('roles', 'read');
        require APP_ROOT . '/templates/admin/roles_list.php';
        break;

    // ─── Activity log ──────────────────────────────────────────────────
    case 'admin/activity':
        requirePagePermission('activity_log', 'read');
        require APP_ROOT . '/templates/admin/activity_log.php';
        break;

    // ─── Email outbox ──────────────────────────────────────────────────
    case 'admin/email-log':
        requirePagePermission('email_log', 'read');
        require APP_ROOT . '/templates/admin/email_log_list.php';
        break;

    case 'enquire':
        require APP_ROOT . '/templates/public/enquire_handler.php';
        break;

    // ─── In-app Bug/FR reporter (AJAX proxy to Nexus Hub) ──────────────
    // POST only. Auth + CSRF enforced inside the handler.
    case 'ajax/report-issue':
        require APP_ROOT . '/templates/admin/report_issue_handler.php';
        break;

    // ─── Matrix-report drill-down (GET, returns HTML fragment) ─────────
    // Used by caregiver_earnings / client_billing / days_worked when a
    // user clicks a cell in the matrix view. Auth + per-report permission
    // enforced inside the handler.
    case 'ajax/report-drill':
        require APP_ROOT . '/templates/admin/report_drill_handler.php';
        break;

    default:
        http_response_code(404);
        require APP_ROOT . '/templates/errors/404.php';
        break;
}
