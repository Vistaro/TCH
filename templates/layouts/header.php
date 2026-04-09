<?php
// Prevent CDN and browser caching during development
if (APP_ENV === 'development') {
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}
$cssVersion = @filemtime(APP_ROOT . '/public/assets/css/style.css') ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'TCH Placements') ?> — TCH Placements</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css?v=<?= $cssVersion ?>">
</head>
<body>
