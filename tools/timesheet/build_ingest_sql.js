/**
 * Build the full D2 roster rebuild as one big SQL transaction.
 * Strategy:
 *   1. Compute file sha256 + create timesheet_uploads rows (2 rows: one per workbook)
 *   2. Parse Timesheet → per-cell shift rows (cost side)
 *   3. Parse Panel → per-income-row bill events (bill side per client-month)
 *   4. Wipe existing daily_roster + engagements (dev only, no customer data)
 *   5. For each Timesheet cell: INSERT daily_roster row with cost_rate resolved
 *      - caregiver_id via alias
 *      - patient_person_id via alias
 *      - client_id via patient's current client_id (patients.client_id)
 *      - units from half-day / split parsing
 *      - cost_rate: override > caregiver's seen rate this month > last-seen rate from any month > average
 *      - bill_rate: filled in pass 2
 *   6. Pass 2: for each client-month sum of Panel invoices ÷ shift count = bill_rate per shift
 *   7. Remaining rounding pennies → last shift of the month for that client
 *
 *  Produces:
 *    - C:/tmp/ingest_roster.sql (run server-side)
 *    - C:/tmp/ingest_report.json (dry-run numbers)
 */
const XLSX = require('xlsx');
const crypto = require('crypto');
const fs = require('fs');

const TS_FILE   = 'C:/tmp/timesheet.xlsx';
const PAN_FILE  = 'C:/tmp/revenue.xlsx';
const TS_NAME   = 'Tuniti Caregiver Timesheets Apr-26.xlsx';
const PAN_NAME  = 'Tuniti Revenue to Clients Apr-26.xlsx';

function sha256(path) {
  return crypto.createHash('sha256').update(fs.readFileSync(path)).digest('hex');
}

function esc(s) { return String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'"); }

function serialToISO(serial) {
  // Excel serial: days since 1900-01-01 (with the leap-year bug)
  const epoch = new Date(Date.UTC(1899, 11, 30)); // 30 Dec 1899
  const d = new Date(epoch.getTime() + Number(serial) * 86400000);
  return d.toISOString().slice(0, 10);
}

function parseShiftCell(raw) {
  let s = String(raw).trim();
  if (!s || /^\d+(\.\d+)?$/.test(s)) return [];
  if (s.includes('/')) {
    return s.split('/').flatMap(p => parseShiftCell(p)).map(x => ({ ...x, units: x.units * 0.5 }));
  }
  let units = 1;
  if (/-\s*half\s*$/i.test(s)) { units = 0.5; s = s.replace(/\s*-\s*half\s*$/i, '').trim(); }
  let override = null;
  const m = s.match(/-\s*R?\s*(\d+(?:\.\d+)?)\s*$/i);
  if (m) { override = parseFloat(m[1]); s = s.replace(/\s*-\s*R?\s*\d+(?:\.\d+)?\s*$/i, '').trim(); }
  s = s.replace(/\s*-\s*Invoice\s.+$/i, '').trim();
  return [{ patientAlias: s, units, rateOverride: override }];
}

// ─── 1. Parse Timesheet ──────────────────────────────────────────
const tswb = XLSX.readFile(TS_FILE);
const tsSha = sha256(TS_FILE);
const shifts = []; // { tab, cell, date, caregiverAlias, patientAlias, units, rateOverride }
const caregiverRates = {}; // name -> [rates seen across months]
const monthTabs = new Map(); // caregiverAlias -> month -> rate

for (const tab of tswb.SheetNames) {
  const sh = tswb.Sheets[tab]; if (!sh['!ref']) continue;
  const range = XLSX.utils.decode_range(sh['!ref']);

  let totalRow = null;
  for (let r = 0; r < Math.min(range.e.r + 1, 60); r++) {
    const a = sh[XLSX.utils.encode_cell({ r, c: 0 })];
    if (a && /^total amount/i.test(String(a.v).trim())) { totalRow = r; break; }
  }
  if (!totalRow) continue;

  // Headers + rates
  const cgCols = [];
  for (let c = 2; c <= range.e.c; c++) {
    const h = sh[XLSX.utils.encode_cell({ r: 0, c })];
    if (!h || !String(h.v).trim()) continue;
    const cg = String(h.v).trim();
    const rateCell = sh[XLSX.utils.encode_cell({ r: 1, c })];
    const rate = rateCell && typeof rateCell.v === 'number' ? rateCell.v : null;
    cgCols.push({ col: c, caregiver: cg, rate });
    if (rate !== null) {
      (caregiverRates[cg] = caregiverRates[cg] || []).push(rate);
    }
  }

  // Shift cells
  for (let r = 3; r < totalRow; r++) {
    const dateCell = sh[XLSX.utils.encode_cell({ r, c: 0 })];
    if (!dateCell || !dateCell.v) continue;
    const dateSerial = Number(dateCell.v);
    if (isNaN(dateSerial) || dateSerial < 40000) continue;
    const isoDate = serialToISO(dateSerial);

    for (const { col, caregiver, rate: monthRate } of cgCols) {
      const addr = XLSX.utils.encode_cell({ r, c: col });
      const cell = sh[addr]; if (!cell) continue;
      const parsed = parseShiftCell(cell.v);
      for (const p of parsed) {
        shifts.push({
          tab, cell: addr, date: isoDate,
          caregiverAlias: caregiver,
          patientAlias: p.patientAlias,
          units: p.units,
          rateOverride: p.rateOverride,
          monthRate, // null when the row-2 rate is blank
        });
      }
    }
  }
}

// Resolve caregiver rates for blanks:
//   1st choice: the caregiver's rate elsewhere in the workbook (any month)
//   2nd choice: overall median of non-null rates
const allRates = Object.values(caregiverRates).flat();
const sorted = [...allRates].sort((a,b) => a-b);
const overallAvg = Math.round(sorted.reduce((s,x)=>s+x,0) / sorted.length);

function resolveRate(cg) {
  const list = caregiverRates[cg] || [];
  if (list.length) {
    // Most-recent-month rate: use the last observed non-null value
    return { rate: list[list.length - 1], source: 'history' };
  }
  return { rate: overallAvg, source: 'average' };
}

// ─── 2. Parse Panel ──────────────────────────────────────────────
const panwb = XLSX.readFile(PAN_FILE);
const panSha = sha256(PAN_FILE);
const invoiceEvents = []; // { tab, cell, clientAlias, date, amount, note }

for (const tab of panwb.SheetNames) {
  const sh = panwb.Sheets[tab]; if (!sh['!ref']) continue;
  const range = XLSX.utils.decode_range(sh['!ref']);

  // Find all panel headers (cell text + cell directly below is "Income")
  for (let r = 0; r <= range.e.r; r++) {
    for (let c = 0; c <= range.e.c; c++) {
      const addr = XLSX.utils.encode_cell({ r, c });
      const cell = sh[addr]; if (!cell) continue;
      const raw = String(cell.v).trim();
      if (!raw) continue;
      const below = sh[XLSX.utils.encode_cell({ r: r + 1, c })];
      if (!below || String(below.v).trim().toLowerCase() !== 'income') continue;

      // Strip suffix
      let canonClient = raw;
      const mDash  = raw.match(/^(.*?)\s*-\s*(monthly|weekly|daily|per[-\s]?visit|fortnightly|invoice.*)\s*$/i);
      const mBrack = raw.match(/^(.*?)\s*\[\s*(monthly|weekly|daily|per[-\s]?visit|fortnightly)\s*\]\s*$/i);
      if (mBrack) canonClient = mBrack[1].trim();
      else if (mDash) canonClient = mDash[1].trim();

      // Scan income rows: start at r+3 (date header is r+2) and read until "Expenses" label
      for (let rr = r + 3; rr <= range.e.r; rr++) {
        const rowA = sh[XLSX.utils.encode_cell({ r: rr, c })];
        const rowB = sh[XLSX.utils.encode_cell({ r: rr, c: c + 1 })];
        const rowC = sh[XLSX.utils.encode_cell({ r: rr, c: c + 2 })];
        // Stop at "Expenses" label in col A
        if (rowA && /^expenses/i.test(String(rowA.v).trim())) break;
        // Also stop if we hit another client header (blank-then-name pattern is unreliable; use Expenses instead)
        if (!rowA && !rowC) continue; // skip blank rows
        const dateSerial = rowA ? Number(rowA.v) : null;
        if (!dateSerial || isNaN(dateSerial) || dateSerial < 40000) continue;
        const amount = rowC && typeof rowC.v === 'number' ? rowC.v : null;
        if (amount === null || amount === 0) continue;
        const note = rowB ? String(rowB.v).trim() : '';
        invoiceEvents.push({
          tab, cell: XLSX.utils.encode_cell({ r: rr, c }),
          clientAlias: canonClient,
          date: serialToISO(dateSerial),
          amount, note,
        });
      }
    }
  }
}

// ─── 3. Write summary JSON and build SQL ────────────────────────
const report = {
  timesheet: {
    sha256: tsSha,
    tabs: tswb.SheetNames,
    shifts_total: shifts.length,
    caregivers_with_rates: Object.keys(caregiverRates).length,
    overall_average_rate: overallAvg,
  },
  panel: {
    sha256: panSha,
    tabs: panwb.SheetNames,
    invoice_events: invoiceEvents.length,
    invoice_total: invoiceEvents.reduce((s, e) => s + e.amount, 0),
  },
};
fs.writeFileSync('C:/tmp/ingest_report.json', JSON.stringify(report, null, 2));
console.log('\n=== INGEST DRY-RUN REPORT ===');
console.log(JSON.stringify(report, null, 2));

// ─── 4. Emit SQL ────────────────────────────────────────────────
const sqlLines = [
  '-- D3 Phase 2: Wipe + rebuild roster from Timesheet (cost) + Panel (bill)',
  '-- Run on DEV DB only. Assumes migration 030 is applied.',
  '',
  'START TRANSACTION;',
  '',
  '-- ── 1. Upload provenance rows (idempotent) ──────────────',
  `DELETE FROM timesheet_uploads WHERE sha256 IN ('${tsSha}', '${panSha}');`,
  `INSERT INTO timesheet_uploads (filename, sha256, workbook_type, months_covered, status, dry_run_at) VALUES `
    + `('${esc(TS_NAME)}', '${tsSha}', 'timesheet', '${esc(JSON.stringify(tswb.SheetNames))}', 'ingested', NOW());`,
  '  SET @ts_upload_id = LAST_INSERT_ID();',
  `INSERT INTO timesheet_uploads (filename, sha256, workbook_type, months_covered, status, dry_run_at) VALUES `
    + `('${esc(PAN_NAME)}', '${panSha}', 'panel', '${esc(JSON.stringify(panwb.SheetNames))}', 'ingested', NOW());`,
  '  SET @pan_upload_id = LAST_INSERT_ID();',
  '',
  '-- ── 2. Backfill missing caregivers.day_rate using resolved rates ────────',
];

// Update caregivers.day_rate to the resolved rate for each alias
const allCaregivers = Array.from(new Set(shifts.map(s => s.caregiverAlias)));
for (const cg of allCaregivers) {
  const r = resolveRate(cg);
  sqlLines.push(
    `UPDATE caregivers c JOIN timesheet_name_aliases a ON a.person_id = c.person_id ` +
    `AND a.alias_text = '${esc(cg)}' AND a.person_role = 'caregiver' ` +
    `SET c.day_rate = COALESCE(c.day_rate, ${r.rate});`
  );
}

sqlLines.push('');
sqlLines.push('-- ── 3. WIPE existing roster + engagements (dev only) ──────────');
sqlLines.push('DELETE FROM daily_roster;');
sqlLines.push('DELETE FROM engagements;');
sqlLines.push('');
sqlLines.push('-- ── 4. Insert shift rows from Timesheet ────────────────────────');

// Batch inserts of ~500 rows each for performance
const BATCH = 200;
for (let i = 0; i < shifts.length; i += BATCH) {
  const chunk = shifts.slice(i, i + BATCH);
  const values = chunk.map(s => {
    // Rate priority: (1) per-cell override (2) this month's column rate
    // (3) caregiver's last-seen rate in any month (4) overall average
    let resolved;
    if (s.rateOverride !== null) resolved = s.rateOverride;
    else if (s.monthRate !== null) resolved = s.monthRate;
    else resolved = resolveRate(s.caregiverAlias).rate;
    return `('${esc(s.caregiverAlias)}', '${esc(s.patientAlias)}', '${s.date}', DAYNAME('${s.date}'), ${s.units}, ${resolved}, '${esc(s.tab + '!' + s.cell)}', @ts_upload_id)`;
  }).join(',\n  ');
  sqlLines.push(
    `INSERT INTO daily_roster (caregiver_name, client_assigned, roster_date, day_of_week, units, cost_rate, source_cell, source_upload_id)\nVALUES\n  ${values};`
  );
}

sqlLines.push('');
sqlLines.push('-- ── 5. Resolve caregiver_id, patient_person_id, client_id, source_alias_id via aliases ──');
sqlLines.push(`UPDATE daily_roster r
    JOIN timesheet_name_aliases a ON a.alias_text = r.caregiver_name AND a.person_role = 'caregiver'
  SET r.caregiver_id = a.person_id, r.source_alias_id = a.id
WHERE r.source_upload_id = @ts_upload_id;`);
sqlLines.push('');
sqlLines.push(`UPDATE daily_roster r
    JOIN timesheet_name_aliases a ON a.alias_text = r.client_assigned AND a.person_role = 'patient'
  SET r.patient_person_id = a.person_id
WHERE r.source_upload_id = @ts_upload_id;`);
sqlLines.push('');
sqlLines.push(`-- For alias-mapped patients that don't yet have a patients row, create one
-- (self-pay default — client_id = person_id). Also ensure a clients shell exists.
INSERT IGNORE INTO clients (id, person_id)
SELECT DISTINCT a.person_id, a.person_id
  FROM timesheet_name_aliases a
 WHERE a.person_role = 'patient' AND a.person_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM clients c WHERE c.id = a.person_id);

INSERT IGNORE INTO patients (person_id, client_id)
SELECT DISTINCT a.person_id, a.person_id
  FROM timesheet_name_aliases a
 WHERE a.person_role = 'patient' AND a.person_id IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM patients p WHERE p.person_id = a.person_id);

-- bill-payer client inherited from patient's patients.client_id
UPDATE daily_roster r
    JOIN patients p ON p.person_id = r.patient_person_id
  SET r.client_id = p.client_id
WHERE r.source_upload_id = @ts_upload_id;

-- Any remaining NULL client_id → self-pay (patient bills themselves)
UPDATE daily_roster r
  SET r.client_id = r.patient_person_id
WHERE r.source_upload_id = @ts_upload_id AND r.client_id IS NULL;`);

sqlLines.push('');
sqlLines.push('-- ── 6. Insert Panel invoice events into a temp staging table for apportionment ──');
sqlLines.push(`DROP TABLE IF EXISTS _panel_invoices_tmp;`);
sqlLines.push(`CREATE TABLE _panel_invoices_tmp (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_alias VARCHAR(200) NOT NULL,
  invoice_date DATE NOT NULL,
  invoice_month CHAR(7) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  note VARCHAR(100) DEFAULT NULL,
  source_cell VARCHAR(120),
  client_id INT UNSIGNED DEFAULT NULL,
  INDEX idx_tmp_clientmonth (client_id, invoice_month)
) ENGINE=InnoDB;`);

for (let i = 0; i < invoiceEvents.length; i += BATCH) {
  const chunk = invoiceEvents.slice(i, i + BATCH);
  const values = chunk.map(e => {
    const ym = e.date.slice(0, 7);
    return `('${esc(e.clientAlias)}', '${e.date}', '${ym}', ${e.amount}, '${esc(e.note)}', '${esc(e.tab + '!' + e.cell)}')`;
  }).join(',\n  ');
  sqlLines.push(
    `INSERT INTO _panel_invoices_tmp (client_alias, invoice_date, invoice_month, amount, note, source_cell)\nVALUES\n  ${values};`
  );
}

sqlLines.push(`UPDATE _panel_invoices_tmp t
    JOIN timesheet_name_aliases a ON a.alias_text = t.client_alias AND a.person_role = 'client'
  SET t.client_id = a.person_id;`);

sqlLines.push('');
sqlLines.push('-- ── 7. Apportion bill_rate — client-month total ÷ shift count ──────');
sqlLines.push(`-- bill_rate = SUM(invoice amount) / SUM(units) for shifts where client matches, for that month
UPDATE daily_roster r
    JOIN (
      SELECT r2.client_id, DATE_FORMAT(r2.roster_date,'%Y-%m') month,
             SUM(r2.units) units_sum
        FROM daily_roster r2
       WHERE r2.source_upload_id = @ts_upload_id
       GROUP BY r2.client_id, DATE_FORMAT(r2.roster_date,'%Y-%m')
    ) shifts ON shifts.client_id = r.client_id AND shifts.month = DATE_FORMAT(r.roster_date,'%Y-%m')
    JOIN (
      SELECT client_id, invoice_month, SUM(amount) total_bill
        FROM _panel_invoices_tmp
       GROUP BY client_id, invoice_month
    ) panel ON panel.client_id = r.client_id AND panel.invoice_month = DATE_FORMAT(r.roster_date,'%Y-%m')
  SET r.bill_rate = ROUND(panel.total_bill / shifts.units_sum, 2)
WHERE r.source_upload_id = @ts_upload_id;`);

sqlLines.push('');
sqlLines.push('-- ── 8. Reconciliation output ──────────────────────────────────────');
sqlLines.push(`SELECT 'roster_rows' metric, COUNT(*) value FROM daily_roster WHERE source_upload_id = @ts_upload_id
UNION ALL
SELECT 'cost_total', ROUND(SUM(units * cost_rate),2) FROM daily_roster WHERE source_upload_id = @ts_upload_id
UNION ALL
SELECT 'bill_total', ROUND(SUM(units * COALESCE(bill_rate,0)),2) FROM daily_roster WHERE source_upload_id = @ts_upload_id
UNION ALL
SELECT 'panel_invoice_total', ROUND(SUM(amount),2) FROM _panel_invoices_tmp
UNION ALL
SELECT 'orphan_no_caregiver', COUNT(*) FROM daily_roster WHERE source_upload_id = @ts_upload_id AND caregiver_id IS NULL
UNION ALL
SELECT 'orphan_no_patient',   COUNT(*) FROM daily_roster WHERE source_upload_id = @ts_upload_id AND patient_person_id IS NULL
UNION ALL
SELECT 'orphan_no_client',    COUNT(*) FROM daily_roster WHERE source_upload_id = @ts_upload_id AND client_id IS NULL
UNION ALL
SELECT 'shifts_missing_bill', COUNT(*) FROM daily_roster WHERE source_upload_id = @ts_upload_id AND bill_rate IS NULL;`);

sqlLines.push('');
sqlLines.push('DROP TABLE IF EXISTS _panel_invoices_tmp;');
sqlLines.push('COMMIT;');

fs.writeFileSync('C:/tmp/ingest_roster.sql', sqlLines.join('\n'));
console.log(`\nWrote C:/tmp/ingest_roster.sql  (${sqlLines.length} lines, ${shifts.length} shifts, ${invoiceEvents.length} invoice events)`);
