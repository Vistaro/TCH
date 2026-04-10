<?php
/**
 * Shared admin layout. Set $pageTitle and $activeNav before including.
 * Content goes between admin_header and admin_footer includes.
 *
 * Sidebar visibility is permission-driven via userCan() so users only see
 * the pages they actually have access to.
 *
 * Includes the impersonation banner across every admin page when active.
 */
initSession();
$user = currentUser();
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<?php if (function_exists('isImpersonating') && isImpersonating()):
    $realUser = currentRealUser();
?>
<div class="impersonation-banner">
    <div class="impersonation-banner-inner">
        <strong>Impersonation active:</strong>
        you (<?= htmlspecialchars($realUser['email'] ?? '') ?>)
        are viewing the system as
        <strong><?= htmlspecialchars($user['email'] ?? '') ?></strong>
        (<?= htmlspecialchars($user['role_name'] ?? '') ?>).
        <a href="<?= APP_URL ?>/admin/impersonate/stop" class="impersonation-stop">End impersonation</a>
    </div>
</div>
<?php endif; ?>

<div class="admin-layout">
    <button class="mobile-menu-toggle" onclick="document.querySelector('.admin-sidebar').classList.toggle('open')" aria-label="Menu">&#9776;</button>
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="admin-sidebar-brand">
            <h2><span class="brand-tch">TCH</span> Admin</h2>
            <small>Placement Management</small>
        </div>
        <ul class="admin-nav">
            <?php if (userCan('dashboard', 'read')): ?>
                <li><a href="<?= APP_URL ?>/admin" class="<?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">&#9632; Dashboard</a></li>
            <?php endif; ?>

            <?php if (userCan('reports_caregiver_earnings', 'read') || userCan('reports_client_billing', 'read') || userCan('reports_days_worked', 'read')): ?>
                <li class="nav-heading">Reports</li>
                <?php if (userCan('reports_caregiver_earnings', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/reports/caregiver-earnings" class="<?= ($activeNav ?? '') === 'report-cg-earnings' ? 'active' : '' ?>">&#9632; Caregiver Earnings</a></li>
                <?php endif; ?>
                <?php if (userCan('reports_client_billing', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/reports/client-billing" class="<?= ($activeNav ?? '') === 'report-client-billing' ? 'active' : '' ?>">&#9632; Client Billing</a></li>
                <?php endif; ?>
                <?php if (userCan('reports_days_worked', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/reports/days-worked" class="<?= ($activeNav ?? '') === 'report-days-worked' ? 'active' : '' ?>">&#9632; Days Worked</a></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (userCan('enquiries', 'read')): ?>
                <li class="nav-heading">Inbox</li>
                <li><a href="<?= APP_URL ?>/admin/enquiries" class="<?= ($activeNav ?? '') === 'enquiries' ? 'active' : '' ?>">&#9632; Enquiries</a></li>
            <?php endif; ?>

            <?php if (userCan('people_review', 'read') || userCan('names_reconcile', 'read')): ?>
                <li class="nav-heading">Data</li>
                <?php if (userCan('people_review', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/people/review" class="<?= ($activeNav ?? '') === 'people-review' ? 'active' : '' ?>">&#9632; Person Review</a></li>
                <?php endif; ?>
                <?php if (userCan('names_reconcile', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/names" class="<?= ($activeNav ?? '') === 'names' ? 'active' : '' ?>">&#9632; Name Reconciliation</a></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (userCan('users', 'read') || userCan('roles', 'read') || userCan('activity_log', 'read') || userCan('email_log', 'read')): ?>
                <li class="nav-heading">Admin</li>
                <?php if (userCan('users', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/users" class="<?= ($activeNav ?? '') === 'users' ? 'active' : '' ?>">&#9632; Users</a></li>
                <?php endif; ?>
                <?php if (userCan('roles', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/roles" class="<?= ($activeNav ?? '') === 'roles' ? 'active' : '' ?>">&#9632; Roles &amp; Permissions</a></li>
                <?php endif; ?>
                <?php if (userCan('activity_log', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/activity" class="<?= ($activeNav ?? '') === 'activity' ? 'active' : '' ?>">&#9632; Activity Log</a></li>
                <?php endif; ?>
                <?php if (userCan('email_log', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/email-log" class="<?= ($activeNav ?? '') === 'email-log' ? 'active' : '' ?>">&#9632; Email Outbox</a></li>
                <?php endif; ?>
            <?php endif; ?>

            <li class="nav-separator">
                <a href="<?= APP_URL ?>/logout">&#9632; Sign Out</a>
            </li>
        </ul>
    </aside>
    <div class="sidebar-overlay" onclick="document.getElementById('adminSidebar').classList.remove('open')"></div>

    <main class="admin-content">
        <div class="admin-header">
            <h1><?= htmlspecialchars($pageTitle ?? 'Admin') ?></h1>
            <div class="admin-user">
                <span><?= htmlspecialchars($user['full_name'] ?? $user['email'] ?? $user['username'] ?? '') ?></span>
                <div class="admin-user-avatar"><?= strtoupper(substr($user['full_name'] ?? $user['email'] ?? $user['username'] ?? '?', 0, 1)) ?></div>
            </div>
        </div>
