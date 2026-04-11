<?php
// Prevent CDN and browser caching during development
if (APP_ENV === 'development') {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}
$cssVersion      = @filemtime(APP_ROOT . '/public/assets/css/style.css')    ?: time();
$reporterCssVer  = @filemtime(APP_ROOT . '/public/assets/css/reporter.css') ?: time();

// The in-app Bug/FR reporter is shown only to logged-in users. The CSS link
// and CSRF/base-URL globals are injected here; the JS itself is loaded from
// admin_footer.php so it doesn't fire on public pages.
$showReporter = function_exists('isLoggedIn') && isLoggedIn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'TCH Placements') ?> — TCH Placements</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= $cssVersion ?>">
    <?php if ($showReporter): ?>
        <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/reporter.css?v=<?= $reporterCssVer ?>">
        <script>
            const TCH_BASE_URL = <?= json_encode(APP_URL) ?>;
            const TCH_CSRF     = <?= json_encode(generateCsrfToken()) ?>;
        </script>
    <?php endif; ?>
</head>
<body<?php if ($showReporter): ?> data-page-slug="<?= htmlspecialchars($activeNav ?? '', ENT_QUOTES) ?>" data-page-title="<?= htmlspecialchars($pageTitle ?? '', ENT_QUOTES) ?>"<?php endif; ?>>
