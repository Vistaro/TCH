# TCH Placements — Architecture

This is the code brief. If you've just cloned the repo and need to make
a small change, read this first.

## Stack

- **PHP 8.1** (bare — no framework, no Composer runtime deps)
- **MariaDB 10.x** (accessed via PDO; prepared statements throughout)
- **Apache** with mod_rewrite (StackCP shared hosting)
- No build step, no JS framework — a little vanilla JS, most
  interactivity is server-rendered
- FontAwesome 6 via CDN for icons

## Hosting

- **PROD:** `https://tch.intelligentae.co.uk`
  webroot `~/public_html/tch/` on
  `intelligentae.co.uk@ssh.gb.stackcp.com`
- **DEV:** `https://dev.tch.intelligentae.co.uk`
  webroot `~/public_html/dev-TCH/dev/` on the same box
- **Databases separated 2026-04-14 (v0.9.22)** — closes FR-0076.
  - PROD: `tch_placements-313539d33a` on `shareddb-y.hosting.stackcp.net`
  - DEV:  `tch_placements_dev-353032377731` on `sdb-61.hosting.stackcp.net`
  - Going-forward rule: once real users are live, any further dump /
    restore happens only inside a declared maintenance window with
    user-facing routes offline.
- Deploy = server-side rsync `dev-TCH/dev/ → tch/` after Ross signs
  off. No CI. Pattern in `docs/sessions/*-prod-deploy.md`.

## Folder layout

```
/public/           — document root. index.php is the front controller.
/includes/         — shared PHP: db.php, auth.php, permissions.php,
                     mailer.php, activities_render.php, countries.php,
                     activity_log_render.php, activity_log_revert.php
/templates/
  /admin/          — all admin screens (one .php per screen)
  /admin/reports/  — reporting screens
  /auth/           — login, password reset, setup-password
  /public/         — marketing site (home.php + enquiry form)
  /layouts/        — admin.php + admin_footer.php shell
/database/         — migration files 001 → 016. Run in numeric order
                     against a fresh DB to rebuild. Each is a single
                     transaction; re-runnable where safe.
/tools/
  /intake_parser/  — one-off Python scripts that built the initial
                     student records from Tuniti PDFs + attendance xlsx.
                     Kept for auditability; not used by the runtime.
/docs/             — Vision, plan, session notes, design artefacts
```

## Front controller (`public/index.php`)

Every request routes through `index.php?route=<path>` via mod_rewrite.
The controller does two things in order:

1. **Parametric routes** (`preg_match` on the route) — e.g.
   `admin/users/123`, `admin/students/47`. Handles anything that takes
   a numeric id.
2. **Static routes** — `switch ($route)` for everything else.

Every admin route calls `requirePagePermission($pageCode, $action)`
from `includes/auth.php`. Pages are registered in the `pages` table
(seeded by migrations 005–016) and permissions are seeded in
`role_permissions` for role_id=1 (Super Admin) by default.

## Auth

- Session-based, cookie-signed, `HttpOnly` + `SameSite=Strict` +
  `secure` flags.
- Login at `/login`, forgot / reset / setup flows in
  `templates/auth/`.
- Impersonation supported via `requireRole('super_admin')` — Super
  Admin can "become" another user; the audit log tracks the
  impersonator separately from the effective identity.
- CSRF tokens generated per-session in `auth.php` and validated on
  every POST.

## Data model

### Single source of truth

The most important invariant: **one fact lives in one place**. If a
value is derivable, compute it at read time in the query, never cache
it as a column. Migration 007/008 dropped a set of cached summary
columns that had drifted during a dedup — see DECISIONS.md.

### People: one table, three roles

```
persons  (id, tch_id, person_type SET, full_name + name parts,
          archived_at, …)
           │
           ├── caregivers (person_id PK, …)  for caregiver-specific fields
           ├── clients    (id, person_id, account_number, billing_…)
           ├── patients   (person_id PK, client_id, patient_name)
           ├── students   (person_id PK, cohort, avg_score, qualified, …)
           ├── person_phones    (multi-phone, primary flag)
           ├── person_emails    (multi-email, primary flag)
           └── person_addresses (multi-address, primary flag)
```

`person_type` is a SET so one human can be both caregiver and student
(normally true — caregivers-in-training). TCH IDs are assigned once
(`TCH-000001`) and survive name changes.

**Name parts (added migration 024)** — `salutation`, `first_name`,
`middle_names`, `last_name`. `full_name` remains the canonical display
string; on edit it auto-recomposes from the parts. Best-effort split
of historical `full_name` populated the parts on migration.

**Archive (added migration 024)** — `archived_at`,
`archived_by_user_id`, `archived_reason`. Soft-delete only; list
queries default `WHERE archived_at IS NULL` with a "Show archived"
toggle. There is no hard-delete pathway for client/patient records.

**Multi-contact tables (added migration 024)** —
`person_phones` / `person_emails` / `person_addresses` carry one row
per contact method with a `is_primary` flag. The legacy scalar
columns (`persons.mobile`, `secondary_number`, `email`, and the flat
address columns) are mirrored from the *primary* row of each new
table on every save and remain authoritative for code that hasn't
been migrated to read from the new tables yet. See `DECISIONS.md` for
the rationale for keeping the mirror.

**Client identity convention** — `clients.id = persons.id` for every
row (set explicitly on insert in both migration 009 and the new
client_create handler). Existing FKs on `client_revenue.client_id`
and `daily_roster.client_id` were populated with `persons.id`-style
values, so this invariant must hold going forward — never let
`clients` use AUTO_INCREMENT alone for new rows.

### Training lifecycle

```
student_enrollments  — one row per (student, cohort) enrolment
                       cohort varchar, enrolled_at, graduated_at,
                       dropped_at, status enum
training_attendance  — one row per (student, week) attendance event
                       attendance_type: classroom | practical | ojt
student_scores       — one row per (student, module) score
```

### Contracts / engagements / roster — the single-source-of-truth ledger

Three distinct layers. Clean separation matters because the
commercial contract, the caregiver assignment, and the per-shift
delivery each change independently.

```
contracts          — commercial contract: client × patient × start ×
                     end (nullable = ongoing) × status × invoice
                     fields. One row per patient. Superseded_by chain
                     for mid-contract product switches. Also acts as
                     a quote while status ∈ (draft, sent, rejected,
                     expired); flips to a live contract on acceptance.
                     Carries quote_reference / sent_at / accepted_at /
                     acceptance_method / acceptance_note for the
                     quote state machine (added migration 038).
contract_lines     — product × billing_freq × min_term × bill_rate ×
                     units_per_period × start_date × end_date
                     (per-line dates added migration 037; each line
                     can have its own run, end_date NULL = ongoing).
                     billing_freq accepts 'hourly' since migration 038.
product_billing_rates — child of products (migration 036). Each
                     product carries 1..N (product_id, billing_freq,
                     rate, currency_code, is_default, is_active)
                     rows. The is_default=1 + is_active=1 row drives
                     prefill on new quote / contract lines.
                     products.default_billing_freq and default_price
                     stay in place as backwards-compat until all
                     call-sites cut over (FR-A2 follow-up).
engagements        — caregiver assignment to a contract (exists today,
                     being repurposed from the old all-in-one model).
daily_roster       — per-shift delivery. Columns: caregiver_id,
                     patient_person_id, client_id, contract_id (new),
                     engagement_id, product_id, units, cost_rate,
                     bill_rate, shift_start/end, status
                     (planned/delivered/cancelled/disputed),
                     source_upload_id + source_alias_id + source_cell
                     for full audit trail back to the source Excel.
                     Every financial report reads from this one table.
```

**Ingest pipeline** (`tools/timesheet/` — Node CLI):
- Parses Tuniti's Caregiver Timesheet workbook (cost side — one tab
  per month, caregiver columns × date rows, patient name per cell)
  and the Revenue Panel workbook (bill side — client panels with
  monthly invoice amounts).
- Wipes + rebuilds `daily_roster` for the ingested month range.
- `cost_rate` resolved via the 5-rule priority (see DECISIONS.md).
- `bill_rate` on roster rows is **legacy** (apportioned from
  Panel invoices at ingest time). **Not read by revenue reports** —
  they pivot `client_revenue` directly at invoice grain. The
  column remains for contract/engagement-agreed rates and as a
  display aid on per-shift screens, but the apportionment logic
  is being phased out. See `DECISIONS.md` 2026-04-14.
- Shifts where the Panel workbook has no matching invoice for the
  client-month keep their **true `client_id`** — no sentinel
  overwrite. The "Care without matching invoice" admin tile
  computes the gap live via `LEFT JOIN client_revenue` at report
  time.

### Revenue vs cost — the two grains

```
daily_roster       — COST side. One row per shift delivered.
                     caregiver × patient × date × cost.
                     Roster answers: what did we do, what did it cost.

client_revenue     — REVENUE side. One row per client × invoice-month.
                     Roster answers: what did we bill.
```

Revenue reports (Dashboard Total Revenue, Client Billing, Client
Profitability) read `client_revenue` exclusively. Cost reports
(Caregiver Earnings, Roster View, Unbilled Care shift list) read
`daily_roster` exclusively. Profitability computes the two
separately and subtracts. Apportioning invoices down to per-shift
`bill_rate` is a legacy hack that conflates the two grains.

**Alias layer** — `timesheet_name_aliases` maps raw names from
Timesheet cells and Panel panel headers to canonical `persons.id`
rows. Admin at `/admin/config/aliases`. Rows carry `confidence` enum;
unresolved rows block the ingest. When an alias is remapped, a
planned trigger will propagate via `roster.source_alias_id` (not
built yet).

**Timesheet_uploads** — one row per workbook version ingested,
sha256-keyed, with dry_run_report JSON.

### Activities (Notes + Tasks)

```
activity_types  — lookup (Note, System, Email, Phone Call, …)
activities      — polymorphic timeline, one row per entity event.
                  entity_type ENUM('persons','enquiries'),
                  source / source_ref / source_batch for provenance.
```

Renders as the Notes timeline on any entity detail page via
`includes/activities_render.php::renderActivityTimeline()`. The same
module provides `logSystemActivity()` for import / workflow writes
with structured source citation.

### Audit trail

`activity_log` table is the forensic, immutable audit — every mutation
goes here via `logActivity()` in `includes/permissions.php`, with
`before_json` / `after_json` full snapshots. Do not conflate with
`activities` — different table, different purpose. Rule: **every POST
handler that mutates state logs to `activity_log` in the same
request**.

## Key modules (what each one does)

- **`includes/db.php`** — PDO connection + `getDB()` singleton.
- **`includes/auth.php`** — sessions, login/logout, CSRF, user-fetch
  helpers, impersonation.
- **`includes/permissions.php`** — `userCan()`, `requirePagePermission()`,
  hierarchy visibility helpers, `logActivity()`.
- **`includes/activities_render.php`** — Notes timeline render + save.
- **`includes/contact_methods.php`** — multi-row phone/email/address
  helpers (`getPersonPhones`, `savePersonPhones`, etc.). Save helpers
  mirror the primary row back to the legacy scalar columns on persons.
- **`includes/dedup.php`** — `findPossibleDuplicates(personType,
  candidate)` for the create-form dedup screen. Match signals: exact
  phone (in person_phones), exact email (in person_emails), exact
  ID/passport, name Levenshtein ≤ 3 OR same soundex.
- **`includes/activity_log_render.php`** / **`_revert.php`** — renders
  the audit log and implements revert (undo a past mutation) without
  destroying history.
- **`includes/countries.php`** — country + dial-code lookup, SA-first
  dropdowns, E.164 phone helpers (`splitE164`, `joinE164`).
- **`includes/mailer.php`** — SMTP via server config; used for
  password setup, invite emails, bug reporter confirmations.

## External integrations

- **Nexus Hub** (`https://hub.intelligentae.co.uk`) — central bug/FR
  tracker. TCH has an API token in `.env` as `NEXUS_HUB_TOKEN` and
  POSTs issues from the in-app reporter. Managed by the Nexus Hub
  agent on the shared PROD box.
- **No other external services.** No Stripe, no Mailgun, no Twilio. If
  a feature needs one of these, flag it to Ross first.

## Conventions specific to this project

- **Every mutation handler logs to `activity_log` with before/after.**
  No exceptions — standing rule.
- **Delete via `activity_log_delete()` helper** — never direct
  `DELETE FROM …` in a handler. Helper captures the full row for the
  undelete pathway.
- **Names**: `full_name` is canonical (surname order South African
  style), `known_as` is the familiar name. Import pipelines write both.
- **Phones**: stored E.164 (`+27XXXXXXXXX`). UI splits into
  dial-prefix + national input on edit.
- **Cohorts**: free-text string `"Cohort N"` on `students.cohort` and
  `student_enrollments.cohort`. No `cohorts` lookup table — not worth
  it at current scale.
- **Migrations are append-only.** Once a migration number ships, it
  does not change. If a schema change needs walking back, write a new
  migration that does it.
- **Backups before PROD migrations.** Logical backup of affected
  tables to `~/db-backups/tch/` on the server.

## Where history lives

- **Commits** — conventional commits on `dev` branch, squashed into
  `main` on release. Never commit directly to main.
- **CHANGELOG.md** — versioned entry per release, detailed enough that
  the release could be manually reverted if git disappeared.
- **Session notes** — `docs/sessions/*.md`, one per substantive working
  session, written before closing the session.
- **Chat History** — `C:\ClaudeCode\_global\Chat History\TCH\*.md`,
  meeting-minutes format, one per session, never committed to git.
  Backed up to OneDrive.
