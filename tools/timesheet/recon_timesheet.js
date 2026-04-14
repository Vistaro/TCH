// Reconcile each caregiver column: (shift cells × rate) vs row-38 total.
// Handles: -half (0.5), split cells "X/ Y" (0.5 each), rate-override cells
// (use override × 1 day instead of column rate).
const XLSX = require('xlsx');
const wb = XLSX.readFile('C:/tmp/timesheet.xlsx');

function parseCell(raw) {
  let s = String(raw).trim();
  if (!s || /^\d+(\.\d+)?$/.test(s)) return [];
  // split compound first
  if (s.includes('/')) {
    return s.split('/').flatMap(p => parseCell(p)).map(x => ({ ...x, units: x.units * 0.5 }));
  }
  // half marker
  let units = 1;
  if (/-\s*half\s*$/i.test(s)) { units = 0.5; s = s.replace(/\s*-\s*half\s*$/i, '').trim(); }
  // rate override: "Name -R500", "Name-600", "Name - Invoice March"
  let override = null;
  const m = s.match(/-\s*R?\s*(\d+(?:\.\d+)?)\s*$/i);
  if (m) { override = parseFloat(m[1]); s = s.replace(/\s*-\s*R?\s*\d+(?:\.\d+)?\s*$/i, '').trim(); }
  s = s.replace(/\s*-\s*Invoice\s.+$/i, '').trim();
  return [{ name: s, units, override }];
}

for (const tabName of wb.SheetNames) {
  const sh = wb.Sheets[tabName]; if (!sh['!ref']) continue;
  const range = XLSX.utils.decode_range(sh['!ref']);

  let totalRow = null;
  for (let r = 0; r < Math.min(range.e.r + 1, 60); r++) {
    const a = sh[XLSX.utils.encode_cell({ r, c: 0 })];
    if (a && /^total amount/i.test(String(a.v).trim())) { totalRow = r; break; }
  }
  if (!totalRow) continue;

  console.log(`\n═══ ${tabName} ═══`);
  console.log('Caregiver'.padEnd(32) + 'Rate'.padStart(6) + 'Cells'.padStart(7) + 'Units'.padStart(8) + 'Computed'.padStart(12) + 'Sheet'.padStart(11) + 'Diff'.padStart(10));

  const rateRow = 1;
  let gtComputed = 0, gtSheet = 0;

  for (let c = 2; c <= range.e.c; c++) {
    const h = sh[XLSX.utils.encode_cell({ r: 0, c })];
    if (!h || !String(h.v).trim()) continue;
    const name = String(h.v).trim();
    const rateCell = sh[XLSX.utils.encode_cell({ r: rateRow, c })];
    const rate = rateCell && typeof rateCell.v === 'number' ? rateCell.v : null;

    let cellCount = 0, totalUnits = 0, computed = 0;
    for (let r = 3; r < totalRow; r++) {
      const cell = sh[XLSX.utils.encode_cell({ r, c })]; if (!cell) continue;
      const parsed = parseCell(cell.v);
      if (!parsed.length) continue;
      cellCount++;
      for (const p of parsed) {
        totalUnits += p.units;
        const unitRate = p.override !== null ? p.override : (rate || 0);
        computed += p.units * unitRate;
      }
    }

    const sheetTotCell = sh[XLSX.utils.encode_cell({ r: totalRow, c })];
    const sheetTot = sheetTotCell && typeof sheetTotCell.v === 'number' ? sheetTotCell.v : 0;
    const diff = computed - sheetTot;
    gtComputed += computed; gtSheet += sheetTot;

    const flag = Math.abs(diff) > 0.01 ? ' ⚠' : '';
    if (totalUnits > 0 || sheetTot > 0) {
      console.log(
        name.padEnd(32)
        + String(rate ?? '-').padStart(6)
        + String(cellCount).padStart(7)
        + totalUnits.toFixed(1).padStart(8)
        + computed.toFixed(0).padStart(12)
        + sheetTot.toFixed(0).padStart(11)
        + (diff.toFixed(0) + flag).padStart(12)
      );
    }
  }

  const gtDiff = gtComputed - gtSheet;
  console.log('─'.repeat(86));
  console.log('TOTALS'.padEnd(53) + gtComputed.toFixed(0).padStart(12) + gtSheet.toFixed(0).padStart(11) + gtDiff.toFixed(0).padStart(10) + (Math.abs(gtDiff) > 0.01 ? ' ⚠' : ''));
}
