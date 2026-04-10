<?php
/**
 * Shared admin layout. Set $pageTitle and $activeNav before including.
 * Content goes between admin_header and admin_footer includes.
 */
initSession();
$user = currentUser();
?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<div class="admin-layout">
    <button class="mobile-menu-toggle" onclick="document.querySelector('.admin-sidebar').classList.toggle('open')" aria-label="Menu">&#9776;</button>
    <aside class="admin-sidebar" id="adminSidebar">
        <div class="admin-sidebar-brand">
            <h2><span class="brand-tch">TCH</span> Admin</h2>
            <small>Placement Management</small>
        </div>
        <ul class="admin-nav">
            <li><a href="<?= APP_URL ?>/admin" class="<?= ($activeNav ?? '') === 'dashboard' ? 'active' : '' ?>">&#9632; Dashboard</a></li>

            <li class="nav-heading">Reports</li>
            <li><a href="<?= APP_URL ?>/admin/reports/caregiver-earnings" class="<?= ($activeNav ?? '') === 'report-cg-earnings' ? 'active' : '' ?>">&#9632; Caregiver Earnings</a></li>
            <li><a href="<?= APP_URL ?>/admin/reports/client-billing" class="<?= ($activeNav ?? '') === 'report-client-billing' ? 'active' : '' ?>">&#9632; Client Billing</a></li>
            <li><a href="<?= APP_URL ?>/admin/reports/days-worked" class="<?= ($activeNav ?? '') === 'report-days-worked' ? 'active' : '' ?>">&#9632; Days Worked</a></li>

            <li class="nav-heading">Data</li>
            <li><a href="<?= APP_URL ?>/admin/people/review" class="<?= ($activeNav ?? '') === 'people-review' ? 'active' : '' ?>">&#9632; Person Review</a></li>
            <li><a href="<?= APP_URL ?>/admin/names" class="<?= ($activeNav ?? '') === 'names' ? 'active' : '' ?>">&#9632; Name Reconciliation</a></li>

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
                <span><?= htmlspecialchars($user['full_name'] ?? $user['username']) ?></span>
                <div class="admin-user-avatar"><?= strtoupper(substr($user['full_name'] ?? $user['username'], 0, 1)) ?></div>
            </div>
        </div>
