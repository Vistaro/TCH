<?php $pageTitle = 'Access Denied'; ?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<div class="error-page">
    <div>
        <h1>403</h1>
        <p>You don't have permission to access this page.</p>
        <a href="<?= APP_URL ?>/" class="btn btn-primary">Back to Home</a>
    </div>
</div>
