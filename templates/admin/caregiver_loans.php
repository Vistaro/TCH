<?php
/**
 * Caregiver Loan Ledger — /admin/caregiver-loans
 *
 * Event-sourced ledger. Two event types:
 *   advance    — loan paid out (balance owing goes up)
 *   repayment  — money deducted from pay (balance owing goes down)
 *
 * Running balance per caregiver = SUM(advance) − SUM(repayment).
 *
 * Per-caregiver drill-down + per-caregiver event log + "record event"
 * form. No external integrations; payroll feed is read-side only
 * (Caregiver Earnings report can subtract pending repayments
 * once wired — deferred).
 */
$pageTitle = 'Caregiver Loans';
$activeNav = 'caregiver-loans';

$db = getDB();
$canCreate = userCan('caregiver_loans', 'create');
$canEdit   = userCan('caregiver_loans', 'edit');

$flash = ''; $flashType = 'success';

// ── POST: record an event ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($canCreate || $canEdit)) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid form submission.'; $flashType = 'error';
    } else {
        try {
            $caregiverId = (int)$_POST['caregiver_id'];
            $eventType   = $_POST['event_type'] ?? '';
            $amountRand  = trim((string)($_POST['amount_rand'] ?? ''));
            $eventDate   = $_POST['event_date'] ?? null;
            $reason      = trim((string)($_POST['reason'] ?? '')) ?: null;
            $payrollMonth= trim((string)($_POST['payroll_run_month'] ?? '')) ?: null;
            $notes       = trim((string)($_POST['notes'] ?? '')) ?: null;

            if (!$caregiverId || !in_array($eventType, ['advance','repayment'], true)
                || !is_numeric($amountRand) || $amountRand <= 0 || !$eventDate) {
                throw new RuntimeException('Caregiver, event type, a positive amount and date are required.');
            }

            $amountCents = (int)round(((float)$amountRand) * 100);

            $db->prepare(
                "INSERT INTO caregiver_loans
                    (caregiver_id, event_type, amount_cents, event_date,
                     reason, payroll_run_month, notes, created_by_user_id)
                 VALUES (?,?,?,?,?,?,?,?)"
            )->execute([
                $caregiverId, $eventType, $amountCents, $eventDate,
                $reason, $payrollMonth, $notes,
                (int)($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            $loanId = (int)$db->lastInsertId();

            logActivity('caregiver_loan_' . $eventType, 'caregiver_loans',
                'caregiver_loans', $loanId,
                ucfirst($eventType) . ' R' . number_format((float)$amountRand, 2) . ' for caregiver_id=' . $caregiverId,
                null,
                ['event_type' => $eventType, 'amount_cents' => $amountCents, 'event_date' => $eventDate]);

            $flash = ucfirst($eventType) . ' recorded: R' . number_format((float)$amountRand, 2) . '.';
        } catch (Throwable $e) {
            $flash = 'Error: ' . $e->getMessage(); $flashType = 'error';
        }
    }
}

// ── Read: per-caregiver balance summary ───────────────────────────
$summarySql = "
  SELECT
    cg.id AS caregiver_id,
    p.full_name AS caregiver_name,
    p.tch_id,
    COALESCE(SUM(CASE WHEN cl.event_type='advance'   THEN cl.amount_cents ELSE 0 END), 0) AS total_advanced_cents,
    COALESCE(SUM(CASE WHEN cl.event_type='repayment' THEN cl.amount_cents ELSE 0 END), 0) AS total_repaid_cents,
    COALESCE(SUM(CASE WHEN cl.event_type='advance'   THEN cl.amount_cents ELSE 0 END), 0)
    - COALESCE(SUM(CASE WHEN cl.event_type='repayment' THEN cl.amount_cents ELSE 0 END), 0) AS balance_cents,
    COUNT(cl.id) AS event_count,
    MAX(cl.event_date) AS last_event_date
  FROM caregivers cg
  JOIN persons p ON p.id = cg.id
  LEFT JOIN caregiver_loans cl ON cl.caregiver_id = cg.id
  GROUP BY cg.id, p.full_name, p.tch_id
  HAVING event_count > 0
  ORDER BY balance_cents DESC, p.full_name
";
$summary = $db->query($summarySql)->fetchAll(PDO::FETCH_ASSOC);

$totalOutstanding = 0;
$totalAdvanced    = 0;
$totalRepaid      = 0;
foreach ($summary as $s) {
    $totalOutstanding += (int)$s['balance_cents'];
    $totalAdvanced    += (int)$s['total_advanced_cents'];
    $totalRepaid      += (int)$s['total_repaid_cents'];
}

// ── Caregiver picker (for the record-event form) ──────────────────
$caregivers = $db->query(
    "SELECT cg.id, p.full_name, p.tch_id
       FROM caregivers cg
       JOIN persons p ON p.id = cg.id
      WHERE p.archived_at IS NULL
   ORDER BY p.full_name"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Detail drill-down ─────────────────────────────────────────────
$drillCaregiver = isset($_GET['caregiver_id']) ? (int)$_GET['caregiver_id'] : 0;
$drillEvents = [];
$drillName = null;
if ($drillCaregiver > 0) {
    $drillNameRow = $db->prepare(
        "SELECT p.full_name FROM caregivers cg JOIN persons p ON p.id = cg.id WHERE cg.id = ?"
    );
    $drillNameRow->execute([$drillCaregiver]);
    $drillName = $drillNameRow->fetchColumn() ?: null;

    $drillEvents = $db->prepare(
        "SELECT cl.*, u.full_name AS created_by_name
           FROM caregiver_loans cl
      LEFT JOIN users u ON u.id = cl.created_by_user_id
          WHERE cl.caregiver_id = ?
       ORDER BY cl.event_date DESC, cl.id DESC"
    );
    $drillEvents->execute([$drillCaregiver]);
    $drillEvents = $drillEvents->fetchAll(PDO::FETCH_ASSOC);
}

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if ($flash): ?>
    <div style="padding:0.8rem 1rem;margin-bottom:1rem;border-radius:4px;background:<?= $flashType === 'error' ? '#f8d7da' : '#d1e7dd' ?>;color:<?= $flashType === 'error' ? '#842029' : '#0f5132' ?>;">
        <?= htmlspecialchars($flash) ?>
    </div>
<?php endif; ?>

<div class="dash-cards" style="margin-bottom:1.5rem;">
    <div class="dash-card accent">
        <div class="dash-card-label">Outstanding</div>
        <div class="dash-card-value">R<?= number_format($totalOutstanding / 100, 0) ?></div>
    </div>
    <div class="dash-card">
        <div class="dash-card-label">Total advanced (all time)</div>
        <div class="dash-card-value">R<?= number_format($totalAdvanced / 100, 0) ?></div>
    </div>
    <div class="dash-card">
        <div class="dash-card-label">Total repaid (all time)</div>
        <div class="dash-card-value">R<?= number_format($totalRepaid / 100, 0) ?></div>
    </div>
    <div class="dash-card">
        <div class="dash-card-label">Caregivers with loans</div>
        <div class="dash-card-value"><?= count($summary) ?></div>
    </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:1rem;">
<div>

<!-- Balances by caregiver -->
<h3 style="margin-top:0;font-size:1rem;">Balances by caregiver</h3>
<?php if (empty($summary)): ?>
    <p style="color:#6c757d;font-style:italic;">No caregiver loan events yet. Record one using the form to the right.</p>
<?php else: ?>
<table class="report-table tch-data-table">
    <thead><tr>
        <th>Caregiver</th>
        <th class="center" data-filterable="false">TCH ID</th>
        <th class="number">Advanced</th>
        <th class="number">Repaid</th>
        <th class="number">Outstanding</th>
        <th class="center">Events</th>
        <th class="center" data-filterable="false">Last event</th>
    </tr></thead>
    <tbody>
    <?php foreach ($summary as $s):
        $balance = (int)$s['balance_cents'];
        $bgColour = $balance > 0 ? '#fff5f5' : ($balance < 0 ? '#f0fdf4' : 'transparent');
    ?>
        <tr style="cursor:pointer;background:<?= $bgColour ?>;"
            onclick="window.location='?caregiver_id=<?= (int)$s['caregiver_id'] ?>'">
            <td><strong><?= htmlspecialchars($s['caregiver_name'] ?? '—') ?></strong></td>
            <td class="center"><code><?= htmlspecialchars($s['tch_id'] ?? '') ?></code></td>
            <td class="number">R<?= number_format((int)$s['total_advanced_cents'] / 100, 2) ?></td>
            <td class="number">R<?= number_format((int)$s['total_repaid_cents']   / 100, 2) ?></td>
            <td class="number" style="font-weight:700;color:<?= $balance > 0 ? '#dc3545' : ($balance < 0 ? '#198754' : '#6c757d') ?>">
                R<?= number_format($balance / 100, 2) ?>
            </td>
            <td class="center"><?= (int)$s['event_count'] ?></td>
            <td class="center" style="color:#6c757d;font-size:0.82rem;"><?= htmlspecialchars($s['last_event_date'] ?? '—') ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<!-- Per-caregiver event log -->
<?php if ($drillCaregiver): ?>
    <h3 style="margin-top:2rem;font-size:1rem;">
        Events — <?= htmlspecialchars($drillName ?? 'caregiver #' . $drillCaregiver) ?>
        <a href="<?= APP_URL ?>/admin/caregiver-loans" style="font-size:0.8rem;font-weight:400;margin-left:0.6rem;">(show all)</a>
    </h3>
    <?php if (empty($drillEvents)): ?>
        <p style="color:#6c757d;font-style:italic;">No events for this caregiver.</p>
    <?php else: ?>
    <table class="report-table tch-data-table">
        <thead><tr>
            <th class="center">Date</th>
            <th>Type</th>
            <th class="number">Amount</th>
            <th>Reason / notes</th>
            <th>Payroll ref</th>
            <th class="center" data-filterable="false">Logged by</th>
        </tr></thead>
        <tbody>
        <?php $running = 0; foreach (array_reverse($drillEvents) as $e):
            $amt = (int)$e['amount_cents'];
            $delta = $e['event_type'] === 'advance' ? $amt : -$amt;
            $running += $delta;
        endforeach; ?>
        <?php $runningDisplay = $running; foreach ($drillEvents as $e):
            $amt = (int)$e['amount_cents'];
            $isAdv = $e['event_type'] === 'advance';
            $label = $isAdv ? 'Advance' : 'Repayment';
            $colour = $isAdv ? '#dc3545' : '#198754';
        ?>
            <tr>
                <td class="center" style="color:#6c757d;"><?= htmlspecialchars($e['event_date']) ?></td>
                <td>
                    <span style="color:<?= $colour ?>;font-weight:600;"><?= $label ?></span>
                </td>
                <td class="number" style="color:<?= $colour ?>;font-weight:600;">
                    <?= $isAdv ? '+' : '−' ?>R<?= number_format($amt / 100, 2) ?>
                </td>
                <td>
                    <?= htmlspecialchars((string)($e['reason'] ?? '')) ?>
                    <?php if (!empty($e['notes'])): ?>
                        <br><span style="font-size:0.78rem;color:#6c757d;"><?= htmlspecialchars($e['notes']) ?></span>
                    <?php endif; ?>
                </td>
                <td style="color:#6c757d;"><?= htmlspecialchars((string)($e['payroll_run_month'] ?? '')) ?: '—' ?></td>
                <td class="center" style="color:#6c757d;font-size:0.82rem;">
                    <?= htmlspecialchars((string)($e['created_by_name'] ?? '—')) ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
<?php endif; ?>

</div>
<div>

<!-- Record-event form -->
<?php if ($canCreate): ?>
<div style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:1rem 1.2rem;">
    <h3 style="margin-top:0;font-size:1rem;">Record an event</h3>
    <form method="post">
        <?= csrfField() ?>

        <label style="display:block;margin-bottom:0.6rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;">Caregiver <span style="color:#dc3545;">*</span></span>
            <select name="caregiver_id" required style="width:100%;padding:0.35rem 0.5rem;">
                <option value="">Pick…</option>
                <?php foreach ($caregivers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $drillCaregiver === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['full_name']) ?>
                        <?= $c['tch_id'] ? '(' . htmlspecialchars($c['tch_id']) . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label style="display:block;margin-bottom:0.6rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;">Event type <span style="color:#dc3545;">*</span></span>
            <select name="event_type" required style="width:100%;padding:0.35rem 0.5rem;">
                <option value="advance">Advance (money out to caregiver)</option>
                <option value="repayment">Repayment (deducted from pay)</option>
            </select>
        </label>

        <label style="display:block;margin-bottom:0.6rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;">Amount (R) <span style="color:#dc3545;">*</span></span>
            <input type="number" step="0.01" min="0.01" name="amount_rand" required
                   style="width:100%;padding:0.35rem 0.5rem;"
                   placeholder="e.g. 1500.00">
        </label>

        <label style="display:block;margin-bottom:0.6rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;">Event date <span style="color:#dc3545;">*</span></span>
            <input type="date" name="event_date" required
                   value="<?= htmlspecialchars(date('Y-m-d')) ?>"
                   style="width:100%;padding:0.35rem 0.5rem;">
        </label>

        <label style="display:block;margin-bottom:0.6rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;">Reason</span>
            <input type="text" name="reason" maxlength="255"
                   style="width:100%;padding:0.35rem 0.5rem;"
                   placeholder="e.g. emergency cash, Jan-26 payroll">
        </label>

        <label style="display:block;margin-bottom:0.6rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;">Payroll month (repayments only)</span>
            <input type="month" name="payroll_run_month"
                   style="width:100%;padding:0.35rem 0.5rem;">
        </label>

        <label style="display:block;margin-bottom:0.8rem;">
            <span style="display:block;font-size:0.82rem;color:#495057;">Notes</span>
            <textarea name="notes" rows="2" style="width:100%;padding:0.35rem 0.5rem;"></textarea>
        </label>

        <button type="submit" class="btn btn-primary" style="width:100%;">Record event</button>
    </form>
</div>
<?php endif; ?>

<div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:6px;padding:0.8rem 1rem;margin-top:1rem;font-size:0.82rem;color:#475569;">
    <strong>About the ledger</strong><br>
    Every advance and every repayment is a separate event with its
    own date. Running balance = advances − repayments. Oldest-advance-
    first repayment allocation is computed at report time, not stored.
    Events are audit-logged; edits and deletes are deliberately not
    supported — log a compensating event instead.
</div>

</div>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
