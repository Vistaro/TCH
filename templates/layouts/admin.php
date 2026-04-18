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
if ($user && empty($user['avatar_path'])) {
    $u2 = fetchUserById((int)$user['id']);
    if ($u2 && !empty($u2['avatar_path'])) {
        $user['avatar_path'] = $u2['avatar_path'];
    }
}
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<?php if (defined('APP_ENV') && APP_ENV !== 'production'): ?>
<div style="background:#ff9800;color:#fff;text-align:center;padding:0.35rem 1rem;font-size:0.85rem;font-weight:600;letter-spacing:0.05em;border-bottom:2px solid #c66900;">
    ⚠ DEV ENVIRONMENT — <?= htmlspecialchars(strtoupper(APP_ENV)) ?> — changes here do NOT affect live customer data.
</div>
<?php endif; ?>

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

            <?php if (userCan('caregivers_list', 'read') || userCan('clients_list', 'read') || userCan('patients_list', 'read') || userCan('engagements', 'read') || userCan('roster_input', 'read') || userCan('student_tracking', 'read') || userCan('opportunities', 'read') || userCan('pipeline', 'read') || userCan('quotes', 'read')): ?>
                <li class="nav-heading">Records</li>
                <?php if (userCan('pipeline', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/pipeline" class="<?= ($activeNav ?? '') === 'pipeline' ? 'active' : '' ?>">&#9632; Pipeline</a></li>
                <?php endif; ?>
                <?php if (userCan('opportunities', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/opportunities" class="<?= ($activeNav ?? '') === 'opportunities' ? 'active' : '' ?>">&#9632; Opportunities</a></li>
                <?php endif; ?>
                <?php if (userCan('quotes', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/quotes" class="<?= ($activeNav ?? '') === 'quotes' ? 'active' : '' ?>">&#9632; Quotes</a></li>
                <?php endif; ?>
                <?php if (userCan('student_tracking', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/students" class="<?= ($activeNav ?? '') === 'student-tracking' ? 'active' : '' ?>">&#9632; Students</a></li>
                <?php endif; ?>
                <?php if (userCan('caregivers_list', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/caregivers" class="<?= ($activeNav ?? '') === 'caregivers' ? 'active' : '' ?>">&#9632; Caregivers</a></li>
                <?php endif; ?>
                <?php if (userCan('clients_list', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/clients" class="<?= ($activeNav ?? '') === 'clients' ? 'active' : '' ?>">&#9632; Clients</a></li>
                <?php endif; ?>
                <?php if (userCan('patients_list', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/patients" class="<?= ($activeNav ?? '') === 'patients' ? 'active' : '' ?>">&#9632; Patients</a></li>
                <?php endif; ?>
                <?php if (userCan('contracts', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/contracts" class="<?= ($activeNav ?? '') === 'contracts' ? 'active' : '' ?>">&#9632; Contracts</a></li>
                <?php endif; ?>
                <?php if (userCan('engagements', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/engagements" class="<?= ($activeNav ?? '') === 'engagements' ? 'active' : '' ?>">&#9632; Care Scheduling</a></li>
                <?php endif; ?>
                <?php if (userCan('roster', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/roster" class="<?= ($activeNav ?? '') === 'roster' ? 'active' : '' ?>">&#9632; Roster View</a></li>
                <?php endif; ?>
                <?php if (userCan('roster_input', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/roster/input" class="<?= ($activeNav ?? '') === 'roster-input' ? 'active' : '' ?>">&#9632; Care Approval</a></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (userCan('reports_caregiver_earnings', 'read') || userCan('reports_client_billing', 'read') || userCan('reports_days_worked', 'read') || userCan('reports_client_profitability', 'read')): ?>
                <li class="nav-heading">Reports</li>
                <?php if (userCan('reports_client_profitability', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/reports/client-profitability" class="<?= ($activeNav ?? '') === 'report-client-profitability' ? 'active' : '' ?>">&#9632; Client Profitability</a></li>
                <?php endif; ?>
                <?php if (userCan('reports_client_billing', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/reports/client-billing" class="<?= ($activeNav ?? '') === 'report-client-billing' ? 'active' : '' ?>">&#9632; Client Billing</a></li>
                <?php endif; ?>
                <?php if (userCan('reports_caregiver_earnings', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/reports/caregiver-earnings" class="<?= ($activeNav ?? '') === 'report-cg-earnings' ? 'active' : '' ?>">&#9632; Caregiver Earnings</a></li>
                <?php endif; ?>
                <?php if (userCan('reports_days_worked', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/reports/days-worked" class="<?= ($activeNav ?? '') === 'report-days-worked' ? 'active' : '' ?>">&#9632; Days Worked</a></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (userCan('enquiries', 'read') || userCan('onboarding', 'read')): ?>
                <li class="nav-heading">Inbox</li>
                <?php if (userCan('enquiries', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/enquiries" class="<?= ($activeNav ?? '') === 'enquiries' ? 'active' : '' ?>">&#9632; Enquiries</a></li>
                <?php endif; ?>
                <?php if (userCan('onboarding', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/onboarding" class="<?= ($activeNav ?? '') === 'onboarding' ? 'active' : '' ?>">&#9632; Tuniti Onboarding</a></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (userCan('people_review', 'read')): ?>
                <li class="nav-heading">Data</li>
                <li><a href="<?= APP_URL ?>/admin/people/review" class="<?= ($activeNav ?? '') === 'people-review' ? 'active' : '' ?>">&#9632; Pending Approvals</a></li>
            <?php endif; ?>

            <?php if (userCan('users', 'read') || userCan('roles', 'read') || userCan('activity_log', 'read') || userCan('email_log', 'read') || userCan('products', 'read') || userCan('config_activity_types', 'read')): ?>
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
                <?php if (userCan('products', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/products" class="<?= ($activeNav ?? '') === 'products' ? 'active' : '' ?>">&#9632; Products</a></li>
                <?php endif; ?>
                <?php if (userCan('config_activity_types', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/config/activity-types" class="<?= ($activeNav ?? '') === 'config-activity-types' ? 'active' : '' ?>">&#9632; Activity Types</a></li>
                <?php endif; ?>
                <?php if (userCan('config_fx_rates', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/config/fx-rates" class="<?= ($activeNav ?? '') === 'config-fx-rates' ? 'active' : '' ?>">&#9632; FX Rates</a></li>
                <?php endif; ?>
                <?php if (userCan('config_aliases', 'read')): ?>
                    <li><a href="<?= APP_URL ?>/admin/config/aliases" class="<?= ($activeNav ?? '') === 'config-aliases' ? 'active' : '' ?>">&#9632; Timesheet Aliases</a></li>
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
                <?php if (!empty($user['avatar_path'])): ?>
                    <img class="admin-user-avatar" src="<?= APP_URL ?>/uploads/<?= htmlspecialchars($user['avatar_path']) ?>" alt="" style="object-fit:cover;">
                <?php else: ?>
                    <div class="admin-user-avatar"><?= strtoupper(substr($user['full_name'] ?? $user['email'] ?? $user['username'] ?? '?', 0, 1)) ?></div>
                <?php endif; ?>
            </div>
        </div>
