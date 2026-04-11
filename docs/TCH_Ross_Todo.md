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

## Ongoing

| # | Item | Notes |
|---|------|-------|
| 9 | **Purge CDN cache after deployments** | StackCP > CDN > Edge Caching. Only needed until we go to production and set proper cache rules. |
| 10 | **DB credentials** | Stored in server `.env`. Username: `tch_admin`, DB: `tch_placements-313539d33a`, host: `shareddb-y.hosting.stackcp.net` |

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

## Person Database Build (added 10 April 2026)

Decisions locked in this session for unifying student/caregiver into a single Person record:

| # | Item | Priority | Notes |
|---|------|----------|-------|
| 11 | **System config admin page** | MEDIUM | One UI to manage all lookup lists: `person_statuses`, `lead_sources`, `attachment_types`, future `relationships`, etc. Replaces hard-coded ENUMs. |
| 12 | **Status promotion gates** | MEDIUM | Define required-fields-per-status and enforce in app layer when status is changed (e.g. cannot move to `Qualified` without DOB, ID number, course completion). DB stays permissive. |
| 13 | **Referrer / affiliate model** | LOW (build later) | When `lead_source = Referral`, capture who referred them. Future-proof for incentive payments. Today: free-text `referred_by_name` + `referred_by_contact` on the person record. Later: full referrer table + payout tracking. |
| 14 | **Field-level role-based edit permissions** | MEDIUM | Per-field edit/view rules based on user role. E.g. only finance role can edit banking, only admin can change `tch_id`. |
| 15 | **Person record card view** | HIGH (this build) | Card view styled to mirror the Tuniti PDF intake page: photo top-left, two columns of structured fields, NoK block, attachments list. Used for the import review screen and for the standard person profile screen. |
| 16 | **Retire `name_lookup` table** | MEDIUM | Once all 9 PDFs imported and matched, backfill all legacy FKs (caregiver_costs, daily_roster, etc.) and drop `name_lookup`. End state = single canonical person record, no name strings used as identity anywhere. |
| 17 | **`tch_id` immutable identifier** | DONE in migration 003 | Format `TCH-000001`. Auto-assigned on insert. Used in URLs and as the human-facing person identifier. Survives marriage / name changes. |
| 18 | **Replace placeholder portraits with full-quality photos** | LOW | Current portraits are crops from the Tuniti intake PDFs — adequate but low resolution. Source higher-quality originals from Tuniti or re-photograph. Each replacement lands as a NEW `profile_photo` attachment (history preserved). |

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
