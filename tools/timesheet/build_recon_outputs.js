// Build (a) an Excel discrepancy list (one row per caregiver-month issue)
// and (b) a plain-text email body grouped by pattern.
const XLSX = require('xlsx');
const fs = require('fs');

const wb = XLSX.readFile('C:/tmp/timesheet.xlsx');

function parseCell(raw) {
  let s = String(raw).trim();
  if (!s || /^\d+(\.\d+)?$/.test(s)) return [];
  if (s.includes('/')) {
    return s.split('/').flatMap(p => parseCell(p)).map(x => ({ ...x, units: x.units * 0.5 }));
  }
  let units = 1;
  if (/-\s*half\s*$/i.test(s)) { units = 0.5; s = s.replace(/\s*-\s*half\s*$/i, '').trim(); }
  let override = null;
  const m = s.match(/-\s*R?\s*(\d+(?:\.\d+)?)\s*$/i);
  if (m) { override = parseFloat(m[1]); s = s.replace(/\s*-\s*R?\s*\d+(?:\.\d+)?\s*$/i, '').trim(); }
  s = s.replace(/\s*-\s*Invoice\s.+$/i, '').trim();
  return [{ name: s, units, override }];
}

const issues = []; // {tab, col, caregiver, cellCount, rate, computed, sheet, diff, pattern, moneyAdded, moneyBorrowed, rateCellRef, totalCellRef, query}

for (const tabName of wb.SheetNames) {
  const sh = wb.Sheets[tabName]; if (!sh['!ref']) continue;
  const range = XLSX.utils.decode_range(sh['!ref']);

  // Locate label rows
  const labelRow = { total: null, added: null, borrowed: null };
  for (let r = 0; r < Math.min(range.e.r + 1, 60); r++) {
    const a = sh[XLSX.utils.encode_cell({ r, c: 0 })];
    const s = a ? String(a.v).trim().toLowerCase() : '';
    if (/^total amount/.test(s))        labelRow.total = r;
    else if (/^money\s*added/.test(s))  labelRow.added = r;
    else if (/^money\s*borrowed/.test(s)) labelRow.borrowed = r;
  }
  if (labelRow.total == null) continue;

  for (let c = 2; c <= range.e.c; c++) {
    const h = sh[XLSX.utils.encode_cell({ r: 0, c })];
    if (!h || !String(h.v).trim()) continue;
    const caregiver = String(h.v).trim();
    const rateCell = sh[XLSX.utils.encode_cell({ r: 1, c })];
    const rate = rateCell && typeof rateCell.v === 'number' ? rateCell.v : null;
    const colLetter = XLSX.utils.encode_col(c);

    let cellCount = 0, totalUnits = 0, computed = 0;
    for (let r = 3; r < labelRow.total; r++) {
      const cell = sh[XLSX.utils.encode_cell({ r, c })]; if (!cell) continue;
      const parsed = parseCell(cell.v); if (!parsed.length) continue;
      cellCount++;
      for (const p of parsed) {
        totalUnits += p.units;
        computed += p.units * (p.override !== null ? p.override : (rate || 0));
      }
    }

    const sheetCell = sh[XLSX.utils.encode_cell({ r: labelRow.total, c })];
    const sheetTot = sheetCell && typeof sheetCell.v === 'number' ? sheetCell.v : 0;
    const diff = computed - sheetTot;
    const moneyAdded = labelRow.added !== null
      ? (sh[XLSX.utils.encode_cell({ r: labelRow.added, c })]?.v ?? null) : null;
    const moneyBorrowed = labelRow.borrowed !== null
      ? (sh[XLSX.utils.encode_cell({ r: labelRow.borrowed, c })]?.v ?? null) : null;

    // Categorise
    let pattern, query;
    const skip = totalUnits === 0 && sheetTot === 0;
    if (skip) continue;

    if (rate === null && sheetTot > 0) {
      pattern = 'MISSING_RATE';
      query = `There is no Caregiver Price on tab "${tabName}" row 2 col ${colLetter} for caregiver "${caregiver}". However the sheet shows a Total Amount of R${sheetTot.toFixed(0)} for this column. Please confirm the day-rate that applied this month so we can compute per-shift cost.`;
    } else if (Math.abs(diff) < 0.01) {
      pattern = 'CLEAN';
      continue; // skip clean rows
    } else if (moneyBorrowed && Math.abs(diff - moneyBorrowed) < 0.01) {
      pattern = 'LOAN_DEDUCTED_FROM_TOTAL';
      query = `On tab "${tabName}" col ${colLetter}, caregiver "${caregiver}": ${cellCount} cells × R${rate} = R${computed.toFixed(0)} gross, but Total Amount row reads R${sheetTot.toFixed(0)} — exactly R${moneyBorrowed} less, matching the Money Borrowed row. Please confirm: is the Total Amount intended as net-of-loans (pay minus borrowings), or should the loan be tracked separately and the Total show gross pay?`;
    } else if (moneyAdded && Math.abs(diff + moneyAdded) < 0.01) {
      pattern = 'BONUS_ADDED_TO_TOTAL';
      query = `On tab "${tabName}" col ${colLetter}, caregiver "${caregiver}": ${cellCount} cells × R${rate} = R${computed.toFixed(0)} gross, but Total Amount row reads R${sheetTot.toFixed(0)} — exactly R${moneyAdded} more, matching the Money Added row. Please confirm: is the Money Added figure intended to be folded into Total Amount this month?`;
    } else {
      pattern = 'UNEXPLAINED';
      const sign = diff > 0 ? 'less' : 'more';
      query = `On tab "${tabName}" col ${colLetter}, caregiver "${caregiver}": ${cellCount} cells × R${rate} = R${computed.toFixed(0)} per my arithmetic, but the Total Amount row shows R${sheetTot.toFixed(0)} — a difference of R${Math.abs(diff).toFixed(0)} (${sign} than the shifts imply). Money Added row: ${moneyAdded ?? '—'}. Money Borrowed row: ${moneyBorrowed ?? '—'}. Please clarify the reason for the difference.`;
    }

    issues.push({
      tab: tabName, col: colLetter, caregiver,
      cellCount, units: totalUnits, rate,
      computed: +computed.toFixed(2),
      sheet:    +sheetTot.toFixed(2),
      diff:     +diff.toFixed(2),
      moneyAdded: moneyAdded ?? '',
      moneyBorrowed: moneyBorrowed ?? '',
      pattern, query,
    });
  }
}

// ─── Write Excel ────────────────────────────────────────────────
const out = XLSX.utils.book_new();
const headers = ['Tab', 'Col', 'Caregiver', 'Cells', 'Units', 'Rate', 'Computed (R)', 'Sheet Total (R)', 'Diff (R)', 'Money Added', 'Money Borrowed', 'Pattern', 'Suggested query to Tuniti'];
const rows = [headers, ...issues.map(i => [
  i.tab, i.col, i.caregiver, i.cellCount, i.units, i.rate ?? '',
  i.computed, i.sheet, i.diff,
  i.moneyAdded, i.moneyBorrowed,
  i.pattern, i.query,
])];
const sheet = XLSX.utils.aoa_to_sheet(rows);
sheet['!cols'] = [
  {wch:20},{wch:6},{wch:28},{wch:6},{wch:7},{wch:7},
  {wch:13},{wch:15},{wch:10},{wch:13},{wch:15},{wch:26},{wch:100},
];
XLSX.utils.book_append_sheet(out, sheet, 'Reconciliation');

const outPath = 'C:/ClaudeCode/_global/output/TCH/Tuniti Timesheet Reconciliation Apr-26.xlsx';
XLSX.writeFile(out, outPath);
console.log(`Wrote ${outPath}  (${issues.length} issues)`);

// ─── Write plain-text email body ─────────────────────────────────
const grouped = {};
for (const i of issues) { (grouped[i.pattern] ??= []).push(i); }

const patternLabels = {
  MISSING_RATE:              'MISSING CAREGIVER RATES (blocker — ingest cannot compute cost)',
  LOAN_DEDUCTED_FROM_TOTAL:  'TOTAL AMOUNT APPEARS NET-OF-LOANS (inconsistent across months)',
  BONUS_ADDED_TO_TOTAL:      'TOTAL AMOUNT APPEARS TO INCLUDE BONUS (inconsistent across months)',
  UNEXPLAINED:               'UNEXPLAINED DIFFERENCES (per-shift math does not tie to Total Amount)',
};

let body = `Hi,

Before we load the Tuniti Caregiver Timesheet into the TCH system we've
run a reconciliation check on each caregiver column in each monthly tab:
(number of cells x day rate) vs the "Total Amount" row.

Across the five populated months (Nov 2025 – Mar 2026), my arithmetic
totals R693,389 and the sheet's Total Amount row totals R709,620 — a
gap of R16,231 spread across about 50 individual caregiver-month rows.

We need to resolve each of these so the ingest loads the correct cost
per shift. I've grouped the discrepancies below into four patterns.
The full list is attached as an Excel file; the queries below are
pre-filled ready for you to annotate.

`;

for (const [pat, label] of Object.entries(patternLabels)) {
  const list = grouped[pat] || []; if (!list.length) continue;
  body += `\n════════════════════════════════════════════════════════════════\n`;
  body += ` ${label}  (${list.length} items)\n`;
  body += `════════════════════════════════════════════════════════════════\n\n`;
  for (const i of list) {
    body += `• ${i.query}\n\n`;
  }
}

body += `
════════════════════════════════════════════════════════════════
 Attachment
════════════════════════════════════════════════════════════════

The file "Tuniti Timesheet Reconciliation Apr-26.xlsx" lists every
discrepancy with columns you can fill in: the pattern, my arithmetic,
your sheet's figure, the money-added / money-borrowed rows, and the
suggested clarification. Please return it annotated and we'll adjust
the ingest accordingly.

Thanks,
Ross
`;

const txtPath = 'C:/ClaudeCode/_global/output/TCH/Tuniti Timesheet Reconciliation Apr-26 - email body.txt';
fs.writeFileSync(txtPath, body);
console.log(`Wrote ${txtPath}  (${body.split('\n').length} lines)`);

// Summary for console
console.log('\nBy pattern:');
for (const [p, l] of Object.entries(grouped)) console.log(`  ${p}: ${l.length}`);
