<?php
/**
 * /admin/onboarding — Tuniti onboarding task dashboard.
 *
 * Landing page listing every outstanding task we need Tuniti (or the
 * assigned user) to resolve. Each task is a registry entry in
 * includes/onboarding_tasks.php. Cards show the pending count + link
 * to the task subpage. Done tasks collapse into a "Completed" section.
 */
require_once APP_ROOT . '/includes/onboarding_tasks.php';

$pageTitle = 'Tuniti Onboarding';
$activeNav = 'onboarding';

$db = getDB();
$tasks = onboardingTasks();

// Compute counts + split into pending / completed
$pending   = [];
$completed = [];
foreach ($tasks as $key => $task) {
    $count = (int)call_user_func($task['count_fn'], $db);
    $task['_key']   = $key;
    $task['_count'] = $count;
    if ($count > 0) $pending[]   = $task;
    else            $completed[] = $task;
}

// Sort pending by priority: high → med → low, then by added_at
$prioWeight = ['high' => 0, 'med' => 1, 'low' => 2];
usort($pending, function ($a, $b) use ($prioWeight) {
    $pa = $prioWeight[$a['priority'] ?? 'med'] ?? 1;
    $pb = $prioWeight[$b['priority'] ?? 'med'] ?? 1;
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['added_at'] ?? '', $b['added_at'] ?? '');
});

$queueCount = onboardingUploadQueueCount($db);

require APP_ROOT . '/templates/layouts/admin.php';

function renderTaskCard(array $task): void {
    $prioColour = [
        'high' => '#dc3545',
        'med'  => '#0d6efd',
        'low'  => '#6c757d',
    ][$task['priority'] ?? 'med'] ?? '#6c757d';
    $isDone = ($task['_count'] === 0);
    ?>
    <a href="<?= APP_URL . htmlspecialchars($task['subpage']) ?>"
       style="display:block;background:#fff;border:1px solid #dee2e6;border-left:4px solid <?= $prioColour ?>;border-radius:8px;padding:1rem 1.25rem;margin-bottom:0.75rem;text-decoration:none;color:inherit;<?= $isDone ? 'opacity:0.55;' : '' ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;">
            <div style="flex:1;">
                <div style="font-weight:600;color:#212529;font-size:1rem;margin-bottom:0.15rem;">
                    <?= htmlspecialchars($task['title']) ?>
                    <?php if ($isDone): ?>
                        <span style="color:#198754;font-size:0.9rem;margin-left:0.4rem;">✓ complete</span>
                    <?php endif; ?>
                </div>
                <div style="color:#6c757d;font-size:0.85rem;line-height:1.4;">
                    <?= htmlspecialchars($task['description']) ?>
                </div>
            </div>
            <?php if (!$isDone): ?>
                <div style="background:<?= $prioColour ?>;color:#fff;font-weight:700;border-radius:999px;padding:0.2rem 0.7rem;font-size:0.85rem;white-space:nowrap;">
                    <?= number_format($task['_count']) ?> pending
                </div>
            <?php endif; ?>
        </div>
    </a>
    <?php
}
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap;">
    <div style="max-width:720px;">
        <p style="color:#495057;margin:0 0 0.5rem 0;">
            Outstanding items the business needs to resolve. Each card is one task — click in, knock it out, move on.
            Attach files (spreadsheets, Word docs, PDFs) on any task's page if you don't have the structured details handy
            — we'll review and extract what we need.
        </p>
    </div>
    <?php if ($queueCount > 0): ?>
        <a href="<?= APP_URL ?>/admin/onboarding/review" class="btn btn-outline btn-sm" style="white-space:nowrap;">
            📥 <?= number_format($queueCount) ?> file<?= $queueCount === 1 ? '' : 's' ?> in review queue
        </a>
    <?php endif; ?>
</div>

<?php if (!empty($pending)): ?>
    <h3 style="font-size:0.9rem;text-transform:uppercase;letter-spacing:0.06em;color:#6c757d;margin:1rem 0 0.5rem 0;">
        Pending — <?= count($pending) ?>
    </h3>
    <?php foreach ($pending as $task) renderTaskCard($task); ?>
<?php endif; ?>

<?php if (!empty($completed)): ?>
    <details style="margin-top:1.5rem;">
        <summary style="cursor:pointer;font-size:0.9rem;text-transform:uppercase;letter-spacing:0.06em;color:#6c757d;padding:0.3rem 0;">
            Completed — <?= count($completed) ?>
        </summary>
        <div style="margin-top:0.5rem;">
            <?php foreach ($completed as $task) renderTaskCard($task); ?>
        </div>
    </details>
<?php endif; ?>

<?php if (empty($pending) && empty($completed)): ?>
    <p style="color:#6c757d;padding:2rem;text-align:center;">No onboarding tasks registered yet.</p>
<?php endif; ?>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
