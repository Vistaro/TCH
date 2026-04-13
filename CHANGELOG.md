# Changelog

All notable changes to the TCH Placements project.

## [0.9.18] - 2026-04-13 (dev)

### Added — Password policy + per-user / global force reset

- **Migration 018** — new `user_password_history` table (last 5 hashes per user, auto-pruned).
- **`includes/password_policy.php`** — central rules (min 10 chars, letter+digit+symbol, no name/email substring, no reuse of last 5). Help text rendered on setup-password, reset-password, login screens.
- **Login flow respects `must_reset_password`** — flagged users are redirected straight into a forced reset (with banner) before any session is established. Excludes the user who triggers a global reset.
- **Global "Force password reset — all users"** on `/admin/users` (Super Admin only, requires re-auth). Logs `password_reset_forced_bulk` to `activity_log`.

### Added — Student admin: edit, create, print, mark-graduated, photo

- **Edit-in-place extended** to the Training section (course_start, avg_score, practical_status, qualified) — writes to `students` table; logs to `activity_log` + Note timeline.
- **Mark as Graduated** action on the student detail page — sets `student_enrollments.graduated_at = today` + `status='graduated'`.
- **Photo replace** on student detail. Uploads to `uploads/people/<TCH-ID>/profile_<ts>.<ext>`, marks old `profile_photo` attachment inactive, inserts new.
- **Add Student** page at `/admin/students/new` — minimal-fields form, allocates next TCH ID, creates `persons` + `students` + `student_enrollments` rows in one transaction.
- **Print / PDF** view at `/admin/students/{id}/print` — single-page A4 layout matching the original Tuniti intake PDF; auto-opens browser print dialog (user picks Save as PDF or printer).

### Added — User profile: avatar + display currency

- **Migration 019** — `users.avatar_path`. Upload UI on `/admin/users/{id}`. Avatar shown in the admin top-bar across the site.
- **Migration 020** — `users.currency_code` (default ZAR), new `fx_rates` table cached from open.er-api.com (free, unauthenticated). 166 currencies. Refreshed on demand or auto when stale (>24h). New page `/admin/config/fx-rates`. Dashboard money values now render the ZAR figure on top with the user's preferred currency converted underneath in smaller text when ≠ ZAR.

### Quick wins

- **Phone display formatting** — E.164 numbers display as `+27 63 239 9863` (SA-aware grouping). Applied across student profile + print view.
- **Yes/No on Students list** — Graduated and Placed columns now show Yes (green) or No (grey) instead of dashes.
- **Mojibake fix** — UTF-8/Windows-1252 double-encoded em dashes in 1,216 attendance notes + activity entries cleaned up via REPLACE on the corrupted byte sequence (`C3A2 E282AC E2809D` → `-`).
- **Pending-invites detail + revoke** on `/admin/users` — list shows name · email · role · invited-by · expires-in-Nd, with per-row Revoke button.

### Fixed

- **BUG-setup-pw** (Migration 017) — `users.linked_client_id` column was missing despite being referenced by both setup-password and user-detail edit. Every invite acceptance was 500'ing.

### Status

All of the above is **on DEV only**. No prod deploy yet.

## [0.9.20 → prod] - 2026-04-13

Everything from 0.9.18 + 0.9.19 promoted to PROD via `rsync dev → tch/`
after prod-webroot snapshot `prod-webroot-pre-0920-20260413-160734.tgz`.
DB changes already in place (shared DB — migrations 011–023 all applied
to the single schema).

### Added post-promote (still dev-only at wrap time)

- Migration 023 — seeded 5 products from the public site (Full-Time,
  Post-Operative, Palliative, Respite, Errand). Day Rate kept as id 1.
- Copy tidy: "Engagements" → "Care Scheduling", "Roster Input" →
  "Care Approval", "+ New Engagement" → "+ New Care Schedule",
  "+ Record Shift" → "+ Approve Delivered Care".
- Sticky-header fix — `border-collapse: separate` on `.report-table`
  so Chrome/Edge stop blocking `position: sticky` on thead. Header
  underline + row separators moved onto th/td explicitly.
  Logged BUG-sticky-header HIGH for verification after cache bust.

## [0.9.17] - 2026-04-13

### Added — Student detail page + Tuniti attendance import + Notes timeline

**New page: `/admin/students/{id}` (Student Detail)** — first proper
single-student detail page. Replaces the read-only detail view that
lived inside `/admin/people/review`.

- Profile card with photo, TCH ID, cohort, training summary, personal,
  contact, address, **emergency contact + 2nd emergency contact**.
- **Edit-in-place per section** (Personal, Contact, Address, NoK 1,
  NoK 2). Every edit writes to `activity_log` (audit before/after) AND
  drops one Note per changed field in the timeline.
- **Approve button** when `import_review_state='pending'`. Reject removed
  by design — fix via Edit then Approve.
- Per-student PDF (split from cohort intake PDF) attached.
- Collapsible **Course Attendance** card: per-week date / module /
  classroom-or-practical / Present-or-Absent + summary footer.
- Collapsible **Notes** card mirroring Nexus CRM Activities & Tasks
  pattern. + Add Note / + Add Task buttons.

**New module: Notes timeline (`includes/activities_render.php`)** —
mirrors Nexus CRM pattern, single `activities` table, polymorphic via
`entity_type`/`entity_id`, type cosmetic only. User-facing label is
"Notes" so non-technical users see plain language.

**Migration 011** — `activities` source columns: `source`, `source_ref`
(e.g. `Ross Intake 1-9.xlsx#Cohort 1!N5`), `source_batch`. Two indexes.
Pattern recommended by Nexus CRM agent — TCH first to ship structured
provenance.

**Migration 012** — `student_view` page registration in `pages` table
with Super Admin permission.

**Migration 013** — `country VARCHAR(60) DEFAULT 'South Africa'` added
to `persons`. All 207 existing rows backfilled. Country dropdown
grouped (SA default, then 53 African countries A–Z).

**Migration 014** — phone numbers normalised to E.164. 123 SA
local-format numbers across mobile / secondary / NoK / NoK-2 rewritten
to `+27...`. Edit screens now use a country-code dropdown + national
number input.

**Migration 015** — 205 legacy `persons.import_notes` (machine-generated
PDF-import audit) migrated into the Notes timeline as System-type
entries tagged `source='import-history'`, batch `pre-2026-04-13`.
Original column preserved.

**Migration 016** — `students.practical_status` widened
`varchar(30)` → `varchar(100)` so full facility names like
"Lonehill Manor Retirement Estate" don't truncate.

### Tuniti attendance + summary import

`tools/intake_parser/import_attendance.py` reads
`Ross Intake 1-9 (3).xlsx` and writes:

- 109 students got `avg_score`
- 107 students got `practical_status`
- 1,216 weekly attendance rows in `training_attendance`
  (per-week P/A from cell fill colours: green=Present, red=Absent)
- 1,982 Notes posted, one per imported value, each carrying a
  `source_ref` back to the originating spreadsheet cell

Name reconciliation: 123 attendance-sheet names matched to live
`students` per cohort. Two manual overrides
(Nelly → TCH-000003, "Wisani Precious Mash" → TCH-000008). Recon
workbook at `_global\output\TCH\Tuniti Attendance Name Recon Apr-26.xlsx`.

### PDF splitting

`tools/intake_parser/split_cohort_pdfs.py` split 9 cohort intake PDFs
into 123 per-student single-page PDFs. Attachment rows updated to point
at the per-student file. Mapping rule: Nth-lowest person_id in cohort
N = Nth page of `Intake N.pdf` (verified for Cohort 1; Cohorts 2-9
inherit parser ordering, spot-check recommended). Mapping CSV at
`_global\output\TCH\intake_pdf_split_mapping_apr-26.csv`.

### Removed

- Reject workflow on student records (by design — Edit then Approve).
- Inline detail view from `/admin/people/review` — page now redirects
  `?id=N` requests to `/admin/students/N`. Queue list preserved.

### Renamed (menu)

- "Student Tracking" → **Students**
- "Person Review" → **Pending Approvals**

### Rollback

Each migration preceded by logical backup of the affected table(s) at
`~/db-backups/tch/` on the server. Attendance import rollback: restore
`students` + `training_attendance` + `activities` from
`pre-attendance-import_20260413-*.sql`.

## [0.9.14] - 2026-04-12

### Added — Phase 4+5+6: Engagements, roster input, student tracking, admin pages

**Migration 010** (`010_engagements_roster_students.sql`):

Schema for the full engagement-to-billing pipeline + student tracking:

- **`caregiver_products`** (139 rows) — which caregivers are qualified
  for which products. Seeded: all caregivers qualified for Day Rate.
- **`patient_products`** (68 rows) — which products each patient needs.
  Seeded: all patients need Day Rate.
- **`engagements`** — the contract: caregiver × patient × product ×
  date range × cost_rate × bill_rate. Status: active/completed/cancelled.
- **`daily_roster` enhanced** — added `engagement_id`, `product_id`,
  `cost_rate`, `bill_rate`, `status` (planned/delivered/cancelled/disputed),
  `shift_start`/`shift_end`, `created_by_user_id`, `confirmed_by_user_id`.
  Existing 1,619 rows backfilled: cost_rate = daily_rate, product = Day Rate,
  status = delivered.
- **`student_enrollments`** (123 rows) — tracks enrollment through to
  graduation. Status: enrolled/in_training/ojt/qualified/graduated/dropped.
  Seeded from existing student data.
- **`training_attendance`** — classroom/practical/OJT attendance per day.
- **`student_scores`** — individual module/course scores.
- **`patient_expenses`** — non-shift costs (Uber, equipment etc.)
  allocated to patients/clients.

### Added — 8 new admin pages

All pages are live on both dev and prod, permission-gated to Super Admin:

| URL | Page | What it does |
|---|---|---|
| `/admin/caregivers` | Caregiver List | Filterable list with cohort, status, day rate, shift count, total earned |
| `/admin/clients` | Client List | All clients with revenue, shifts, cost, and gross margin per client |
| `/admin/patients` | Patient List | All patients with client link, shift count, last shift date |
| `/admin/engagements` | Engagements | Create + manage caregiver-patient contracts with cost/bill rates. Auto-populates cost rate from caregiver's day rate. Status management (complete/cancel). |
| `/admin/roster/input` | Roster Input | Record shifts from an engagement — selects engagement, date, marks as delivered/planned. Shows recent 30 days of shifts with cancel capability. |
| `/admin/students` | Student Tracking | Dashboard cards (enrolled/in-training/qualified/graduated/placed) + filterable list with cohort, scores, attendance, practical status, graduation date, placement status |
| `/admin/products` | Products | CRUD for the product catalogue (code, name, description, active flag) |
| `/admin/config/activity-types` | Activity Types | CRUD for the activity/task type lookup (name, icon, colour, sort order) |

### How the engagement → shift → billing flow works

1. Create an engagement: pick a caregiver, patient, product, set cost rate + bill rate + dates
2. Record shifts against that engagement (Roster Input page) — each shift inherits the rates
3. Client Billing report shows revenue per client per month (from client_revenue today; from roster bill_rate × delivered shifts in Phase 5 completion)
4. Caregiver Earnings shows pay per caregiver per month (from caregiver_costs today; from roster cost_rate × delivered shifts in Phase 5 completion)
5. Gross margin per client = sum(bill_rate) - sum(cost_rate) across delivered shifts

The full "compute revenue from roster" switchover (D2 completion) is queued
for when enough engagements + shifts are entered via the new pages to
replace the workbook-ingested data.

## [0.9.13] - 2026-04-12

### Added — Phase 2+3: Table decomposition + reports on role tables

**Migration 009** (`009_table_decomposition.sql`):
Created five role tables alongside the existing `persons` identity
table. Additive approach — no existing FKs repointed, no code
breaks during rollout. Role tables are extension tables joined via
`person_id`:

- **`students`** (137 rows) — cohort, student_id, scores,
  qualification, import_review_state. Populated from caregiver-type
  persons that have training data.
- **`caregivers`** (139 rows) — day_rate (from latest rate_history),
  status (available/placed/inactive). One row per deployable
  caregiver.
- **`clients`** (68 rows) — account_number, billing_entity,
  billing_freq. Separate auto-increment id supports future company
  clients (person_id nullable). Existing `client_revenue.client_id`
  and `daily_roster.client_id` values work as FKs without repointing
  because clients.id = persons.id for individual clients.
- **`patients`** (68 rows) — client_id FK (who pays), patient_name.
  1:1 with clients today; supports N:1 when corporate clients arrive.
- **`products`** (1 row) — seeded with "Day Rate". Ready for Night
  Shift, Live-In, Hourly etc. when TCH adds them.

Admin pages registered for caregivers, clients, patients, products
(Super Admin CRUD granted). Handlers not yet built — queued for
Phase 4.

### Changed — Reports read from role tables

All reports and the dashboard now JOIN through the role tables
instead of filtering `persons` with `FIND_IN_SET`:

- Dashboard: caregivers count from `caregivers`, placed from
  `caregivers WHERE status='placed'`, clients from `clients`,
  active from `clients JOIN client_revenue`
- Client Billing: JOINs `clients` → `persons` for display name
- Caregiver Earnings: JOINs `persons` + `students` for cohort
- Days Worked: same pattern
- People Review: reads cohort, import state from `students` table
- Permissions: `getVisibleCaregiverIds` reads from `caregivers`,
  `getVisibleClientIds` reads from `clients`
- Homepage: counts from `caregivers` and `clients`
- Report drill-down: client lookup through `clients JOIN persons`

**Note:** Role-specific columns are NOT yet dropped from `persons`.
They stay temporarily so any code path not yet migrated doesn't
break. Migration 010 (future) strips persons to identity-only.

### Verified

Dashboard: 139 caregivers, 68 clients, 24 active, R1,765,204
revenue, R1,055,584 margin, 1,619 shifts. All 6 admin pages + 
homepage return 200 on both dev and prod.

## [0.9.12] - 2026-04-12

### Changed — Phase 1: cohort rename + drop derivations + data rebuild

**Migration 008** (`008_cohort_rename_and_drop_derivations.sql`):
- Renamed `tranche` → `cohort` across `persons` and `name_lookup`
  tables (column + data values "Tranche N" → "Cohort N")
- Dropped `persons.total_billed` (derivation of caregiver_costs)
- Dropped `persons.standard_daily_rate` (derivation of rate_history)
- Dropped `margin_summary` table (entirely derived)
- All UI labels updated: Tranche → Cohort in dropdowns, table
  headers, and filter controls across 4 template files

**Data rebuild from source workbooks:**
- Rebuilt `client_revenue` from the billing workbook panels (80
  aggregated rows, R1,765,204 total — corrected from the old
  R1,554,103 which was ingested from a different source)
- Rebuilt `daily_roster` from the attendance workbook shift matrix
  (1,619 rows, R692,148 total caregiver cost)
- **Zero orphan rows** — every revenue row links to a client, every
  roster row links to both a caregiver AND a client/patient
  (previously 1,224 of 1,619 roster rows had no client link)
- Every row carries a `source_ref` column pointing back to the
  exact cell in the original Excel file for full traceability
- Created `caregiver_loans` table with 39 rows from the "Money
  Borrowed" lines in the attendance workbook

**Name normalisation (prerequisite):**
- Ross manually reviewed and confirmed every caregiver and client
  name across three sources (PDF intake, billing panels, attendance
  cells) via the Name Normalisation spreadsheet
- Three caregiver duplicates merged: TCH-000136 Musa Zulu → 
  TCH-000064 Musa Glenda Zulu; TCH-000130 Emily Mentula →
  TCH-000098 Thembi Emily Mpete; TCH-000140 Sylvia Nene →
  TCH-000029 Sylvia Delisile Nene
- Two new caregivers created: TCH-000205 Nelly Nkayabu Kaniki,
  TCH-000206 Ada Stipens
- 17 "Not Known" client/patient placeholders created
  (TCH-000207 through TCH-000223) for attendance cell values
  Tuniti needs to identify

**Dashboard stats after rebuild:**
- Total Caregivers: 139 (was 140; -3 merges +2 new)
- Client Accounts: 68 (51 known + 17 Not Known placeholders)
- Active Clients: 24 (derived from recent revenue)
- Total Revenue: R1,765,204 (corrected, was R1,554,103)
- Gross Margin: R1,055,584 (was R844,483)
- Roster Shifts: 1,619 (unchanged count, but 0 orphans now vs 1,224)

## [0.9.11] - 2026-04-11

### Added — Migration 007: clients table retired into persons

Migration 007 completes the persons unification by moving the 51
clean clients (post-dedup) into the `persons` table as
`person_type='patient,client'` and retiring the old `clients` table.
After this migration the `clients` table name no longer exists in
the active schema — it lives only as cold storage at
`clients_deprecated_2026_04_11`, preserved on disk as a last-resort
rollback artefact but never queried by any code path.

**Applies the Single Source of Truth standing rule (added to
C:\ClaudeCode\CLAUDE.md in this same release).** The four derived
fields on the old `clients` table (`first_seen`, `last_seen`,
`months_active`, `status`) were **NOT** carried across. They are
now computed from `client_revenue` at read time. Specifically:

- `first_seen` / `last_seen` — MIN/MAX of `client_revenue.month_date`
  per client, computed only when a report needs them
- `months_active` — COUNT(DISTINCT `month_date`), same
- `status` — Active if the client has any revenue row in the
  current or previous 2 calendar months, else Inactive. Derived
  live on every page that shows it.

This fixed a live drift: before migration 007, the dashboard reported
20 "active clients" based on the stale stored flag; after, it
correctly reports **34 active clients** computed from actual revenue.
The 14-client gap was the cumulative effect of the 2026-04-11
patient dedup repointing revenue rows to surviving clients without
refreshing the summary columns — caught by Ross during review of
migration 007.

### Columns actually copied across (non-derived, independent state)

```
account_number  VARCHAR(12) UNIQUE    -- billing identifier TCH-C0001 etc.
patient_name    VARCHAR(150)          -- care recipient when different from payer
day_rate        DECIMAL(10,2)         -- service config
billing_freq    VARCHAR(30)           -- service config
shift_type      VARCHAR(30)           -- service config
schedule        VARCHAR(50)           -- service config
billing_entity  VARCHAR(10)           -- NPC or TCH; renamed from `entity`
                                       to avoid the overloaded term
```

Only 3 of 51 clients (Berthe Botha, Darren Blumenthal, Julian Sam)
had any of the service-config fields populated — the rest are NULL
— but we carried the columns anyway so those three didn't need
re-entering. Expected later refactor (separate `client_engagements`
table) is deferred per Ross's earlier call.

### Rewiring — all FKs retargeted to persons

- `client_revenue.client_id` — FK constraint dropped and recreated
  pointing at `persons(id)` instead of `clients(id)`. 129 rows, all
  repointed to the new persons.id values via a temporary
  `persons._legacy_client_id` column used during the insert.
- `daily_roster.client_id` — same pattern. 419 matched rows
  repointed; the ~1,200 NULL-client_id rows left as-is (they were
  never matched at ingest and remain an orphan class outside the
  scope of this migration).
- `users.linked_client_id` — FK + column dropped entirely.
  Provisional wiring for future client self-service login, zero
  populated rows. Can be re-added via a future migration if/when
  client self-service becomes a real feature.
- `activity_log` — 66 of the 79 historical `entity_type='clients'`
  rows rewritten to `entity_type='persons'` with the new
  `persons.id`. Covers `client_merged` (13), `client_renamed` (5),
  and `client_patient_backfilled` (48). The remaining 13
  `record_deleted` entries point at loser client_ids that were
  deleted during the dedup — they have no surviving persons row
  to rewire to, so they retain `entity_type='clients'` and
  gracefully reject revert attempts via the whitelist (which no
  longer includes 'clients').

### tch_id assignment

Every new client row got a `tch_id` in the existing caregiver
format: `TCH-000141` through `TCH-000192`, continuing the
universal person sequence.

### Code changes — 6 files

Every read path that used to query `clients` now queries `persons`
filtered by `FIND_IN_SET('client', person_type)`:

| File | Change |
|---|---|
| `includes/activity_log_revert.php` | Temporary `'clients'` whitelist entry removed. Historical entries now point at `'persons'`. |
| `includes/permissions.php` | `getVisibleClientIds()` queries persons with FIND_IN_SET filter. The `role_id === 5` client self-service branch returns `[]` for now (the `linked_client_id` column was dropped; re-add when client self-service becomes real). |
| `templates/admin/dashboard.php` | Client count + "Active Clients" count both derived from persons + `client_revenue` — no stored flag read. |
| `templates/public/home.php` | Homepage "Active Clients" same derivation. |
| `templates/admin/report_drill_handler.php` | Billing drill-down client name lookup reads `full_name` from persons. |
| `templates/admin/reports/client_billing.php` | LEFT JOIN on persons with client-type filter; `client_status` derived in the PHP pivot loop from whether any of the last 3 months has income > 0, rather than SELECT-ed from a stored column. |

### Rollback artefacts on the server

- `database/backups/post_dedup_pre_migration_007_2026-04-11.sql`
  (432K, 457 lines) — full dump of persons + clients + client_revenue
  + daily_roster + caregiver_banking + name_lookup + caregiver_costs
  + caregiver_rate_history + activity_log + users, taken immediately
  before migration 007 ran.
- `~/public_html/tch_backup_pre_007_2026-04-11/` (19M) — full file
  copy of the prod webroot before the rsync.
- Migration 007 itself wraps everything in a transaction; any
  mid-flight failure auto-rolls back. Post-commit failure recovery
  goes via the backup dump above.

### Smoke tests

Sixteen smoke tests green (8 pages × 2 environments). Dashboard
stats verified against the DB:

| Stat | Value |
|---|---|
| Total Caregivers | 140 |
| Client Accounts | 51 |
| **Active Clients** | **34** (derived, was 20 stored — see above) |
| Total Revenue | R1,554,103 |
| Gross Margin | R844,483 |
| Roster Shifts | 1,619 |
| client_billing report rows | 51 (was 64 pre-dedup) |

### Standing rule added — Single Source of Truth

Added a new top-level section to `C:\ClaudeCode\CLAUDE.md`
applicable to every project Ross runs, not just TCH:

> If a value can be derived from another table on demand, do not
> store it as a column on a second table. Compute it in the query
> at read time. There is one version of the truth, and everything
> queries it.

Ross articulated this principle while reviewing migration 007 —
the 4 derived-field columns on `clients` that were about to be
copied to `persons` became the test case for dropping the pattern.
The standing order now applies cross-project, and a code review
(DQ0 in `docs/TCH_Ross_Todo.md`) is queued to walk every existing
table in the TCH schema looking for the same pattern.

## [0.9.10.1-dev] - 2026-04-11

### Fixed — Matrix reports pivot on canonical name, not denormalised source

The three matrix reports — `Client Billing by Month`,
`Caregiver Earnings by Month`, and `Days Worked by Month` — were
pivoting on denormalised `*_name` columns frozen into
`client_revenue`, `caregiver_costs` and `daily_roster` at ingest
time. That meant any merge, rename, typo fix or slash-split on the
underlying `clients` / `persons` row did **not** reflect on the
report — the old source string persisted as a ghost row.

Symptom after the 2026-04-11 patient dedup: `/admin/reports/client-
billing` was still showing 64 rows including `Andre Theron- monthly`,
`Gildenhyus`, `Angela/ Dimitri Paoadopoulos`, etc. — each with the
CORRECT post-merge account number (proof that the dedup itself
worked), but pivoted by the stale raw source string.

**Root cause:** each report's SQL selected `cr.client_name` /
`cc.caregiver_name` / `dr.caregiver_name` (the denormalised source
text) and the PHP pivot used that value as the matrix key. The
canonical name from the joined `clients` / `persons` row was
available via the LEFT JOIN but never read.

**Fix:** each report's SELECT now computes
`COALESCE(joined.canonical_name, raw.source_name) AS display_name`
and the PHP pivot keys on `$r['display_name']`. Orphan rows where
the FK join returns NULL (unmatched data) fall back to the raw
source name so nothing silently disappears.

Files touched (3):
- `templates/admin/reports/client_billing.php` — SELECT gains
  `COALESCE(c.client_name, cr.client_name) AS display_name`;
  pivot key changed to `$r['display_name']`; ORDER BY updated.
- `templates/admin/reports/caregiver_earnings.php` — SELECT gains
  `COALESCE(cg.full_name, cc.caregiver_name) AS display_name`;
  pivot key changed; ORDER BY updated.
- `templates/admin/reports/days_worked.php` — same pattern, plus
  the `GROUP BY` clause switched from `dr.caregiver_name` to
  `display_name` so caregiver_id + canonical name pairs collapse
  correctly into a single row.

**Verified on dev + prod:**

| Report | Before | After | Stale ghosts |
|---|---|---|---|
| client_billing | 64 rows (13 dedup ghosts) | **51 rows** | 0 |
| caregiver_earnings | 42 rows (latent bug) | 42 rows | 0 |
| days_worked | 42 rows (latent bug) | 42 rows | 0 |

The caregiver reports had zero visible symptoms today — no
caregiver has been renamed yet — but they carried the same latent
bug. Fixing now makes them safe against any future rename on a
`persons.full_name` value.

### Ops note

The reports were rsync'd dev → prod before this commit, so prod is
already running the fix. This commit is the after-the-fact code
checkpoint. Rollback is `git revert` + rsync the previous version
from `~/public_html/tch_backup_pre_persons_2026-04-11/` or from
the prior commit. No DB changes, no schema touched.

## [0.9.10-dev] - 2026-04-11

### Added — Persons unification + universal Activities & Tasks timeline

**Migration 006** (`database/006_persons_unification.sql`), wrapped in
a single transaction with an inline rollback block:

- `RENAME TABLE caregivers TO persons`. InnoDB silently re-targets
  every FK that referenced `caregivers(id)` — specifically:
  `caregiver_banking`, `caregiver_costs`, `caregiver_rate_history`,
  `daily_roster`, `name_lookup`, `attachments`. Column names on those
  tables (e.g. `daily_roster.caregiver_id`) are **intentionally left
  as-is** because they describe the *role* the person plays in that
  relationship, not the table they live in.
- `ADD COLUMN person_type SET('patient','caregiver','client') NOT NULL
  DEFAULT 'caregiver'` on `persons`. SET (not ENUM) so one row can hold
  multiple labels — in particular today's patients are also their own
  clients until a corporate payer arrives, so they will be marked
  `'patient,client'` in migration 007. Existing 140 caregiver rows
  backfill as `'caregiver'` via the DEFAULT.
- `CREATE TABLE activity_types` — lookup table (NOT an enum) so new
  activity types can be added by an admin via the config UI without
  a migration. Schema copied verbatim from Nexus CRM for cross-product
  alignment (see mailbox thread `activities-tasks-schema-2026-04-11`).
  Seven seed rows: Email, Phone Call, Meeting, Demo, Follow-up, Note
  (six Nexus-canonical) + System (TCH-specific, for auto-generated
  timeline entries).
- `CREATE TABLE activities` — universal Activities & Tasks timeline,
  polymorphic via `entity_type ENUM('persons','enquiries') + entity_id`.
  Schema matches Nexus CRM exactly: `activity_type_id` FK,
  `user_id` (author), `subject`, `notes` (body, NOT a JSON blob),
  `activity_date` (doubles as due date when `is_task=1`), `is_task`,
  `task_status ENUM('pending','completed','cancelled')`, `assigned_to`,
  `completed_at`, `is_test_data`, plus the usual timestamps.
- New admin page `config_activity_types` registered in `pages`
  (section=admin, sort_order=250) with Super Admin CRUD granted.
  The UI handler for this page is NOT in this release — the page
  row exists so the migration can wire permissions, the actual
  `/admin/config/activity-types` screen is queued for next session.

### Changed — Code aligned with new schema

Eleven files had every `FROM`/`JOIN`/`INTO`/`UPDATE caregivers`
switched to `persons`. Comments, marketing copy on `templates/public/
home.php`, terminal echo messages in seed scripts, and internal PHP
array labels (e.g. `$stats['caregivers']`) were deliberately left as-is
— only SQL fragments changed. Historical migrations (001, 003*, 004,
005) were NOT touched, per the standing rule that migration files are
frozen records of what was applied at the time.

| File | Change |
|---|---|
| `includes/activity_log_revert.php` | Whitelist for the single-field-revert / undelete helpers gains `'persons'` and `'clients'`. Keeps `'caregivers'` alongside for the brief cutover window; remove once confident no code path still passes that entity_type. `'clients'` is on the whitelist TEMPORARILY for the patient dedup exercise (see below) — remove when migration 007 drops the `clients` table. |
| `includes/permissions.php` | `getVisibleCaregiverIds()` now filters `WHERE FIND_IN_SET('caregiver', person_type)` so admins querying "all caregivers" don't accidentally pick up patient/client rows after the unification. |
| `database/seeds/ingest.php` | `INSERT INTO persons` (person_type defaults to 'caregiver'); `UPDATE persons SET standard_daily_rate`. Comment at top still references legacy schema history — left as-is. |
| `database/seeds/reconcile.php` | `LEFT JOIN persons cg ON dr.caregiver_id = cg.id` |
| `templates/admin/dashboard.php` | Total caregivers + placed-count queries filtered by `FIND_IN_SET('caregiver', person_type)` |
| `templates/admin/names.php` | Name Reconciliation page JOIN |
| `templates/admin/people_review.php` | 2× UPDATE, 2× FROM, logActivity entity_type changed from `'caregivers'` to `'persons'`, tranche dropdown, pending count |
| `templates/admin/report_drill_handler.php` | Drill-down caregiver name lookup |
| `templates/admin/reports/caregiver_earnings.php` | Tranche dropdown + JOIN |
| `templates/admin/reports/days_worked.php` | Tranche dropdown + JOIN |
| `templates/public/home.php` | Public homepage caregiver count filtered by `person_type` |

### Added — Patient dedup exercise (data cleanup, already executed)

Four one-shot helper scripts under `database/seeds/`, all already
run against the shared dev/prod DB during this session. They stay in
git as a historical record of what changed, not for re-use:

- `dedup_clients.php` — the CLI merge helper. Takes `--loser`,
  `--survivor`, `--reason`, optional `--survivor-name` (for same-step
  renames), optional `--dry-run`. Wraps the merge in a transaction:
  repoint `client_revenue.client_id` + `daily_roster.client_id` from
  loser → survivor, optionally rename survivor, log the merge as
  `client_merged`, then `activity_log_delete()` the loser so the full
  row is captured in the audit log (undeletable). Used 13 times.
- `dedup_recovery_2026-04-11.php` — one-shot recovery for a whitelist
  bug that affected the first 2 merges: the local edit adding `'clients'`
  to `activity_revert_supported_entity_types()` hadn't been deployed
  to the server yet, so `activity_log_delete()` rejected both loser
  rows. Result: `clients.id=3` ended up hard-deleted with no audit
  entry; `clients.id=6` was repointed but the row sat orphaned. The
  recovery script (a) backfilled a synthetic `record_deleted` audit
  entry for id=3 reconstructed from the pre-dedup backup,
  (b) properly deleted id=6 via the now-working helper, (c) no-op'd
  the Papadopoulos rename (already done inside the original successful
  transaction before the delete step failed).
- `rename_clients_round2.php` — one-shot for five `client_renamed`
  entries that don't involve a merge: typo fixes (Gildenhyus →
  Gildenhuys, Ishaan/Elizabth → Ishaan/Elizabeth) and word-order /
  suffix-strip cleanups (Oosthuizen- Weekly → Oosthuizen, Roux- Esme →
  Esme Roux, Webb- Sonja → Sonja Webb).
- `backfill_patient_names.php` — one-shot that populated every row
  where `patient_name` was NULL, using the slash-split rule:
  `"X / Y"` → client=X, patient=Y; everything else → patient=client.
  48 rows touched (7 splits + 41 mirrors); 3 rows were already
  non-NULL with genuine payer ≠ patient pairings (Berthe Botha /
  Anne Botha, Darren Blumenthal / Diane Blumenthal, Julian Sam /
  Sui Fon) and were left alone.

**Net dedup result:** 64 → **51 clean clients**; 129 revenue rows
preserved (0 orphans); 79 audit entries in `activity_log` with
entity_type='clients' covering every mutation. Every loser row is
recoverable via `activity_undelete()` from its `record_deleted`
audit entry.

### Pre-deploy backups (manual rollback artefacts)

Two snapshots were taken on the server before migration 006 runs, so
the dedup work is preserved if rollback is needed:

- `~/public_html/dev-TCH/dev/database/backups/post_dedup_pre_migration_006_2026-04-11.sql`
  — mysqldump of `caregivers` (140 rows), `clients` (51 clean rows),
  `client_revenue`, `daily_roster`, `caregiver_banking`, `name_lookup`,
  `caregiver_costs`, `caregiver_rate_history`, and `activity_log`.
  428K, 406 lines.
- `~/public_html/tch_backup_pre_persons_2026-04-11/` — full file-level
  copy of the prod webroot (`cp -a`), 19MB. Used to roll back prod
  code if the rsync + migration window exposes a bug.

An earlier backup, `pre_persons_unification_2026-04-11.sql`, is also
on disk and captures the state BEFORE the dedup exercise (64 clients,
with duplicates). That's a fallback rollback point of last resort —
restoring it would lose all the dedup work and is only appropriate
if both migration 006 rollback AND the post-dedup backup fail to
recover.

### Docs

- `docs/TCH_Ross_Todo.md`: new sections **Data Quality** (DQ1 id=47
  Morrison R0 income investigation, DQ2 seven slash-split rows needing
  human review) and **UI Requirements** (UI1 edit-client↔patient
  relationship on the patient record screen).
- `_global/keys/web-logins.md`: TCH section added (PROD SSH host,
  webroots, DB creds, `.env`-parsing caveat).

### Nexus CRM alignment

The `activities` + `activity_types` schema was derived by a mailbox
exchange with the Nexus CRM agent earlier in this session (thread
`activities-tasks-schema-2026-04-11` in
`_global/output/agent-messages/`). Field names, enum values, and seed
activity-type rows all match Nexus CRM verbatim so future shared
tooling — e.g. the centralised reporter widget on the Hub
(FR-0065) — can target one canonical schema across both products.

### NOT in this release (queued)

- Admin UI for `/admin/config/activity-types` CRUD
- Admin UI for creating / logging activities & tasks on persons
- Timeline rendering on the person detail page (unified view of
  `activities` + `activity_log` entries scoped to that person)
- Migration 007 — moving the 51 clean clients into `persons` as
  `person_type='patient,client'`, repointing `client_revenue.client_id`
  + `daily_roster.client_id` to new person IDs, and renaming `clients`
  to `clients_deprecated_2026_04_xx` (NOT dropping, per Ross's safety
  instinct)

## [0.9.9.2] - 2026-04-11 — SHIPPED TO PROD

### Prod deploy

**Production deploy of v0.9.2-dev through v0.9.9.2-dev as a single
block of eleven dev increments built across one long working session.**

This deploy brings to prod:
- Inline field-level diff viewer on the activity log list
- Failed logins, account lockouts, and email sends now logged to
  `activity_log`
- Single-field revert from the activity log detail page (A2)
- Whole-record rollback with preview (A3)
- Undelete infrastructure + `activity_log_delete()` helper (A4)
- In-app Bug/FR reporter widget proxying to the Nexus Hub, with
  confirmation email and activity log integration
- Short description field on the reporter widget
- Shared sortable + filterable table component (`tch-data-table`)
  applied to every admin list page
- Three matrix reports (caregiver earnings, client billing, days
  worked) rebuilt as caregiver/client x 12-month grids with click-
  through drill-down
- Drill-down honest empty state for the 78% of clients whose roster
  entries can't be linked yet (tracked as FR-0069)

Full detail: `docs/sessions/2026-04-11-prod-deploy-v0.9.9.2.md`

### Pre-deploy state

- Dev branch at `b8d0671` (v0.9.9.2-dev)
- Main branch at `966755a` (v0.9.1)
- 12 commits on dev not yet on main
- No schema migrations, no DB table backup needed

### Deploy steps executed

1. **Server-side prod files backup:**
   `cp -a ~/public_html/tch ~/public_html/tch_backup_pre_v0.9.9.2_20260411_131926`
   (19MB)

2. **Git: fast-forward main, tag, push** — 12 commits merged as a
   single block via `git merge dev --ff-only`, tagged `v0.9.9.2`,
   both pushed to GitHub.

3. **Prod `.env` updated** — added `NEXUS_HUB_URL`,
   `NEXUS_HUB_PROJECT_SLUG`, and `NEXUS_HUB_TOKEN` (the same
   TCH-scoped token used on dev). Same file is gitignored and was
   NOT rsynced.

4. **Server-side rsync** `~/public_html/dev-TCH/dev/` →
   `~/public_html/tch/` (excluding `.env`, `.git/`, backups,
   intake outputs). 35 files transferred, ~330KB.

5. **Server-side `php -l` clean** on every PHP file touched
   (16 files).

6. **Smoke tests on prod** (`https://tch.intelligentae.co.uk`):
   - `/` → 200
   - `/login` → 200
   - `/admin` → 302 (redirects unauth'd users to login)
   - `POST /ajax/report-issue` → 401 (auth gate working)

### Outstanding post-deploy actions for Ross

1. **Purge CDN cache** on StackCP > CDN > Edge Caching so anonymous
   users hit the latest CSS/JS.
2. **Test A1.5, A2, A3, A4 on prod** — these features didn't get a
   full browser smoke test on dev before shipping (they lint clean
   and are defensive / click-triggered, so the risk was judged
   acceptable).

### Rollback

Server-side rollback (non-destructive):
```
rsync -av --delete --exclude='.env' \
  ~/public_html/tch_backup_pre_v0.9.9.2_20260411_131926/ \
  ~/public_html/tch/
```

Git rollback (requires Ross's explicit approval) — reset main to
`966755a`, force-push, delete the `v0.9.9.2` tag.

## [0.9.9-dev] - 2026-04-11

### Added — Shared sortable/filterable table component + matrix reports (FR-0056, FR-0057, FR-0066)

Single commit closing three real FRs and shipping a cross-cutting UI
upgrade that lands on every admin table in TCH.

**1. Shared sortable + filterable table component (`tch-data-table`)**

New JS library at `public/assets/js/tch-table.js` (~220 lines, no
framework). Opt-in by adding `class="tch-data-table"` to any
`<table>` — the library will:

- Make every column header clickable to toggle sort asc → desc → off.
  Sort indicators (↕ idle, ▲ asc, ▼ desc) appear next to the header
  text.
- Auto-detect numeric vs text sorting. Currency cells like
  `R12,345` are parsed as 12345 and sorted numerically; text cells
  fall back to case-insensitive string compare.
- Insert a filter row below the header with one text input per
  non-excluded column. Typing in any input hides rows whose
  corresponding cell doesn't contain the typed text
  (case-insensitive). Multiple filters AND together.
- Handle drill-down child rows correctly: rows with class
  `drill-row` or `tch-drill-row` stay with their logical parent
  during sort and inherit parent visibility during filter.
- Handle total rows correctly: rows with class `total-row` or
  `tch-total-row` always stay at the bottom and are never filtered.

Per-column opt-outs:
- `data-sortable="false"` — this column is not sortable
- `data-filterable="false"` or `data-no-filter` — this column gets no
  filter input (rendered as an empty cell in the filter row)

Loaded automatically on every admin page via
`templates/layouts/admin_footer.php` when `isLoggedIn()` is true.

Matching CSS added to `public/assets/css/style.css` under the
heading "Shared sortable + filterable table component". Hover
highlight on sortable headers uses the TCH teal (#10B2B4). Filter
inputs match the existing form input style with a 2px teal focus
ring.

**2. Matrix reports (FR-0056, FR-0057, FR-0066)**

All three reports rewritten from their old flat shape ("one row per
entity-per-month") to the matrix shape Ross asked for: one row per
entity, 12 month columns (current + previous 11) plus a total column.

- `templates/admin/reports/caregiver_earnings.php` — caregiver ×
  month earnings grid. Data source: `caregiver_costs` joined to
  `caregivers`. Tranche dropdown stays as a server-side pre-filter.
- `templates/admin/reports/client_billing.php` — client × month
  income grid. Data source: `client_revenue` joined to `clients`.
- `templates/admin/reports/days_worked.php` — caregiver × month
  days-count grid. Data source: `daily_roster` aggregated by
  caregiver + month (`COUNT(*)` per month bucket).

All three share the same pattern: fetch flat rows from the source
table restricted to the 12-month window, pivot in PHP into a
`caregiver_name => [month_key => amount, total]` map, render as a
matrix with clickable cells. The `tch-data-table` class is applied
so sort + filter work out of the box (name column filterable, month
columns sortable but not filterable per Ross's agreed scope —
numeric range filters are a separate future enhancement).

**3. Drill-down AJAX handler**

New `templates/admin/report_drill_handler.php` exposed at
`GET /ajax/report-drill?report={earnings|billing|days}&entity_id=<id>&month=<YYYY-MM>`.

- Returns a rendered HTML fragment (not JSON) — the reports drop
  it into `#drill-body` via `innerHTML` on click. Simple and
  avoids JSON serialisation of an HTML table.
- Per-report permission gate: each report type maps to its own
  page-permission code (`reports_caregiver_earnings`,
  `reports_client_billing`, `reports_days_worked`) so a user who
  can see a matrix can also drill into its cells.
- Session + auth + input validation (regex on month, int on
  entity_id).
- For `earnings` and `days` reports the drill shows every
  `daily_roster` row for that caregiver in that month: date, day,
  client, rate + totals footer.
- For `billing` the drill pivots on client — same daily_roster
  rows but from the client side, showing date / day / caregiver /
  rate.

The drill-down panel on each matrix page is a single `<div>` below
the table that slides into view on first click, loads via `fetch`,
and offers a Close button to dismiss.

**4. Retrofit — every admin list page gets sort + filter**

Added `tch-data-table` to the `<table>` class list on:

- `templates/admin/activity_log.php` (activity log list)
- `templates/admin/users_list.php` (user list)
- `templates/admin/enquiries.php` (enquiry inbox)
- `templates/admin/names.php` (name reconciliation — both the
  unmatched-billing-names table and the main canonical list)
- `templates/admin/people_review.php` (pending-person queue)
- `templates/admin/email_log_list.php` (email outbox)
- `templates/admin/roles_list.php` (roles list)

Each page now has per-column text filters and click-to-sort
headers with zero additional per-page code.

**Not retrofitted (detail pages where sort/filter doesn't make
sense):**

- `templates/admin/activity_detail.php` — the Was/Now diff table
  (already structured, single-record context)
- `templates/admin/users_detail.php` — single-user detail
- `templates/admin/roles_permissions.php` — CRUD permission matrix
  editor (user is editing, not browsing)

**5. Pre-existing filter styling — verified consistent**

Ross asked me to check whether the existing server-side filter
dropdowns (`.report-filters` block at the top of each report page)
already look the same across pages. Answer: yes, they already use
the shared `.report-filters` / `.filter-group` / `.report-filters
select` rules in `style.css` (lines 659–706). No cleanup needed.

**Hub record closures**

FR-0056, FR-0057, and FR-0066 will be PATCHed to
`status: implemented` on the Hub as part of this deploy, with a
note pointing at this changelog entry and the dev URL.

### Explicitly pushed back on / deferred

- **Numeric range filters on the matrix month columns.** A text
  "contains" filter on a number column is bad UX — typing "500"
  matches 500, 5000, 15000, 25000 and so on. I opted to make the
  name/label columns filterable and the month columns sortable
  only. If Ross wants real numeric filters later (">=10000",
  "<5000", between), that's a clean future enhancement and a
  different component.
- **Column-hide/show toggle.** Not asked for; skipped.
- **CSV / Excel export from the matrix.** Not asked for; skipped.
- **Server-side sort + pagination on the new matrix reports.** All
  three pivot in PHP and render every caregiver/client on one
  page. Fine at TCH's volume (123 caregivers × 12 months = ~1500
  cells per page). If the lists grow to 10k+ rows we'll revisit.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via scp:
  `public/assets/js/tch-table.js` (new),
  `public/assets/css/style.css`,
  `public/index.php`,
  `templates/layouts/admin_footer.php`,
  `templates/admin/reports/caregiver_earnings.php`,
  `templates/admin/reports/client_billing.php`,
  `templates/admin/reports/days_worked.php`,
  `templates/admin/report_drill_handler.php` (new),
  `templates/admin/activity_log.php`,
  `templates/admin/users_list.php`,
  `templates/admin/enquiries.php`,
  `templates/admin/names.php`,
  `templates/admin/people_review.php`,
  `templates/admin/email_log_list.php`,
  `templates/admin/roles_list.php`.
- Server-side `php -l` clean on every PHP file.
- No schema migrations.
- Not yet promoted to prod.

## [0.9.8-dev] - 2026-04-11

### Added — Short description field on the in-app reporter + B3 migration

Two threads merged into a single commit:

**1. Short description field on the reporter widget (B5)**

Ross asked for a one-line short-description field on the reporter so
the submitter controls the Hub ticket title explicitly, instead of the
handler auto-generating it from the first 80 chars of the long
description.

What the user sees: a new single-line text input **above** the long
description textarea, labelled **"Short description"** with the hint
`(optional — this becomes the Hub title)`. 140-char max. Optional —
when left blank, the handler falls back to the existing auto-generated
`[slug] Type: first-80-chars-of-description` title format. When
filled, the title on the Hub is exactly `[slug] {user's short
description}` (keeping the `[slug]` prefix because the duplicate-
detection logic matches on it).

Also changed: the panel now focuses the **short description** input
on open (instead of the long description textarea) because the short
description is the most important field — user types a one-line title
first and tabs into the textarea for more detail if they want.

Files touched:
- `public/assets/js/reporter.js` — new `<input>` element, new
  reference in the init block, send value as `short_description` in
  the POST payload, clear on reset, focus on panel open.
- `public/assets/css/reporter.css` — merged the `textarea` and
  `input[type="text"]` style rules so the new input visually matches
  the textarea (border, padding, focus state, placeholder colour).
- `templates/admin/report_issue_handler.php` — new `$shortDescription`
  variable parsed from JSON body, length-capped at 140. Title-
  building branch now prefers `$shortDescription` when set and falls
  back to the auto-generated snippet otherwise. `short_description`
  is also added to the `activity_log.after_json` so the TCH audit
  trail preserves the user's exact wording.

Nexus CRM's reporter does NOT have this field yet. A mailbox message
has been sent to the Nexus CRM agent asking them to mirror the change
locally. Until they do, the two projects' reporters will diverge — an
acceptable trade-off given the centralisation FR (below) is already
queued.

**2. B3 — seven Person Database FRs migrated from docs to the Hub**

Ross decided that from now on, every TCH bug and FR lives on the
Nexus Hub, not in markdown checklists in `docs/`. As a one-off
migration step, I scanned `docs/TCH_Ross_Todo.md` and
`docs/TCH_Plan.md` for any items that were really bugs or FRs (not
blockers, not planning items, not historical context) and ported
them to the Hub via direct API POST.

Source: the "Person Database Build" section of
`docs/TCH_Ross_Todo.md`. Seven items migrated:

| Old | Hub ref | Priority | Title |
|-----|---------|----------|-------|
| 11  | FR-0059 | medium   | System config admin page for all lookup lists |
| 12  | FR-0060 | medium   | Status promotion gates (required fields per status) |
| 13  | FR-0063 | low      | Referrer / affiliate model for paid referrals |
| 14  | FR-0061 | medium   | Field-level role-based edit permissions |
| 15  | FR-0058 | **high** | Person record card view matching Tuniti PDF layout |
| 16  | FR-0062 | medium   | Retire name_lookup table once all PDFs matched |
| 18  | FR-0064 | low      | Replace placeholder portraits with full-quality photos |

Each FR carries a footer note stating it was migrated from the Todo
doc on 2026-04-11 at Ross's request. The matching section in
`docs/TCH_Ross_Todo.md` has been replaced with a link table pointing
at the Hub refs — it's no longer a source of truth for these items,
just a cross-reference.

**What did NOT get migrated to the Hub, and why:**

- **`docs/TCH_Plan.md` items** — that's a build-plan roadmap (phases
  A/B/C/D), not a tracker. Stays as-is.
- **"Blocking Next Session" / "Blocking Later Phases" items** — those
  are waiting-on-Ross-for-data items (paper form, service list,
  training data, etc.). Not bugs, not FRs, they're blockers. Stay as
  a markdown checklist.
- **"Requires Tuniti Approval / Clarification" (~30 items)** — data
  defects in Tuniti's intake PDFs. TCH can't fix them; only Tuniti
  can by re-issuing the forms. They're effectively reference notes
  for me so I know what weird shapes to expect in the data.
  Ross explicitly confirmed they stay in the markdown.
- **Historical "done" sections (v0.7.0 → v0.9.1, A1–A4, etc.)** —
  historical record of completed work. Stays.

**3. B6 — FR-0065 queued on the Hub for centralising the widget**

Filed an FR on the Hub: **FR-0065 — Centralise the in-app Bug/FR
reporter widget on the Hub**, priority medium. The work is to host
one canonical widget CSS + JS on the Hub itself so all projects link
to it instead of duplicating the code.

**Trigger:** BEFORE any third project is onboarded to the in-app
reporter. TCH and Nexus CRM can tolerate two copies that drift
slightly (we just proved it — v0.9.8-dev's short-description field
will land in Nexus CRM shortly via the mailbox loop). A third copy
is the breaking point.

**FR-0065 is misfiled on the TCH project instead of the Nexus Hub
project.** Reason: TCH's API token is scoped to TCH only. I can
GET from any project (scope enforcement is loose on the list
endpoints) but I can't POST into another project's backlog. Moving
it to the nexus-hub project requires the Hub web UI and a Super
Admin. Ross can do that at his convenience; not urgent.

**Incidental Hub bug spotted:** I tried to PATCH FR-0065 to add a
note in `implementation_notes`. The PATCH call returned empty body
with no error, and the field remained null. Likely cause: the Hub's
PATCH allowlist accepts `implementation_notes` as the body key but
the actual DB column is `impl_notes` — so the UPDATE SET fails
silently when MySQL doesn't find the column. This is a Nexus Hub
bug, not TCH. Worth raising with the Hub agent.

**Files touched in this commit:**

- `public/assets/js/reporter.js` — short description field
- `public/assets/css/reporter.css` — input + textarea style merge
- `templates/admin/report_issue_handler.php` — title-building branch
- `docs/TCH_Ross_Todo.md` — Person Database section replaced with
  Hub cross-references; B3 marked done; B5 + B6 added
- `CHANGELOG.md` — this entry

**Mailbox activity (separate from the commit, lives outside the
repo):**

- `C:\ClaudeCode\_global\output\agent-messages\2026-04-11-1200-tch-to-nexus-crm-add-short-description-field.md`
  — detailed change spec for Nexus CRM agent to mirror the short
  description field in their reporter copy.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via scp.
- Server-side `php -l` clean on the handler.
- No schema migrations.
- Not yet promoted to prod.

## [0.9.7.1-dev] - 2026-04-11

### Fixed — Reporter handler bugs caught in smoke test

Two silent bugs in v0.9.7-dev's handler:

1. Missing `initSession()` — handler was reading `$_SESSION` before PHP
   had started the session, so `isLoggedIn()` always returned false and
   every submission bounced with "Not authenticated" even from a valid
   admin browser session. Fix: call `initSession()` at the top of the
   handler.

2. Missing `require_once includes/mailer.php` — the `Mailer` class
   wasn't loaded when the handler ran, so `Mailer::send()` threw
   "Class not found". The defensive try/catch around the email call
   (which catches `Throwable`) swallowed the Error, so the Hub record
   and activity log entry both landed but no email was sent and the
   widget happily showed a success state. Fix: require the mailer at
   the top of the handler, same pattern as every other handler that
   uses it.

Both bugs were caught only because I checked `email_log` directly
after the smoke test and found nothing. The defensive patterns
(graceful auth error, swallowed exception) masked the failures from
the user-facing flow — which is exactly when silent misses happen.

## [0.9.7-dev] - 2026-04-11

### Added — In-app Bug/FR reporter → Nexus Hub

A floating **Help** button now appears on every admin page in TCH (bottom-
right). Click it → slide-in panel with a type toggle (Bug / Feature),
impact toggle (Fatal / Improvement), page context (auto-captured),
description textarea, and a Submit button. Submissions POST to a
server-side proxy at `/ajax/report-issue` which forwards to the Nexus
Hub API at `https://hub.intelligentae.co.uk` using a server-side token
(never exposed to the browser).

**Widget features:**

- **Page context auto-captured** — `data-page-slug` and
  `data-page-title` are injected on the `<body>` tag by
  `templates/layouts/header.php` when the user is logged in, using the
  existing `$activeNav` and `$pageTitle` variables each admin page
  already sets.
- **Duplicate detection** — before creating a new Hub record, the proxy
  GETs the Hub's list endpoint for open bugs/features in the `tch`
  project and scans for an item whose title contains `[{pageSlug}]` or
  whose description contains `Page: {pageSlug}`. If a match exists and
  the user didn't click "No — submit as new", the widget surfaces a
  yellow warning with Yes (view existing) / No (submit anyway) actions.
- **Confirmation email** — on successful submission, TCH's mailer sends
  a plain-text confirmation to the reporter with the Hub reference
  (e.g. `BUG-0042`), the page, severity, and a link back to the Hub
  issue view. Template lives at `templates/emails/report_confirmation.php`
  and follows the same `$subject + $body` convention as the existing
  invite/reset emails.
- **Activity log integration** — per the standing order in
  `C:\ClaudeCode\CLAUDE.md`, every bug/FR submission writes an entry to
  TCH's own `activity_log` with `action = 'bug_reported'` or
  `'feature_requested'`, `entity_type = 'nexus_hub'`, `entity_id =
  <Hub numeric id>`, and `after_json` carrying ref / type / severity /
  page / issue_url. So "who reported what, when, from where" is
  answerable from TCH alone without visiting the Hub.
- **Graceful failure** — if the Hub is unreachable, the widget shows a
  red inline error and the user can retry. If the Hub token isn't
  configured, the proxy returns 503 with a clear "ask Ross for the
  token" message. Network errors surface as "network error — check
  your connection".
- **Admin-only** — widget only renders for logged-in users via an
  `isLoggedIn()` check in `header.php` (CSS + globals) and
  `admin_footer.php` (JS). Public pages (home, enquiry form, login)
  don't load it.
- **No Quick Links menu** — TCH v1 skips the Nexus CRM reporter's
  configurable Quick Links sub-menu; the Help button opens the panel
  directly.

**Safety / security layers:**

1. **Server-side proxy holds the token.** The browser never sees
   `NEXUS_HUB_TOKEN`. Only `templates/admin/report_issue_handler.php`
   reads it, via the `NEXUS_HUB_TOKEN` constant defined in
   `includes/config.php` from `.env`.
2. **Auth gate** — handler returns 401 if `isLoggedIn()` is false.
3. **CSRF gate** — handler reads `X-CSRF-Token` header and calls
   `validateCsrfToken()`. Returns 403 on mismatch.
4. **Input whitelist** — `type`, `severity` are whitelisted to known
   values; `description`, `page_slug`, `page_url`, `page_title` are
   trimmed and length-capped.
5. **Real user identity** — uses `currentRealUser()` not
   `currentUser()`, so an impersonated session reports as the real
   human at the keyboard.
6. **Scoped token recommended** — the TCH Hub token will be scoped to
   the `tch` project in the Hub's token admin UI, so even if the
   token leaks it cannot touch other projects' data.

**Config:**

New constants in `includes/config.php`, loaded from `.env`:
- `NEXUS_HUB_URL` — defaults to `https://hub.intelligentae.co.uk`
- `NEXUS_HUB_PROJECT_SLUG` — defaults to `tch`
- `NEXUS_HUB_TOKEN` — defaults to empty; must be set in `.env` for the
  reporter to actually submit. When empty, the handler returns 503
  with a clear message, so the UI stays functional as a preview.

`.env.example` updated with the three new keys and a comment explaining
how to generate the token in the Hub's Super Admin UI.

**API contract** (documented for future maintenance):

- Hub endpoint for creation: `POST ?page=api&resource=bugs` (or
  `features`). No `&action=create` URL param — the Hub dispatches on
  HTTP method alone.
- Request body: `{"project": "tch", "title": "...", "description": "...",
  "priority": "low|medium|high|critical"}`. Note: the Hub reads
  `project`, NOT `project_slug`. Nexus CRM's reporter sends
  `project_slug` — that's a latent bug in Nexus CRM (tracked separately
  by the Nexus CRM maintainer); it only "works" there because their
  token is project-scoped so the body param is ignored entirely. TCH
  sends `project` correctly from day one.
- Severity → priority mapping: `fatal → high`, `improvement → low`.
- Response envelope on success: `{"ok": true, "data": {"ref":
  "BUG-0042", "id": 123, "status": "open"}}`.
- Hub issue view URL: `/?page={bugs|features}&action=view&id={id}`
- No rate limits, no attachments in the API (v1).

**Files added:**
- `public/assets/css/reporter.css` — widget styles, TCH palette
- `public/assets/js/reporter.js` — widget logic, no framework dependency
- `templates/admin/report_issue_handler.php` — server-side proxy
- `templates/emails/report_confirmation.php` — confirmation email
  template

**Files modified:**
- `includes/config.php` — three new `NEXUS_HUB_*` constants
- `.env.example` — documented the three new keys
- `templates/layouts/header.php` — body data attrs + CSS link + CSRF/
  base-URL globals, all conditional on `isLoggedIn()`
- `templates/layouts/admin_footer.php` — conditional reporter.js include
- `public/index.php` — new `ajax/report-issue` route

**Explicitly NOT changed:**

- `activity_log` schema
- `logActivity()` signature
- Existing mailer, auth, CSRF helpers
- Public layout / public pages (no widget there)
- Permission model (reporter is open to every logged-in user; if we
  later want to restrict it to specific roles, add a `userCan` check
  in the handler — not needed for v1)

### Outstanding for Ross before this ships

1. **Generate the Hub API token** in the Hub web UI at
   `?page=tokens&action=create`, label it `TCH Agent`, scope it to the
   `tch` project, and paste the plain token to me once.
2. I will add `NEXUS_HUB_TOKEN=<paste>` to the dev server's `.env`
   (gitignored), then smoke-test end-to-end.
3. Until the token is set, the widget is live and clickable on dev,
   but submitting will return a graceful 503 with a
   "Hub integration not configured" error. You can open the panel to
   see the look and feel before the token exists.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via scp. Created the
  new `public/assets/js/` directory on the server first (didn't exist).
- Server-side `php -l` clean on all six PHP files.
- No schema migrations.
- Not yet promoted to prod — held for Ross to test on dev and provide
  the token.

## [0.9.6-dev] - 2026-04-11

### Added — Activity log undelete (A4, Level 3) + standard delete helper

Third and final undo level. A4 has two halves:

**1. `activity_log_delete()` — the standard delete helper.**

Any future delete handler in TCH must call this helper instead of running
`DELETE FROM …` directly. The helper loads the full row, runs the DELETE
inside a transaction, then writes a `record_deleted` activity_log entry
with the captured row as `before_json`. That captured row is what makes
undelete possible. This pattern is now codified in the global
`CLAUDE.md` so future sessions on any project do the same thing.

Usage:
```php
$result = activity_log_delete(
    'enquiries',          // entity_type (must be in whitelist)
    (int)$enqId,
    'enquiries',          // page_code for the audit entry
    'Deleted enquiry #' . $enqId . ' (' . $reason . ')'
);
if (!$result['ok']) { /* handle failure */ }
```

**2. Undelete UI on the activity detail page.**

- When a log entry has `action = 'record_deleted'` and all the gates
  align, a green **Undelete this record…** button appears next to the
  Back button.
- Click it → JS confirm dialog (with plain-English explanation of what
  *will* and *won't* be restored) → re-inserts the row with its original
  id, under the same safety envelope as A3.
- On success, a green flash: "Record restored. A new audit entry records
  the undelete."
- On failure (id already occupied, column schema drift broke the insert,
  etc.), a red flash explaining what stopped it.
- The original `record_deleted` entry is preserved. The undelete itself
  is recorded as a separate `record_undeleted` entry so both events are
  part of the audit trail.

**Safety layers (same envelope as A3):**

1. Super Admin only (`isSuperAdmin()`).
2. CSRF token validated on the POST.
3. Entity whitelist — same `activity_revert_supported_entity_types()`
   map as A2/A3: users, enquiries, caregivers, name_lookup.
4. **PK-collision check** — refuses to undelete if the original id has
   since been taken by a new row, to avoid duplicate-key collisions.
5. **Schema drift handling** — any column in `before_json` that no
   longer exists on the table is silently dropped from the INSERT, and
   its name is surfaced in the success flash ("skipped N obsolete
   fields: …") so it's visible to the admin but doesn't block the
   restore. If a surviving NOT NULL column is missing from the capture,
   the INSERT fails and the error is surfaced verbatim.
6. **Transactional INSERT** — runs inside `beginTransaction() / commit()`;
   partial failure rolls back cleanly.

**Hard limitations — stated loudly in the UI, CHANGELOG, and confirm dialog:**

- **Undelete only works for records deleted AFTER v0.9.6-dev goes live.**
  There are no pre-existing `record_deleted` entries in TCH today, so
  the button has nothing to act on until some code path starts calling
  `activity_log_delete()`.
- **Only the primary row is restored.** Related/child records that were
  cascade-deleted alongside the original stay gone. If an enquiry had
  notes in a child table with `ON DELETE CASCADE`, those notes are lost
  and the restored enquiry comes back empty.
- **Auto-increment counters are not reset.** MySQL is fine with
  re-inserting the same id — the next new insert just gets a higher id —
  but the restored row sits in a gap in the sequence.
- **The restored row keeps its original `created_at` / `updated_at`
  timestamps.** The fresh `record_undeleted` audit entry captures when
  the restore happened and who triggered it.

**What changed in the code:**

- `includes/activity_log_revert.php` — two new functions appended:
  * `activity_log_delete(string $entityType, int $entityId, string $pageCode, string $summary): array`
  * `activity_undelete(int $logId): array`
  ~200 lines of additions.
- `templates/admin/activity_detail.php`:
  * Top-of-page handler now dispatches on four actions: `revert_field`
    (A2), `apply_rollback` (A3), **`undelete` (A4)**, and the
    `?preview_rollback=1` preview path.
  * New `$showUndeleteButton` gate.
  * New green **Undelete this record…** button in the back-nav bar,
    only rendered when the log entry is a `record_deleted` for a
    whitelisted entity type and the user is Super Admin.
  * `$showRollbackButton` now also excludes `record_deleted` and
    `record_undeleted` actions (rollback doesn't make sense on either).
- `C:\ClaudeCode\CLAUDE.md` (global standing orders): the transactional
  audit section now explicitly says **never call `DELETE FROM …`
  directly — use the project's delete helper**. This is the rule for
  every project, not just TCH.

**Explicitly NOT changed:**

- `activity_log` schema — no new columns.
- `logActivity()` signature.
- No existing delete handlers were modified (there aren't any in TCH).
  The moment a delete handler is needed, it will use the helper by
  standing rule.
- A2 and A3 behaviour — unchanged.
- Permission model — reuses `isSuperAdmin()` from `includes/auth.php`.

### Testing note

Because TCH currently has no user-facing delete paths, A4 cannot be
smoke-tested end-to-end yet — there are no `record_deleted` log entries
to undelete. The feature is fully wired and will light up the moment
`activity_log_delete()` is called from anywhere. When the first delete
UI lands (likely on enquiries, to clean up spam submissions from the
public form), the Undelete button on that deletion's activity log
entry will be the real end-to-end test.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via scp:
  `includes/activity_log_revert.php`,
  `templates/admin/activity_detail.php`.
- Server-side `php -l` clean.
- No schema migrations.
- Not yet promoted to prod.

## [0.9.5-dev] - 2026-04-11

### Added — Activity log whole-record rollback (A3, Level 2)

Second of the three undo levels. A3 lets a Super Admin restore an entire
record to the state it held just before a given activity log entry was
applied — not just a single field. It's a two-step inline flow: click
"Restore whole record to this point" on the activity detail page, review
an in-page preview that lists every field that will change and loudly
flags any intermediate edits that will be discarded, then apply.

**What the user sees on `/admin/activity/{id}`:**

- When the gates align (Super Admin + supported entity type + log entry
  is not itself a revert or rollback + there's a before-snapshot), an
  amber **Restore whole record to this point…** button appears next to
  the Back button.
- Clicking it opens a **Rollback preview** panel inline above the normal
  detail. The panel lists:
  - The entity being restored and the log entry being rolled back
    through.
  - A **prominent red warning** listing any fields that have been edited
    in more than one log entry in the range — with the count of touching
    entries — so Ross can see exactly what newer work would be lost.
  - A quiet footnote listing any synthetic diff fields that were dropped
    from the plan (e.g. `note_appended` on enquiries) because they're
    not real columns on the target table.
  - A **Current → After rollback** table showing every field that will
    change, tinted red/green.
  - An **Apply rollback** button (amber, matches the warning palette)
    and a Cancel button.
- Apply triggers a JS confirm dialog, then POSTs back to the same URL.
  On success, a green flash appears: "Rolled back N field(s). A new
  audit entry records the rollback."
- The original log entry is never mutated. The rollback itself is
  recorded as a single new `record_rolled_back` entry carrying the
  current → target diff, so it renders with the exact same machinery
  as any other field-level change.

**Algorithm for "state at the time of log entry X":**

For each field touched in any log entry with id ≥ X for the same
entity, the target value is the **earliest** `before_json[field]` seen
in the range — because the oldest `before` is the value the field held
before it was first touched in that window, which by definition is
state-at-time-of-X. Fields untouched from X onwards are left alone (no
action needed; they're still at their at-time-of-X values). Fields
touched more than once in the range trigger the intermediate-edit
warning.

**Safety layers (stricter than A2):**

1. **Permission gate** — `isSuperAdmin()`. Admin and Manager can revert
   single fields (A2) but cannot roll back a whole record. Rationale:
   A3 can discard newer work on purpose, so the bar is higher.
2. **CSRF token** — validated on the apply POST.
3. **`confirmed=1` guard** — the apply POST must carry `confirmed=1`
   (set by the hidden field in the preview form). Prevents accidental
   apply from outside the preview UX.
4. **Preview required** — the plan is only recomputed-and-applied when
   the user goes through the preview flow. There is no one-click
   rollback button.
5. **Plan is recomputed at apply time** (not trusted from POST body) to
   avoid time-of-check-to-time-of-use drift — if the record changed
   between preview and apply, the fresh plan wins.
6. **Entity whitelist** — same `activity_revert_supported_entity_types()`
   map as A2: users, enquiries, caregivers, name_lookup.
7. **Column whitelist** — every field in the plan is validated against
   `INFORMATION_SCHEMA.COLUMNS` for the target table. Synthetic diff
   fields are silently dropped from the UPDATE (and listed in the
   preview footnote).
8. **Record-exists check** — if the target row has been deleted, the
   rollback refuses with the same "use A4 once built" message as A2.
9. **Transactional UPDATE** — the multi-field UPDATE runs inside a
   `beginTransaction() / commit()` block; partial failures rollback.
10. **Suppressed on revert/rollback entries themselves** — clicking
    into a `field_reverted` or `record_rolled_back` entry does not show
    the button (to avoid chained-rollback confusion).

**What changed in the code:**

- `includes/activity_log_revert.php` — new functions
  `activity_rollback_compute_plan(int $logId): array` and
  `activity_rollback_apply(int $logId): array`. Appended to the same
  helper file as A2 because they share the whitelist and column
  validation. ~200 lines of additions.
- `templates/admin/activity_detail.php`:
  - Top-of-page handler now dispatches on three actions:
    `revert_field` (A2), `apply_rollback` (A3), and the GET-with-
    `?preview_rollback=1` preview path.
  - New `$showRollbackButton` and `$showRollbackPreview` gates driving
    the UI render.
  - New amber button in the back-nav bar.
  - New rollback-preview panel rendered inline above the existing
    metadata panel.

**Explicitly NOT changed:**

- `activity_log` schema — no new columns.
- `logActivity()` signature.
- A2 behaviour (single-field revert) — unchanged.
- List view (`/admin/activity`) — rollback is detail-page only.
- Permission model — reuses `isSuperAdmin()` from `includes/auth.php`,
  no new permission row required.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via scp:
  `includes/activity_log_revert.php`,
  `templates/admin/activity_detail.php`.
- Server-side `php -l` clean.
- No schema migrations.
- Not yet promoted to prod.

## [0.9.4-dev] - 2026-04-11

### Added — Activity log single-field revert (A2, Level 1)

The activity log now supports **reverting a single field** of a record back
to the value it held before a specific logged change. This is the first
of three undo levels Ross scoped (A2 single-field, A3 whole-record
rollback, A4 undelete).

**What the user sees on `/admin/activity/{id}`:**

- When the log entry's entity_type is one we support and the user has
  `activity_log.edit` permission, the Changes table gains a fourth
  column — **Action** — with a **Revert** button next to every changed
  field.
- Clicking Revert triggers a JS confirm dialog, then POSTs back to the
  same URL. On success, a green flash appears: "Field X reverted. A new
  audit entry was created recording the revert."
- If the field has been changed *again* since the logged action (the
  intermediate-edit check), the revert is refused with a red flash
  explaining what the current value is vs what we expected to find.
  No overwrite of newer work.
- The original log entry is never mutated — the revert is recorded as a
  *new* `field_reverted` entry so both events are part of the audit trail.

**Entity types supported (whitelist in `includes/activity_log_revert.php`,
`activity_revert_supported_entity_types()`):**

- `users` → users.{field}
- `enquiries` → enquiries.{field}
- `caregivers` → caregivers.{field}
- `name_lookup` → name_lookup.{field}

Synthetic entity types (role permission matrix, user invites, email_log,
activity_log itself) are **excluded** — reverting a matrix cell or a
cached audit row doesn't make semantic sense. To add a new entity, extend
the whitelist map.

**Safety layers:**

1. **Permission gate** — `userCan('activity_log', 'edit')` is checked in
   the POST handler. Super Admin and Admin have it by default; Manager
   does not (they can read the activity log but not revert from it).
2. **CSRF token** — enforced via the existing `validateCsrfToken()` helper.
3. **Entity whitelist** — only the four entity_types above can be reverted.
4. **Column whitelist** — the field name is validated against
   `INFORMATION_SCHEMA.COLUMNS` for the target table. Synthetic diff fields
   like `note_appended` on enquiries are rejected with a plain-English
   reason.
5. **Intermediate-edit check** — live record's current value must match
   the "Now" value in the log. If not, the revert refuses.
6. **Record-exists check** — if the target row has been deleted, the
   revert refuses and points Ross at the undelete feature (A4, not yet
   built).
7. **Revert-of-revert suppression** — a log entry with action
   `field_reverted` does not show Revert buttons, to avoid chained-revert
   UX confusion. Users can still revert the *original* entry if they
   change their mind.

**What changed in the code:**

- `includes/activity_log_revert.php` (new) — helper module with
  `activity_revert_supported_entity_types()`,
  `activity_revert_entity_is_supported()`,
  `activity_field_is_valid_column()`, and
  `activity_revert_field(int $logId, string $field): array`.
- `templates/admin/activity_detail.php` — POST handler at the top of the
  page for `action=revert_field`, new Action column in the Changes table
  rendered only when all gates pass, flash-message block for success /
  error.

**Explicitly NOT changed:**

- `activity_log` table schema — no new columns.
- `logActivity()` signature.
- The detail page route and permission gate
  (`requirePagePermission('activity_log', 'read')`) — the revert gate is
  a separate, additional check inside the POST handler, so read-only
  users still see the page but don't see the Revert column.
- No changes to the list view (`/admin/activity`). Revert is detail-page
  only — the list view intentionally stays scannable.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via scp:
  `includes/activity_log_revert.php`,
  `templates/admin/activity_detail.php`.
- Server-side `php -l` clean.
- No schema migrations.
- Not yet promoted to prod.

## [0.9.3-dev] - 2026-04-11

### Changed — Activity log coverage gaps closed (A1.5)

Followup to v0.9.2-dev. Ross accepted the coverage audit recommendations and
authorised the three high-value gap closures plus the two cosmetic
backfills. The standing rule for this project (and all future projects) is
now: **every user-triggered mutation on a transactional site must be
captured in the activity log with a field-level before/after snapshot.**
Added to `C:\ClaudeCode\CLAUDE.md` as a top-level standing order.

**Gaps closed:**

1. **Failed logins now appear in `activity_log`** (`includes/auth.php`,
   `attemptLogin()`):
   - Unknown email / inactive account → `login_failed` with reason in
     summary. entity_id is the matched user id if any, else null.
   - Already-locked account attempt → `login_failed` with the existing
     `locked_until` in the summary.
   - Wrong password → `login_failed` with `failed_login_count` before/after
     in the diff. Super Admin (role_id 1) is still exempt from the count
     increment per the existing lockout spec but the attempt is still
     logged.
   - The existing `login_log` table is untouched — it still carries every
     attempt and is used by the lockout logic. `activity_log` is now a
     superset so admins can answer "I didn't do that" from the main audit
     UI in one place.

2. **Account lockouts now emit a dedicated `account_locked` entry** in
   addition to the `login_failed` entry (`includes/auth.php`,
   `attemptLogin()`). Separating them makes lockouts filterable and gives
   a clean hook for future alerting. Before/after snapshots the
   `locked_until` field.

3. **Every email send now emits an `email_sent` activity entry**
   (`includes/mailer.php`, `Mailer::send()`). entity_type is `email_log`
   and entity_id is the corresponding outbox row, so the activity detail
   page can deep-link back to the full outbox entry. The diff captures
   template, recipient, subject, and final status (sent/failed).
   `logActivity()` has its own internal try/catch so a logging failure
   cannot break mail delivery.

**Cosmetic backfills (same pass):**

4. **`user_unlocked` now carries a before/after snapshot** of
   `failed_login_count` and `locked_until`
   (`templates/admin/users_detail.php`, `unlock` action). Previously a
   summary-only entry with no diff.

5. **`password_reset_forced` now carries a before/after snapshot** of
   the `must_reset_password` flag
   (`templates/admin/users_detail.php`, `force_reset` action).

### Explicitly NOT changed

- `login_log` table (still the source of truth for lockout counting).
- The existing `logActivity()` signature.
- Any call site outside the five listed above.
- Schema — no migrations.

### Known noise trade-off

- `login_failed` with `reason = unknown email` will be emitted for every
  probe attempt with a non-existent email address, not only for real user
  accounts. At Ross's volume this is fine and the signal is more valuable
  than the noise (probe activity is itself forensically interesting). If
  the log ever gets spammy from this, a simple filter on the list page
  (or a retention rule that archives `action='login_failed' AND
  entity_id IS NULL` after N days) will tame it without losing the data.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via scp over SSH:
  `includes/auth.php`, `includes/mailer.php`,
  `templates/admin/users_detail.php`.
- Server-side `php -l` clean on all three.
- No schema migrations.
- Not yet promoted to prod — held for Ross sign-off.

## [0.9.2-dev] - 2026-04-11

### Changed — Activity log field-level diff is now inline on the list view

Ross flagged that the TCH activity log wasn't showing what he wanted to see at
a glance: when a record was edited, the list row showed only a summary line
and he had to click "View" to see *which fields actually changed*. The Nexus
CRM activity log shows the `old → new` diff inline on the list row, colour-
coded (red strikethrough → green). This release brings TCH to the same shape.

**What the user sees on `/admin/activity`:**

- Every row that captured a before/after snapshot now shows a small
  `▶ N fields changed` disclosure triangle under the summary cell.
- Click to expand → one line per changed field, rendered as
  `field: <del>old</del> → <ins>new</ins>`. Old is red strikethrough, new
  is green. Matches the Nexus visual convention.
- Login/logout/public-form rows render nothing extra (no snapshot = nothing
  to show). Keeps the table scannable.
- The existing **View** button on each row is unchanged — it still opens
  `/admin/activity/{id}` with the full forensic detail (IP, user agent,
  link-throughs to the affected entity, raw JSON).

**What changed in the code:**

- New `includes/activity_log_render.php` — shared helpers for snapshot
  decoding, per-field value rendering, diff computation, and the new
  inline-diff renderer used by the list view.
- `templates/admin/activity_log.php` — replaced the placeholder
  "(field-level diff available)" hint with a real collapsible inline diff
  block. View button preserved.
- `templates/admin/activity_detail.php` — refactored to use the shared
  helpers (removed duplicated private `decode_snapshot()` / `render_value()`
  / diff-loop). Detail page Was/Now cells are now tinted red/green to match
  the inline view.
- `public/assets/css/style.css` — added `.activity-inline-diff`,
  `.diff-was`, `.diff-now`, `.diff-arrow`, and `.diff-was-cell` /
  `.diff-now-cell` rules.

**Explicitly NOT changed:**

- No schema change to `activity_log`. The single-table + JSON blob design
  stays.
- No changes to `logActivity()` or any call site — coverage is the same as
  v0.9.1.
- No retention policy, no source/route column, no tamper protection — all
  still outstanding and tracked in `docs/TCH_Ross_Todo.md`.

**Also in this commit:**

- `.gitignore` now excludes `.last-backup-timestamp` (written by the
  cross-device SessionEnd hook; never meant to be committed).
- `docs/TCH_Ross_Todo.md` — added the "Activity Log — full audit + revert
  capability" section with 4 planned work items (A1 audit sweep, A2 single-
  field revert, A3 whole-record rollback, A4 undelete) and workload
  estimates.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via rsync over SSH.
- No schema migrations.
- Not yet promoted to prod — held for Ross sign-off after dev smoke test.

## [0.9.1] - 2026-04-10 — SHIPPED TO PROD

### Prod deploy

**Production deploy of v0.7.0 + v0.8.0 + v0.9.0 + v0.9.1 as a single block.**
First prod release of the new email-based login + RBAC + audit system.

Deploy steps executed:
1. Cleaned up test data on shared DB (deleted testmanager user, related
   user_invites row, "Audit Sweep Test" enquiry). activity_log and
   email_log left intact as audit/forensic data.
2. Server-side prod files backup:
   `~/public_html/tch_backup_pre_v0.9.1_2026-04-10/` (18MB).
3. DB tables backup:
   `~/public_html/dev-TCH/dev/database/backups/user_mgmt_pre_v0.9.1_prod_2026-04-10.sql`
   (users, roles, pages, role_permissions, user_invites, password_resets,
   email_log, activity_log — 329 lines).
4. Fast-forward `main` from `4b4ad0f` (v0.6.0+hamburger) to `966755a`
   (v0.9.1). 36 files, +5,437/-67 lines. Pushed.
5. Tagged `v0.9.1` on the merged commit, pushed tag to GitHub.
6. Server-side rsync `~/public_html/dev-TCH/dev/` → `~/public_html/tch/`
   excluding `.env`, `database/backups/`, `tools/intake_parser/output/`,
   `.git/`. 26 templates updated/created.
7. Smoke tests on prod (https://tch.intelligentae.co.uk):
   - `/`, `/login`, `/forgot-password`, `/reset-password?token=invalid`,
     `/setup-password?token=invalid` → all 200
   - `/admin` (unauthed) → 302 to /login
   - `/login` form contains `name="email"` (not username — confirms new code is live)
   - POST `/login` with `ross@intelligentae.co.uk` / `TchAdmin2026x` → 302 → /admin
   - All 14 authed admin pages → 200:
     `/admin`, `/admin/users`, `/admin/users/invite`, `/admin/users/1`,
     `/admin/roles`, `/admin/roles/2/permissions`, `/admin/activity`,
     `/admin/email-log`, `/admin/people/review`, `/admin/enquiries`,
     `/admin/names`, three reports

**OUTSTANDING POST-DEPLOY ACTION FOR ROSS:**
* **Purge CDN cache** via StackCP > CDN > Edge Caching, or use Development
  Mode. Until purged, anonymous browsers may see the cached old `/login`
  page with the username field. Edge cache typically clears within
  minutes anyway, but a manual purge accelerates it.

### Added — Activity Log Field-Level Diff View

Ross noticed the activity log captured `before_json` / `after_json` columns
but the viewer didn't render them. This patch adds a per-entry detail page
that renders mutations as a field-by-field diff (Field / Was / Now), and
backfills the three remaining mutations that didn't yet capture proper
before/after snapshots.

**New: `/admin/activity/{id}` detail page (`templates/admin/activity_detail.php`):**

* Full row metadata: when, action, real actor, impersonator (when set),
  page, entity (with click-through to /admin/users/{id} or /admin/enquiries?id=
  when applicable), summary, IP, user agent.
* **Changes section** — decodes `before_json` and `after_json`, computes
  the set of changed fields, and renders them as a 3-column table:

  | Field      | Was            | Now                       |
  |------------|----------------|---------------------------|
  | full_name  | Test Manager   | Test Manager (Renamed)    |
  | users.read | 0              | 1                         |

* Smart value rendering: nulls show as `(empty)`, booleans as `true`/`false`,
  arrays/objects as inline JSON, long strings escaped.
* Identical-snapshot detection: if before == after the page says "no fields
  actually changed" instead of an empty table.
* For events without before/after (login, logout, public submission,
  token-based flows): the page explicitly says "did not capture a
  field-level diff" so it's clear it's a known intentional gap.
* Collapsible "Raw JSON snapshots" `<details>` block at the bottom for
  forensic inspection (pretty-printed JSON of both snapshots).

**Front controller:** new parametric route
`^admin/activity/(\d+)$ → activity_detail.php`, gated on `activity_log.read`.

**Activity log list (`templates/admin/activity_log.php`):**

* New "View" button on every row → links to `/admin/activity/{id}`.
* Summary cell shows a small "(field-level diff available)" hint when the
  row has a non-empty before_json or after_json, so users know which entries
  are worth clicking through.
* Existing colspan adjusted from 8 to 9 for the empty state.

### Backfilled before/after captures

Three mutations that previously logged only a summary string now capture
proper field-level diffs:

* **`users_list.php` deactivate/reactivate** — captures `is_active` flip.
  Reactivate also includes `failed_login_count` and `locked_until` reset.
* **`enquiries.php` add_note** — captures the appended note line as
  `note_appended: null → "<text>"`. Notes are append-only on the column,
  so the diff records *what was added*, not the full growing notes blob.
* **`roles_permissions.php` matrix update** — previously stored a placeholder
  string ("see role_permissions") in `after_json` instead of the actual
  diff. Replaced with a flat snapshot of the form `{pagecode}.{verb} → 0|1`,
  so the activity detail page renders one row per *changed* permission
  (e.g. `users.read: 0 → 1`, `enquiries.create: 1 → 0`, etc).
  - Also fixed a latent bug: the previous snapshot used `PDO::FETCH_KEY_PAIR`
    which only captures 2 columns, so the original `$before` array would
    have been malformed even if it had been used. The new
    `$snapshotMatrix()` closure pulls all 4 verbs per page properly.
  - Summary line now reports the change count, e.g. "Updated permission
    matrix for Manager (3 field changes)".

### End-to-end verification on dev

* Edited Test Manager's full_name from "Test Manager" → "Test Manager (Renamed)"
  via /admin/users/2.
* Activity row 19 (action=user_edited) → /admin/activity/19 renders:
  ```
  Field        Was             Now
  full_name    Test Manager    Test Manager (Renamed)
  ```
* Submitted a permission matrix change for the Manager role enabling
  users/roles read across the board. Activity row 20
  (action=role_permissions_updated) → /admin/activity/20 renders ~16
  rows of `{page}.{verb}: 0 → 1` diffs. Summary says "16 field changes".
* Verified the Manager role permissions restore round-trip: after putting
  Manager perms back, `GET /admin/users` as testmanager returns 403 again.
* Restored Test Manager's full_name back to "Test Manager".

## [0.9.0] - 2026-04-10

### Added — Audit Log Integration Sweep (Session C of 3)

Final session of the locked 3-session User Management + RBAC + Audit +
Impersonation build. Session C closes the audit-log integration gap by
mechanically sweeping every remaining mutation path that Sessions A and
B did not already cover.

**Sweep methodology:**

* Grepped every PHP file in the repo for `INSERT|UPDATE|DELETE` statements
* Cross-referenced against files that already contain `logActivity()` calls
* Three uncovered mutation paths identified:
  1. `templates/public/enquire_handler.php` — public form submission
  2. `templates/admin/names.php` — name lookup approve/reject
  3. `database/seeds/create_admin.php` — CLI admin password upsert

**`templates/public/enquire_handler.php`:**

* Added `logActivity('enquiry_submitted', ...)` after the INSERT.
* Anonymous submission — `real_user_id=NULL` (the form is public, no
  session). The summary captures the submitter's name and care type so
  the audit log is useful even without a user link.

**`templates/admin/names.php`:**

* Added `logActivity('name_lookup_approved', ...)` and
  `logActivity('name_lookup_rejected', ...)` with before/after JSON
  snapshots of the `approved` flag.
* Mutation block now gates on `userCan('names_reconcile', 'edit')`.
* **Latent bug fixed**: the file referenced `$user['username']` to set
  `name_lookup.approved_by`, but `$user` was never defined in this template
  (it's only set in `templates/layouts/admin.php`, which is included AFTER
  the mutation block runs). The bug caused approved_by to be silently
  populated as null/undefined for every approval. Replaced with
  `currentEffectiveUser()` returning a proper email-or-fallback label.

**`database/seeds/create_admin.php`:**

* Added `require auth.php` so `logActivity()` is available in the CLI context.
* Logs `admin_password_set_cli` when updating an existing user, or
  `admin_user_created_cli` when creating from scratch. Both anonymous
  (no session in CLI), with `entity_type='users'` and `entity_id=ross_id`.
* Fixed a pre-existing issue where the INSERT path didn't set `role_id`
  or `email_verified_at` — now correctly creates ross as Super Admin
  (role_id=1) with `email_verified_at=NOW()`.

**Out of scope (deferred — see session notes):**

* `database/seeds/ingest.php` and `database/seeds/reconcile.php` are
  one-shot historical bulk ingest scripts. They already provide their
  own provenance via the existing `audit_trail` table and `import_notes`
  columns. Adding `logActivity()` per row would generate tens of
  thousands of entries from a single ingest run with no operational value.
  These scripts have done their job and are unlikely to run again.

### End-to-end audit verification

Triggered one mutation of each new type on dev and verified the
`activity_log` captured them with the right actor / entity / summary:

| id | action                  | real | imp  | entity      | summary                                  |
|---:|-------------------------|-----:|-----:|-------------|------------------------------------------|
| 15 | enquiry_status_changed  | 1    | NULL | enquiries#1 | Status: new -> contacted                 |
| 14 | admin_password_set_cli  | NULL | NULL | users#1     | create_admin.php CLI updated ross pw     |
| 13 | enquiry_submitted       | NULL | NULL | enquiries#1 | Public enquiry from Audit Sweep Test     |

The activity log viewer (`/admin/activity`) renders all three new entries
and the action filter dropdown picks up the new action types automatically.

### Distinct action types currently exercised

10 distinct actions captured in the dev log after Sessions A + B + C testing:
`admin_password_set_cli`, `enquiry_status_changed`, `enquiry_submitted`,
`impersonate_start`, `impersonate_stop`, `login`, `password_reset_completed`,
`password_reset_requested`, `user_invite_accepted`, `user_invited`.

The remaining defined action types (`logout`, `user_edited`, `user_deactivated`,
`user_reactivated`, `user_unlocked`, `password_reset_forced`, `person_approved`,
`person_rejected`, `enquiry_note_added`, `name_lookup_assigned`,
`name_lookup_approved`, `name_lookup_rejected`, `role_permissions_updated`,
`admin_user_created_cli`) all have `logActivity()` calls in their handlers
and will appear in the log when their UI actions are triggered.

### Deployment

* Files uploaded to `~/public_html/dev-TCH/dev/` via scp.
* No new schema migrations.
* **Held from prod deploy pending Ross sign-off.** v0.7.0 + v0.8.0 + v0.9.0
  are now ready to ship to prod as a single block via dev → prod rsync.

## [0.8.0] - 2026-04-10

### Added — Admin UIs, Impersonation, Permission Retrofit (Session B of 3)

Second session of the locked 3-session User Management + RBAC + Audit +
Impersonation build. Session B ships the admin user-management UIs, the
roles/permissions matrix editor, the activity log + email outbox viewers,
the full impersonation flow with persistent banner, and retrofits all
existing handlers to use `requirePagePermission()` and `logActivity()`.

**Front controller (`public/index.php`):**

* Two-pass routing: parametric routes (`/admin/users/{id}`,
  `/admin/users/{id}/impersonate`, `/admin/roles/{id}/permissions`,
  `/admin/email-log/{id}`) matched via preg_match BEFORE the static switch.
* New static routes: `/admin/users`, `/admin/users/invite`,
  `/admin/impersonate/stop`, `/admin/roles`, `/admin/activity`,
  `/admin/email-log`.
* Every existing admin route migrated from `requireAuth()` to
  `requirePagePermission($pageCode, $action)`. Manager logins now correctly
  hit 403 on `/admin/users` and `/admin/roles` while still being able to
  access `/admin`, `/admin/people/review`, `/admin/enquiries`, etc.

**Admin layout (`templates/layouts/admin.php`):**

* Sidebar nav is now fully permission-driven via `userCan()` — users only
  see the pages they actually have access to. New "Admin" section appears
  for users with `users`, `roles`, `activity_log`, or `email_log` access.
* Persistent impersonation banner injected at the very top of every admin
  page when `isImpersonating()` returns true. Shows the real user's email,
  the impersonated user's identity + role, and a one-click "End impersonation"
  link. Sticky-positioned, red background, z-index above the sidebar.
* `admin-user` block in the header now falls back gracefully to
  email/username when `full_name` is empty.

**`/admin/users` — `templates/admin/users_list.php`:**

* Lists every user visible via `getVisibleUserIds()` (Super Admin/Admin
  see all; Manager sees their hierarchy).
* Filters: search by email/name, role dropdown, active/inactive status.
* Stats cards: active user count, pending invite count.
* Per-row actions: View / Deactivate (or Reactivate). Self-deactivation
  is blocked with a flash message.
* Status badges: Active / Locked / Unverified / Inactive.
* "Invite User" button visible only to users with `users.create`.
* Every mutation logs via `logActivity()`.

**`/admin/users/invite` — `templates/admin/users_invite.php`:**

* Form: email, full name, role, optional manager, optional linked
  caregiver/client IDs.
* Refuses if an active user with that email already exists.
* Generates a SHA-256 token, INSERTs into `user_invites`, calls
  `Mailer::send('invite', ...)`. 72-hour expiry.
* On success, displays the dev fallback link (the raw setup-password URL)
  inline so the developer can copy it directly even if shared-host
  `mail()` drops the message.
* `logActivity('user_invited', ...)` records the invite.

**`/admin/users/{id}` — `templates/admin/users_detail.php`:**

* Profile section: edit full name, role, manager, linked caregiver/client.
  Save uses transactional UPDATE with before/after JSON snapshots in the
  audit log.
* Account actions:
    - Send Password Reset Email (creates `password_resets` row, calls
      mailer, sets `must_reset_password=1`, shows dev fallback URL)
    - Unlock (clears `failed_login_count` and `locked_until`, only shown
      when the account is currently locked)
    - Impersonate User (Super Admin only, hidden when target is the
      current user or inactive)
* Recent Activity panel: 20 most recent rows from `activity_log` where
  this user was either the actor (real_user_id) OR the target of
  impersonation (impersonator_user_id).
* Email column is read-only in the UI — email changes are not supported
  in v1.

**`/admin/users/{id}/impersonate` — `templates/admin/users_impersonate.php`:**

* Two-step flow: GET renders the re-auth form, POST calls `startImpersonation()`.
* Hard pre-checks before showing the form: must be Super Admin, must not
  already be impersonating, target cannot be self.
* Re-auth: Super Admin must enter their own current password.
* On success: redirects to `/admin` where the persistent banner appears.
* `/admin/impersonate/stop` route handles ending the session and redirects
  back to `/admin`. Both start and stop log to activity_log.

**`/admin/roles` + `/admin/roles/{id}/permissions` — roles UI:**

* `roles_list.php` — lists all 5 system roles with user count, "pages with
  any access" count, and Edit Permissions button. Role creation/deletion
  is intentionally not exposed in v1 — the 5 system roles are fixed; only
  the matrix is editable.
* `roles_permissions.php` — full pages × CRUD checkbox grid (17 × 4 = 68
  checkboxes). UPSERTs every row in a single transaction. Captures
  before/after via SELECT...PIVOT for the audit log.
* Hard guard: the Super Admin role (id 1) cannot have its permissions
  modified through this UI even if `roles.edit` is granted. The form
  renders read-only (disabled inputs) and POSTs against role_id=1 are
  rejected with a flash error. This prevents anyone from accidentally
  locking themselves (and everyone else) out of the system.

**`/admin/activity` — `templates/admin/activity_log.php`:**

* Filters: action dropdown (populated from DISTINCT actions), entity_type
  dropdown, user_id (matches both real and impersonator), date range.
* Pagination: 50 entries per page.
* Columns: When, Actor, Impersonator (badge), Action, Page, Entity, Summary, IP.
* The Impersonator column makes it visually obvious which actions
  happened under impersonation — answers the "who really did this?"
  question at a glance.

**`/admin/email-log` + `/admin/email-log/{id}` — email outbox:**

* List view: filterable by status (queued/sent/failed) and template.
  Pagination 50/page. Status badges. Click to view the full body.
* Detail view: full envelope (from/to/subject/template/status/timing) +
  the email body verbatim in a `<pre>` block. Reset and invite links can
  be copied directly from here when shared-host `mail()` fails to deliver.

**Existing handler retrofit:**

* `templates/admin/enquiries.php` — both POST handlers now gate on
  `userCan('enquiries', 'edit')` and call `logActivity()` with action
  `enquiry_status_changed` (with old/new status snapshot) or
  `enquiry_note_added`. Replaced legacy `$user['username']` with
  `$user['email']`.
* `templates/admin/people_review.php` — approve/reject mutations gated
  on `userCan('people_review', 'edit')` and log as `person_approved` /
  `person_rejected` with before/after import_review_state.
* `templates/admin/names_assign.php` — name lookup updates log as
  `name_lookup_assigned` with before/after billing_name snapshot.

**CSS additions (`public/assets/css/style.css`):**

* `.impersonation-banner` + `.impersonation-banner-inner` +
  `.impersonation-stop` — sticky red banner.
* `.badge-danger` — for failed email statuses.
* `.alert-info` — for the dev fallback link blocks on the invite form.

### End-to-end verification on dev

Smoke tests run during the deploy:

* All 9 new admin pages return 200 as ross (Super Admin):
  `/admin/users`, `/admin/users/invite`, `/admin/users/1`,
  `/admin/roles`, `/admin/roles/1/permissions`, `/admin/roles/2/permissions`,
  `/admin/activity`, `/admin/email-log`, `/admin/email-log/1`
* All 7 existing admin pages still return 200 (legacy handlers under
  `requirePagePermission()`): `/admin`, `/admin/people/review`,
  `/admin/enquiries`, `/admin/names`, three reports.

End-to-end invite + permission gating + impersonation:

1. Created Test Manager user via `/admin/users/invite` (role_id=3)
2. Extracted `setup-password` URL from `email_log`
3. Walked through `/setup-password?token=...` → password set → "account is ready"
4. Logged in as `testmanager@example.com` / `TestMgr2026Pwd`
5. Manager session: `/admin` → 200, `/admin/people/review` → 200,
   `/admin/enquiries` → 200, `/admin/activity` → 200,
   **`/admin/users` → 403, `/admin/roles` → 403** ← permission gating works
6. Logged back in as ross (Super Admin)
7. `GET /admin/users/2/impersonate` → re-auth form
8. `POST` with ross's password → 302 to `/admin`
9. `GET /admin` → 200 with **"Impersonation active" banner present**
10. While impersonating Manager: `GET /admin/users` → **403** (Manager
    doesn't have that page — impersonation correctly inherits the target's
    permissions)
11. `GET /admin/impersonate/stop` → 302 → back to ross
12. `GET /admin/users` → 200 (Super Admin again)

Activity log final state (12 rows):

| id | action                  | real | imp  |
|---:|-------------------------|-----:|-----:|
| 11 | impersonate_stop        | 1    | NULL |
| 10 | impersonate_start       | 2    | 1    | ← real_user_id = effective (mgr), imp = ross
|  9 | login                   | 2    | NULL |
|  8 | user_invite_accepted    | NULL | NULL |
|  7 | user_invited            | 1    | NULL |
| 1-6| (Session A test events) |      |      |

The audit convention works: row 10 records the impersonated session
correctly with `real_user_id=2` (the effective identity, Test Manager)
and `impersonator_user_id=1` (the human at the keyboard, Ross). A query
of `WHERE real_user_id = 2 OR impersonator_user_id = 2` returns
"everything that happened to/as Test Manager" including the impersonation
session.

### Test data left on dev

The Test Manager user (`testmanager@example.com` / `TestMgr2026Pwd`) was
left on the dev database so Ross can try the impersonation flow himself.
Delete via SQL or the deactivate button on `/admin/users` whenever convenient.

### Deployment

* Files uploaded to `~/public_html/dev-TCH/dev/` via scp
* No new schema migrations (all schema work landed in 005 in Session A)
* NOT yet promoted to prod — held until Session C completes the final
  audit-log integration sweep

### Out of scope this session (deferred to Session C)

* Audit-log integration sweep across the database seed scripts and any
  remaining mutation paths Session B didn't touch
* Hierarchy filtering on additional list pages — currently a no-op since
  the only users are Super Admin/Admin who bypass hierarchy. Will be
  retrofitted if/when real Manager users are invited.

## [0.7.0] - 2026-04-10

### Added — User Management Foundation (Session A of 3)

First session of the locked 3-session User Management + RBAC + Audit +
Impersonation build. This session ships the schema, auth library, mailer,
and public auth flows. Admin UIs land in Session B; the audit-log integration
sweep across existing handlers lands in Session C.

**New schema (`database/005_users_roles_hierarchy.sql`):**

* `roles` table — 5 seeded system roles: Super Admin, Admin, Manager, Caregiver,
  Client. `is_system=1` flag prevents UI deletion.
* `pages` table — 17 pages registered (dashboard, caregivers, clients, roster,
  billing, people_review, names_reconcile, enquiries, three reports, users,
  roles, activity_log, email_log, config, self_service). Each page is the
  unit of permission gating.
* `role_permissions` table — page × role × CRUD verb (read / create / edit / delete).
  Default matrix seeded: Super Admin and Admin get full CRUD on everything;
  Manager gets full CRUD on everything except `users` and `roles`; Caregiver
  and Client get read+edit on `self_service` only. 51 rows total.
* `users` table extended (additive) with: `role_id` (FK roles), `manager_id`
  (self-FK for hierarchy), `linked_caregiver_id` (FK caregivers), `linked_client_id`
  (FK clients), `email_verified_at`, `failed_login_count`, `locked_until`,
  `must_reset_password`. Email made `NOT NULL UNIQUE`. Legacy `username` and
  `role` columns retained for back-compat.
* `user_invites` table — pending invitations. Token stored as SHA-256 hash;
  raw token only ever appears in the email body. Includes `manager_id`,
  `linked_caregiver_id`, `linked_client_id` so an invite carries the full
  identity setup forward into the created user row.
* `password_resets` table — same SHA-256 hash pattern. `requested_ip` recorded
  for forensics. Single-use: `used_at` is set on success, plus all other
  outstanding tokens for the same user are invalidated in the same transaction.
* `email_log` table — outbox. Every send attempt is INSERTed as `queued`
  before `mail()` is called, then flipped to `sent` or `failed`. Guarantees
  the developer can always retrieve the link from the table even when
  shared-host `mail()` silently drops messages.
* `activity_log` table — mutation audit log. Records action, page_code,
  entity_type, entity_id, summary, before/after JSON, IP, user agent. Both
  `real_user_id` (effective identity / actor as logged) AND `impersonator_user_id`
  (the human at the keyboard, only set when impersonation is active) are
  stored, so impersonated actions trace back to the real human. Page views
  are NOT logged in v1 — mutations only.
* Migration is idempotent: every column add is conditional via INFORMATION_SCHEMA
  checks, every INSERT uses `INSERT IGNORE`, every constraint add is conditional.
  Safe to re-run on the shared dev/prod database.
* Existing `ross` user row migrated in place: `role_id=1` (Super Admin),
  `email_verified_at=NOW()`. Password and email unchanged.

**New auth library (`includes/auth.php` — rewritten):**

* `attemptLogin($email, $password)` — email is now the canonical login identifier.
  Lockout enforcement: 10 failed attempts in a row → 15-minute lockout. Super
  Admin (role_id 1) is exempt from lockout per the spec. Resets failed counter
  and `locked_until` on success.
* `fetchUserById()` / `fetchUserByEmail()` helpers — both join to `roles` for
  role_slug + role_name.
* Session keys added: `real_user_id`, `impersonator_user_id`, `email`, `role_id`,
  `role_slug`, `role_name`. Legacy `username` and `role` keys retained so the
  existing `requireAuth()` and `requireRole()` shims continue to work for the
  pages built before this session.
* `currentUser()` is now an alias for `currentEffectiveUser()` (returns the
  impersonated identity when impersonation is active).
* `currentRealUser()` always returns the human at the keyboard regardless of
  impersonation, by re-fetching from the DB using `real_user_id`.
* `isImpersonating()`, `startImpersonation($targetUserId, $reauthPassword)`,
  `stopImpersonation()` — full impersonation lifecycle. `startImpersonation()`
  enforces:
    - real user must be Super Admin (role_id 1)
    - cannot already be impersonating
    - cannot impersonate yourself
    - re-auth: caller must supply their own current password (`password_verify`)
    - target user must exist and be active
* `logout()` records a `logout` action to `activity_log` before destroying
  the session.

**New permissions library (`includes/permissions.php`):**

* `userCan($pageCode, $action)` — looks up the role × page × verb in
  `role_permissions`. Returns false for unauthenticated users or unknown
  pages.
* `requirePagePermission($pageCode, $action)` — calls `requireAuth()` first,
  then enforces the CRUD verb. Returns 403 if missing.
* `isSuperAdmin()` — gate for impersonation. Always checks the REAL user,
  never the effective one, so an impersonated session cannot start a
  nested impersonation.
* `getVisibleUserIds($forUserId)` — recursive BFS down `users.manager_id`.
  Super Admin and Admin (role_id 1, 2) bypass the hierarchy and see every
  user. Returns the manager + every direct/indirect report.
* `getVisibleCaregiverIds($forUserId)` — Super Admin/Admin see all; Caregiver
  (role_id 4) sees only their own `linked_caregiver_id`; Manager sees
  caregivers linked to any user in their visible-user set.
* `getVisibleClientIds($forUserId)` — same pattern as caregivers, mirrored
  for the Client role.
* `logActivity($action, $pageCode, $entityType, $entityId, $summary, $before, $after)` —
  central audit recorder. Wraps the INSERT in try/catch so an audit failure
  never breaks the user-facing flow. JSON-encodes before/after snapshots.
  Captures real_user_id + impersonator_user_id correctly under all session states.

**New mailer (`includes/mailer.php`):**

* `Mailer::send($template, $toEmail, $toName, $vars, $relatedUserId)` —
  outbox-first design. Always INSERTs into `email_log` as `queued` BEFORE
  attempting `mail()`. Updates row to `sent` or `failed` based on result.
* Template renderer: loads `templates/emails/<name>.php`, extracts vars
  into the local scope, the template defines `$subject` and `$body`.
* `From:` address comes from `MAIL_FROM_EMAIL` / `MAIL_FROM_NAME` env vars,
  with sensible defaults derived from `APP_URL`.
* RFC 2047 encoded-word for non-ASCII subjects.
* No HTML in v1 — text/plain only. Real provider (Mailgun / SES) is wired
  in a future session.
* Three templates seeded: `invite.php`, `reset.php`, `reset_confirm.php`.

**New public auth flows (`templates/auth/`):**

* `login.php` — REWRITTEN. Email field replaces username field. Validates
  email format. Shows distinct success messages for `?logged_out=1`,
  `?timeout=1`, and `?reset=1` (post-reset). New "Forgot your password?"
  link below the form.
* `forgot_password.php` — accepts email, INSERTs into `password_resets`,
  calls `Mailer::send('reset', ...)`. **Anti-enumeration**: always shows
  the same "if an account exists" success message regardless of whether
  the email matches a real user, so attackers cannot probe for valid
  accounts. Reset tokens expire in 2 hours.
* `reset_password.php` — accepts `?token=` from the email, validates
  (exists, not used, not expired, user still active), shows the password
  form. On submit: enforces 10-char minimum + match, hashes with bcrypt,
  resets `failed_login_count` and `locked_until`, marks the token used,
  invalidates all other outstanding reset tokens for the same user in the
  same transaction, sends a confirmation email, redirects to login with
  `?reset=1` flag.
* `setup_password.php` — same flow but for `user_invites` instead of
  `password_resets`. On accept, creates the user row with the role_id,
  manager_id, linked_caregiver_id, linked_client_id captured at invite
  time. Handles the rare case where a user with that email already exists
  (updates in place) so the invite is robust against races.
* All four templates use the existing CSRF token machinery and the existing
  `auth-card` styles, so they look native to the existing /login design.

**Front controller (`public/index.php`):**

* Four new public routes wired: `/login` (already existed), `/forgot-password`,
  `/reset-password`, `/setup-password`. None of these require authentication.

### End-to-end verification on dev

Smoke tests run during the deploy:

* `GET /login` → 200
* `GET /forgot-password` → 200
* `GET /reset-password?token=invalid` → 200 (shows "invalid or expired" alert)
* `GET /setup-password?token=invalid` → 200 (shows "invalid or expired" alert)
* `GET /admin` (unauthenticated) → 302 to /login
* `POST /login` with `email=ross@intelligentae.co.uk` + existing password
  → 302 to /admin; subsequent `GET /admin` → 200
* `POST /forgot-password` with ross's email → 200, success message rendered,
  `email_log` row id 1 created with status `sent`
* Reset link extracted from `email_log.body_text`, visited, new password
  POSTed → 200 with success alert
* `POST /login` with NEW password → 302 to /admin
* Password restored to documented `TchAdmin2026x` via existing `database/seeds/create_admin.php`
* `POST /login` with original password → 302 to /admin
* `GET /admin/people/review` and `GET /admin/enquiries` (authed) → both 200
* `activity_log` shows 5 entries spanning the test cycle: login, password_reset_requested,
  password_reset_completed, login (temp pass), login (restored pass)

### Backups taken

* `database/backups/users_pre_migration_005.sql` on dev — `users` + `login_log`
  table dumps before migration ran. 90 lines.

### Deployment

* Files uploaded to `~/public_html/dev-TCH/dev/` via scp. NOT yet promoted to prod.
* Migration ran against the shared dev/prod database, so the schema changes are
  also visible to prod — but prod still has the OLD `auth.php` / `index.php` /
  `templates/auth/login.php`, so prod login still uses the username field. Prod
  promotion is held back until Session B is complete and the admin user-mgmt
  pages are also tested.
* CDN cache purge not required — only dev was touched at the file level.

### Out of scope this session (deferred to B / C per the locked plan)

* Admin UIs: `/admin/users`, `/admin/roles`, `/admin/activity`, `/admin/email-log`,
  the impersonate button + banner. Session B.
* Wiring `requirePagePermission()` calls into existing handlers (dashboard,
  enquiries, people_review, names_reconcile, reports). Session B.
* Hierarchy filtering on caregiver/client list pages. Session B.
* Audit-log mutation sweep across every existing handler. Session C.

## [0.6.0] - 2026-04-10

### Added — Public-facing homepage rebuild

The TCH public homepage has been rewritten to be a real customer-facing
landing page rather than the placeholder marketing skeleton it was before.

**New schema (`database/004_regions_and_enquiries.sql`):**

* `regions` table — one row per geographic region TCH operates in.
  Holds phone numbers, emails, physical address, postal code, service-area
  description, hero headline override, office hours, and social URLs.
  Seeded with Gauteng (placeholder phone `XXX XXX XXXX`, placeholder email).
  The public homepage now loads its primary region from this table, so
  contact details are configurable without code changes. Future per-region
  pages (Western Cape, KZN, etc.) will reuse the same template with a
  different region row.
* `enquiries` table — captures public form submissions with full audit
  metadata (IP, user agent, referrer, source page), POPIA consent fields,
  and a status workflow (`new` → `contacted` → `converted`/`closed`/`spam`).
  Free-text notes append-only with audit stamps.

**New homepage (`templates/public/home.php`):**

* Hero — "Trusted Caregivers, Placed Where You Need Them" with the placeholder
  Tuniti-style hero background image (CSS gradient fallback when image is absent).
* Stats bar driven by live caregivers/clients counts.
* **Care Services** block — six cards covering the five named Tuniti services
  (Full-Time, Post-Op, Palliative, Respite, Errand) plus a "Not Sure" CTA.
* **Why TCH** block — four differentiators: Verified/Vetted/Trained,
  Matched-not-just-Sent, Cover When Life Happens, One Trusted Brand.
* **How It Works** — three-step process.
* **Trust block** — gradient panel: "You're not just hiring a person, you're
  joining a network."
* **Enquiry form** — full POPIA-compliant inquiry form with CSRF token,
  honeypot for bots, required-field validation, dropdown for care type,
  urgency selector, free-text message, mandatory consent checkbox.
* **Contact section** — phone / email / area, all from the regions table.

**Form handler (`templates/public/enquire_handler.php`):**

* Validates CSRF, drops bot submissions silently, server-side sanitises and
  truncates inputs, validates care type against an allow-list, captures
  audit metadata, writes to `enquiries`, redirects back to the homepage
  with `?enquiry=success#enquire` (or `?enquiry=error`).

**Admin enquiries inbox (`templates/admin/enquiries.php`):**

* List view filterable by status with badge counts dashboard.
* Detail view with full submitter details, audit metadata, status workflow
  (set status, add audit-stamped notes).
* New sidebar entry under "Inbox".

**Footer refresh:**

* Footer now reads phone, email, and service area from the regions table
  via `$footerRegion` variable. Falls back gracefully on standalone pages.

**Image prompts (`docs/Brand_Image_Prompts.md`):**

* Eight ChatGPT/DALL-E prompts for the imagery the homepage needs (hero +
  five service tiles + two optional support images). Each prompt includes
  the South African demographic guidance (caregivers predominantly Black,
  clients typically older White Afrikaans), tone guidance, anti-cliché
  rules, exact filenames, and aspect ratios. Page works without the images
  thanks to CSS gradient fallbacks — imagery is an upgrade not a blocker.

**Deployed to dev:**

* Migration 004 applied to the shared dev/prod DB
* New homepage live at `https://dev.tch.intelligentae.co.uk/`
* Admin enquiries inbox live at `/admin/enquiries`
* Form submission tested via route — POST handler responding correctly

## [0.5.2] - 2026-04-10

### Added — Tranches 2–9 enrichment (109 caregivers)

The remaining 8 Tuniti intake PDFs (Tranches 2–9) have been read, cross-matched
against the existing 109 caregivers in those tranches, and enriched with PDF
data. All 109 records now have:

* Full PDF data (title, initials, ID/passport, DOB, gender, nationality, home
  and other languages, mobile, secondary mobile where present, email, complex
  estate, full address, NoK details, lead source) adopted as canonical per
  Ross's locked-in decision.
* `import_review_state = 'pending'` so they appear in the admin review page.
* Two attachments per person — the source PDF page and the cropped portrait.

**New lead sources surfaced and added to the lookup:**

* `website` — used by 9 candidates across multiple tranches
* `advertisement` — used by 3 Tranche 3 candidates

**Cross-tranche observations flagged in `import_notes`:**

* Two records named "Nelly", three records with similar names ("Siphilisiwe",
  "Siphathisiwe", "Sthenjisiwe"), two "Thandi"s — confirmed as different people
  by DOB/ID, no merge.
* One record (Ntombifikile Octavia Mhlongo, id 103) had a clearly invalid PDF
  DOB of `0005-08-03` — DOB left as the existing DB value, flagged.
* Several records share addresses or nok contact numbers with other records —
  flagged for review (possible household links).
* Generic "Social_media" lead source on ~15 records left blank for review with
  a note (TODO: ask each candidate which platform).
* Numerous typos preserved verbatim (Pretoira, Pretroia, Johnesburg, Sweto,
  Acradia, Mamalodi, Bryaston, Spedi, Speed, Setswane, Yoryba, Xitsongo,
  Xitsomga, Hammenskraal, etc.) — each one flagged in `import_notes`.

**Schema/data files added:**

* `database/003c_tranches_2_9_enrichment.sql` — the one-shot enrichment script
  for all 8 tranches. Each tranche is its own transaction so a failure in one
  does not block the others.
* `tools/intake_parser/upload_photos.py` — staging script that reorganises the
  rendered portraits into per-person folders ready for SCP.

**Deployed to dev:**

* Migration 003c applied to the shared dev/prod database (109 UPDATEs + 218
  attachment INSERTs).
* All 9 source PDFs uploaded to `public/uploads/intake/`.
* All 109 cropped portraits uploaded to `public/uploads/people/TCH-NNNNNN/photo.png`.
* Pre-enrichment backup of `caregivers` and `attachments` tables preserved at
  `database/backups/caregivers_pre_tranches_2_9.sql` on the server.
* Post-load verification: 123 caregivers in `pending` review state, 246
  attachments total, all 9 tranches consistently labelled.

## [0.5.1] - 2026-04-10

### Added — Tranche 1 enrichment + admin review page

**Tranche 1 imported and enriched** against the existing 14 caregivers
(ids 1–14):

* All 14 Tranche 1 candidates from the Tuniti PDF were already in the
  caregivers table as name-only stubs (12 of 14) or with workbook data
  that conflicted with the PDF (Jolie / Mukuna). Per Ross's decision the
  Tuniti PDF data was adopted as canonical and the workbook values were
  preserved verbatim in `import_notes` for audit.
* Special handling for id 5 (Jovani Mukuna Tshibingu): the DB full_name
  "Jovani" was kept because the PDF title spells it "Jonvai" — a typo
  confirmed by the PDF's own Known As field.
* All 14 enriched rows set to `import_review_state = 'pending'` so they
  appear in the new admin review queue.
* 28 attachments inserted: 14 Original Data Entry Sheet rows pointing
  to the source PDF page, 14 Profile Photo rows pointing to the cropped
  portraits.

**Tranche label standardisation** (system-wide):

* `1st Intake` → `Tranche 1`, `2nd Intake` → `Tranche 2`, … `9th Intake`
  → `Tranche 9`. Affects 113 caregivers across all 9 cohorts. The `N/K`
  label is left alone — unknown remains unknown.

**New admin page: Person Review** (`/admin/people/review`):

* Lists all caregivers in `import_review_state = 'pending'`, filterable
  by tranche, with photo thumbnail, TCH ID, full name, known_as,
  student_id, attachment count and a notes flag.
* Detail view (`?id=N`) renders a person card styled to mirror the
  Tuniti intake PDF layout: photo top-left, two-column field grid
  (Personal / Contact / Address / Emergency Contact), attachments list,
  import-notes panel and human-notes panel.
* Approve / Reject actions, CSRF-protected. Approve clears
  `import_review_state` and appends an audit line; Reject sets the
  state to `rejected` and appends an audit line.
* Sidebar nav updated with "Person Review" entry under Data.

**Migration patches:**

* `database/003a_finish_migration.sql` — completes migration 003 after
  the original `tch_id` GENERATED column failed under MariaDB 10.6
  (auto-increment columns can't be referenced by generated columns).
  `tch_id` is now a regular VARCHAR(20) populated by application code,
  with a unique index. Existing 140 rows backfilled.
* `database/003b_tranche_1_enrichment.sql` — the one-shot enrichment
  script described above.

**Deployed to dev** (`https://dev.tch.intelligentae.co.uk/`):

* Migration 003 + 003a + 003b applied to the shared dev/prod database
* 14 photos uploaded to `public/uploads/people/TCH-NNNNNN/photo.png`
* Source PDF uploaded to `public/uploads/intake/Tranche 1 - Intake 1.pdf`
* Pre-migration backup of caregivers table preserved at
  `/tmp/caregivers_pre_migration_003.sql` on the server

## [0.5.0] - 2026-04-10

### Added — Person Database (foundation for unified caregiver record)

This release lays the foundation for collapsing student / caregiver / lookup-name
records into a single canonical Person record per individual. Goal: eliminate
the multi-name lookup as soon as the new model is fully populated.

**Schema migration `database/003_person_database.sql`** (additive where possible):

* New lookup tables (replace hard-coded ENUMs, ready for the future config admin page):
  * `person_statuses` — seeded with: Lead, Applicant, Student, In Training,
    Qualified, Available, Placed, Inactive
  * `lead_sources` — seeded with: Facebook, TikTok, Instagram, LinkedIn,
    Walked In, Phoned Us, Emailed Us, Referral, Word of Mouth, Other, Unknown
  * `attachment_types` — seeded with: Original Data Entry Sheet, Profile Photo,
    ID Document, Passport, Proof of Address, Qualification Certificate, Other
* New `attachments` table — files attached to a person (PDFs, ID copies,
  photos), typed via `attachment_types`. Files live on disk under
  `public/uploads/people/<tch_id>/`.
* `caregivers` table extended with all Tuniti intake fields:
  * Personal: `title`, `initials`
  * Contact: `secondary_number`, `complex_estate`
  * NoK: `nok_email`, plus full `nok_2_*` block for multi-value rows
  * Lead source: `lead_source_id` FK + `referred_by_name` / `referred_by_contact`
* `caregivers.tch_id` — immutable, human-facing person identifier (`TCH-000001`),
  generated column derived from `id`. Survives marriage / name corrections.
  Replaces `full_name` as the practical identity field.
* `caregivers.status` ENUM replaced with `status_id` FK → `person_statuses`.
  Existing values backfilled before drop.
* `caregivers.import_notes` (machine-generated) and `caregivers.notes` (human)
  added — split deliberately so audit data and human commentary stay separate.
* `caregivers.import_review_state` ENUM (`pending` / `approved` / `rejected`) —
  filters the import review queue. NULL for records not from import.
* Legacy `caregivers.source` column dropped per session decision (option C).
  Existing values are preserved into `import_notes` immediately before the drop.

**Tuniti intake PDF parser** (`tools/intake_parser/parse_intake.py`):

* Python + PyMuPDF, runs locally. Reads a Tuniti intake PDF and emits:
  JSON records, SQL load file, cropped portrait per candidate, full-page
  reference render per candidate.
* Auto mode tries text extraction; falls back to scaffold mode if the PDF has
  no text layer (current Tuniti exports are image-only).
* `--from-json` mode reads a hand-built or scaffolded records JSON, still
  renders photos and emits SQL.
* Output goes to `tools/intake_parser/output/`.

**Tranche 1 imported** (14 candidates):

* All 14 land with `status_id = 'lead'` and `import_review_state = 'pending'`,
  ready for human review on the new admin page before promotion to a real status.
* Each gets two attachments: Original Data Entry Sheet (PDF page reference)
  and Profile Photo (cropped portrait).
* `import_notes` flags the assumptions made during extraction: typos
  (Preotia, Johnnesburg, Pretoira West, Zimbabwan), geographic
  inconsistencies, the off-tranche student `202603-1`, the Akhona Mkize
  multi-NoK split, and three records with the generic "Social_media" lead
  source that needs follow-up.

**Updated dependent code** to match the new schema:

* `templates/admin/dashboard.php` — `placed` count now joins `person_statuses`
* `templates/admin/names.php` — `cg_status` display joins `person_statuses`
* `database/seeds/ingest.php` — INSERT no longer references the dropped
  `source` column; uses `status_id` lookup; preserves any workbook `source`
  value into `import_notes`

### Added — TODOs

Logged in `docs/TCH_Ross_Todo.md` (items 11–18):

* Config admin page for managing all lookups
* Status promotion gates (validation per status)
* Referrer / affiliate model
* Field-level role-based edit permissions
* Person record card view (mirroring the PDF layout)
* Retire `name_lookup` table once unified person model is complete
* `tch_id` immutable identifier (DONE in this release)
* Replace placeholder portraits with full-quality photos

### Manual rollback notes

If migration 003 needs to be rolled back without git:

1. The migration is wrapped in `SET FOREIGN_KEY_CHECKS = 0` / `1` blocks but
   not in a transaction (DDL in MySQL auto-commits). To revert manually:
   * `DROP TABLE attachments, attachment_types, lead_sources, person_statuses;`
   * `ALTER TABLE caregivers DROP COLUMN tch_id;`
   * `ALTER TABLE caregivers DROP COLUMN status_id;`
   * `ALTER TABLE caregivers ADD COLUMN status ENUM('In Training','Available','Placed','Inactive') NOT NULL DEFAULT 'In Training';`
   * `ALTER TABLE caregivers ADD COLUMN source VARCHAR(50) DEFAULT NULL;`
   * Drop the new caregivers columns: `title`, `initials`, `secondary_number`,
     `complex_estate`, `nok_email`, `nok_2_name`, `nok_2_relationship`,
     `nok_2_contact`, `nok_2_email`, `lead_source_id`, `referred_by_name`,
     `referred_by_contact`, `import_notes`, `notes`, `import_review_state`
2. Source values that were preserved into `import_notes` cannot be split back
   out automatically — they remain visible in the notes column.
3. Backfilled `status_id` mapping is reversible by reading the
   `person_statuses` codes before dropping the lookup table.

## [0.4.0] - 2026-04-09

### Added — Reports & Name Reconciliation

**Three reports under Reports menu** (all login-gated, with filters and drill-down):

1. **Caregiver Earnings by Month** (`/admin/reports/caregiver-earnings`)
   - Summary: caregiver name, tranche, month, days worked, daily rate, total amount
   - Drill-down: click any row to see each day worked — date, day of week, client, rate
   - Filters: caregiver name, tranche, date range (from/to month)

2. **Client Billing by Month** (`/admin/reports/client-billing`)
   - Summary: client name, account number, month, income, expense, margin
   - Drill-down: click any row to see caregivers who worked for that client — date, name, rate
   - Filters: client name, date range

3. **Days Worked by Caregiver** (`/admin/reports/days-worked`)
   - Summary: caregiver, tranche, month, days worked, clients served, avg rate, total value
   - Drill-down: each shift date, client assigned, daily rate
   - Filters: caregiver, client, tranche, date range

**Name Reconciliation screen** (`/admin/names`):
- Table showing all 140 name lookup records: canonical, training, PDF/legal, billing names with match scores
- Colour-coded scores (green >90%, amber >70%, red <70%)
- Approve/Revoke workflow per row — nothing goes live without human approval
- Unmatched billing names panel at top with dropdown to assign to canonical name
- Filters: status (pending/approved), tranche, free-text search across all name fields
- Stats cards: pending count, approved count, unmatched count

**Updated admin layout:**
- Shared sidebar layout (`templates/layouts/admin.php`) — DRY, consistent nav across all admin pages
- Sidebar now has Reports submenu and Data section with Name Reconciliation
- Dashboard updated: shows total revenue, gross margin, link to name review

**Bug fix:** Fixed PhpSpreadsheet `getComment()` call in ingestion script (use worksheet method, not cell method)

### Files changed
- `public/index.php` — added 5 new routes
- `public/assets/css/style.css` — report tables, filters, drill-down, name reconciliation styles
- `templates/layouts/admin.php` (new) — shared admin sidebar layout
- `templates/layouts/admin_footer.php` (new) — shared admin page close
- `templates/admin/dashboard.php` — refactored to use shared layout, added revenue/margin cards
- `templates/admin/reports/caregiver_earnings.php` (new)
- `templates/admin/reports/client_billing.php` (new)
- `templates/admin/reports/days_worked.php` (new)
- `templates/admin/names.php` (new) — name reconciliation screen
- `templates/admin/names_assign.php` (new) — billing name assignment handler
- `database/seeds/ingest.php` — fixed comment extraction for PhpSpreadsheet compatibility

## [0.3.0] - 2026-04-09

### Added — Landing Page, Admin Login & Dashboard

**Front controller** (`public/index.php`):
- Routes all requests: home, login, logout, admin/dashboard, 404

**Public landing page** (`templates/public/home.php`):
- On-brand design using Tuniti Care Hero colour palette (Teal #10B2B4, Charcoal #3A3839, Dark Charcoal #242424)
- Hero section with dual CTA (caregivers / clients)
- Stats bar (140+ caregivers, 60+ clients, Gauteng, QCTO)
- Services grid: Recruitment & Vetting, Certified Training, Placement & Matching
- Split CTA blocks for caregivers and clients
- How It Works 3-step flow
- Contact section, footer with Vistaro/Intelligentae credit
- Fully responsive (mobile-friendly)

**Admin login** (`templates/auth/login.php`):
- CSRF-protected login form
- Styled auth card matching brand
- Error/success alerts, logout confirmation

**Admin dashboard** (`templates/admin/dashboard.php`):
- Sidebar navigation (Dashboard, Caregivers, Clients, Roster, Revenue, Name Reconciliation)
- Live stats cards that pull from DB when available, fall back to placeholders
- Getting Started info panel

**User management foundation** (`database/002_seed_admin.sql`, `database/seeds/create_admin.php`):
- `users` table with username, password_hash (bcrypt), role, active flag
- `login_log` table for audit trail
- CLI script to create Ross as admin user with configurable password

**Shared assets**:
- `public/assets/css/style.css` — complete stylesheet with all brand colours
- `templates/layouts/header.php` / `footer.php` — shared layout
- `templates/errors/404.php` / `403.php` — error pages

### Files changed
- `public/index.php` (new) — front controller
- `public/assets/css/style.css` (new) — main stylesheet
- `templates/layouts/header.php` (new) — shared HTML head
- `templates/layouts/footer.php` (new) — shared footer
- `templates/public/home.php` (new) — landing page
- `templates/auth/login.php` (new) — login page
- `templates/admin/dashboard.php` (new) — admin dashboard
- `templates/errors/404.php` (new) — 404 page
- `templates/errors/403.php` (new) — 403 page
- `database/002_seed_admin.sql` (new) — users + login_log tables
- `database/seeds/create_admin.php` (new) — admin user creation script
- `CHANGELOG.md` — updated

## [0.2.0] - 2026-04-09

### Added — Phase 1: Data Layer & Ingestion

**Database schema** (`database/001_schema.sql`):
- `clients` — master client list with auto-generated account numbers (TCH-C0001 format), enriched with patient name, day rate, billing frequency, shift type, schedule, and entity (NPC/TCH) from the v5 master list
- `caregivers` — full caregiver profiles (140 records): personal details, training tranche/source, assessment scores, qualification status, standard daily rate, placement status
- `caregiver_banking` — banking details (sensitive, finance-role only in Phase 2): bank name, account number, account type, rate notes
- `name_lookup` — name reconciliation table mapping canonical ↔ PDF/legal ↔ training ↔ billing name variants with fuzzy match scores; enforces human approval before any match activates
- `client_revenue` — monthly income/expense/margin per client with source sheet traceability
- `caregiver_costs` — monthly pay per caregiver with days worked, daily rate, and source sheet
- `daily_roster` — 1,619 individual shift records: date, caregiver, client assigned, daily rate
- `caregiver_rate_history` — tracks rate changes over time per caregiver for billing comparison
- `audit_trail` — preserves 264 cell comments from TCH_Payroll_Analysis_v5.xlsx linking summary figures back to raw source sheet/row/column locations
- `margin_summary` — consolidated monthly P&L computed from revenue and cost data

**Ingestion script** (`database/seeds/ingest.php`):
- Reads both Excel workbooks using PhpSpreadsheet
- Populates all 10 tables with cross-referencing (client IDs, caregiver IDs, billing name lookups)
- Extracts audit trail comments from Client Summary (129) and Caregiver Summary (135) tabs
- Builds rate history from daily roster data, auto-sets each caregiver's current standard rate
- Computes margin summaries per month from actual revenue and cost data
- Reports unmatched records at completion for data quality review

**Project setup**:
- `composer.json` with phpoffice/phpspreadsheet dependency

### Files changed
- `database/001_schema.sql` (new) — full MySQL schema, 10 tables
- `database/seeds/ingest.php` (new) — data ingestion CLI script
- `composer.json` (new) — PHP dependency management
- `CHANGELOG.md` (new) — this file

## [0.1.0] - 2026-04-09

### Added — Project Scaffolding

- `.htaccess` — forces HTTPS, routes all traffic through `public/`
- `public/.htaccess` — front-controller routing
- `includes/config.php` — .env loader, app/db constants
- `includes/db.php` — PDO database connection (prepared statements, no emulation)
- `includes/auth.php` — authentication system: secure sessions, CSRF, login/logout, role-based access, login audit logging, bcrypt password hashing with auto-rehash
- `.env.example` — environment config template
- `.gitignore` — excludes secrets, IDE files, vendor, Chat History
