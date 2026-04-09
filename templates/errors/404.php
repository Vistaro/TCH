<?php $pageTitle = 'Page Not Found'; ?>
<?php require APP_ROOT . '/templates/layouts/header.php'; ?>

<div class="error-page">
    <div>
        <h1>404</h1>
        <p>The page you're looking for doesn't exist.</p>
        <a href="<?= APP_URL ?>/" class="btn btn-primary">Back to Home</a>
    </div>
</div>
