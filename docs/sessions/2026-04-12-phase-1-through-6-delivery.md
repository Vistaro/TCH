# Session Notes — 2026-04-12 — Phase 1 through 6 delivery

**Owner:** Ross
**Agent:** Claude (Claude Opus 4.6 1M context)
**Duration:** Full day, effectively one very long working session
**Outcome:** Eight tagged releases shipped (v0.9.10 → v0.9.16). The TCH system moved from "correct-but-shallow" (persons table unified, reports working) to "end-to-end operational" (engagements, roster input, student tracking, client profitability, dashboard with month filter). All data reconciled to the two Tuniti source workbooks with source-cell traceability.

---

## What shipped today

| Tag | What |
|---|---|
| **v0.9.10** | Persons table unification — `caregivers` renamed to `persons`, added `person_type SET`, created Nexus-aligned `activities` + `activity_types` tables. Patient dedup: 64 → 51 clean clients via chat-driven merge flow with full audit trail. |
| **v0.9.10.1** | Three matrix reports (Client Billing, Caregiver Earnings, Days Worked) pivot on canonical name not denormalised source — so merges and renames reflect immediately. |
| **v0.9.11** | Migration 007: retired `clients` table into `persons` as `person_type='patient,client'`. Four derived fields (first_seen/last_seen/months_active/status) dropped per the new Single Source of Truth standing rule added to global `CLAUDE.md`. Old `clients` table renamed `clients_deprecated_2026_04_11`. |
| **v0.9.12** | Phase 1. Cohort rename (tranche → cohort). Dropped `persons.total_billed`, `persons.standard_daily_rate`, and the entire `margin_summary` table (all derivations). Rebuilt `client_revenue` and `daily_roster` from the two Tuniti workbooks via a generated SQL script, with `source_ref` on every row pointing back to the original Excel cell. Zero orphan roster rows (was 1,224). New `caregiver_loans` table populated from "Money Borrowed" lines. |
| **v0.9.13** | Phase 2+3. Table decomposition: `students` (137), `caregivers` (139), `clients` (68), `patients` (68), `products` (1 — "Day Rate") created as role extension tables alongside persons. Clients.id = persons.id for individual clients so existing FKs align without repointing. All reports and dashboard updated to JOIN through role tables. |
| **v0.9.14** | Phase 4+5+6. Migration 010: `engagements` table (caregiver × patient × product × dates × dual rates), `caregiver_products` + `patient_products` junction tables, `daily_roster` enhanced with engagement_id + dual rates + status + shift times + attribution, `student_enrollments` (123 rows seeded) + `training_attendance` + `student_scores` + `patient_expenses`. 8 new admin pages built: Caregivers List, Clients List, Patients List, Engagements, Roster Input, Student Tracking, Products, Activity Types. |
| **v0.9.15** | Dashboard redesign: horizontal pill month filter replacing dropdown, 3 caregiver tiles (Total / Active / Inactive with %), Total Wages tile, filter affects every tile. Name Reconciliation retired (redirects to dashboard). |
| **v0.9.16** | Client Profitability report: summary page with billed/wages/expenses/gross profit/margin per client, plus a detail page with months-as-columns pivot showing per-caregiver wages, total days, gross profit, margin %. Both pages now have the month pill filter. |

Plus several post-tag fix commits:
- Billing drill-down switched from 5-strategy fuzzy match to clean FK match; shows dual rates + source refs
- Student Tracking "Placed" corrected to mean "has delivered shift OR active engagement"
- Nav sidebar updated to include all new pages under proper section headings
- Nav sidebar font + padding tightened to match Nexus CRM exactly
- Data-quality fix: `cost_rate` backfilled on 1,531 roster rows after the rebuild

---

## The two big things done today

### 1. Name normalisation exercise (morning)

The blocker going into today was that workbook names, attendance sheet cells, billing panel names, and DB persons rows all used different conventions. Ross worked through two Excel spreadsheets produced by Claude (`Caregiver Names RT.xlsx` and `Client Patient Names RT.xlsx`) and confirmed every mapping.

During the exercise:
- 3 caregiver duplicates found and merged in the DB: TCH-000136 Musa Zulu → 064 Musa Glenda Zulu; 130 Emily Mentula → 098 Thembi Emily Mpete; 140 Sylvia Nene → 029 Sylvia Delisile Nene
- 2 new caregivers created: TCH-000205 Nelly Nkayabu Kaniki, TCH-000206 Ada Stipens
- 17 "Not Known" patient/client placeholders created (TCH-000207 to TCH-000223) for attendance cell values Tuniti needs to identify (Apie, Cecily, Papou, Klientjie, etc.). `import_notes` on each records the original attendance string.

### 2. Reconciliation + data rebuild

Two distinct bugs found in the original revenue ingest:

**Bug 1 — fixed-grid panel scan** missed 5+ panels per month because the billing workbook doesn't use uniform 25-row spacing between panel blocks. Replaced with an anchor-based scan (find every "Income" cell in the sheet, panel header is the row above).

**Bug 2 — subtotal rows being counted as income rows**. Panels with 1–4 income dates have their subtotal row within the offset-3-to-7 range my parser was checking. Subtotal rows have NO date in column A. Fix: require a date before counting.

After both fixes, revenue reconciles exactly: Nov 2025 = R201,150 (matches Ross's hand check). Total R1,554,103 matches the original DB value — which turned out to have been correct all along. The intermediate R1,765,204 figure was the inflated double-counted number.

---

## Standing rules added

New section in global `C:\ClaudeCode\CLAUDE.md`: **"Single Source of Truth"** (applies to every project). Articulated by Ross while reviewing migration 007 when he spotted that four columns on `clients` were stored derivations of `client_revenue`. Rule: if a value can be derived from another table on demand, don't store it. Compute in the query. Stored aggregates, counts, cached summary flags — all banned. Only legitimate stored values are *independent business state* (e.g. caregiver's `status_id` of In Training / Available / Placed — a human sets this, it's not derived from aggregating other tables).

---

## Files outside the repo that matter (all in `_global` backed to OneDrive)

- `C:\ClaudeCode\_global\output\TCH\source-workbooks\Tuniti Client Billing RT Apr-26.xlsx` — Ross's revenue source of truth
- `C:\ClaudeCode\_global\output\TCH\source-workbooks\Claude Tuniti Caregiver attendance RT Apr-26.xlsx` — Ross's cost source of truth
- `C:\ClaudeCode\_global\output\TCH\Caregiver Names RT.xlsx` — completed name mapping
- `C:\ClaudeCode\_global\output\TCH\Client Patient Names RT.xlsx` — completed name mapping
- `C:\ClaudeCode\_global\output\TCH\rebuild-revenue-roster.js` — the parser that generates SQL
- `C:\ClaudeCode\_global\output\TCH\rebuild-data.sql` — the last-run SQL that rebuilt revenue + roster
- `C:\ClaudeCode\_global\output\TCH\TCH Build Plan Apr-26.md` — the 6-phase plan (Phase 6 TTMS still future work)
- `C:\ClaudeCode\_global\output\TCH\session-pause-2026-04-12.md` — brief for recovery in a new session

## Server-side backups taken today

- `~/public_html/dev-TCH/dev/database/backups/pre_persons_unification_2026-04-11.sql` (pre-dedup)
- `~/public_html/dev-TCH/dev/database/backups/post_dedup_pre_migration_006_2026-04-11.sql`
- `~/public_html/dev-TCH/dev/database/backups/post_dedup_pre_migration_007_2026-04-11.sql`
- `~/public_html/dev-TCH/dev/database/backups/pre_phase1_2026-04-12.sql`
- `~/public_html/tch_backup_pre_persons_2026-04-11/` (webroot)
- `~/public_html/tch_backup_pre_007_2026-04-11/` (webroot)

## Git state at session end

- `dev` = `main` = `ac7e2e9`
- Tags added today: v0.9.10, v0.9.10.1, v0.9.11, v0.9.12, v0.9.13, v0.9.14, v0.9.15, v0.9.16
- Several post-tag fix commits on top of v0.9.16

## Live DB state at session end

- **persons** 207 rows (139 caregivers, 68 clients, 17 of which are Not-Known placeholders)
- **client_revenue** 80 monthly rows, R1,554,103 total, zero orphans
- **daily_roster** 1,619 rows, R692,148 wages, zero orphans, dual rates populated
- **caregiver_loans** 39 rows, R29,401 total borrowed
- **engagements** 0 rows (ready for input)
- **student_enrollments** 123 rows (seeded from existing student data)
- **Dashboard verified:** 139 caregivers (42 active, 97 inactive), 68 clients, R1,554,103 revenue, R692,148 wages, R861,955 gross margin (55%), 1,619 shifts

---

## What's queued for future sessions

Documented in `docs/TCH_Ross_Todo.md`:

- **DQ0** — full audit of every table for more stored derivations (partial: caregiver_costs, client_revenue still candidates, will retire when D2 completes)
- **DQ1** — id=47 Morrison R0 income investigation (currently id=181 post-migration — need to re-look)
- **DQ2** — 7 slash-split rows needing Tuniti review (Angela/Dimitri Papadopoulos etc.)
- **UI0** — replace month pills with date-range picker when we pass ~12 months of data
- **UI1** — edit client ↔ patient relationship from patient record
- **D1** — engagements model (schema delivered v0.9.14, UI delivered same release; data-driven billing still pending real engagement rows)
- **D2** — roster redesign (schema delivered v0.9.14; "compute revenue from roster" switchover pending real engagement rows)
- **D3** — patient expenses recharged-or-absorbed flag needs Tuniti decision
- **TTMS / Phase 6** — training attendance + score entry forms; auto-create caregiver on graduation
- **Entity detail/edit pages** — list pages shipped but click-through to edit form still missing
- **Activities timeline on person records** — schema + config page live; rendering on person detail not yet built
- **17 Not Known patients** — Tuniti to identify and merge into real persons
- **April 2026 attendance** — attendance workbook April sheet is empty; when Tuniti provides it, run the rebuild parser again

---

## Deploy discipline notes

Every deploy today followed the same pattern:
1. Write migration SQL locally
2. Scp to server
3. Run via `mysql -h ... < migration.sql`
4. Tar-push code changes to dev webroot
5. PHP lint on the server
6. Server-side rsync dev → prod
7. Curl-based smoke test
8. Commit + push + tag

Context-window-aware: every substantive change also backed up to `_global` before committing. The session-pause + session-notes files give any new session a clean entry point.
