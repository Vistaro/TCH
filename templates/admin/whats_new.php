<?php
/**
 * "What's new" — release notes shown after a deploy.
 *
 * Login routes here automatically when the user has unread releases
 * (login.php checks last_release_seen_id vs the newest published
 * release.id and redirects). Users can also visit any time to re-read.
 *
 * Permission: whats_new.read (granted to every role by migration 026).
 */

$pageTitle = "What's New";
$activeNav = '';

$db   = getDB();
$me   = currentEffectiveUser();
$myId = (int)($me['id'] ?? 0);

// Pick up last_release_seen_id for THIS user
$lastSeen = 0;
if ($myId) {
    $stmt = $db->prepare('SELECT last_release_seen_id FROM users WHERE id = ?');
    $stmt->execute([$myId]);
    $lastSeen = (int)($stmt->fetchColumn() ?: 0);
}

// Mark-as-read POST
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['action'] ?? '') === 'mark_seen'
        && validateCsrfToken($_POST['csrf_token'] ?? '')
        && $myId) {
    $newest = (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM releases WHERE is_published = 1')->fetchColumn();
    if ($newest > 0) {
        $db->prepare('UPDATE users SET last_release_seen_id = ? WHERE id = ?')
           ->execute([$newest, $myId]);
        logActivity('whats_new_acked', 'whats_new', 'releases', $newest,
            'Acknowledged release id ' . $newest, ['last_release_seen_id' => $lastSeen],
            ['last_release_seen_id' => $newest]);
    }
    header('Location: ' . APP_URL . '/admin');
    exit;
}

// Decide what to show:
//   - Unread releases (id > lastSeen) — primary case after a deploy
//   - If the user has read everything, show the most recent release as
//     a re-read with a small "(already acknowledged)" note
$stmt = $db->prepare(
    'SELECT * FROM releases WHERE is_published = 1 AND id > ? ORDER BY released_at DESC, id DESC'
);
$stmt->execute([$lastSeen]);
$unread = $stmt->fetchAll();

$rereadOnly = false;
if (empty($unread)) {
    $rereadOnly = true;
    $unread = $db->query(
        'SELECT * FROM releases WHERE is_published = 1 ORDER BY released_at DESC, id DESC LIMIT 1'
    )->fetchAll();
}

// Tiny inline markdown-ish renderer — supports **bold**, *italic*,
// ## h2, - bullets, blank-line paragraph breaks. Everything else is
// escaped. Keep this server-side and small (no markdown library).
function renderReleaseBody(string $md): string {
    $lines = explode("\n", $md);
    $html  = '';
    $inList = false;
    foreach ($lines as $line) {
        $line = rtrim($line);
        if ($line === '') {
            if ($inList) { $html .= "</ul>\n"; $inList = false; }
            $html .= "\n";
            continue;
        }
        if (preg_match('/^## (.+)$/', $line, $m)) {
            if ($inList) { $html .= "</ul>\n"; $inList = false; }
            $html .= '<h4 style="margin:1rem 0 0.4rem;">' . htmlspecialchars($m[1]) . "</h4>\n";
            continue;
        }
        if (preg_match('/^\- (.+)$/', $line, $m)) {
            if (!$inList) { $html .= "<ul>\n"; $inList = true; }
            $body = htmlspecialchars($m[1]);
            $body = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $body);
            $body = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $body);
            $html .= '<li>' . $body . "</li>\n";
            continue;
        }
        if ($inList) { $html .= "</ul>\n"; $inList = false; }
        $body = htmlspecialchars($line);
        $body = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $body);
        $body = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $body);
        $html .= '<p style="margin:0.4rem 0;">' . $body . "</p>\n";
    }
    if ($inList) $html .= "</ul>\n";
    return $html;
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<div style="max-width:840px;">

    <?php if ($rereadOnly): ?>
        <p style="color:#6c757d;font-size:0.9rem;margin-bottom:1rem;">
            You're up to date — this is the most recent release. Re-reading is fine.
        </p>
    <?php else: ?>
        <p style="color:#6c757d;font-size:0.9rem;margin-bottom:1rem;">
            <?= count($unread) ?> release<?= count($unread) === 1 ? '' : 's' ?> since you last visited. Click <em>Got it</em> at the bottom to acknowledge and continue to the dashboard.
        </p>
    <?php endif; ?>

    <?php foreach ($unread as $r): ?>
        <div class="person-card" style="margin-bottom:1.5rem;">
            <div class="person-card-header">
                <div class="person-card-title">
                    <h2><?= htmlspecialchars($r['title']) ?></h2>
                    <div class="person-card-meta">
                        Version <strong><?= htmlspecialchars($r['version']) ?></strong>
                        &middot; Released <?= htmlspecialchars(date('d M Y', strtotime($r['released_at']))) ?>
                    </div>
                </div>
            </div>
            <div class="person-card-grid" style="grid-template-columns:1fr;">
                <?php if (!empty($r['summary'])): ?>
                    <div class="person-card-section">
                        <h3>What's changed</h3>
                        <div style="font-size:0.95rem;line-height:1.5;">
                            <?= renderReleaseBody((string)$r['summary']) ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($r['known_issues'])): ?>
                    <div class="person-card-section" style="background:#fff8e1;border-left:3px solid #ffc107;border-radius:0;">
                        <h3 style="color:#856404;">Known issues / in flight</h3>
                        <div style="font-size:0.95rem;line-height:1.5;">
                            <?= renderReleaseBody((string)$r['known_issues']) ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!$rereadOnly): ?>
        <form method="POST" style="text-align:right;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="mark_seen">
            <a href="<?= APP_URL ?>/admin" class="btn btn-link">Skip for now</a>
            <button type="submit" class="btn btn-primary">Got it — go to dashboard</button>
        </form>
    <?php else: ?>
        <div style="text-align:right;">
            <a href="<?= APP_URL ?>/admin" class="btn btn-primary">Back to dashboard</a>
        </div>
    <?php endif; ?>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
