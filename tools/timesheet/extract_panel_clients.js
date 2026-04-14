// Extract every client panel header from the Panel workbook.
// A "panel header" = any cell whose value is a client name AND the cell
// directly below it has the exact label "Income" (or "Income ").
//
// Strips billing-frequency suffixes (- monthly / - weekly / etc.) so the
// alias string is the canonical client name.
const XLSX = require('xlsx');
const fs = require('fs');

const wb = XLSX.readFile('C:/tmp/revenue.xlsx');
const workbookName = 'Tuniti Revenue to Clients Apr-26.xlsx';

// alias_text → { alias, suffix, first_source }
const aliases = new Map();

function stripFreqSuffix(raw) {
  let s = String(raw).trim();
  // Square-bracket suffix: "Maria Burgum [weekly]"
  let m = s.match(/^(.*?)\s*\[\s*(monthly|weekly|daily|per[-\s]?visit|fortnightly)\s*\]\s*$/i);
  if (m) return { canon: m[1].trim(), suffix: m[2].trim().toLowerCase() };
  // Dash suffix: "Johnstons- monthly"
  m = s.match(/^(.*?)\s*-\s*(monthly|weekly|daily|per[-\s]?visit|fortnightly|invoice.*)\s*$/i);
  if (m) return { canon: m[1].trim(), suffix: m[2].trim().toLowerCase() };
  return { canon: s, suffix: null };
}

for (const tabName of wb.SheetNames) {
  const sh = wb.Sheets[tabName]; if (!sh['!ref']) continue;
  const range = XLSX.utils.decode_range(sh['!ref']);

  for (let r = 0; r <= range.e.r; r++) {
    for (let c = 0; c <= range.e.c; c++) {
      const addr = XLSX.utils.encode_cell({ r, c });
      const cell = sh[addr]; if (!cell) continue;
      const raw = String(cell.v).trim();
      if (!raw || raw.toLowerCase() === 'income' || raw.toLowerCase() === 'expenses'
          || raw.toLowerCase() === 'date:' || /^\d+$/.test(raw)) continue;

      // Must have "Income" directly below
      const below = sh[XLSX.utils.encode_cell({ r: r + 1, c })];
      if (!below) continue;
      const belowV = String(below.v).trim().toLowerCase();
      if (belowV !== 'income') continue;

      const { canon, suffix } = stripFreqSuffix(raw);
      if (!canon) continue;
      const key = canon;
      if (!aliases.has(key)) {
        aliases.set(key, {
          alias: canon,
          suffix,
          first_source: `${workbookName}!${tabName}!${addr}`,
        });
      }
    }
  }
}

const out = Array.from(aliases.values()).sort((a,b) => a.alias.localeCompare(b.alias));
fs.writeFileSync('C:/tmp/panel_clients.json', JSON.stringify(out, null, 2));
console.log(`Extracted ${out.length} distinct client-panel headers:`);
out.forEach(r => console.log(`  ${r.alias}${r.suffix ? ' [' + r.suffix + ']' : ''}  (first: ${r.first_source})`));
