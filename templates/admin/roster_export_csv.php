<?php
/**
 * Roster CSV export — /admin/roster/export.csv
 * Same query as roster_view.php; flat row-per-shift CSV.
 */
$db = getDB();

$monthStr = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthStr)) $monthStr = date('Y-m');
$monthStart = $monthStr . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$filterCaregiver = (int)($_GET['caregiver'] ?? 0);
$filterPatient   = trim((string)($_GET['patient'] ?? ''));
$filterCohort    = trim((string)($_GET['cohort']  ?? ''));

$sql = "SELECT r.roster_date, r.units, r.cost_rate, r.bill_rate, r.source_cell, r.status,
               p_cg.full_name AS caregiver, p_cg.tch_id AS cg_tch_id,
               p_pt.full_name AS patient,  p_pt.tch_id AS pt_tch_id,
               p_cl.full_name AS client,   p_cl.tch_id AS cl_tch_id
        FROM daily_roster r
   LEFT JOIN persons p_pt ON p_pt.id = r.patient_person_id
   LEFT JOIN persons p_cg ON p_cg.id = r.caregiver_id
   LEFT JOIN persons p_cl ON p_cl.id = r.client_id
   LEFT JOIN students s   ON s.person_id = r.caregiver_id
        WHERE r.roster_date >= ? AND r.roster_date <= ?
          AND r.status = 'delivered'";
$params = [$monthStart, $monthEnd];
if ($filterCaregiver) { $sql .= " AND r.caregiver_id = ?";   $params[] = $filterCaregiver; }
if ($filterPatient !== '') {
    $sql .= " AND (p_pt.full_name LIKE ? OR p_pt.tch_id LIKE ?)";
    $params[] = "%{$filterPatient}%"; $params[] = "%{$filterPatient}%";
}
if ($filterCohort !== '') { $sql .= " AND s.cohort = ?"; $params[] = $filterCohort; }
$sql .= " ORDER BY r.roster_date, p_pt.full_name";

$stmt = $db->prepare($sql);
$stmt->execute($params);

$filename = 'roster_' . $monthStr . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel recognises UTF-8
fputcsv($out, ['Date','Day','Patient','Patient TCH ID','Caregiver','Caregiver TCH ID','Client','Client TCH ID','Units','Cost (R)','Bill (R)','Status','Source Cell']);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
        $r['roster_date'],
        date('D', strtotime($r['roster_date'])),
        $r['patient'] ?? '',
        $r['pt_tch_id'] ?? '',
        $r['caregiver'] ?? '',
        $r['cg_tch_id'] ?? '',
        $r['client'] ?? '',
        $r['cl_tch_id'] ?? '',
        $r['units'],
        number_format((float)$r['cost_rate'], 2, '.', ''),
        $r['bill_rate'] !== null ? number_format((float)$r['bill_rate'], 2, '.', '') : '',
        $r['status'],
        $r['source_cell'] ?? '',
    ]);
}
fclose($out);
exit;
