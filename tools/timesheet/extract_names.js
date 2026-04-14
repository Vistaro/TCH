// Extract every distinct name from the Timesheet (caregivers + patient cells)
// across all populated monthly tabs.
const XLSX = require('xlsx');
const wb = XLSX.readFile('C:/tmp/timesheet.xlsx');

const caregivers = new Map();  // name -> [{month, col}]
const patients   = new Map();  // name -> [{month, addr, count}]

function pushMap(m, key, info) {
  if (!m.has(key)) m.set(key, []);
  m.get(key).push(info);
}

for (const name of wb.SheetNames) {
  const sh = wb.Sheets[name];
  if (!sh['!ref']) continue;
  const range = XLSX.utils.decode_range(sh['!ref']);

  // Find the Total Amount row (caregiver section end)
  let totalRow = null;
  for (let r = 0; r < Math.min(range.e.r + 1, 60); r++) {
    const a = sh[XLSX.utils.encode_cell({ r, c: 0 })];
    if (a && /^total amount/i.test(String(a.v).trim())) { totalRow = r; break; }
  }
  if (!totalRow) continue;

  // Caregivers: row 1, col C onwards
  const cgCols = [];
  for (let c = 2; c <= range.e.c; c++) {
    const h = sh[XLSX.utils.encode_cell({ r: 0, c })];
    if (h && String(h.v).trim()) {
      const nm = String(h.v).trim();
      cgCols.push({ col: c, name: nm });
      pushMap(caregivers, nm, { month: name, col: XLSX.utils.encode_col(c) });
    }
  }

  // Shift cells: rows 4-row to totalRow-1, cols of caregivers
  for (let r = 3; r < totalRow; r++) {
    for (const { col: c } of cgCols) {
      const addr = XLSX.utils.encode_cell({ r, c });
      const cell = sh[addr];
      if (!cell) continue;
      const raw = String(cell.v).trim();
      if (!raw) continue;
      // Ignore pure numbers (subtotals scribbled in)
      if (/^\d+(\.\d+)?$/.test(raw)) continue;
      // Strip the "- half" marker to canonicalise the patient name
      const patient = raw.replace(/\s*-\s*half\s*$/i, '').trim();
      pushMap(patients, patient, { month: name, addr, count: 1 });
    }
  }
}

console.log(`\n=== CAREGIVERS (${caregivers.size}) ===`);
const cgList = Array.from(caregivers.entries()).sort((a,b) => a[0].localeCompare(b[0]));
cgList.forEach(([name, occ]) => console.log(`  ${name}  [${occ.length} month(s): ${occ.map(o => o.month.replace('Caregiver ','')).join(', ')}]`));

console.log(`\n=== PATIENTS (${patients.size}) ===`);
const ptList = Array.from(patients.entries()).sort((a,b) => a[0].localeCompare(b[0]));
ptList.forEach(([name, occ]) => console.log(`  ${name}  [${occ.length} cells]`));

// Write to JSON for downstream ingest
require('fs').writeFileSync('C:/tmp/timesheet_names.json', JSON.stringify({
  caregivers: cgList.map(([name, occ]) => ({ name, occurrences: occ })),
  patients: ptList.map(([name, occ]) => ({ name, occurrences: occ })),
}, null, 2));
console.log('\nWrote C:/tmp/timesheet_names.json');
