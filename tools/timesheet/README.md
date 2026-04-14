# Timesheet + Panel ingest tooling (D3)

Node CLI scripts that parse Tuniti's monthly workbooks into SQL for the
TCH database. Not wired to the admin UI yet — run locally, ship SQL to
dev DB via SSH.

## Prerequisites

- Node 18+ with `xlsx` (cached at `C:/tmp/xlsx-read/node_modules`)
- Two workbooks copied to `C:/tmp/`:
  - `timesheet.xlsx` — Tuniti Caregiver Timesheets (cost side)
  - `revenue.xlsx`   — Tuniti Revenue to Clients (Panel, bill side)

## Workflow

```bash
# 1. Extract distinct alias names from the Timesheet (caregivers + patients)
NODE_PATH="C:/tmp/xlsx-read/node_modules" node tools/timesheet/extract_names.js

# 2. Extract distinct client-panel headers from the Revenue workbook
NODE_PATH="C:/tmp/xlsx-read/node_modules" node tools/timesheet/extract_panel_clients.js

# 3. Reconcile per-caregiver column arithmetic vs sheet Total Amount
NODE_PATH="C:/tmp/xlsx-read/node_modules" node tools/timesheet/recon_timesheet.js

# 4. Build Tuniti-facing Excel + email body of discrepancies
NODE_PATH="C:/tmp/xlsx-read/node_modules" node tools/timesheet/build_recon_outputs.js

# 5. Build the full rebuild SQL (wipes + re-inserts roster from workbooks)
NODE_PATH="C:/tmp/xlsx-read/node_modules" node tools/timesheet/build_ingest_sql.js
```

Outputs land at `C:/tmp/*.sql` and `C:/ClaudeCode/_global/output/TCH/`.
Ship the SQL to dev via `scp` and run against the dev DB.

## What the ingest does

1. Creates two rows in `timesheet_uploads` (one per workbook, sha256-keyed).
2. Backfills `caregivers.day_rate` for caregivers with blank row-2 rates
   using their history (any prior month) or the overall average.
3. Wipes `daily_roster` and `engagements` on the target DB.
4. Parses every shift cell and inserts a `daily_roster` row with:
   - `caregiver_name` → resolved via alias → `caregiver_id`
   - `client_assigned` (patient) → resolved via alias → `patient_person_id`
   - `units` — 1.00 default, 0.50 for `-half` suffix or split cells
   - `cost_rate` — from per-cell override > this month's column rate >
     caregiver history > overall average
   - `source_cell` — e.g. `Caregiver Jan 2026!J6`
   - `source_upload_id`, `source_alias_id` — FK provenance
5. Derives `client_id` from `patients.client_id` (self-pay default for
   patients without an explicit link).
6. Parses every Panel income row into a temp table, resolves client_id
   via alias, apportions `bill_rate = SUM(client-month invoice) / SUM(units)`
   across that client's shifts that month.
7. Runs a reconciliation SELECT showing totals + orphan counts.

## Known limitations

- **No admin UI yet** — ingest runs via local Node + remote SSH.
  Admin `/admin/timesheets` page to upload + run exists as a pending
  ToDo.
- **Bill apportionment depends on `patients.client_id` being right.**
  If a patient's bill-payer client isn't linked in the DB, that
  patient's shifts stay at `bill_rate = NULL`. Fix by linking the
  patient to the correct client via the patient profile.
- **No alias re-map trigger yet.** When an alias is re-pointed in
  `/admin/config/aliases`, existing roster rows referencing that alias
  are NOT auto-updated. Pending ToDo: add an UPDATE that re-points
  `caregiver_id` / `patient_person_id` / `client_id` via `source_alias_id`.
