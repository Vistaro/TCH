# Handoff — TCH — 2026-04-13 21:30

## State

Live on prod at **v0.9.21** (rolled up today). Client + Patient profiles fully
built and deployed. Admin role locked down to read-only with no misleading
write UI. Orange DEV banner now shows on dev, not on prod. Approaching Tuniti
UAT — urgent blocker is DEV/PROD DB separation (TODO #13).

## Last session did

- **Shipped v0.9.21 to prod** (20+ files, 4 migrations, ~3,600 lines of new code):
  full Client+Patient profile/create/edit/archive with duplicate detection,
  "Same person" smart banners (blue for genuinely-one-human, yellow for legacy
  data mismatches), multi-row phones/emails/addresses, Phase-1 billing-history
  (`patient_client_history`), What's New release-notes gate, releases admin.
- **Bill-payer guardrail** added to `/admin/engagements` — hard-blocks creating
  a care schedule when the patient has no client linked.
- **Tightened the admin role's read-only view** so Andre + Donnay (pending setup)
  see no misleading write buttons — Notes "+ Add Note" + releases admin
  create/edit form both now gated on edit permission.
- **DEV banner** (FR-0067) live — orange stripe on every admin page when
  `APP_ENV != production`.
- **Hardened prod error-display** (BUG-0035) — `includes/config.php` now forces
  `display_errors=off` whenever APP_ENV=production, regardless of php.ini.
- **Verified BUG-0036** fix for Super Admin wrong-password summary.
- **Closed on the Hub:** FR-0067, FR-0069, FR-0070, FR-0072, FR-0073 implemented;
  BUG-0035, BUG-0036 fixed.
- **Discussed design items** D1 (engagements cleanup), D2 (roster redesign
  single-source cost+revenue), D3 (re-ingest from Client Billing Spreadsheet).

## In flight (not finished)

- **Tuniti's reply to the 10-record split candidates email** — HTML + CSV
  templates saved to `_global/output/TCH/Tuniti Split Candidates Apr-26.*`.
  When she replies, a one-shot migration splits the conflated records.
- **Andre + Donnay admin accounts** — permissions matrix is correct, welcome
  email draft ready in session minutes. Waiting on Ross to create the accounts.

## Open items needing attention

- **Bugs (Hub):** 1 open — BUG-0031 (smoke-test leftover, ignore).
- **FRs (Hub):** FR-0074 (edit caregivers pre-approval, medium),
  FR-0071 (engagement first-class, high — largely done, needs close-out),
  FR-0065 (centralise reporter on Hub, medium),
  FR-0076/77/78/79 (governance audit items).
- **ToDos (repo):**
  - 🔴 **#13 URGENT** separate DEV/PROD DBs before Tuniti UAT (Ross creates
    empty DB via StackCP, Claude runs the dump/restore/smoke-test — ~30 min)
  - **#14** historic 10-record split (waiting on Tuniti's reply)
  - **#15** Phase-2 re-assign time-stamping (flip on once historic data locked)
  - **#16** onboarding workflow (care proposal + email acceptance + guardrail
    extension) — ~10–12 hrs
  - **#18** schedule UI rework (pick patient first, bill-payer derived) — ~1.5 hrs
  - **#12** Hub token path for session-start briefing script
- **Blockers:** none today.

## Next session should

1. **D1 cleanup** (Ross parked for tomorrow) — move `day_rate`, `billing_freq`,
   `shift_type`, `schedule` off `persons` onto `engagements` as defaults.
   Small tidy; unlocks D2/D3. ~1 hr.
2. **When Ross has empty DB from StackCP**, execute TODO #13 (DB separation):
   `mysqldump` prod → restore to dev DB → update dev `.env` → smoke-test →
   close FR-0076 on Hub. ~30 min.
3. **Process Tuniti's reply** on the 10-record split when it arrives —
   one-shot migration with audit log + Notes timeline entries per split.
4. **If time**, scope D2 + D3 properly (roster redesign + spreadsheet
   re-ingest) — this is the big one. Do it as a clean focused session.
