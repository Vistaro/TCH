<?php
$pageTitle = 'Student Tracking';
$activeNav = 'student-tracking';

$db = getDB();

$filterCohort = $_GET['cohort'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterReview = $_GET['review'] ?? '';

$where = [];
$params = [];
if ($filterCohort) { $where[] = 'se.cohort = ?'; $params[] = $filterCohort; }
if ($filterStatus) { $where[] = 'se.status = ?'; $params[] = $filterStatus; }
if ($filterReview === 'pending')  { $where[] = "s.import_review_state = 'pending'"; }
if ($filterReview === 'approved') { $where[] = "(s.import_review_state IS NULL OR s.import_review_state = 'approved')"; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT p.id, p.tch_id, p.full_name, p.known_as,
               se.id AS enrollment_id, se.cohort, se.enrolled_at, se.graduated_at,
               se.status AS enrollment_status,
               s.avg_score, s.practical_status, s.qualified, s.import_review_state,
               (SELECT COUNT(*) FROM training_attendance ta WHERE ta.enrollment_id = se.id) AS attendance_days,
               (SELECT COUNT(*) FROM student_scores ss WHERE ss.enrollment_id = se.id) AS score_count,
               (SELECT AVG(ss2.score) FROM student_scores ss2 WHERE ss2.enrollment_id = se.id) AS avg_module_score,
               (SELECT CASE WHEN EXISTS (
                         SELECT 1 FROM daily_roster dr
                         WHERE dr.caregiver_id = p.id AND dr.status = 'delivered'
                       ) OR EXISTS (
                         SELECT 1 FROM engagements e
                         WHERE e.caregiver_person_id = p.id AND e.status = 'active'
                       ) THEN 'Yes' ELSE 'No' END) AS is_placed
        FROM student_enrollments se
        JOIN students s ON s.person_id = se.student_person_id
        JOIN persons p ON p.id = se.student_person_id
        $whereSQL
        ORDER BY se.cohort, p.full_name";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$cohorts = $db->query("SELECT DISTINCT cohort FROM student_enrollments WHERE cohort IS NOT NULL ORDER BY cohort")->fetchAll(PDO::FETCH_COLUMN);

// Summary stats
$totalEnrolled = count($rows);
$graduated = count(array_filter($rows, fn($r) => $r['enrollment_status'] === 'graduated'));
$inTraining = count(array_filter($rows, fn($r) => in_array($r['enrollment_status'], ['enrolled','in_training','ojt'])));
$qualified = count(array_filter($rows, fn($r) => $r['enrollment_status'] === 'qualified'));
$placedAsCaregivers = count(array_filter($rows, fn($r) => $r['is_placed'] === 'Yes'));

require APP_ROOT . '/templates/layouts/admin.php';
?>

<?php if (userCan('student_view', 'create')): ?>
<div style="margin-bottom:1rem;text-align:right;">
    <a href="<?= APP_URL ?>/admin/students/new" class="btn btn-primary">+ Add Student</a>
</div>
<?php endif; ?>

<div class="dash-cards" style="margin-bottom:1.5rem;">
    <div class="dash-card accent"><div class="dash-card-label">Enrolled</div><div class="dash-card-value"><?= $totalEnrolled ?></div></div>
    <div class="dash-card accent"><div class="dash-card-label">In Training / OJT</div><div class="dash-card-value"><?= $inTraining ?></div></div>
    <div class="dash-card accent"><div class="dash-card-label">Qualified</div><div class="dash-card-value"><?= $qualified ?></div></div>
    <div class="dash-card accent"><div class="dash-card-label">Graduated</div><div class="dash-card-value"><?= $graduated ?></div></div>
    <div class="dash-card accent"><div class="dash-card-label">Placed as Caregiver</div><div class="dash-card-value"><?= $placedAsCaregivers ?></div></div>
</div>

<form method="GET" class="report-filters" style="margin-bottom:1rem;">
    <div style="display:flex;gap:0.75rem;align-items:end;">
        <div>
            <label>Cohort</label>
            <select name="cohort" onchange="this.form.submit()" class="form-control">
                <option value="">All Cohorts</option>
                <?php foreach ($cohorts as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $filterCohort === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Status</label>
            <select name="status" onchange="this.form.submit()" class="form-control">
                <option value="">All</option>
                <option value="enrolled" <?= $filterStatus === 'enrolled' ? 'selected' : '' ?>>Enrolled</option>
                <option value="in_training" <?= $filterStatus === 'in_training' ? 'selected' : '' ?>>In Training</option>
                <option value="ojt" <?= $filterStatus === 'ojt' ? 'selected' : '' ?>>OJT</option>
                <option value="qualified" <?= $filterStatus === 'qualified' ? 'selected' : '' ?>>Qualified</option>
                <option value="graduated" <?= $filterStatus === 'graduated' ? 'selected' : '' ?>>Graduated</option>
                <option value="dropped" <?= $filterStatus === 'dropped' ? 'selected' : '' ?>>Dropped</option>
            </select>
        </div>
        <div>
            <label>Approval</label>
            <select name="review" onchange="this.form.submit()" class="form-control">
                <option value="">All</option>
                <option value="pending"  <?= $filterReview === 'pending'  ? 'selected' : '' ?>>Pending Approval</option>
                <option value="approved" <?= $filterReview === 'approved' ? 'selected' : '' ?>>Approved</option>
            </select>
        </div>
    </div>
</form>

<?php
$totDays = 0; $countGrad = 0; $countPlaced = 0;
foreach ($rows as $r) {
    $totDays    += (int)$r['attendance_days'];
    $countGrad  += !empty($r['graduated_at']) ? 1 : 0;
    $countPlaced += $r['is_placed'] === 'Yes' ? 1 : 0;
}
?>
<div class="report-table-wrap">
<table class="report-table tch-data-table">
    <thead><tr>
        <th>TCH ID</th><th>Name</th><th>Cohort</th><th>Enrolled</th>
        <th>Status</th><th>Approval</th><th>Avg Score</th><th>Practical</th>
        <th>Attendance Days</th><th>Graduated</th><th>Placed?</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
    <tr>
        <td><code><?= htmlspecialchars($r['tch_id']) ?></code></td>
        <td><a href="<?= APP_URL ?>/admin/students/<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['full_name']) ?></a></td>
        <td><?= htmlspecialchars($r['cohort']) ?></td>
        <td><?= $r['enrolled_at'] ? htmlspecialchars($r['enrolled_at']) : '—' ?></td>
        <td><span class="badge badge-<?= in_array($r['enrollment_status'], ['graduated','qualified']) ? 'success' : ($r['enrollment_status'] === 'dropped' ? 'danger' : 'info') ?>"><?= ucfirst(str_replace('_',' ',$r['enrollment_status'])) ?></span></td>
        <td><?= $r['import_review_state'] === 'pending'
                ? '<span class="badge badge-warning">Pending</span>'
                : '<span style="color:#198754">✓</span>' ?></td>
        <td class="number"><?= $r['avg_score'] ? number_format((float)$r['avg_score'] * 100, 1) . '%' : ($r['avg_module_score'] ? number_format((float)$r['avg_module_score'], 1) . '%' : '—') ?></td>
        <td><?= htmlspecialchars($r['practical_status'] ?? '') ?: '—' ?></td>
        <td class="number"><?= (int)$r['attendance_days'] ?: '—' ?></td>
        <td><?= !empty($r['graduated_at'])
                ? '<span style="color:#198754;font-weight:600;">Yes</span>'
                : '<span style="color:#6c757d;">No</span>' ?></td>
        <td><?= $r['is_placed'] === 'Yes'
                ? '<span style="color:#198754;font-weight:600;">Yes</span>'
                : '<span style="color:#6c757d;">No</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="totals-row">
            <td colspan="8">Total — <?= count($rows) ?> student<?= count($rows) !== 1 ? 's' : '' ?></td>
            <td class="number"><?= number_format($totDays) ?></td>
            <td><?= $countGrad ?></td>
            <td><?= $countPlaced ?></td>
        </tr>
    </tfoot>
</table>
</div>

<?php require APP_ROOT . '/templates/layouts/admin_footer.php'; ?>
