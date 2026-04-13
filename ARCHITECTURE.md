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
- Both share **one database** (`tch_placements-313539d33a`) — a
  documented exception to the global "separate dev and prod DBs" rule,
  justified by there being no real customer-driven mutation traffic
  yet. Tracked as FR-0076 on the Nexus Hub. The exception expires
  the moment the first real client, caregiver, or Tuniti approval
  moves through the system.
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
persons  (id, tch_id, person_type SET, full_name, …)
           │
           ├── caregivers (person_id PK, …)  for caregiver-specific fields
           ├── clients    (id, person_id, …)  for client-specific fields
           ├── patients   (person_id PK, …)  for patient-specific fields
           └── students   (person_id PK, cohort, avg_score, qualified, …)
```

`person_type` is a SET so one human can be both caregiver and student
(normally true — caregivers-in-training). TCH IDs are assigned once
(`TCH-000001`) and survive name changes.

### Training lifecycle

```
student_enrollments  — one row per (student, cohort) enrolment
                       cohort varchar, enrolled_at, graduated_at,
                       dropped_at, status enum
training_attendance  — one row per (student, week) attendance event
                       attendance_type: classroom | practical | ojt
student_scores       — one row per (student, module) score
```

### Engagements & roster (Phase 4 — partially live)

```
engagements   — the contract: caregiver × patient × product × date
                range × cost_rate × bill_rate
daily_roster  — per-shift: planned/delivered/cancelled/disputed,
                cost_rate + bill_rate so cost AND revenue reconcile at
                shift level. Current state: carries cost only;
                bill_rate column added but not yet populated from
                engagements. See D2 design note in TCH_Ross_Todo.md.
```

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
