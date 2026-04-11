    </main>
</div>
<?php
// In-app Bug/FR reporter widget — admin pages only, logged-in only.
// The CSS link + CSRF/base-URL globals are injected by header.php; the JS
// here builds the floating Help button and the slide-in panel, then POSTs
// to /ajax/report-issue. Graceful failure: if the JS file can't load, the
// rest of the admin UI is unaffected.
if (function_exists('isLoggedIn') && isLoggedIn()):
    $reporterJsVer = @filemtime(APP_ROOT . '/public/assets/js/reporter.js') ?: time();
?>
<script src="<?= APP_URL ?>/assets/js/reporter.js?v=<?= $reporterJsVer ?>"></script>
<?php endif; ?>
</body>
</html>
