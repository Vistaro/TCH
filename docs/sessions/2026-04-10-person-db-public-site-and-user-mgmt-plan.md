# Session: 2026-04-10 — Person Database, Public Homepage, User Management Plan

**Owner:** Ross
**Project:** TCH Placements
**Branch worked on:** `dev` (with first-ever `main` created and pushed at end)
**Sessions count covered:** One single (long) session
**Outcome:** v0.5.0 → v0.6.0 + hamburger fix delivered to **prod**. User management build plan locked for next 3 sessions.

---

## Agenda (in order of execution)

1. Field-set agreement for Tuniti intake PDFs (1 sample PDF reviewed)
2. Schema design for unified Person record (no separate students/caregivers tables)
3. Migration 003 — Person Database (additive schema overhaul)
4. Tuniti intake PDF parser (Python + PyMuPDF)
5. Tranche 1 enrichment (14 candidates) + admin review page
6. Deploy to shared dev/prod database
7. Tranches 2–9 enrichment (109 candidates)
8. Tuniti follow-up TODO list capturing data quality issues
9. Public homepage rebuild based on Tuniti site analysis
10. Configurable per-region contact details (regions table)
11. Public enquiry form with POPIA consent + admin inbox
12. Image prompts for ChatGPT for upcoming brand imagery
13. Mobile hamburger menu fix
14. Production deployment (first ever main branch)
15. User management + RBAC + audit + impersonation **plan only** — execution deferred

---

## What was built and shipped

### v0.5.0 — Person Database foundation (commit `9ae5292`)

Migration `database/003_person_database.sql` (additive where possible):

- New lookup tables, all editable via the future config admin page:
  - `person_statuses` — Lead, Applicant, Student, In Training, Qualified, Available, Placed, Inactive
  - `lead_sources` — Facebook, TikTok, Instagram, LinkedIn, Walked In, Phoned Us, Emailed Us, Referral, Word of Mouth, Other, Unknown
  - `attachment_types` — Original Data Entry Sheet, Profile Photo, ID Document, Passport, Proof of Address, Qualification Certificate, Other
- New `attachments` table for files attached to a person
- `caregivers` table extended with: title, initials, secondary_number, complex_estate, nok_email, nok_2_*, lead_source_id, referred_by_*, import_notes, notes, import_review_state, status_id (replaces ENUM), tch_id (immutable identifier)
- Legacy `caregivers.source` column dropped per Ross's decision (option C); existing values preserved in `import_notes` first

`tools/intake_parser/parse_intake.py`:

- Python + PyMuPDF parser
- Reads a Tuniti intake PDF, emits records JSON, SQL load file, cropped portrait per candidate, full-page reference render
- `--from-json` mode reads a hand-built records JSON
- Output goes to `tools/intake_parser/output/` (gitignored — contains PII)

Dependent code updates in `templates/admin/dashboard.php`, `templates/admin/names.php`, `database/seeds/ingest.php`.

### v0.5.0 → 0.5.1 fix-up

Migration `003_person_database.sql` partially applied. The `tch_id GENERATED ALWAYS AS` clause failed under MariaDB 10.6 (auto_increment columns can't be referenced by generated columns). Created `database/003a_finish_migration.sql` to:

- Add `tch_id` as a regular VARCHAR column with unique index
- Backfill all 140 existing rows
- Run the source-preservation UPDATE
- Drop the legacy `source` column

### v0.5.1 — Tranche 1 enrichment + admin review page (commit `b3c3d22`)

Cross-checked all 14 Tranche 1 PDF candidates against the existing 140 caregivers — **all 14 already existed** as workbook stubs (12 of 14) or with conflicting workbook data (Jolie + Mukuna). Per Ross's decision, the Tuniti PDF data was adopted as canonical and old workbook values preserved verbatim in `import_notes`.

Special case for id 5 (Jovani Mukuna Tshibingu): the DB full_name "Jovani" was kept because the PDF title spells it "Jonvai" — typo confirmed by the PDF's own Known As field.

System-wide tranche label standardisation: `1st Intake` → `Tranche 1` etc., across all 113 caregivers in 9 cohorts. `N/K` preserved.

`database/003b_tranche_1_enrichment.sql` — 14 UPDATEs + 28 attachment INSERTs.

New admin page: `templates/admin/people_review.php` at `/admin/people/review`. List view + per-person card view styled to mirror the Tuniti intake PDF layout. Approve / Reject actions, CSRF-protected, append audit lines to `import_notes`. New sidebar nav entry. CSS additions: `.person-card` and child classes, `.btn-danger`.

### v0.5.2 — Tranches 2–9 enrichment (commit `8baa0fd`)

109 caregivers across the remaining 8 tranches enriched from PDFs. Identical pattern to Tranche 1: cross-match by name, UPDATE existing rows, mark `import_review_state='pending'`, attach source PDF + cropped portrait.

`database/003c_tranches_2_9_enrichment.sql` — 109 UPDATEs + 218 attachment INSERTs across 8 transactions (each tranche its own transaction so a failure in one doesn't block the others).

New lead sources surfaced and added: `website` (9 candidates), `advertisement` (3 candidates).

`tools/intake_parser/upload_photos.py` — staging script that reorganises rendered portraits into per-person folders ready for SCP.

Tuniti follow-up TODO section added (commit `2d74444`) — see `docs/TCH_Ross_Todo.md`.

### v0.6.0 — Public homepage rebuild (commit `9b5596e`)

Migration `database/004_regions_and_enquiries.sql`:

- `regions` table — phone, email, address, service area, hero copy, office hours, social URLs. Seeded with Gauteng row (placeholder phone `XXX XXX XXXX`, placeholder email). Public homepage loads its primary region from this table — contact details now configurable without code changes.
- `enquiries` table — public form submissions with full audit metadata (IP, UA, referrer), POPIA consent, status workflow.

`templates/public/home.php` rewritten:

- Hero with TCH-branded warm hero text
- Live stats bar from caregivers/clients counts
- Care Services block: 6 cards (5 named Tuniti services + "Not Sure" CTA) — Full-Time, Post-Op, Palliative, Respite, Errand
- Why TCH 4-tile differentiator block (Verified/Vetted/Trained, Matched not Sent, Cover When Life Happens, One Trusted Brand)
- How It Works 3-step process
- Trust block: "You're not just hiring a person, you're joining a network"
- POPIA-compliant enquiry form (CSRF, honeypot, allow-list care types)
- Contact section pulled from regions table

`templates/public/enquire_handler.php` — POST handler with CSRF check, honeypot check, server validation, audit metadata capture, INSERT into `enquiries`.

`templates/admin/enquiries.php` — admin inbox at `/admin/enquiries`. List view filterable by status with badge counts. Detail view with status workflow + audit-stamped notes.

`templates/layouts/footer.php` refreshed to read phone/email/area from regions row via `$footerRegion`.

`docs/Brand_Image_Prompts.md` — eight ChatGPT/DALL-E prompts for brand imagery, with SA demographic guidance (caregivers predominantly Black SA women per 87% population share, clients typically older White Afrikaans), exact filenames, aspect ratios, and anti-cliché rules. Page works without the images thanks to CSS gradient fallbacks.

Mass CSS additions for all the above sections.

### Mobile hamburger fix (commit `4b4ad0f`)

Public navbar `.public-menu-toggle` added — fixed top-left, only visible below 768px. Drawer slides in from the left, body scroll locked while open. Click overlay or any nav link to close. ARIA attributes for accessibility.

### Production deployment

First-ever push to `main` and prod files. Sequence:

1. Server-side backup: `cp -a ~/public_html/tch ~/public_html/tch_backup_pre_v0.6_2026-04-10`
2. `git checkout -b main && git push -u origin main` — created main branch from current dev state
3. Server-side rsync: `rsync -av --delete --exclude='.env' --exclude='database/backups/' ~/public_html/dev-TCH/dev/ ~/public_html/tch/`
4. Smoke tests (all 200 / 302 as expected):
   - https://tch.intelligentae.co.uk/ → 200, contains "Trusted Caregivers", "Find a Care Hero", placeholder phone, hamburger toggle
   - /admin/people/review → 302 to /login (auth gate working)
   - /admin/enquiries → 302 to /login
   - /uploads/people/TCH-000001/photo.png → 200
   - /uploads/people/TCH-000123/photo.png → 200
   - /uploads/intake/Tranche 1 - Intake 1.pdf → 200
   - /enquire (GET) → 302 (handler redirecting GETs back to homepage as designed)
5. Ross to purge CDN cache via StackCP > CDN > Edge Caching

**DB note:** dev and prod share the same database (per memory `hosting_deployment.md`). All migrations were already applied during the dev work, so no DB changes were needed in the prod deploy step — only files and uploads.

---

## Files created or significantly modified (this session)

### Database
- `database/003_person_database.sql` (created)
- `database/003a_finish_migration.sql` (created — fix for MariaDB compat)
- `database/003b_tranche_1_enrichment.sql` (created)
- `database/003c_tranches_2_9_enrichment.sql` (created)
- `database/004_regions_and_enquiries.sql` (created)
- `database/seeds/ingest.php` (modified — schema-aware INSERT)

### Templates
- `templates/admin/dashboard.php` (modified — joins person_statuses)
- `templates/admin/names.php` (modified — joins person_statuses)
- `templates/admin/people_review.php` (created)
- `templates/admin/enquiries.php` (created)
- `templates/layouts/admin.php` (modified — sidebar entries)
- `templates/layouts/footer.php` (modified — reads from regions)
- `templates/public/home.php` (rewritten)
- `templates/public/enquire_handler.php` (created)

### Public assets
- `public/assets/css/style.css` (~600 lines added across two passes)
- `public/index.php` (modified — new routes)

### Tools
- `tools/intake_parser/parse_intake.py` (created)
- `tools/intake_parser/upload_photos.py` (created)

### Docs
- `CHANGELOG.md` (added v0.5.0, v0.5.1, v0.5.2, v0.6.0)
- `docs/TCH_Ross_Todo.md` (added 8 new TODO items + Tuniti approval section + locked user mgmt build plan)
- `docs/Brand_Image_Prompts.md` (created)
- `docs/sessions/2026-04-10-person-db-public-site-and-user-mgmt-plan.md` (this file)

### Project
- `.gitignore` (added `tools/intake_parser/output/` and `docs/PDF Imports/`)

---

## Commits (in order)

| Hash | Version | Subject |
|---|---|---|
| `9ae5292` | v0.5.0 | Person Database — schema migration 003 + Tuniti intake parser |
| `b3c3d22` | v0.5.1 | Tranche 1 enrichment + admin review page |
| `8baa0fd` | v0.5.2 | Tranches 2-9 enriched (109 caregivers + 218 attachments) |
| `2d74444` | (docs)  | Tuniti approval items + data quality issues |
| `9b5596e` | v0.6.0 | Public homepage rebuild + enquiry form + regions config |
| `4b4ad0f` | (fix)   | Public navbar: hamburger menu top-left on mobile |

`main` branch was created from `4b4ad0f` and pushed to GitHub for the first time.

---

## Database migrations applied (shared dev/prod DB)

| File | What it did |
|---|---|
| `003_person_database.sql` | Lookup tables, attachments table, caregivers extension (partial — failed at tch_id step) |
| `003a_finish_migration.sql` | Completed 003: tch_id as regular column, backfill, source preservation, source column drop |
| `003b_tranche_1_enrichment.sql` | 14 caregiver UPDATEs (Tranche 1) + 28 attachment INSERTs + tranche label standardisation system-wide |
| `003c_tranches_2_9_enrichment.sql` | 109 caregiver UPDATEs (Tranches 2-9) + 218 attachment INSERTs + 2 new lead sources |
| `004_regions_and_enquiries.sql` | regions + enquiries tables with Gauteng seed |

---

## Server backups created

Path | Contents
---|---
`/tmp/caregivers_pre_migration_003.sql` | caregivers table dump just before migration 003 ran (140 rows)
`~/public_html/dev-TCH/dev/database/backups/caregivers_pre_tranches_2_9.sql` | caregivers + attachments dump just before tranches 2-9 enrichment ran
`~/public_html/tch_backup_pre_v0.6_2026-04-10/` | Full prod filesystem snapshot before the first prod deploy (7.8 MB)

---

## Outstanding work for next session

### High priority
1. **User management + RBAC + audit + impersonation** — full plan locked in `docs/TCH_Ross_Todo.md` under "Locked Build Plan: User Management". Three sessions: Schema/auth/mailer/public flows → admin UIs/impersonation/integration → audit log integration sweep.
2. **Replace placeholder contact details** in the `regions` row — phone is still `XXX XXX XXXX`, email is still `hello@tch.intelligentae.co.uk`. Update directly in DB once Ross has the real values.
3. **Tuniti follow-up list** — Ross to walk Tuniti through the data quality issues in `docs/TCH_Ross_Todo.md` "Requires Tuniti Approval / Clarification" section.
4. **Brand imagery** — Ross to feed the prompts in `docs/Brand_Image_Prompts.md` to ChatGPT and drop the resulting JPEGs into `public/assets/img/site/` on dev and prod.

### Medium priority
5. **Person Review queue (123 caregivers in pending)** — Tuniti's job per the latest TODO update. Ross will hand the queue to Tuniti to walk through.
6. **CDN cache purge on prod** — Ross to do via StackCP > CDN > Edge Caching after seeing the prod deploy.

### Lower priority / future
7. Config admin page (manage statuses, lead sources, attachment types via UI)
8. Status promotion gates (validation per status — required-fields-per-state)
9. Referrer / affiliate model
10. Field-level role-based edit permissions
11. Person record card view styling polish
12. Retire `name_lookup` table
13. Replace placeholder portraits with full-quality photos
14. Email notifications on enquiry submission
15. Spam/rate limiting on enquiry form (CAPTCHA / Turnstile)
16. Per-region pages (Western Cape, KZN, etc.) — infrastructure ready, just need new region rows + routing

---

## Re-entry instructions for next session

When Ross logs back in to start Session A of the user management build:

1. Branch is `dev`, working directory clean (modulo `.claude/settings.local.json` and `audio_extract.mp3` which are local).
2. `main` exists for the first time at `4b4ad0f`. Future prod deploys will merge `dev` into `main`.
3. The plan to execute is in `docs/TCH_Ross_Todo.md` under "Locked Build Plan: User Management + RBAC + Audit + Impersonation".
4. Session A scope: Migration 005, auth library, mailer, public auth flows. End that session with `ross@intelligentae.co.uk` as canonical login on dev.
5. Existing `ross` user on the live DB has password `TchAdmin2026x` (per memory) — migration must preserve this.
6. Email delivery: PHP `mail()`. Email log table will store every send so dev testing can copy the link manually if `mail()` fails.
7. Shared dev/prod DB — schema migrations applied to one are applied to both. Be mindful when running 005.

---

## Notes / lessons captured this session

- **MariaDB 10.6 forbids generated columns referencing `AUTO_INCREMENT`**. Plan around this for any future "generated identifier" columns.
- **The Tuniti intake PDFs are image-only** (no text layer). PyMuPDF text extraction returns empty. Workflow: render photos via PyMuPDF, build records JSON manually using vision, run parser in `--from-json` mode to emit SQL.
- **Cross-tranche cross-check is mandatory** before any bulk enrichment. Found that 14/14 Tranche 1 candidates already existed in the DB under different student IDs and known_as values — same will likely apply to any future bulk imports.
- **The 87% Black SA population** rule needs to be applied to all caregiver imagery. Captured in `docs/Brand_Image_Prompts.md`.
- **Dev and prod share a database**. Anyone making schema changes should be deliberate — there is no isolation between environments at the data layer.
