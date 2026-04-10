# Changelog

All notable changes to the TCH Placements project.

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
