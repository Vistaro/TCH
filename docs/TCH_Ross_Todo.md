# Ross — Action Items

**As of:** 10 April 2026 (end of day, post v0.9.1 prod deploy)

---

## Status snapshot (end of 2026-04-10)

- ✅ **v0.6.0** (homepage rebuild + enquiry form + regions config) — LIVE on prod
- ✅ **v0.7.0 → v0.9.1** (User mgmt + RBAC + audit + impersonation + field-level diff viewer) — LIVE on prod as of this evening
- ✅ Locked 3-session User Management plan: **complete**
- ✅ Schema migration 005 applied to shared dev/prod DB
- ✅ All 14 admin pages live on https://tch.intelligentae.co.uk/
- ✅ Email-based login (`ross@intelligentae.co.uk` / `TchAdmin2026x`) live on prod

**Pending action from Ross right now:**
- Purge CDN cache (StackCP > CDN > Edge Caching) so anonymous users hit the new `/login` page

---

## Bugs — active

| # | Item | Priority | Notes |
|---|------|----------|-------|
| ~~BUG-setup-pw~~ | ~~**User registration broken — `/setup-password` returns HTTP 500**~~ | **FIXED 2026-04-13** | Root cause: `users` table was missing the `linked_client_id` column, but `setup_password.php` + `users_detail.php` both referenced it in INSERT/UPDATE. Every invite acceptance 500'd on "Unknown column 'linked_client_id'". Migration 017 added the column + index. Diagnosed by running the full POST path via a server-side diagnostic script. |

## Pending design — features queued (added 2026-04-13)

| # | Item | Priority | Notes |
|---|------|----------|-------|
| UAT-tuniti | **Plan UAT — give Tuniti a structured set of tasks to test on dev** | HIGH | Before promoting more features to prod, Tuniti needs to actually use the system end-to-end on dev: log in, find a student, edit a field, replace a photo, mark someone graduated, approve a pending import, log a Note, view the Source Data card, hit Print, browse Caregivers/Clients/Patients lists, drill into a report, request a password reset, change their currency, see the converted figure. Build a one-page UAT checklist (10–15 numbered tasks, each with "expected result" and a tick-box for them to confirm). Treat their feedback as a backlog feed. Also covers: confirm name reconciliation for the 16 N/K students; spot-check 3 students per cohort against the per-student PDF; smoke-test the in-app Report widget end-to-end (now the Hub token is live). |
| UAT-product-remap | **Tuniti: remap historical roster shifts to the correct product** | HIGH (Tuniti action) | Products seeded 2026-04-13 from the public site (Full-Time, Post-Operative, Palliative, Respite, Errand Care) plus the existing Day Rate. Every historical roster row (1,619 shifts) is still tagged `product_id = 1 Day Rate`. Tuniti needs to walk each active engagement / client and tell us which product applies, then we bulk-reassign. Not urgent unless we start per-product reporting. Fold into the UAT session. |
| FR-roster-rebuild | **Rebuild roster from Client Billing Spreadsheet (single source) + EOL caregiver_costs** | HIGH | Ross's standing decision after seeing the R 17,472 drift: only ONE source of truth for caregiver wages going forward. Rebuild `daily_roster` from the Client Billing Spreadsheet (panels-per-client) using `persons.id` as the canonical key. Once roster is the only source: drop or read-only-archive the `caregiver_costs` table, switch the Caregiver Earnings report to read from roster (sum of `cost_rate` per caregiver per month). After this, all wage figures across the site come from one place by definition. Tightly coupled to D2 (roster redesign with `bill_rate` so revenue also lives in roster) and D3 (re-ingest plan) — likely all done in one session. **Future workflow:** Tuniti enters Care Schedule → generates planned roster rows → marks delivered via Care Approval → Caregiver Earnings + Client Billing both compute from those rows. Manual ad-hoc additions still allowed. |
| FR-admin-config | **Admin / config section sort-out** | MEDIUM | We've now got several scattered config pages (`/admin/config/activity-types`, `/admin/config/fx-rates`) plus the new `system_settings` table that has no UI yet. Build one canonical Admin → Config landing page that lists all config areas in one place, with a link to each. Then: (a) add a Settings page that edits `system_settings` rows (Tuniti GPS coords first; everything else added as it shows up); (b) decide which existing admin pages live under "Config" vs "Admin". Currently the menu mixes Users / Roles / Activity Log (operational) with Activity Types / FX Rates (config). Worth grouping. |
| FR-help-report | **Combined Help + Report widget** | LOW | The floating bottom-right button currently just opens the bug/FR reporter. Long-term: turn it into a Help+Report dropdown — Help links to docs / FAQ / shortcuts; Report opens the existing reporter panel. Wait until there's actual help content to link to before building. |
| FR-client-expenses | **Client expenses workflow** | MEDIUM | We need a way to capture costs incurred *for* a client (not caregiver pay): equipment hire, transport, third-party medical, etc. Design Q's: (a) where does it live — a tab on the patient record, or a standalone Expenses page? (b) approval workflow (does Tuniti or finance approve before it hits the client invoice?) (c) currency + receipt attachment. (d) flow into the Client Profitability report — should reduce gross margin. Add a `patient_expenses` UI (table already exists, currently empty). |
| FR-caregiver-loans | **Caregiver loans — balances, payments, repayments** | MEDIUM | `caregiver_loans` table already holds R 58,802 outstanding across some caregivers. Need: (a) view current loan balance per caregiver on their record; (b) record a new loan disbursement (cash advance); (c) record a repayment (deducted from a future wage); (d) running balance maintained by application of repayments to oldest loan first; (e) flow into Caregiver Earnings report — repayments shown as a deduction line, loans shown as a credit line. **Loans should NOT impact Gross Margin** — they're a cash-flow item, not a P&L cost. |
| FR-pagination | **Pagination on long table pages** | LOW | Current fix: sticky header + scroll wrapper handles up to ~200 rows comfortably. If we ever hit 500+ rows, switch to numbered pagination (10/25/50/100 per page picker + first/prev/next/last). Defer until needed. |
| BUG-sticky-header | **Sticky table headers still scroll out of view** | HIGH | Ross reported 2026-04-13 that column headers still disappear when scrolling list pages (Students, Clients, Patients, plus reports). Tried three fixes so far: (1) `.report-table-scroll` wrapper with in-container scroll, (2) page-level `position:sticky` on thead th with `top:0; z-index:3`, (3) switched `.report-table` from `border-collapse: collapse` to `border-collapse: separate` (known Chrome/Edge bug with collapsed borders blocking sticky). **Try first:** hard refresh (Ctrl+F5) to bust browser cache of the old CSS. **If still broken after cache bust:** inspect the live DOM — likely an ancestor has `overflow: auto/hidden` or a transform that creates a new scrolling context and breaks `position: sticky`. **Fallback if un-fixable:** replace sticky with numbered pagination (FR-pagination, 25-rows-per-page default + first/prev/next/last), which doesn't depend on CSS tricks. |

## Blocking Next Session

| # | Item | Priority | Notes |
|---|------|----------|-------|
| 1 | **Drop blank caregiver onboarding form into `docs/`** | HIGH | Scan, photo, or PDF of the paper form caregivers currently fill in. Needed to build the data entry screen. |
| 2 | **List standard attachments required per caregiver** | HIGH | ID copy + what else? (qualifications, proof of address, police clearance, etc.) |
| 3 | **Provide product/service list** | MEDIUM | What services does TCH currently offer? Needed for the product database. E.g. "Day Shift Care", "Live-In Care", "Post-Operative Care" etc. |

## Blocking Later Phases

| # | Item | Priority | Notes |
|---|------|----------|-------|
| 4 | **Provide messy training data** | MEDIUM | Individual course/module scores, weekly attendance — whatever format you have. I'll clean it. |
| 5 | **Confirm room/location names** | LOW | Are training rooms fixed names (dropdown) or free text? |
| 6 | **Confirm OJT duration** | LOW | Investor doc says weeks 11–14. Is that always 4 weeks? |
| 7 | **Review and confirm vision doc** | MEDIUM | `docs/TCH_Platform_Vision.md` — read through, flag anything wrong |
| 8 | **Review and confirm build plan** | MEDIUM | `docs/TCH_Plan.md` — check priority order makes sense |

## Data Quality — post-dedup follow-ups (added 2026-04-11)

| # | Item | Notes |
|---|------|-------|
| DQ0 | **Audit every table for stored derivations — single source of truth principle** | Added 2026-04-11 during migration 007 planning. TCH must have one version of the truth for any given fact; anything derivable from another table must be computed in queries, not cached as a column. The 2026-04-11 patient dedup exposed this on `clients.first_seen / last_seen / months_active / status` — all of them stale snapshots of `client_revenue` that drifted after merges repointed rows. Do a full walk of every table in the schema looking for the same pattern: stored aggregates, stored counts, stored "status" fields that are really just "has recent activity", stored min/max of child rows. Raise each one for Ross's call and either drop it (computing in queries instead) or document why it legitimately represents independent state. Standing principle now applies to every new column added going forward. Saved as a feedback memory and will be enforced by default on all new design work. |
| DQ1 | **id=47 Morrison — R0 total income, 2 revenue rows** | Flagged during patient dedup Round 2 (2026-04-11). The two revenue rows attached to `clients.id = 47 "Morrison"` sum to R0.00. Either genuinely zero-value data from ingest or an import bug. Investigate before we trust any report that filters by client income > 0. |
| DQ2 | **Slash-split client/patient rows may be wrong** | During the patient-name backfill (2026-04-11), 7 client rows with "X / Y" naming were split into client=before-slash, patient=after-slash. Known-rough case: id=5 "Angela / Dimitri Papadopoulos" almost certainly means Angela Papadopoulos + Dimitri Papadopoulos (shared surname lost on the client side). Other candidates: ids 4, 7, 21, 24, 25, 53. These need human review and correction via the edit-relationship UI once it exists. |

## UI requirements — queued for the person-record edit screen

| # | Item | Notes |
|---|------|-------|
| UI0 | **Dashboard month filter — replace pills with a date-range or year/quarter picker when months exceed ~12** | The current horizontal pill buttons work well for 6 months but won't scale. When data spans 12+ months, switch to a date-range picker or a year → quarter → month drill-down. Low priority until we pass 12 months of live data. |
| UI1 | **Edit client ↔ patient relationship from the Patient record** | On the patient edit screen, show "Billed to: [Client name]" with an edit control so the billing relationship can be changed. Applies both to the current one-record-is-both-client-and-patient rows and to the split rows from DQ2. Becomes genuinely multi-record when a corporate Client pays for multiple Patients. Build when we first need to correct one of the DQ2 rows. |

## Design items — billing/engagements model (added 2026-04-11)

These are design requirements, not Hub FRs yet. Captured here so the
next design session has the context.

| # | Item | Notes |
|---|------|-------|
| D1 | ~~**Forward-looking client ↔ caregiver engagements model**~~ | **DONE 2026-04-14.** `engagements` table was built in migration 010 (Phase 4). The billing-fields-off-persons tidy is done in migration 028 (Option B, per Ross): `persons.day_rate / billing_freq / shift_type / schedule` dropped; `clients` gains `default_day_rate`, `default_billing_freq`, `default_shift_type`, `default_schedule` (renamed from `billing_freq`), backfilled from persons. The client profile's "Billing" section is now "Billing Defaults" (prefill-only). The engagements create form now carries `data-bill-rate` per patient option and prefills the Bill Rate input from the patient's client-level default when picked — same pattern as the caregiver → Cost Rate prefill. Unlocks D2/D3. |
| D3 | **Re-ingest from the Client Billing Spreadsheet as the single source** | Added 2026-04-13. Root cause of the R 17,472 drift between Caregiver Earnings (R 709,620, from `caregiver_costs`) and Dashboard Total Wages (R 692,148, from `daily_roster`): they were ingested from two different sheets that don't reconcile. The ledgers also fail to join on caregiver name because `caregiver_costs.caregiver_name` uses short forms ("Susan Murire") while `persons.full_name` has the long form ("Rudo Susan Murire"), plus parenthetical tags in costs (`Siphilisiwe Nkala(Patricia)`) that aren't in persons. **Fix: rebuild both ledgers from the Client Billing Spreadsheet (the panels-per-client one) using `persons.id` as the canonical key, not name strings.** Once both sums come from the same source they reconcile by definition. Couples to D2 (roster redesign with cost+bill rate) — probably done in the same session. |
| D2 | **Roster table redesign — single source of truth for BOTH cost and revenue** | Added 2026-04-11 after Ross described how roster rows should be created going forward, then refined when a reconciliation check showed `client_revenue` total (R1,554,103) and `daily_roster` total (R692,148) don't match because they measure different things. **The central insight:** the current roster table only holds the cost side (`daily_rate` = what we pay the caregiver for that shift). It has no column for the bill side (what we charge the client for that shift), so it cannot be the source of truth for invoicing. `client_revenue` exists today as a parallel monthly ledger ingested from a spreadsheet, and there is no way to answer "which shifts made up Client X's March revenue" — only "how much revenue came from Client X in March". The redesigned roster must hold BOTH sides of the money so cost, revenue, and margin all reconcile at per-shift line item level from ONE source. Design rules: **(1) Strict FK validation.** Every roster row carries three FKs to `persons`: `caregiver_id` (person_type containing 'caregiver'), `client_id` ('client'), `patient_id` ('patient'). If any doesn't resolve to an existing person of the right type, the insert is rejected. No raw-string fallback — the existing 1,224 orphan rows (FR-0069) couldn't exist under the new rules. **(2) Product-aware billing with BOTH rates per row.** Drop `daily_rate`. Replace with: `product_id` (FK to a new `products` lookup — Day Shift, Night Shift, Live-In, Hourly, Respite, etc.), `units` (how many of that thing — 1 day, 8 hours, 7 nights), `cost_rate` (what we pay the caregiver per unit for this shift), `bill_rate` (what we charge the client per unit for this shift). Row cost = `units × cost_rate`, row bill = `units × bill_rate`, row margin = bill − cost. Both rates default from the engagement (D1) — the contract says "this client pays R850/day for Day Shift, we pay the caregiver R450/day" — but allow per-row override for one-off situations, substitutions, emergency rate adjustments. **(3) Status column.** One table, one row per shift, with `status` column `ENUM('planned','delivered','cancelled','disputed')`. Starts as planned, flips to delivered when it happens, can become cancelled or disputed. Never-delete rule applies — cancelled shifts stay in the table as non-billable rows. Only `status='delivered'` rows contribute to cost/revenue/margin totals. **(4) Start/end times.** Two columns `shift_start` / `shift_end` (DATETIME) even when the billing unit is a full day. Needed for handover tracking, shifts that cross midnight, and hourly billing. **(5) Engagement link.** Once D1 exists, every roster row carries `engagement_id` (nullable for historical / ad-hoc rows) — identifies the contract the shift was fulfilled under, so billing auto-picks product + cost_rate + bill_rate from the engagement's defaults. **(6) Attribution.** Add `created_by_user_id` and `confirmed_by_user_id` — who entered the row, who signed off on it being delivered. **(7) Drop denormalised strings under single-source-of-truth.** Remove `caregiver_name`, `client_assigned`, `day_of_week` from the schema. Names come from joining persons; day of week derives from `roster_date`. **Net new schema for the redesigned roster (roughly):** `id`, `caregiver_id`, `client_id`, `patient_id`, `engagement_id`, `product_id`, `units`, `cost_rate`, `bill_rate`, `shift_start`, `shift_end`, `roster_date` (for indexing), `status`, `notes`, `created_by_user_id`, `created_at`, `confirmed_by_user_id`, `confirmed_at`, `updated_at`. **Knock-on: `client_revenue` becomes a computed view, not an ingested table.** Revenue per client per month = SUM of `units × bill_rate` across delivered shifts for that client. Cost per caregiver per month = SUM of `units × cost_rate`. Margin per month = difference. This completes the single source of truth story — same principle we applied to `clients.first_seen / last_seen / months_active / status` in migration 007. The two reports that currently read daily_roster (Caregiver Earnings, Days Worked) switch to computing pay from `units × cost_rate` grouped by caregiver/month. The Client Billing by Month report stops reading `client_revenue` at all — instead it sums `units × bill_rate` from the roster, grouped by client/month. **Migration strategy TBD** — probably create the new columns, backfill historical rows as `product='Day Shift'` + `units=1` + `cost_rate=daily_rate` + `bill_rate=(derived from the parallel client_revenue row for that month, apportioned by shift count)` so the new totals match history, then drop `daily_rate` + `day_of_week` + denormalised name strings + `client_revenue` (or keep it as a snapshot archive) in a follow-up migration once confident. Historical reconciliation gap means some older shifts may not be cleanly re-costable at per-shift level — document and accept for pre-redesign data. Design lives alongside D1 — they're tightly coupled and likely implemented in the same session. |

## Ongoing

| # | Item | Notes |
|---|------|-------|
| 9 | ~~**Purge CDN cache after deployments**~~ | **No longer required** as of 2026-04-11 (v0.9.9.3). The `.htaccess` no-cache block was removed (BUG-0035) and static assets are cache-busted via `?v=<filemtime>` query strings. Try the next deploy without a purge — if anything's stale, re-add as a fallback. |
| 10 | **DB credentials** | Stored in server `.env`. Username: `tch_admin`, DB: `tch_placements-313539d33a`, host: `shareddb-y.hosting.stackcp.net` |
| 16 | **Client/patient onboarding workflow — care proposal + email acceptance, blocks scheduling until accepted** | **Rule:** liability must be confirmed BEFORE care is scheduled, not at approval. **Phase 1 (done):** engagements.php now hard-blocks create when patient has no `patients.client_id`. **Phase 2 (this TODO):** build the full onboarding workflow. Three parts: **(a) Care proposal** — new `care_proposals` table (patient_person_id, client_person_id, products, frequency, start/end dates, total_cost_estimate, status enum[draft/sent/accepted/declined/withdrawn], doc_path, timestamps, created_by). New `/admin/proposals/new` page: pick patient → bill-payer auto-fills → pick products + frequency + duration + rates → preview totals → Save draft → Send. Document generation v1 = HTML page browser-prints to PDF (same pattern as `templates/admin/student_print.php:51,75,80,204`, no new deps). v2 = Word template merge (requires PHPWord Composer dep, defer until Ross provides a .docx). Stored in `attachments` linked to both parties. **(b) Email acceptance flow** — new `onboarding_confirmations` table modelled on `password_resets` (proposal_id, party_type enum[client,patient], person_id, token_hash CHAR(64), sent_to_email, sent_at, expires_at, confirmed_at, confirmed_ip, declined_at, decline_reason). New email template `templates/emails/proposal_invite.php`. Send: generate raw token (random_bytes(32)) → store SHA-256 hash → Mailer::send() with `/onboarding/confirm?token=...` URL (model: `templates/auth/forgot_password.php:30–46`). Public verify route (no auth) renders Accept/Decline page; on submit records confirmed_at + confirmed_ip (pattern: `templates/auth/reset_password.php:25–84`). When client = patient → only one row to confirm. Both rows confirmed → proposal status flips to `accepted` automatically. **(c) Schedule guardrail extension** — engagements.php check extends to: `patients.client_id IS NOT NULL` AND latest care_proposal for this pairing `status='accepted'`. Patient profile gets an "Onboarding status" panel (proposal sent/confirmed/fully-accepted + resend/cancel actions). **Effort:** ~10–12 hrs (v1 doc only). **Trigger:** after the historic 10-record split is complete. |
| 18 | **Schedule input UI — pick patient first, derive bill-payer from pairing** | Current `engagements.php` form has caregiver + patient + product as parallel selectors. Change to patient-first wizard: pick patient → show the linked client (read-only, from `patients.client_id` / `patient_client_history`) and onboarding-status badge → pick caregiver → pick product + dates + rates. Bill-payer is never manually edited here — it's a property of the patient. If no client linked → the form won't let you past step 1 (matches Phase-1 guardrail already in place). ~1.5 hrs. |
| 15 | **Switch patient re-assign from Phase-1 (retroactive) to Phase-2 (time-stamped) once historic data is locked** | `patient_client_history` table built in migration 027 with one open row per patient (`valid_from=NULL, valid_to=NULL`). Phase-1 handler in `patient_view.php#change_client` and `client_view.php#link_existing_patient` does an UPDATE on the open row (treats every change as a data-error correction, applies retroactively). Yellow banner above the re-assign control says so. **Phase-2 switch:** change handlers to (a) `UPDATE` open row `SET valid_to = NOW()`, (b) `INSERT` new open row with `valid_from = NOW()` and the new client_id, (c) keep the `patients.client_id` denormalised pointer in sync. Remove the yellow banner; replace with optional "effective from" date picker. Reporting can then ask "who paid for patient X on date Y" via a single time-window query. Trigger: when Tuniti confirms historic billing data is correct. |
| 13 | ~~**🔴 URGENT — Separate DEV and PROD databases before Tuniti UAT**~~ | **DONE 2026-04-14.** New dev DB `tch_placements_dev-353032377731` on `sdb-61.hosting.stackcp.net`. Prod dumped (`~/db-backups/tch_prod_20260414T074716Z.sql`, 1.8M) and restored into dev; dev `.env` repointed. Row counts match across 8 key tables. Sentinel-table write test confirmed full isolation. FR-0076 ready to close. **Going-forward rule:** once real users are live, any further dump/restore only inside a maintenance window with user-facing routes offline. |
| 14 | **Data cleanup — split conflated client/patient `persons` rows** | Two parts; treat as separate. **(a) Going forward — DONE.** The new create forms (`/admin/clients/new`, `/admin/patients/new`) force client and patient to be separate persons rows by construction. Any new records entered through the UI cannot reproduce the conflation. **(b) Historic backfill — standalone migration, INDEPENDENT of FR-roster-rebuild.** Walk every `patients` row where `patient_name <> persons.full_name` AND `client_id = person_id`. For each: create a NEW persons row for the recipient (e.g. Praxia, fresh TCH ID, person_type='patient'); re-point that patient row's `person_id` from the old conflated id to the new id; strip 'patient' from the old persons row's `person_type` (leave as client-only). `daily_roster.client_id` already points at the bill-payer (correct, no change needed) — the patient linkage flows through `patients.client_id` so it's auto-fixed by the patient row repoint. Audit log captures every split. ~1 hour + Tuniti spreadsheet review for ambiguous cases (nicknames vs different humans). |
| 12 | **Hub API token — fix session-start briefing path** | The session-start `check-onedrive-freshness.sh` / briefing script looks for a Hub token at `C:/ClaudeCode/_global/keys/nexus-hub-governance-token` and a base URL at `C:/ClaudeCode/_global/keys/nexus-hub-base-url`. The actual TCH Hub token lives in `C:/ClaudeCode/_global/keys/web-logins.md` under "TCH Placements". Result: every session starts with "`[SKIP] No Hub token/base-url configured`" even though the token works fine. Fix options: (a) drop the token + base URL at the expected paths as plain files (simple, duplicates the secret), or (b) update the briefing script to parse `web-logins.md`. Governance/global concern — same problem likely affects other projects that also only have their tokens in `web-logins.md`. Raise on the `_global` mailbox once pattern is confirmed. |
| 11 | **DEV and PROD currently share one database** | **Documented exception to the global "Production Database Discipline" standing rule**, because TCH has no real customer activity yet. Tracked on the Hub as **FR-0076** (LOW priority, trigger-based). The rule activates — and the FR becomes HIGH priority — the moment any of: first real caregiver/client self-service login, first real enquiry becomes a real lead, Tuniti begins real approvals, or any real billing/placement activity begins. Ross judges the trigger subjectively. |

## Bug / Feature Request reporter → Nexus Hub (added 11 April 2026)

**Status: LIVE on dev, pending Hub token from Ross.**

Goal: every logged-in TCH user can raise a Bug or FR with one click from
any admin page. Submissions forward to the central Nexus Hub at
`hub.intelligentae.co.uk` (single tracker across all Intelligentae
projects). From now on, TCH bugs and FRs live on the Hub — NOT in
markdown notes in this todo doc.

| # | Item | Status | Notes |
|---|------|--------|-------|
| B1 | **Widget + server proxy + activity log integration** | **DONE 2026-04-11** (v0.9.7-dev) | Floating Help button bottom-right on every admin page → slide-in panel → POST to `/ajax/report-issue` → Hub API. Duplicate detection, confirmation email, activity log entry. Graceful failure if Hub unreachable. |
| B2 | **Ross provides the Hub API token** | PENDING | Log into Hub as Super Admin → `?page=tokens&action=create` → label `TCH Agent`, scope to `tch` project → copy the plain token once → paste to Claude → Claude pastes it into the dev server's `.env` and smoke tests end-to-end. |
| ~~B3~~ | ~~**Migrate existing TCH bugs/FRs off markdown and onto the Hub**~~ | **DONE 2026-04-11** | Seven Person Database FRs migrated to the Hub as FR-0058 through FR-0064. Blockers-waiting-on-Ross stay in this file (they aren't bugs/FRs). The Tuniti data-quality list (~30 items) also stays in this file as a shared checklist for handover to Tuniti — it isn't TCH backlog. |
| B4 | **Standing practice: review Hub backlog at start of every session** | ONGOING | From now on every TCH session begins with Ross + Claude reviewing the open items in the Hub's TCH project to decide priorities. Recorded as a project memory so future sessions know to check. |
| B5 | **Short-description field on the in-app reporter** | **DONE 2026-04-11** (v0.9.8-dev) | New one-line input above the long-description textarea. Optional. When set, it becomes the Hub ticket title verbatim. When blank, falls back to the auto-generated `[slug] Type: first-80-chars` style. Nexus CRM has been asked to mirror the same change via the agent mailbox. |
| B6 | **Centralise the reporter widget on the Hub** | QUEUED ([FR-0065](https://hub.intelligentae.co.uk/?page=features&action=view&id=66)) | Host the widget CSS + JS on the Hub itself so all projects link to one canonical copy instead of duplicating the code. Trigger: before onboarding any third project to the in-app reporter. Filed on the TCH project on the Hub because TCH's API token is scoped to TCH only — but the work is for the Nexus Hub agent. |

## Activity Log — full audit + revert capability (added 11 April 2026)

Goal (Ross's words): "full audit capability so when a user says 'I didn't do that, the system is broken' we can then find out the reality." Plus: ability to **reverse** a past change from the log without erasing history.

Inline field-level diff view on the activity log list is already shipped (v0.9.2-dev, 11 April 2026). The four items below are the remaining pieces.

| # | Item | Effort | Priority | What it does (plain English) |
|---|------|--------|----------|------------------------------|
| ~~A1~~ | ~~**Audit sweep — coverage gap report**~~ | **DONE 2026-04-11** | — | Read-only scan completed. Three real gaps found (failed logins, account lockouts, email sends), closed under A1.5 below. |
| ~~A1.5~~ | ~~**Close the gaps A1 found**~~ | **DONE 2026-04-11** (v0.9.3-dev) | — | Failed logins + account lockouts now logged to activity_log. Every email send now emits an `email_sent` activity entry linked to the outbox row. Also backfilled before/after snapshots for `user_unlocked` and `password_reset_forced`. Standing order added to global CLAUDE.md: **every mutation on a transactional site must be logged with before/after, no exceptions.** |
| ~~A2~~ | ~~**Level 1 — Single-field revert**~~ | **DONE 2026-04-11** (v0.9.4-dev) | — | Revert button per changed field on the activity log detail page. Gated to users with `activity_log.edit` permission (Super Admin + Admin). Intermediate-edit check refuses if the field has been changed again since. Supported entity types: users, enquiries, caregivers, name_lookup. The revert is recorded as a new `field_reverted` audit entry. |
| ~~A3~~ | ~~**Level 2 — Restore whole record to a point in time**~~ | **DONE 2026-04-11** (v0.9.5-dev) | — | Amber "Restore whole record to this point…" button on the detail page opens an inline preview panel. Preview shows every field that will change, flags any intermediate edits, drops synthetic fields, then an Apply button with double confirmation. Gated to Super Admin only. Writes a single `record_rolled_back` audit entry. |
| ~~A4~~ | ~~**Level 3 — Undelete**~~ | **DONE 2026-04-11** (v0.9.6-dev) | — | `activity_log_delete()` helper captures the full row before running DELETE. Undelete button on `record_deleted` log entries re-inserts with original id. Super Admin only. PK-collision and schema-drift handling built in. **Only works for records deleted via the helper, from now on** — no pre-existing deletes to recover because nothing has ever been deleted in TCH. Standing order added to global CLAUDE.md: never call `DELETE FROM` directly, always use the helper. |

**Order:** A1 first (cheap, tells us where the holes are). Then A2, A3, A4 in sequence, each committed and deployed independently so Ross can test before moving on.

**Storage note:** Ross asked whether growing the log forever is OK. Answer: yes. A log entry is a few hundred bytes — even at thousands of changes a day, that's ~30–50 MB per year of database growth. Worth adding a retention policy (e.g. auto-archive > 2 years old) at some point for GDPR comfort, not urgent.

## Person Database Build (added 10 April 2026 — migrated to Hub 2026-04-11)

Decisions locked for unifying student/caregiver into a single Person record.

**Items 11–16 and 18 have been migrated to the Nexus Hub as feature requests** (2026-04-11, B3). They are no longer tracked in this file — view the current backlog on the Hub:

| Old # | Hub ref | Priority | Title |
|-------|---------|----------|-------|
| 11 | [FR-0059](https://hub.intelligentae.co.uk/?page=features&action=view&id=60) | medium | System config admin page for all lookup lists |
| 12 | [FR-0060](https://hub.intelligentae.co.uk/?page=features&action=view&id=61) | medium | Status promotion gates (required fields per status) |
| 13 | [FR-0063](https://hub.intelligentae.co.uk/?page=features&action=view&id=64) | low | Referrer / affiliate model for paid referrals |
| 14 | [FR-0061](https://hub.intelligentae.co.uk/?page=features&action=view&id=62) | medium | Field-level role-based edit permissions |
| 15 | [FR-0058](https://hub.intelligentae.co.uk/?page=features&action=view&id=59) | **high** | Person record card view matching Tuniti PDF layout |
| 16 | [FR-0062](https://hub.intelligentae.co.uk/?page=features&action=view&id=63) | medium | Retire name_lookup table once all PDFs matched |
| 18 | [FR-0064](https://hub.intelligentae.co.uk/?page=features&action=view&id=65) | low | Replace placeholder portraits with full-quality photos |

**Item 17** stays here as historical context (it was done in migration 003):

| # | Item | Status | Notes |
|---|------|--------|-------|
| 17 | **`tch_id` immutable identifier** | **DONE** in migration 003 | Format `TCH-000001`. Auto-assigned on insert. Used in URLs and as the human-facing person identifier. Survives marriage / name changes. |

## Locked Build Plan: User Management + RBAC + Audit + Impersonation

**Decided 2026-04-10. Build will run across 3 sessions starting next session.**

### Locked decisions

| # | Decision | Value |
|---|---|---|
| 1 | Email delivery | PHP `mail()` initially. Real provider later. |
| 2 | Login identifier | Email throughout. `ross@intelligentae.co.uk` is the seed super admin. |
| 3 | Permission verbs | CRUD: Read / Create / Edit / Delete |
| 4 | Hierarchy applies to records | Yes — managers see only their hierarchy's caregivers/clients/billing/roster data |
| 5 | Hierarchy applies to admin pages | No — anyone with permission sees everything on admin pages (Enquiries, Name Reconciliation, Config) |
| 6 | Caregiver/client login self-edit | Can edit own contact details (mobile, secondary, email, address, NoK). Cannot edit identity (name, ID, DOB) or training/billing. |
| 7 | Initial roles seed | Super Admin, Admin, Manager, Caregiver, Client (5) |
| 8 | Manager role permissions | Same as Admin minus user-management and role-management |
| 9 | Audit log scope | Mutations only by default (login, logout, create, edit, delete, status change, approve, reject, impersonation). Page-view forensics deferred. |
| 10 | Impersonation | Super admin only. Re-auth (own password) required. Persistent banner while active. Audit log records both real_user_id and impersonator_user_id. |
| 11 | Caregiver/client user account creation | Build the infrastructure + invite button. Don't blast 123 invites. Ross invites individuals as needed. |
| 12 | Existing `ross` user | Migrate in place: rename username → email, keep password, mark verified. |
| 13 | Page-level access | Action-level (CRUD) per role per page. Configurable via admin matrix. |

### Three-session build plan

**Session A — Schema, auth, mailer, public flows**
- Migration `005_users_roles_hierarchy.sql`:
  - Update `users` table (email, verification, lockout, hierarchy via `manager_id`, `linked_caregiver_id`, `linked_client_id`)
  - New tables: `roles`, `pages`, `role_permissions`, `user_invites`, `password_resets`, `email_log`, `activity_log`
  - Seed: 5 default roles, all current pages registered, default permission matrix, migrate `ross` row
- `includes/auth.php` + new `includes/permissions.php`:
  - Login by email
  - `requirePagePermission($pageCode, $action)` helper
  - `getVisibleUserIds()`, `getVisibleCaregiverIds()`, `getVisibleClientIds()` recursive helpers
  - Impersonation start/stop helpers (with re-auth)
  - `currentEffectiveUser()` vs `currentRealUser()`
  - `logActivity()` helper
- `includes/mailer.php`:
  - `Mailer::send()` writes to `email_log`, then attempts PHP `mail()`
  - Templates: invitation, password reset, password set confirmation
- Public auth flows:
  - `/setup-password?token=…`
  - `/forgot-password`
  - `/reset-password?token=…`
  - Update `/login` to use email field
- Deploy to dev. Verify `ross@intelligentae.co.uk` login works. Verify reset flow.

**Session B — Admin UIs, impersonation, integration**
- `/admin/users` — list, filter, invite button, deactivate
- `/admin/users/invite` — invite form
- `/admin/users/N` — detail, edit role/manager, force reset, impersonate button
- `/admin/roles` — list of roles, edit permissions matrix per role
- `/admin/roles/N/permissions` — pages × CRUD checkbox grid
- `/admin/activity` — activity log viewer with filters
- `/admin/email-log` — outbox view (so invite/reset links can be copied during dev when mail() fails)
- Impersonation flow:
  - Re-auth modal
  - Session updated with both real and impersonated user IDs
  - Persistent banner across all pages
  - End-impersonation button
- Update `public/index.php` and every existing route handler to call `requirePagePermission()` and `logActivity()`
- Apply hierarchy filtering to list pages that show caregiver/client records
- Deploy to dev. Test all flows. Push to prod when verified.

**Session C — Audit log integration sweep**
- Mechanical pass through every existing handler in `templates/admin/` and `database/seeds/`
- Add `logActivity()` calls for every mutation
- Add detailed before/after JSON for edits
- Verify audit page shows everything
- Deploy to dev → push to prod

### Out of scope for these 3 sessions (deferred)

- Real email provider (Mailgun / SendGrid / SES) — wire when Ross has signed up
- Caregiver self-service portal UI (the dedicated page caregivers see when they log in) — schema and permissions ready, full UI in a future session
- Client self-service portal UI — same
- Bulk caregiver invitation — single invites per individual is the v1 pattern
- Page-view audit logging — only mutations are logged in v1
- Action-level permissions deeper than CRUD (e.g. "approve" as a separate verb)
- Role assignment cascading (e.g. promoting someone changes their reports)

## Requires Tuniti Approval / Clarification

**Context:** All 123 caregivers in `import_review_state = 'pending'` were enriched
from Tuniti intake PDFs. The PDF data is canonical per Ross's decision but contains
data-quality issues that need Tuniti (or the candidates) to confirm/correct before
the records are approved. Each item below has a matching note in the relevant
caregiver's `import_notes` column on dev.

### Alias provenance report (added 2026-04-14 — build after D3 Phase 2)

After the Timesheet ingest runs (D3 Phase 2), build a report showing:
"This canonical person (X) is linked to these raw names (aliases), from
these data sources (workbook + tab + cell)".

- Entry point: a panel on each person profile showing all
  `timesheet_name_aliases` rows that point at them, with the
  `first_seen_source` for each.
- A standalone `/admin/config/aliases/by-person` view that inverts the
  admin page — canonical person in col 1, list of aliases in col 2.
- Clicking an alias row should link back to the source cell context
  (tab name + cell reference).

### Alias data replication dev ↔ prod (added 2026-04-14)

The `timesheet_name_aliases` table is data Tuniti will want to edit on
prod (with permission). Rules:

- On every prod deploy: export dev's alias rows, re-import on prod as a
  one-off merge (on alias_text+person_role unique key).
- Post-go-live: alias edits on prod become source of truth. Any fresh
  dev-restore-from-prod will pick up the latest aliases automatically.
- Dev can still be used for bulk ingest of new Timesheets (adds new
  unresolved aliases); those get pushed to prod on next deploy.

### Timesheet reconciliation — 56 caregiver-month discrepancies (added 2026-04-14)

Per-caregiver column arithmetic (cells × rate) does not tie to the
"Total Amount" row across 5 populated months. Five-month net gap:
**R16,231** (computed R693,389 vs sheet R709,620).

**Four patterns, 56 items total:**

| Pattern | Count | What it is |
|---|---|---|
| MISSING_RATE | 4 | Blank row 2 rate, non-zero Total Amount — ingest-blocker |
| LOAN_DEDUCTED_FROM_TOTAL | 32 | Total Amount = gross − Money Borrowed (mostly Nov 2025) |
| BONUS_ADDED_TO_TOTAL | 3 | Total Amount = gross + Money Added |
| UNEXPLAINED | 17 | Diff isn't explained by added/borrowed rows |

**Materials prepared for the Tuniti email:**

- **Excel:** `C:/ClaudeCode/_global/output/TCH/Tuniti Timesheet Reconciliation Apr-26.xlsx` — one row per discrepancy with pattern, computed, sheet, diff, money added/borrowed, and a pre-written clarification query.
- **Email body:** `C:/ClaudeCode/_global/output/TCH/Tuniti Timesheet Reconciliation Apr-26 - email body.txt` — grouped by pattern with per-item queries ("There is no Caregiver Price on tab X row 2 col Y for caregiver Z…" style).

**Pending decisions (our side, before ingest can proceed):**

1. When per-shift arithmetic disagrees with Total Amount: trust the
   cells (shift-level fidelity for D2) or trust the Total (historical
   pay figures)? Provisional middle-path is cells + discrepancy note
   per roster row.
2. Is the Nov 2025 "net-of-loans" convention wrong, or are the other
   months failing to deduct? This affects how `caregiver_loans` is
   seeded from the Money Borrowed rows.
3. Missing rates (4 caregiver-months) hard-block ingest until Tuniti
   supplies the rate that applied.

### Timesheet data anomalies — self-check required (added 2026-04-14)

Discovered during Phase 1 name-alignment of the Caregiver Timesheets
Apr-26 workbook (6 monthly tabs, Nov 2025–Mar 2026 populated).
Tuniti does **not** need to justify these — they just need to
confirm the data is correct so we know we've captured it right.

**Rate overrides written into shift cells** (override the caregiver's
default rate in row 2 for that specific shift):

| Tab | Cell | Content | Caregiver | Date |
|---|---|---|---|---|
| Dec 2025 | V29 | `Trish-600` | Marion Goeda | 19-Dec-2025 |
| Feb 2026 | Q10 | `Botes- Invoice March` | Siphilisiwe Nkala (Patricia) | 1-Feb-2026 |
| Mar 2026 | H14 | `Carli - R1000` | Ruth Nnadi | 4-Mar-2026 |
| Various | — | `Carli - R500` (×14), `O Niel -R500` (×6), `Scroope- R400` (×2), `Scroope-R450` (×1) | | |

Ask Tuniti to confirm:
- Are these per-shift rate overrides (one-off rate for that day) correct?
- What does `Botes- Invoice March` mean — billing deferred from Feb to
  March's invoice? Does Botes pay a different rate?

**Split-day / compound cells** (two patients in one cell — treated as
two shifts at 0.5 units each):

| Cell content | Count |
|---|---|
| `Apie/ Anne- Marie` | 2 |
| `Scholtz/ Carli` | 1 |

Ask Tuniti to confirm: these are half-day splits where the caregiver
covered two patients on one day?

**Ambiguous single-first-name patient cells** (collide with caregiver
first names):

| Patient cell value | Cell count | Possible meaning |
|---|---|---|
| `Linda` | 6 | A patient called Linda, OR caregiver Linda Rapuluchukwa covering for someone |
| `Christina` | 1 | A patient called Christina, OR caregiver Christina Maluleka covering |

After Phase 1 admin page is built, we'll present Tuniti with the
proposed canonical-name match and ask them to confirm.

**Half-day marker:** only one instance across 6 months — `Kotie- half`
at Jan 2026 J6 (Susan Murire, 2-Jan-2026). Rare but our parser
handles `-half` suffix as `units = 0.5`.

### Employment classification of caregivers (added 2026-04-14)

**Q:** Are TCH caregivers **self-employed contractors** invoicing TCH for their
time, or **TCH employees** (with PAYE/UIF deducted at source)?

**Why it matters:** determines how Money Added, Money Borrowed, and loan
repayments interact with "net pay" on the Caregiver Earnings report. If
self-employed, loans and pay are separate cash events that don't net. If
employed, loan repayments may be deducted from gross or net depending on
contract terms.

**Working assumption for now:** self-employed. Loans and wages tracked as
independent cash streams at the data layer; reports display them side-by-side
but don't pre-compute a net-of-loans figure. Revisit when Tuniti confirms.



### Invalid / impossible data

| # | Person (TCH ID) | Issue | What Tuniti needs to do |
|---|---|---|---|
| T1 | TCH-000103 (Ntombifikile Octavia Mhlongo, T8) | PDF DOB literally `0005-08-03` (year 0005, impossible). Existing DB DOB left in place, NOT overwritten. ID number 7508030807080 suggests real DOB is 1975-08-03. | Confirm the correct DOB and re-issue the PDF if needed. |
| T2 | TCH-000060 (Esther Kawanzaruwa, T5) | NoK contact `078282933` is only 9 digits — missing one digit. | Provide correct 10-digit number. |
| T3 | TCH-000100 (Martha Kedibone Mashigo, T8) | NoK contact `07258455122` is 11 digits — one too many. | Provide correct 10-digit number. |
| T4 | TCH-000026 (Segethi Tabea Molefe, T2) | Email `molefetabea154@gmail` — missing `.com`. | Provide complete email. |
| T5 | TCH-000052 (Sara Mdaka, T4) | Email `sarahmdaka41@gmai.com` — missing the `l` (gmai → gmail). | Confirm correct email. |
| T6 | TCH-000090 (Josephine Olaide Olaleye, T7) | Email `josephinetoolz@outlool.com` — `outlool` not `outlook`. | Confirm correct email. |

### NoK contact identical to candidate's own mobile (likely data entry error)

| Person (TCH ID) | NoK | Both numbers |
|---|---|---|
| TCH-000002 (Mukuna Mbuyi / Giselle, T1) | Felly (Brother) | 0610932278 |
| TCH-000024 (Refiloe Khuzwayo, T2) | _(Lerato, Sister)_ | _(check on dev — flagged)_ |
| TCH-000059 (Beverly Lehabe, T5) | Grace (Mother) | 0837105468 |
| TCH-000099 (Maphefo Dinah Mogola, T8) | Aaron (Partner) | 0846473643 |
| TCH-000108 (Chipo Mujere, T8) | Stewart Marange (Husband) | 0718097605 |
| TCH-000119 (Juliet Tshakane Lekgothoane, T9) | Albert Soafo (Husband) | 0765283954 |

→ **Tuniti to provide correct, distinct NoK numbers for all six.**

### Possible name collisions (need confirmation these are different people)

| # | Records | Status |
|---|---|---|
| N1 | TCH-000003 (Nelly Nachilongo, T1) and TCH-000014 (Siphiwe Nelly Ezeadum, T1) | Two "Nelly"s in the same tranche — different DOBs and IDs, treated as different people. **Confirm with Tuniti.** |
| N2 | TCH-000030 (Thandi Ngobeni, T2) and TCH-000097 (Thandiwe Dhlodhlo, T7) | Two "Thandi"s — treated as different people. **Confirm.** |
| N3 | TCH-000028 (Siphilisiwe Nkala, T2), TCH-000067 (Siphathisiwe Nkala, T5), TCH-000105 (Sithenjisiwe Gumbi, T8) | Three similar names — different DOBs and IDs, treated as different people. **Confirm with Tuniti.** |

### Possible household / family links (need confirmation)

| # | Records | Observation |
|---|---|---|
| H1 | TCH-000002 (Mukuna Mbuyi, T1) and TCH-000005 (Jovani Mukuna Tshibingu, T1) | Both have NoK first name "Felly". Possibly same person (sibling/parent of one is parent of the other). |
| H2 | TCH-000087 (Bekithemba Mpofu, T7) and TCH-000105 (Sithenjisiwe Gumbi, T8) | Bekithemba's NoK contact (0652458862) is identical to Sithenjisiwe's own mobile. Bekithemba's NoK is named "Sthenjisiwe" — likely the same person. |
| H3 | TCH-000021 (Merriam Mashadi Maluleke, T2) and TCH-000078 (Phemela Rachel Maluleke, T6) | Identical mobile number 0798474060. Same surname. Likely related. |
| H4 | TCH-000109 (Janine Louise Jones, T8) and TCH-000114 (Marlise Louise Els, T9) | Same address: 147B Tammy Street, Grootfontein. Both have middle name "Louise". Possibly housemates or family. |

→ **Tuniti to confirm relationships so we know how to model them.**

### Tranche assignment / student ID anomalies

| # | Person | Issue |
|---|---|---|
| Y1 | TCH-000003 (Nelly Nachilongo) | Listed in Tranche 1 but student ID is `202603-1` (March 2026 prefix), not `202507-NN` like the other 13. Other `202603-*` IDs all live in Tranche 9. **Confirm correct tranche.** |
| Y2 | TCH-000003 | Single-digit suffix `-1` rather than `-NN` format used by every other record. **Confirm ID.** |

### Gender / first name mismatches (likely data entry errors)

| # | Person | Issue |
|---|---|---|
| G1 | TCH-000056 (Colin Khutso Nyalungu, T4) | First name "Colin" is typically masculine but the title is "Miss" and gender is Female. **Confirm with candidate.** |
| G2 | TCH-000054 (Hloniphani Moyo / Kelly, T4) | "Hloniphani" is typically a male Zimbabwean name; recorded as Female with title "Miss" and known_as "Kelly". **Confirm.** |

### Address inconsistencies (suburb/city mismatches in Gauteng metros)

These are not blockers but should be cleaned up. Each is a case where a suburb
in one metro is recorded with a city in a different metro:

* **Tembisa/Pretoria** (Tembisa is in Ekurhuleni): TCH-000010, 000011, 000054, 000056, 000091, 000113, 000116
* **Midrand/Pretoria** (Midrand is Johannesburg metro): TCH-000010
* **Benoni/Pretoria** (Benoni is in Ekurhuleni): TCH-000115
* **Kempton Park/Pretoria** (Kempton Park is in Ekurhuleni): TCH-000040
* **Waterkloof/Mamelodi East** (different parts of Pretoria, can't be both): TCH-000012
* **Mpumalanga as a city** (it's a province): TCH-000048, 000095
* **Gauteng as a city** (it's a province): TCH-000019

→ **Tuniti to clean up addresses with each candidate** and re-issue the form data.

### Person Review queue itself

The 123 caregivers in `import_review_state = 'pending'` at
`/admin/people/review` need to be reviewed and approved one by one. This is
**Tuniti's job, not Ross's** — the data came from Tuniti's intake forms and
they're the only ones who can confirm whether each record is correct or
needs further action with the candidate.

→ **Tuniti to walk through the queue and approve / reject each record**,
referencing the import_notes panel on each card.

### Generic "Social_media" lead source

The PDF lead source `Social_media` is not specific enough to map to a channel
(we have Facebook, TikTok, Instagram, LinkedIn as separate values). Currently
NULL with a note. Affects approximately 15 records:

TCH-000010, TCH-000011, TCH-000013, TCH-000014, TCH-000019, TCH-000028,
TCH-000039, TCH-000074, TCH-000081, TCH-000082, TCH-000083, TCH-000085,
TCH-000098, TCH-000102, TCH-000103, TCH-000113, TCH-000114, TCH-000115,
TCH-000116, TCH-000117

→ **Tuniti to ask each candidate which platform** (Facebook / TikTok / Instagram /
LinkedIn / other) and update the source data.

### Typos in source data (preserved as written)

Numerous typos in nationalities, languages, suburbs, cities, NoK names. Each one
is flagged in the relevant caregiver's `import_notes`. These are not blockers but
the candidate-facing source data should be cleaned up by Tuniti at some point.
Examples (not exhaustive):

* Cities: Preotia, Pretoira, Pretroia, Johnesburg, Johnnesburg, Jobrug, Sweto,
  Hammenskraal, Acradia
* Suburbs: Pretoira West, Klinkenberg Gradens, Bryaston, Tuffontain, Mamalodi
* Nationalities: Zimbabwean → Zimbabwen, Zimbabwan, Zimbabwe (country not
  nationality)
* Languages: Sepedi → Spedi, Speed; Setswana → Setswane; Xitsonga → Xitsongo,
  Xitsomga; Ndebele → Ndebeale; Yoruba → Yoryba
* NoK names: Husabnd, Darlignton, Mahlanlane, Suprise, Jocob

### Known As discrepancies (workbook vs PDF — PDF adopted)

The workbook had a different "Known As" value to the PDF on roughly 25 records.
PDF wins per Ross's decision. Each one is flagged in `import_notes`. **Tuniti
may want to confirm the PDF version is the candidate's actual preference.**

Highlights:
* TCH-000002: Maman Mukuna → Giselle
* TCH-000015: Hlengiwe → Mahle
* TCH-000026: Tabea → Mia
* TCH-000027: Spheto → Sphe
* TCH-000029: Delisile → Sylvia
* TCH-000032: Bongani → Bongo
* TCH-000039: Susan → Susie
* TCH-000044: Busisiwe → Busi
* TCH-000045: Dikeledi → Kgosigadi
* TCH-000046: Emilia → Emmy
* TCH-000047: Mariam → Joan
* TCH-000048: Martha → Mokgadi
* TCH-000049: Julia → Nare
* TCH-000050: Casey → Nipho
* TCH-000054: Hloniphani → Kelly
* TCH-000064: Glenda → Musa
* TCH-000069: Marcia → Busisiwe
* TCH-000071: Mondli → Collen
* TCH-000073: Nomvula → Christina
* TCH-000076: Memory → Cindy
* TCH-000078: Rachel → Phemela
* TCH-000084: Division → Lani
* TCH-000085: Kemi → Mary
* TCH-000087: Bekithemba → T Man
* TCH-000088: Blessing → Rofhiwa
* TCH-000089: Tshego → Imma
* TCH-000092: Margaret → Katso
* TCH-000094: Octovia → Tsundzu
* TCH-000096: Sibonokuhle → Bongi
* TCH-000097: Thandiwe → Thandi
* TCH-000100: Kedibone → Martha
* TCH-000101: Martha → Uke
* TCH-000109: Janine → Louise
* TCH-000113: Mamohlolo → Nompi
* TCH-000118: Siphesihle → Sihle
* TCH-000121: Mashudu → Thalitha
* TCH-000122: Tsakani → Philadelphia

## Access Details (for reference)

- **Site:** https://tch.intelligentae.co.uk/
- **Admin login:** https://tch.intelligentae.co.uk/login
  - Username: `ross`
  - Password: `TchAdmin2026x`
- **Git branch:** `dev` (8 commits, nothing on `main` yet)
