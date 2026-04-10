<?php
/**
 * TCH Placements — Phase 1 Data Ingestion
 *
 * Reads both Excel workbooks and populates the database.
 * Run from project root: php database/seeds/ingest.php
 *
 * Requires: composer install (phpoffice/phpspreadsheet)
 *
 * Sources:
 *   - docs/TCH_Data_Workbook.xlsx   (cleaned, structured data)
 *   - docs/TCH_Payroll_Analysis_v5.xlsx (raw source with audit comments)
 *
 * NOTE (migration 003 — 10 April 2026):
 *   Schema changed: caregivers.source dropped, caregivers.status replaced with
 *   status_id FK → person_statuses. This script has been updated to match.
 *   Source values from the workbook are preserved in import_notes for audit.
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__, 2));

require APP_ROOT . '/vendor/autoload.php';
require APP_ROOT . '/includes/config.php';
require APP_ROOT . '/includes/db.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ── Helpers ─────────────────────────────────────────────────

/**
 * Convert "Nov 2025" or "Mar 2026" to a DATE string (first of month).
 */
function monthToDate(string $month): ?string
{
    $parsed = date_create_from_format('M Y', trim($month));
    if (!$parsed) {
        return null;
    }
    return $parsed->format('Y-m-01');
}

/**
 * Clean whitespace, non-breaking spaces, and trim a cell value.
 */
function clean(?string $val): ?string
{
    if ($val === null || $val === '') {
        return null;
    }
    $val = str_replace("\xc2\xa0", ' ', $val); // non-breaking space
    return trim($val);
}

/**
 * Parse a numeric string, stripping currency symbols and commas.
 */
function parseNum(?string $val): ?float
{
    if ($val === null || $val === '') {
        return null;
    }
    $val = str_replace(['R', ',', ' '], '', $val);
    return is_numeric($val) ? (float)$val : null;
}

/**
 * Parse a date value from Excel (could be datetime string or serial number).
 */
function parseDate($val): ?string
{
    if ($val === null || $val === '') {
        return null;
    }
    if ($val instanceof \DateTimeInterface) {
        return $val->format('Y-m-d');
    }
    $str = (string)$val;
    // Try YYYY-MM-DD HH:MM:SS format
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $str)) {
        return substr($str, 0, 10);
    }
    // Try Excel serial number
    if (is_numeric($str) && (int)$str > 40000) {
        $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$str);
        return $date->format('Y-m-d');
    }
    return null;
}

/**
 * Read a worksheet into an array of associative arrays using row 1 as headers.
 * If $headerRow is specified, use that row for headers instead.
 */
function sheetToArray(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws, int $headerRow = 1): array
{
    $rows = [];
    $headers = [];
    foreach ($ws->getRowIterator($headerRow, $headerRow) as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $val = clean((string)$cell->getValue());
            // Strip newlines from header names
            if ($val !== null) {
                $val = str_replace("\n", ' ', $val);
            }
            $headers[] = $val;
        }
    }

    foreach ($ws->getRowIterator($headerRow + 1) as $row) {
        $data = [];
        $colIdx = 0;
        $hasData = false;
        foreach ($row->getCellIterator() as $cell) {
            $key = $headers[$colIdx] ?? "col_$colIdx";
            $val = $cell->getValue();
            if ($val !== null && $val !== '') {
                $hasData = true;
            }
            $data[$key] = $val;
            $colIdx++;
        }
        if ($hasData) {
            $rows[] = $data;
        }
    }
    return $rows;
}

// ── Main ────────────────────────────────────────────────────

$db = getDB();

$dataFile = APP_ROOT . '/docs/TCH_Data_Workbook.xlsx';
$rawFile  = APP_ROOT . '/docs/TCH_Payroll_Analysis_v5.xlsx';

if (!file_exists($dataFile)) {
    die("Missing: $dataFile\n");
}
if (!file_exists($rawFile)) {
    die("Missing: $rawFile\n");
}

echo "Loading Data Workbook...\n";
$dataWb = IOFactory::load($dataFile);

echo "Loading Payroll Analysis v5 (with comments)...\n";
$rawWb = IOFactory::load($rawFile);

// ── 1. Ingest Clients ───────────────────────────────────────

echo "\n── Ingesting Clients ──\n";
$clientSheet = sheetToArray($dataWb->getSheetByName('Clients'));
$clientIdMap = []; // client_name => db id

// Also load the master client list from v5 for patient_name, day_rate, etc.
$v5Clients = sheetToArray($rawWb->getSheetByName('Clients'));
$v5ClientLookup = [];
foreach ($v5Clients as $v5c) {
    $name = clean((string)($v5c['Client Name '] ?? $v5c['Client Name'] ?? ''));
    if ($name) {
        $v5ClientLookup[mb_strtolower($name)] = $v5c;
    }
}

$clientStmt = $db->prepare(
    'INSERT INTO clients (account_number, client_name, patient_name, day_rate, billing_freq, shift_type, schedule, entity, first_seen, last_seen, months_active, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$seq = 1;
foreach ($clientSheet as $row) {
    $name = clean((string)($row['client_name'] ?? ''));
    if (!$name) {
        continue;
    }

    $accountNum = sprintf('TCH-C%04d', $seq);

    // Try to find enrichment data from v5 master client list
    $v5 = $v5ClientLookup[mb_strtolower($name)] ?? null;
    $patientName = $v5 ? clean((string)($v5['Patient Name '] ?? $v5['Patient Name'] ?? '')) : null;
    $dayRate     = $v5 ? parseNum((string)($v5['Day Rate'] ?? '')) : null;
    $billingFreq = $v5 ? clean((string)($v5['Monthly/ Weekly '] ?? $v5['Monthly/ Weekly'] ?? '')) : null;
    $shiftType   = $v5 ? clean((string)($v5['Day Shift/ Live In '] ?? $v5['Day Shift/ Live In'] ?? '')) : null;
    $schedule    = $v5 ? clean((string)($v5['Full Time/ '] ?? $v5['Full Time/'] ?? '')) : null;
    $entity      = $v5 ? clean((string)($v5['NPC/TCH'] ?? '')) : null;

    $firstSeen = monthToDate((string)($row['first_seen'] ?? ''));
    $lastSeen  = monthToDate((string)($row['last_seen'] ?? ''));
    $monthsActive = (int)($row['months_active'] ?? 0);
    $status = clean((string)($row['status'] ?? 'Active'));

    $clientStmt->execute([
        $accountNum, $name, $patientName, $dayRate, $billingFreq,
        $shiftType, $schedule, $entity, $firstSeen, $lastSeen,
        $monthsActive, $status
    ]);

    $clientIdMap[$name] = (int)$db->lastInsertId();
    $seq++;
}
echo "  Inserted " . count($clientIdMap) . " clients\n";

// ── 2. Ingest Caregivers ────────────────────────────────────

echo "\n── Ingesting Caregivers ──\n";
$cgSheet = sheetToArray($dataWb->getSheetByName('Caregivers'));
$cgIdMap = []; // full_name => db id

// Build status code → id map for the legacy ENUM values we still write here.
$statusMap = [];
foreach ($db->query("SELECT id, code FROM person_statuses") as $r) {
    $statusMap[$r['code']] = (int)$r['id'];
}

$cgStmt = $db->prepare(
    'INSERT INTO caregivers (full_name, student_id, known_as, tranche, gender, dob, nationality,
     id_passport, home_language, other_language, mobile, email, street_address, suburb, city, province,
     postal_code, nok_name, nok_relationship, nok_contact, course_start, available_from, avg_score,
     qualified, total_billed, status_id, import_notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($cgSheet as $row) {
    $fullName = clean((string)($row['full_name'] ?? ''));
    if (!$fullName) {
        continue;
    }

    $gender = clean((string)($row['gender'] ?? ''));
    if ($gender && !in_array($gender, ['Male', 'Female', 'Other'])) {
        $gender = null;
    }

    // Map legacy ENUM-style status string ("In Training" etc.) to the lookup code.
    $statusRaw  = clean((string)($row['status'] ?? 'In Training'));
    $statusCode = strtolower(str_replace(' ', '_', $statusRaw));
    if (!isset($statusMap[$statusCode])) {
        $statusCode = 'in_training';
    }
    $statusId = $statusMap[$statusCode];

    // The legacy `source` column has been dropped. Preserve any value from the
    // workbook in import_notes so the original data is not lost.
    $sourceVal = clean((string)($row['source'] ?? ''));
    $importNotes = $sourceVal !== ''
        ? "Legacy `source` value from workbook: {$sourceVal}"
        : null;

    $cgStmt->execute([
        $fullName,
        clean((string)($row['student_id'] ?? '')),
        clean((string)($row['known_as'] ?? '')),
        clean((string)($row['tranche'] ?? '')),
        $gender,
        parseDate($row['dob'] ?? null),
        clean((string)($row['nationality'] ?? '')),
        clean((string)($row['id_passport'] ?? '')),
        clean((string)($row['home_language'] ?? '')),
        clean((string)($row['other_language'] ?? '')),
        clean((string)($row['mobile'] ?? '')),
        clean((string)($row['email'] ?? '')),
        clean((string)($row['street_address'] ?? '')),
        clean((string)($row['suburb'] ?? '')),
        clean((string)($row['city'] ?? '')),
        clean((string)($row['province'] ?? '')),
        clean((string)($row['postal_code'] ?? '')),
        clean((string)($row['nok_name'] ?? '')),
        clean((string)($row['nok_relationship'] ?? '')),
        clean((string)($row['nok_contact'] ?? '')),
        parseDate($row['course_start'] ?? null),
        parseDate($row['available_from'] ?? null),
        parseNum((string)($row['avg_score'] ?? '')),
        clean((string)($row['qualified'] ?? '')),
        parseNum((string)($row['total_billed'] ?? '0')),
        $statusId,
        $importNotes
    ]);

    $cgIdMap[$fullName] = (int)$db->lastInsertId();
}
echo "  Inserted " . count($cgIdMap) . " caregivers\n";

// ── 3. Ingest Caregiver Banking Details (from v5) ──────────

echo "\n── Ingesting Banking Details ──\n";
$bankSheet = $rawWb->getSheetByName('Caregiver Banking Details ');
$bankCount = 0;

$bankStmt = $db->prepare(
    'INSERT INTO caregiver_banking (caregiver_id, bank_name, account_number, account_type, rate_note)
     VALUES (?, ?, ?, ?, ?)'
);

for ($r = 2; $r <= $bankSheet->getHighestRow(); $r++) {
    $name = clean((string)$bankSheet->getCell("A$r")->getValue());
    if (!$name) {
        continue;
    }

    $bankName = clean((string)$bankSheet->getCell("B$r")->getValue());
    $accNum   = clean((string)$bankSheet->getCell("C$r")->getValue());
    $accType  = clean((string)$bankSheet->getCell("D$r")->getValue());
    $rateNote = clean((string)$bankSheet->getCell("E$r")->getValue());

    if (!$bankName || !$accNum) {
        continue;
    }

    // Try to match caregiver by name
    $cgId = $cgIdMap[$name] ?? null;
    if (!$cgId) {
        // Try case-insensitive partial match
        foreach ($cgIdMap as $cgName => $id) {
            if (mb_strtolower($cgName) === mb_strtolower($name)) {
                $cgId = $id;
                break;
            }
        }
    }

    if (!$cgId) {
        echo "  WARNING: No caregiver match for banking record: $name\n";
        continue;
    }

    $bankStmt->execute([$cgId, $bankName, $accNum, $accType, $rateNote]);
    $bankCount++;
}
echo "  Inserted $bankCount banking records\n";

// ── 4. Ingest Name Lookup ───────────────────────────────────

echo "\n── Ingesting Name Lookup ──\n";
$nlSheet = sheetToArray($dataWb->getSheetByName('Name Lookup'));
$nlCount = 0;

$nlStmt = $db->prepare(
    'INSERT INTO name_lookup (caregiver_id, canonical_name, pdf_name, training_name, billing_name,
     tranche, source, pdf_match_score, billing_match_score, approved, approved_by, notes)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($nlSheet as $row) {
    // Headers have newlines in them
    $canonical = clean((string)($row['Common Name (Canonical)'] ?? ''));
    if (!$canonical) {
        continue;
    }

    $pdfName      = clean((string)($row['PDF Name (Legal)'] ?? ''));
    $trainingName = clean((string)($row['Training Name (Intake Sheet)'] ?? ''));
    $billingName  = clean((string)($row['Billing Name (Payroll)'] ?? ''));
    $tranche      = clean((string)($row['Tranche'] ?? ''));
    $source       = clean((string)($row['Source'] ?? ''));
    $pdfScore     = parseNum((string)($row['PDF Match Score'] ?? $row['PDF MatchScore'] ?? ''));
    $billScore    = parseNum((string)($row['Billing Match Score'] ?? $row['Billing MatchScore'] ?? ''));
    $approved     = mb_strtolower(clean((string)($row['Approved'] ?? '')) ?? '') === 'yes' ? 1 : 0;
    $approvedBy   = clean((string)($row['Approved By'] ?? ''));
    $notes        = clean((string)($row['Notes'] ?? ''));

    // Link to caregiver record
    $cgId = $cgIdMap[$canonical] ?? null;

    $nlStmt->execute([
        $cgId, $canonical, $pdfName, $trainingName, $billingName,
        $tranche, $source, $pdfScore, $billScore, $approved, $approvedBy, $notes
    ]);
    $nlCount++;
}
echo "  Inserted $nlCount name lookup records\n";

// ── 5. Ingest Client Revenue ────────────────────────────────

echo "\n── Ingesting Client Revenue ──\n";
$crSheet = sheetToArray($dataWb->getSheetByName('Client Revenue'));
$crCount = 0;
$crIdMap = []; // sequential index => db id, for audit trail linking

$crStmt = $db->prepare(
    'INSERT INTO client_revenue (client_id, client_name, month, month_date, income, expense, margin, margin_pct, source_sheet)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($crSheet as $row) {
    $name = clean((string)($row['client_name'] ?? ''));
    if (!$name) {
        continue;
    }

    $month = clean((string)($row['month'] ?? ''));
    $monthDate = monthToDate($month ?? '');

    // Try to match client
    $clientId = $clientIdMap[$name] ?? null;
    if (!$clientId) {
        // Try stripping "- monthly" suffix
        $baseName = preg_replace('/[-\s]*monthly$/i', '', $name);
        $clientId = $clientIdMap[trim($baseName)] ?? null;
    }

    $income  = parseNum((string)($row['income'] ?? '0'));
    $expense = parseNum((string)($row['expense'] ?? '0'));
    $margin  = parseNum((string)($row['margin'] ?? ''));
    $marginPct = parseNum((string)($row['margin_pct'] ?? ''));

    $crStmt->execute([
        $clientId, $name, $month, $monthDate,
        $income ?? 0, $expense ?? 0, $margin, $marginPct,
        clean((string)($row['source_sheet'] ?? ''))
    ]);

    $crIdMap[$crCount] = (int)$db->lastInsertId();
    $crCount++;
}
echo "  Inserted $crCount client revenue records\n";

// ── 6. Ingest Caregiver Costs ───────────────────────────────

echo "\n── Ingesting Caregiver Costs ──\n";
$ccSheet = sheetToArray($dataWb->getSheetByName('Caregiver Cost'));
$ccCount = 0;
$ccIdMap = [];

$ccStmt = $db->prepare(
    'INSERT INTO caregiver_costs (caregiver_id, caregiver_name, month, month_date, amount, days_worked, daily_rate, source_sheet)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

// Build a billing-name-to-caregiver-id map from name_lookup
$billingToId = [];
$nlRows = $db->query('SELECT caregiver_id, billing_name FROM name_lookup WHERE billing_name IS NOT NULL AND billing_name != ""')->fetchAll();
foreach ($nlRows as $nlr) {
    if ($nlr['caregiver_id']) {
        $billingToId[mb_strtolower($nlr['billing_name'])] = (int)$nlr['caregiver_id'];
    }
}

foreach ($ccSheet as $row) {
    $name = clean((string)($row['caregiver_name'] ?? ''));
    if (!$name) {
        continue;
    }

    $month = clean((string)($row['month'] ?? ''));
    $monthDate = monthToDate($month ?? '');

    // Try to match caregiver: direct name match, then billing name lookup
    $cgId = $cgIdMap[$name] ?? null;
    if (!$cgId) {
        $cgId = $billingToId[mb_strtolower($name)] ?? null;
    }
    if (!$cgId) {
        foreach ($cgIdMap as $cgName => $id) {
            if (mb_strtolower($cgName) === mb_strtolower($name)) {
                $cgId = $id;
                break;
            }
        }
    }

    $ccStmt->execute([
        $cgId, $name, $month, $monthDate,
        parseNum((string)($row['amount'] ?? '0')) ?? 0,
        $row['days_worked'] !== null && $row['days_worked'] !== '' ? (int)$row['days_worked'] : null,
        parseNum((string)($row['daily_rate'] ?? '')),
        clean((string)($row['source_sheet'] ?? ''))
    ]);

    $ccIdMap[$ccCount] = (int)$db->lastInsertId();
    $ccCount++;
}
echo "  Inserted $ccCount caregiver cost records\n";

// ── 7. Ingest Daily Roster ──────────────────────────────────

echo "\n── Ingesting Daily Roster ──\n";
$drSheet = sheetToArray($dataWb->getSheetByName('Daily Roster'));
$drCount = 0;

$drStmt = $db->prepare(
    'INSERT INTO daily_roster (caregiver_id, client_id, roster_date, day_of_week, caregiver_name, client_assigned, daily_rate, source_sheet)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

foreach ($drSheet as $row) {
    $cgName = clean((string)($row['caregiver_name'] ?? ''));
    $clientName = clean((string)($row['client_assigned'] ?? ''));
    if (!$cgName || !$clientName) {
        continue;
    }

    $rosterDate = parseDate($row['date'] ?? null);
    if (!$rosterDate) {
        continue;
    }

    // Match caregiver
    $cgId = $cgIdMap[$cgName] ?? null;
    if (!$cgId) {
        $cgId = $billingToId[mb_strtolower($cgName)] ?? null;
    }

    // Match client
    $clId = $clientIdMap[$clientName] ?? null;

    $drStmt->execute([
        $cgId, $clId, $rosterDate,
        clean((string)($row['day_of_week'] ?? '')),
        $cgName, $clientName,
        parseNum((string)($row['daily_rate'] ?? '')),
        clean((string)($row['source_sheet'] ?? ''))
    ]);
    $drCount++;
}
echo "  Inserted $drCount daily roster records\n";

// ── 8. Extract Audit Trail Comments ─────────────────────────

echo "\n── Extracting Audit Trail ──\n";
$atCount = 0;

$atStmt = $db->prepare(
    'INSERT INTO audit_trail (record_type, record_id, summary_sheet, summary_cell, source_sheet, source_location)
     VALUES (?, ?, ?, ?, ?, ?)'
);

// Client Summary comments → linked to client_revenue records
$clientSummaryWs = $rawWb->getSheetByName('Client Summary');
// Row 3 has month headers (Nov 2025, Dec 2025, ...) in columns B-G
// Row 4 onwards has client names in column A
// Comments on cells B4:G{last} link to client revenue records

$csMonths = [];
for ($c = 2; $c <= 7; $c++) { // B=2 through G=7
    $csMonths[$c] = clean((string)$clientSummaryWs->getCell([$c, 3])->getValue());
}

for ($r = 4; $r <= $clientSummaryWs->getHighestRow(); $r++) {
    $clientName = clean((string)$clientSummaryWs->getCell("A$r")->getValue());
    if (!$clientName) {
        continue;
    }

    for ($c = 2; $c <= 7; $c++) {
        $cell = $clientSummaryWs->getCell([$c, $r]);
        $cellRef = $cell->getCoordinate();
        $comment = $clientSummaryWs->getComment($cellRef);
        if ($comment && $comment->getText()->getPlainText()) {
            $commentText = trim($comment->getText()->getPlainText());
            $month = $csMonths[$c] ?? '';

            // Extract source sheet from comment
            $sourceSheet = '';
            if (preg_match("/Sheet:\s*'([^']+)'/", $commentText, $m)) {
                $sourceSheet = $m[1];
            }

            // Find matching client_revenue record
            $matchStmt = $db->prepare(
                'SELECT id FROM client_revenue WHERE client_name = ? AND month = ? LIMIT 1'
            );
            $matchStmt->execute([$clientName, $month]);
            $matchRow = $matchStmt->fetch();

            // Also try the "Source:" name from the comment (may differ from summary row name)
            if (!$matchRow && preg_match('/Source:\s*(.+)$/m', $commentText, $sm)) {
                $sourceName = trim($sm[1]);
                $matchStmt->execute([$sourceName, $month]);
                $matchRow = $matchStmt->fetch();
            }

            if ($matchRow) {
                $atStmt->execute([
                    'client_revenue', $matchRow['id'],
                    'Client Summary', $cellRef,
                    $sourceSheet, $commentText
                ]);
                $atCount++;
            }
        }
    }
}

// Caregiver Summary comments → linked to caregiver_costs records
$cgSummaryWs = $rawWb->getSheetByName('Caregiver Summary');

$cgMonths = [];
for ($c = 2; $c <= 7; $c++) {
    $cgMonths[$c] = clean((string)$cgSummaryWs->getCell([$c, 3])->getValue());
}

for ($r = 4; $r <= $cgSummaryWs->getHighestRow(); $r++) {
    $cgName = clean((string)$cgSummaryWs->getCell("A$r")->getValue());
    if (!$cgName) {
        continue;
    }

    for ($c = 2; $c <= 7; $c++) {
        $cell = $cgSummaryWs->getCell([$c, $r]);
        $cellRef = $cell->getCoordinate();
        $comment = $cgSummaryWs->getComment($cellRef);
        if ($comment && $comment->getText()->getPlainText()) {
            $commentText = trim($comment->getText()->getPlainText());
            $month = $cgMonths[$c] ?? '';

            $sourceSheet = '';
            if (preg_match("/Source:\s*'([^']+)'/", $commentText, $m)) {
                $sourceSheet = $m[1];
            }

            // Find matching caregiver_costs record
            $matchStmt = $db->prepare(
                'SELECT id FROM caregiver_costs WHERE caregiver_name = ? AND month = ? LIMIT 1'
            );
            $matchStmt->execute([$cgName, $month]);
            $matchRow = $matchStmt->fetch();

            if ($matchRow) {
                $atStmt->execute([
                    'caregiver_cost', $matchRow['id'],
                    'Caregiver Summary', $cellRef,
                    $sourceSheet, $commentText
                ]);
                $atCount++;
            }
        }
    }
}
echo "  Inserted $atCount audit trail records\n";

// ── 9. Compute and Insert Margin Summary ────────────────────

echo "\n── Computing Margin Summary ──\n";
$marginStmt = $db->prepare(
    'INSERT INTO margin_summary (month, month_date, total_revenue, total_cost, gross_margin, gross_margin_pct)
     VALUES (?, ?, ?, ?, ?, ?)'
);

$months = $db->query(
    'SELECT DISTINCT month, month_date FROM client_revenue WHERE month_date IS NOT NULL ORDER BY month_date'
)->fetchAll();

foreach ($months as $m) {
    $rev = $db->prepare('SELECT COALESCE(SUM(income), 0) AS total FROM client_revenue WHERE month_date = ?');
    $rev->execute([$m['month_date']]);
    $totalRev = (float)$rev->fetch()['total'];

    $cost = $db->prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM caregiver_costs WHERE month_date = ?');
    $cost->execute([$m['month_date']]);
    $totalCost = (float)$cost->fetch()['total'];

    $grossMargin = $totalRev - $totalCost;
    $marginPct = $totalRev > 0 ? round(($grossMargin / $totalRev) * 100, 2) : null;

    $marginStmt->execute([
        $m['month'], $m['month_date'], $totalRev, $totalCost, $grossMargin, $marginPct
    ]);
}
echo "  Computed margins for " . count($months) . " months\n";

// ── 10. Build Rate History from Daily Roster ────────────────

echo "\n── Building Rate History ──\n";
$rateCount = 0;

$rateStmt = $db->prepare(
    'INSERT INTO caregiver_rate_history (caregiver_id, daily_rate, effective_from, source)
     VALUES (?, ?, ?, ?)'
);

// Get distinct rates per caregiver from roster data
$rateQuery = $db->query(
    'SELECT caregiver_id, daily_rate, MIN(roster_date) AS first_seen, source_sheet
     FROM daily_roster
     WHERE caregiver_id IS NOT NULL AND daily_rate IS NOT NULL
     GROUP BY caregiver_id, daily_rate
     ORDER BY caregiver_id, first_seen'
);

$prevCg = null;
$prevRateId = null;

foreach ($rateQuery->fetchAll() as $rr) {
    // If same caregiver has a new rate, close the previous one
    if ($prevCg === $rr['caregiver_id'] && $prevRateId) {
        $db->prepare('UPDATE caregiver_rate_history SET effective_to = ? WHERE id = ?')
           ->execute([$rr['first_seen'], $prevRateId]);
    }

    $rateStmt->execute([
        $rr['caregiver_id'], $rr['daily_rate'], $rr['first_seen'],
        'Daily Roster: ' . $rr['source_sheet']
    ]);
    $prevRateId = (int)$db->lastInsertId();
    $prevCg = $rr['caregiver_id'];
    $rateCount++;

    // Also update the caregiver's standard_daily_rate to the most recent rate
    $db->prepare('UPDATE caregivers SET standard_daily_rate = ? WHERE id = ?')
       ->execute([$rr['daily_rate'], $rr['caregiver_id']]);
}
echo "  Inserted $rateCount rate history records\n";

// ── Summary ─────────────────────────────────────────────────

echo "\n══════════════════════════════════════════\n";
echo "  INGESTION COMPLETE\n";
echo "══════════════════════════════════════════\n";
echo "  Clients:          " . count($clientIdMap) . "\n";
echo "  Caregivers:       " . count($cgIdMap) . "\n";
echo "  Banking records:  $bankCount\n";
echo "  Name lookups:     $nlCount\n";
echo "  Client revenue:   $crCount\n";
echo "  Caregiver costs:  $ccCount\n";
echo "  Daily roster:     $drCount\n";
echo "  Audit trail:      $atCount\n";
echo "  Rate history:     $rateCount\n";
echo "  Margin summaries: " . count($months) . "\n";
echo "══════════════════════════════════════════\n";

// Report unmatched records
echo "\n── Matching Report ──\n";

$unmatchedCR = $db->query('SELECT COUNT(*) FROM client_revenue WHERE client_id IS NULL')->fetchColumn();
$unmatchedCC = $db->query('SELECT COUNT(*) FROM caregiver_costs WHERE caregiver_id IS NULL')->fetchColumn();
$unmatchedDR_cg = $db->query('SELECT COUNT(*) FROM daily_roster WHERE caregiver_id IS NULL')->fetchColumn();
$unmatchedDR_cl = $db->query('SELECT COUNT(*) FROM daily_roster WHERE client_id IS NULL')->fetchColumn();
$unmatchedNL = $db->query('SELECT COUNT(*) FROM name_lookup WHERE caregiver_id IS NULL')->fetchColumn();
$pendingApproval = $db->query('SELECT COUNT(*) FROM name_lookup WHERE approved = 0')->fetchColumn();

echo "  Client revenue without client_id:  $unmatchedCR\n";
echo "  Caregiver costs without cg_id:     $unmatchedCC\n";
echo "  Roster shifts without cg_id:       $unmatchedDR_cg\n";
echo "  Roster shifts without client_id:   $unmatchedDR_cl\n";
echo "  Name lookups without caregiver_id: $unmatchedNL\n";
echo "  Name lookups pending approval:     $pendingApproval\n";
echo "\nDone.\n";
