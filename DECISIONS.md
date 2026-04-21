# TCH Placements — Decisions Log

Append-only. One entry per non-obvious design choice. Format:

```
## YYYY-MM-DD — <title>
**Chose:** X
**Over:** Y, Z
**Because:** …
```

## 2026-04-21 — `patient_care_needs` as single-row TEXT-field schema, not normalised

**Chose:** One row per patient in `patient_care_needs` with TEXT columns per care category (medical_conditions, allergies, medications, mobility, hygiene, cognitive, emotional, dietary, recreational, language, care_summary) + a single DNR ENUM + notes.
**Over:** Fully normalised tables (`patient_allergies`, `patient_medications`, each with structured fields like severity / dose / frequency / reaction), linked N:1 to patient.
**Because:** We don't yet know what structured queries Tuniti actually needs — "patients allergic to X" reports aren't a live requirement. TEXT is faithful to how Tuniti captures this information today (paper + WhatsApp notes). The fields are semantically distinct and labelled in the UI, which preserves most of the benefit of normalisation for human reading while avoiding the schema work + data-migration churn when we don't yet know the shape of the queries. Normalise later if (a) we need drug-interaction reporting, (b) we need structured allergy severity matching in the matching engine (FR-5.1), or (c) Tuniti starts asking for filters on specific fields. Documented in mig 048 comments + on the `patient_care_needs` card component so the next dev doesn't reach for refactoring without understanding the deliberate pragmatism.

## 2026-04-18 — `/admin/help` bound to `dashboard.read`, not its own RBAC page
**Chose:** Route the user-guide page at `/admin/help` through the generic `dashboard.read` permission. Every logged-in admin sees the guide regardless of their specific page grants.
**Over:** Registering a dedicated `help.read` page in the `pages` / `role_permissions` registry so each role's access is managed independently.
**Because:** The guide is informational and aids onboarding — hiding it from any logged-in admin only costs them time finding answers. A separate permission would add a row to maintain with zero practical gating we'd actually want. Revisit if/when caregiver or client portals exist and non-admin users reach the system — at that point the guide either splits into admin/caregiver/client flavours or picks up a role-aware filter. Logged 2026-04-18 with Ross's sign-off in the autonomous-cleanup session; see `docs/sessions/2026-04-18-autonomous-cleanup.md`.

## 2026-04-16 — Per-line dates on `contract_lines`; parent `contracts.start_date` / `.end_date` become a display cache (FR-B)
**Chose:** Add `start_date` + `end_date` (nullable) to `contract_lines` via migration 037. Each line carries its own run; `end_date = NULL` means ongoing. The parent `contracts.start_date` / `contracts.end_date` stay in place for now but are treated as display-cache / sort-key only — anywhere that cares about a line's actual window reads the line's own dates, falling through to the contract's dates only when the line's are NULL (migration 037 backfilled every existing row, so the fallback is a safety net for edge cases, not the primary path).
**Over:** Leaving the single contract-level start/end and forcing every line to share them; or dropping `contracts.start_date` / `.end_date` immediately.
**Because:** Real quotes bundle multiple products with different runs — "Day Care Jan–Mar, Errand Care Feb-ongoing" is common. Single contract-level dates either forced identical windows across lines (wrong) or required a separate contract per run (fragmented the commercial record). Per-line dates model reality. Retaining the contract-level columns avoids a noisy cutover — `contracts_list.php` and `onboarding_contracts.php` still sort by `c.start_date`, which is fine as a summary value. A follow-up (FR-B2) will either compute `contracts.start_date` / `.end_date` from lines (MIN/MAX with NULL-wins-for-ongoing) on read and retire the stored columns, or leave them as an advisory cache — TBD when FR-B2 is scoped.

## 2026-04-16 — Upload extension whitelist widened beyond spreadsheets
**Chose:** Allowlist of `xlsx, xls, csv, pdf, doc, docx, png, jpg, jpeg, txt` on `includes/onboarding_upload.php::onboardingHandleUpload()`.
**Over:** Spreadsheet-only list (`xlsx, xls, csv`).
**Because:** Task 3 (caregiver_patterns) upload hint explicitly invites non-spreadsheet uploads ("Availability list, WhatsApp thread, spreadsheet — anything you have"). A spreadsheet-only allowlist would break documented intent — Tuniti realistically sends PDF printouts, Word docs, WhatsApp screenshots. The widened list still blocks every dangerous class: `.php`, `.svg`, `.exe`, `.js`, `.html`, `.htaccess`. MIME-content validation via `finfo_file()` is deferred as a defence-in-depth follow-up FR — the extension allowlist is the floor, not the ceiling.

## 2026-04-16 — `onboarding_tasks.php#permission_page` harmonised to `'onboarding'` despite being dead metadata
**Chose:** Set `permission_page => 'onboarding'` on every entry in `onboardingTasks()`, including task 3 (`caregiver_patterns`) and task 5 (`timesheet_recon`) which had inherited `'caregiver_view'` from an earlier scoping attempt.
**Over:** (a) Leaving the two entries on `'caregiver_view'` and documenting the inconsistency; (b) Removing the `permission_page` field altogether.
**Because:** The field is declared but not read anywhere in the codebase — the onboarding dashboard (`templates/admin/onboarding_dashboard.php`) renders every registered task unconditionally; route-level gating is done by `requirePagePermission('onboarding','read')` in `public/index.php`, and the edit gate is the `$canEdit = userCan(...)` call inside each subpage template. So today the field is dead metadata. Harmonising costs nothing, protects against a future change that starts reading it (a filter on the dashboard, a cron job that rescans tasks by page scope, etc.), and keeps the registry coherent with itself. Removing the field would be a larger rework than this commit warrants; left as a cleanup candidate for the next pass on onboarding.

## 2026-04-16 — Ambiguous-name cascade in timesheet reconciliation: skip rather than LIMIT 1
**Chose:** When resolving the caregiver for a `rate_corrected` reconciliation, COUNT matches first against both the alias table and `persons.full_name`. If either returns > 1, flag the row as `ambiguous` and skip the `UPDATE caregivers SET day_rate`. The resolution row still saves with the new rate; the flash tells Tuniti to map the alias first.
**Over:** The previous `LIMIT 1` on each lookup that would silently cascade to whichever caregiver happened to match first.
**Because:** `caregivers.day_rate` is business-critical — a silent wrong-person update has a real cost (pay disputes, incorrect cost apportionment on future shifts). `persons.full_name` is NOT unique (two caregivers can legitimately share a name and be distinguished by `tch_id`). The alias table can also legitimately carry multiple unresolved mappings for the same raw string. Deferring the cascade to an unambiguous mapping moves the ambiguity resolution to the operator flow where it belongs.

## 2026-04-14 — Revenue reports read from `client_revenue`, not apportioned `daily_roster.bill_rate`
**Chose:** One table per grain. `daily_roster` = cost grain (per shift); `client_revenue` = revenue grain (per client × month). Revenue reports pivot `client_revenue` directly.
**Over:** Apportioning invoice totals down to per-shift `bill_rate` on `daily_roster` and summing those as "revenue." Single-table-drives-everything was seductive but wrong.
**Because:** Invoices don't split per shift — Tuniti bills monthly lump sums. Apportioning invents a per-shift number that drifts when shifts are added/cancelled or matching fails. Caused the ~R652k understatement that surfaced this session. Revenue lives at the invoice grain; cost lives at the shift grain; don't conflate.

## 2026-04-14 — Roster keeps its true `client_id`; "Care without matching invoice" is a live query
**Chose:** Roster `client_id` is always the true resolved client (from alias → patients → self-pay fallback). "Care without matching invoice" is computed at report time via `LEFT JOIN client_revenue` on `(client_id, month)`.
**Over:** The previous ingest-time sentinel overwrite (step 8 in `build_ingest_sql.js`) that destructively repointed any shift without a matching Panel invoice to the Unbilled Care umbrella client.
**Because:** The overwrite threw away correctly-derived client_id and made the tile misleading — "Unbilled" implied we'd checked the invoice side when really we'd just checked "did the apportionment join succeed." Live query keeps the data pristine and the signal honest. Split into Mapping-gap (alias layer incomplete) vs Un-invoiced (client known, no invoice) for actionable drill-downs.

## 2026-04-14 — `timesheet_name_aliases` is the single client-name resolver for ingest
**Chose:** Panel ingest resolves client_id from panel-header strings exclusively via `timesheet_name_aliases`. Unresolved aliases halt the ingest with a clear operator-facing error.
**Over:** Dual resolution paths (Panel pipeline using alias table, `database/seeds/ingest.php` using a pre-matched clients-sheet lookup). Two truths diverge over time.
**Because:** Two different name-matching strategies produced inconsistent client_ids between `_panel_invoices_tmp` and `client_revenue` (e.g. Roux-Esme / Webb-Sonja resolved on one path but not the other). One source of truth, enforced by a gate, closes the class of bug at ingest rather than at reconciliation time.

New entries go at the top.

---

## 2026-04-14 — Contracts are first-class, separate from engagements and roster

**Chose:** Introduce a `contracts` table (client + patient + dates + invoice + status) with `contract_lines` (product × billing_freq × min_term × bill_rate × units). Engagements remain the caregiver-assignment record; daily_roster holds per-shift delivery and gains a nullable `contract_id` FK. A mid-contract product switch creates a new contract with `superseded_by` pointing from the old one.
**Over:** Keeping everything in the existing `engagements` row (current shape has caregiver+patient+client+product+rate on one row).
**Because:** The commercial contract outlives any one caregiver assignment (Ross's rule: patient buys care from TCH, not from a named caregiver). Substitutions shouldn't create new contracts; caregiver changes shouldn't touch billing. Separating the contract from the assignment from the delivery matches how Tuniti actually operates and how Xero will integrate.

## 2026-04-14 — Contracts auto-renew until actively cancelled (flag, never auto-cancel)

**Chose:** `contracts.auto_renew` bool (default 1). Contract rolls forward month-by-month until explicitly cancelled. System flags for attention N days pre-renewal, but never auto-cancels. Minimum term (`contract_lines.min_term_months`) warns at cancel, does not block.
**Over:** Auto-closing on min-term end date, or hard-enforcing min-term.
**Because:** Care is ongoing by default — "no pay, no carer" is a business decision, not a system one. Auto-cancelling would silently stop care. Alerting + leaving the cancel action human keeps safety and trust. Min-term is a commercial commitment, not a database constraint.

## 2026-04-14 — Caregiver swap = substitution in the engagement, not a new contract

**Chose:** When caregiver is off and another covers, the engagement's caregiver_id is updated (or a new engagement row created under the same contract). Contract stays unchanged. Roster rows reflect the actual caregiver.
**Over:** Creating a new contract per caregiver change.
**Because:** Patient buys care from TCH, not from a named caregiver (Ross's framing). Contract is the commercial agreement between TCH and the client; which specific carer delivers it is operational. Creating a new contract every time would inflate the contract list and confuse billing.

## 2026-04-14 — Unbilled Care umbrella client

**Chose:** One sentinel client (`persons.tch_id = 'TCH-UNBILLED'`) that absorbs every shift with no matching Panel invoice. Shifts get `client_id = umbrella_id` and `bill_rate = 0.00`. The umbrella is highlighted prominently (red dashboard tile, red row in Client Profitability, dedicated drill-down at `/admin/unbilled-care`).
**Over:** Leaving `bill_rate = NULL` on orphan shifts, or fabricating an invoice to make them balance.
**Because:** A suspense-account pattern. Every shift lands somewhere, the negative-GP bucket is painfully visible, resolution is a click away (admin links the real bill-payer → apportionment re-runs → shifts migrate out). Data-honest — no fabricated invoices; no silent nulls that disappear from reports. Creates pressure on Tuniti to either raise the invoices or confirm the care is intentionally uncharged.

## 2026-04-14 — Wipe-and-rebuild daily_roster from Timesheet + Panel, single source of truth

**Chose:** Drop the old `daily_roster` (1,619 rows historical ingest from the Client Billing Spreadsheet) and rebuild from the Tuniti Caregiver Timesheets + Revenue Panel workbooks. Every row gets `source_cell` provenance, a `source_alias_id` FK so alias re-mappings can cascade, a `source_upload_id` linking it to the workbook version. Reports cut over to read only from `daily_roster`. `client_revenue` and `caregiver_costs` become historical read-only snapshots.
**Over:** Merging new data with existing roster rows, or maintaining parallel ledgers.
**Because:** The old roster came from a different ingest with known name-matching problems (the R17,472 drift). Merging would carry that mess forward untraceable. Wipe-and-rebuild on dev was zero-risk (no customers); after verification, same pattern to prod. Every roster row now traces to one Excel cell; every total reconciles by definition because there's only one ledger.

## 2026-04-14 — 5-rule cost-rate resolver for Timesheet ingest

**Chose:** Per-shift cost_rate resolved in priority order: (1) per-cell override in the Timesheet cell text (e.g. `Carli-R500`); (2) this month's row-2 column rate; (3) derive from monthly Total Amount ÷ days worked (for blank row-2 rates); (4) other-months' row-2 rate for the same caregiver (avg if multiple); (5) overall average across caregivers.
**Over:** A single "last-seen-rate" fallback, or always-derive-from-total.
**Because:** Rule 1 respects Tuniti's explicit per-shift intent. Rule 2 is the common case (row-2 rate populated). Rule 3 handles the edge case Tuniti sometimes leaves blank but records a paid total — our derived rate matches what she actually paid. Rule 4 + 5 are genuine fallbacks only. Each rule is traceable — every cost_rate in the DB can be audited against its source.

## 2026-04-14 — Billing defaults live on `clients`, not `persons` (D1, Option B)

**Chose:** Keep the four billing fields (`day_rate`, `billing_freq`, `shift_type`, `schedule`) as prefill-only **defaults on `clients`** (renamed `default_*`). The engagement row holds the actual contract rates; `clients.default_*` only prefill the new-engagement form. Migration 028 drops these from `persons` and adds/renames on `clients` with a backfill.
**Over:** (A) Just drop them — cleaner, but Tuniti's workflow ("same client, same rate, different caregivers over time") would mean retyping the same rate every engagement.
**Because:** Single source of truth for *contract* billing is the engagement (one rate per engagement row). The pre-engagements fields on `persons` were lying whenever a client had two engagements at different rates. Moving them to `clients` as `default_*` makes their purpose explicit (prefill, not truth) and saves Tuniti typing on the common case. If a default drifts, nothing is misreported — it's only a form prefill.

## 2026-04-14 — DEV / PROD database separation (FR-0076 resolved)

**Chose:** New dev DB `tch_placements_dev-353032377731` on `sdb-61.hosting.stackcp.net`; server-side `mysqldump` of prod restored into it; dev `.env` repointed.
**Over:** Staying on the shared DB until UAT actually broke something.
**Because:** Tuniti UAT means real test writes from outside users, and a shared DB would pollute prod billing rows the moment a tester clicks "create". The global "Production Database Discipline" rule mandates separation; the shared-DB exception (FR-0076) was only allowable while no real customer activity existed. Split done now while the window is quiet. Going-forward rule: any further dump/restore happens only inside a declared maintenance window with user-facing routes offline.

## 2026-04-13 — Multi-row contact tables (phones / emails / addresses), legacy columns kept as fallback

**Chose:** Build new `person_phones`, `person_emails`, `person_addresses` tables with primary flags + FKs to `persons`. Keep the legacy scalar columns (`persons.mobile`, `secondary_number`, `email`, and the flat address columns) **in place** as a fallback. The new save helpers mirror the *primary* row from each new table back into the matching legacy column on every write.
**Over:** A clean cut-over (drop the legacy columns the moment the new tables exist), or a "shim view" that recomputes the legacy columns at read time.
**Because:** Plenty of code outside the new templates still reads the legacy columns directly (reports, exports, the public site, the in-app reporter widget). A clean cut-over would mean a multi-day audit + refactor across the codebase before a single client/patient page could go live. The shim-view approach hides the legacy columns behind a query layer but doesn't help PHP code that does `SELECT mobile FROM persons` (which is most of it). Keeping the legacy columns + mirroring the primary on write means the new functionality ships now, the rest of the codebase migrates incrementally as files are touched, and there is one source of truth at any moment (the new table, with the mirror as a denormalised cache that is always written from it). The mirror is allowed because it fits the "stored value represents *no* derivation" exception in the SoT rule — it's literally a copy of one row, not a summary.

## 2026-04-13 — Soft-archive (no delete) + default exclusion in lists

**Chose:** Add `archived_at` / `archived_by_user_id` / `archived_reason` to `persons`. List queries default to `WHERE archived_at IS NULL`; a "Show archived" toggle reveals them with muted styling. Restore is one click. Never delete.
**Over:** Hard delete with the existing `activity_log_delete()` undelete pathway, or a separate `clients_archive` / `patients_archive` table.
**Because:** Audit defensibility for billing-adjacent records. A client with paid invoices in `client_revenue` cannot be deleted without orphaning revenue rows; archive keeps the FK target alive while hiding the record from working views. The undelete pathway is meant for accidental dev-time deletes, not "this customer left us". Separate archive tables would duplicate every column and double maintenance cost — a single nullable timestamp on persons is the cheapest correct shape.

## 2026-04-13 — `clients.id = persons.id` convention preserved on new client creation

**Chose:** When creating a new client via `/admin/clients/new`, INSERT the `clients` row with `id = persons.id` (using the just-allocated person ID), not letting AUTO_INCREMENT pick.
**Over:** Letting `clients` use its own AUTO_INCREMENT id and joining via `clients.person_id`.
**Because:** Migration 009 deliberately seeded `clients.id = persons.id` so existing FKs on `client_revenue.client_id` and `daily_roster.client_id` (which point at `persons.id`-style values) continue to resolve. Letting AUTO_INCREMENT diverge for newly-created clients would break that invariant — half the table would have `clients.id = persons.id`, the other half wouldn't, and every JOIN involving billing or roster would need to be hand-corrected. Cheap to preserve, expensive to undo later.

## 2026-04-13 — Two-stage POST for create-with-dedup (no JS modal)

**Chose:** Server-side dedup runs on first POST; if matches found, the form re-renders with the matches inline above it and a "Create anyway" tickbox. Second POST with `dedup_confirmed=1` skips dedup and inserts.
**Over:** A JavaScript modal on the create page that intercepts submit, calls a JSON dedup endpoint, and shows matches in-overlay before proceeding.
**Because:** The two-stage server flow works without JS, has no API surface to maintain, and the user state lives in the form's hidden field rather than a JS variable. Worst case (user gets distracted, comes back later, hits Create) is they get the dedup screen again — never a silent-create-of-a-duplicate. The JS modal would be slicker but adds an endpoint, an XHR layer, and an "is the JS loaded yet" race.

## 2026-04-13 — Structured source-citation columns on `activities`

**Chose:** Add `source`, `source_ref`, `source_batch` columns to the
`activities` table and display `source_ref` as a muted "Source: …" line
under each imported Note.
**Over:** Embedding source info in the free-text `notes` body
(what Nexus CRM does today), or adding a separate `import_provenance`
table keyed on activity_id.
**Because:** Free text rots (people edit note bodies) and doesn't
query — we can't filter "show me every value sourced from sheet X".
A separate table is over-engineered for what is really just three
short strings per row. Nexus CRM agent agreed and will likely adopt
the same pattern; TCH ships it first.

## 2026-04-13 — Reject button removed from Tuniti approval flow

**Chose:** Only an **Approve** action on the student detail page. If
the imported data is wrong, the approver edits the fields via the
existing per-section edit, then approves.
**Over:** Keeping Approve + Reject (the CRM-style "reject takes it out
of the workflow" pattern).
**Because:** Rejecting doesn't help anyone — the student still exists,
they still need processing, and Reject just creates a dead-letter
queue someone has to re-process. Edit-then-approve keeps the record
moving in one direction.

## 2026-04-13 — Notes panel as single source of truth, not per-screen free-text columns

**Chose:** Move every existing `persons.import_notes` /
`student_enrollments.notes` free-text field into one unified Notes
timeline (backed by the `activities` table). Keep the source columns
for now but stop rendering them.
**Over:** Leaving each table with its own notes column and rendering
them in separate sections.
**Because:** Users had three places to look for "why is this record
the way it is". A single reverse-chronological timeline per entity is
how Nexus CRM does it and how Ross expects it to look. Matches his
muscle memory, removes "which notes column?" as a question.

## 2026-04-13 — Shared DEV/PROD DB — documented exception

**Chose:** Both `dev.tch.intelligentae.co.uk` and
`tch.intelligentae.co.uk` point at the same MariaDB database.
**Over:** Separate dev and prod databases (the standing global rule).
**Because:** TCH has no real customer activity yet — every row was
entered by Ross or Claude. The cost of maintaining two schemas would
exceed the risk. Tracked as FR-0076 on the Nexus Hub. The exception
expires the moment the first real caregiver self-service login, the
first real client billing event, or the first real Tuniti approval
happens in prod. At that trigger, the FR becomes HIGH priority.

## 2026-04-11 — Drop cached summary columns that had drifted during dedup

**Chose:** Compute `first_seen`, `last_seen`, `months_active`, and
`status` for clients at read time from `client_revenue`. Migration 008
dropped the stored columns.
**Over:** Keeping the columns and maintaining them via triggers or
recompute-on-write.
**Because:** The 2026-04-11 patient dedup exposed silent drift — after
merges repointed revenue rows, the cached columns on the surviving
client still said "1 month, Inactive" while the client was actually
billing R30k/month. The data was correct; the cache was the lie. Rule
promoted to global standing order ("single source of truth — no stored
derivations"): derivable values must be computed, not cached, unless a
profiler proves a real performance problem.

## 2026-04-11 — Nexus Hub as central bug/FR tracker across projects

**Chose:** All bugs and FRs for TCH (and Nexus CRM, and future
projects) filed on https://hub.intelligentae.co.uk via a shared API.
**Over:** Keeping each project's backlog in markdown files in its own
repo.
**Because:** Markdown backlogs don't survive context switches. Once
there's more than one project, a single cross-project tracker with
per-project scoping is how the work stays legible. TCH got the
in-app floating Help widget first; other projects follow.

## 2026-04-10 — LAMP stack, no framework, no JS build

**Chose:** Bare PHP 8 + MariaDB + Apache. Front controller dispatches
via switch/preg_match on `?route=`. Vanilla JS where needed; most
interactivity is server-rendered.
**Over:** Laravel, Symfony, or a JS-SPA front end.
**Because:** This is an internal ops tool for low traffic (dozens to
low hundreds of users, never thousands). A framework adds upgrade
churn, a build step, and dependencies we don't need. The whole stack
is one `git pull` + one `rsync`. Hosting bill is R12/month. Every
feature so far has shipped in under a day of work; the stack isn't
the bottleneck.

## 2026-04-10 — Polymorphic `entity_type`/`entity_id` on activities + audit tables

**Chose:** Use a string enum + integer id for any table that hangs off
multiple other tables (`activities`, `activity_log`, `attachments`).
**Over:** Separate join tables per entity pair, or inheritance via
superclass tables.
**Because:** We want one code path for rendering the timeline on any
entity, not one per entity type. The polymorphic shape comes at the
cost of no FK enforcement on `entity_id` — acceptable because the
app-side insert sites are few and they control what they pass. Nexus
CRM uses the same shape.

## 2026-04-09 — TCH IDs (`TCH-000001`) as stable identifiers, independent of names

**Chose:** Every person gets an immutable `tch_id` on creation. All
cross-references (reports, attachments, URLs) use the TCH ID, not the
name.
**Over:** Using `full_name` as the external identifier.
**Because:** Names change — marriage, correction of typos, legal
transliteration fixes. The dedup process exposed seven students whose
names had been recorded three different ways across three
spreadsheets. A TCH ID survives all of that. Names survive editing;
the ID survives migration.
