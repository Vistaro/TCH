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
| DQ1 | **id=47 Morrison — R0 total income, 2 revenue rows** | Flagged during patient dedup Round 2 (2026-04-11). The two revenue rows attached to `clients.id = 47 "Morrison"` sum to R0.00. Either genuinely zero-value data from ingest or an import bug. Investigate before we trust any report that filters by client income > 0. |
| DQ2 | **Slash-split client/patient rows may be wrong** | During the patient-name backfill (2026-04-11), 7 client rows with "X / Y" naming were split into client=before-slash, patient=after-slash. Known-rough case: id=5 "Angela / Dimitri Papadopoulos" almost certainly means Angela Papadopoulos + Dimitri Papadopoulos (shared surname lost on the client side). Other candidates: ids 4, 7, 21, 24, 25, 53. These need human review and correction via the edit-relationship UI once it exists. |

## UI requirements — queued for the person-record edit screen

| # | Item | Notes |
|---|------|-------|
| UI1 | **Edit client ↔ patient relationship from the Patient record** | On the patient edit screen, show "Billed to: [Client name]" with an edit control so the billing relationship can be changed. Applies both to the current one-record-is-both-client-and-patient rows and to the split rows from DQ2. Becomes genuinely multi-record when a corporate Client pays for multiple Patients. Build when we first need to correct one of the DQ2 rows. |

## Ongoing

| # | Item | Notes |
|---|------|-------|
| 9 | ~~**Purge CDN cache after deployments**~~ | **No longer required** as of 2026-04-11 (v0.9.9.3). The `.htaccess` no-cache block was removed (BUG-0035) and static assets are cache-busted via `?v=<filemtime>` query strings. Try the next deploy without a purge — if anything's stale, re-add as a fallback. |
| 10 | **DB credentials** | Stored in server `.env`. Username: `tch_admin`, DB: `tch_placements-313539d33a`, host: `shareddb-y.hosting.stackcp.net` |
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
