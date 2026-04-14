# Handoff — TCH — 2026-04-14

## State

Live on prod at **v0.9.21**. DEV and PROD DBs now separated (FR-0076
resolved). **D1 done** — billing defaults moved off `persons` onto
`clients`; engagements now prefill bill rate from client defaults.
Ready for Tuniti UAT prep once D2/D3 land.

## Last session did

- **DEV / PROD database split** — new dev DB
  `tch_placements_dev-353032377731` on `sdb-61.hosting.stackcp.net`.
  Server-side dump of prod (1.8M, 47 tables, 207 persons, 1,619 roster
  rows) restored into it; dev `.env` repointed. Sentinel-table write
  test confirmed full isolation. Prod untouched.
  Going-forward rule: once real users are live, any further
  dump/restore only inside a maintenance window with user-facing
  routes offline.
- **D1 — billing defaults moved off `persons`** (Option B):
  - Migration 028 — renames `clients.billing_freq` → `default_billing_freq`;
    adds `default_day_rate`, `default_shift_type`, `default_schedule`;
    backfills from persons; drops the four fields from persons.
  - `client_view.php` — "Billing" section renamed "Billing Defaults"
    with a prefill hint. Form field names switched to `default_*`.
  - `engagements.php` — patient picker carries `data-bill-rate` from
    `clients.default_day_rate`; Bill Rate prefills on patient select.
- Ran on dev → smoke-tested (login, `/admin/clients/141`, `/admin/engagements`
  edit + view all clean) → then prod migration + rsync + prod smoke test.

## In flight (not finished)

- **Tuniti's reply to the 10-record split candidates email** — still
  waiting. When she replies, run the one-shot split migration (audit
  log + Notes timeline entries per split).
- **Andre + Donnay admin accounts** — Ross still to create on prod.

## Open items needing attention

- **Bugs (Hub):** 1 open — BUG-0031 (smoke-test leftover, ignore).
- **FRs (Hub):** FR-0074, FR-0071, FR-0065, FR-0077/78/79. FR-0076 now
  ready to close (DB split done).
- **ToDos (repo):**
  - **#14** historic 10-record split (waiting on Tuniti's reply)
  - **#15** Phase-2 re-assign time-stamping (flip on once historic data locked)
  - **#16** onboarding workflow (proposal + email acceptance) — ~10–12 hrs
  - **#18** schedule UI rework (pick patient first, bill-payer derived) — ~1.5 hrs
  - **#12** Hub token path for session-start briefing script
- **Blockers:** none today.

## Next session should

1. **D2 + D3 scoping** — roster redesign (cost + bill per shift, single
   source of truth) plus re-ingest from the Client Billing Spreadsheet.
   Biggest remaining architectural job. Do as a clean focused session.
2. **Process Tuniti's reply** on the 10-record split when it arrives —
   one-shot migration with audit log + Notes timeline entries per split.
3. **Close FR-0076 on Hub** (DB split done).
4. **Fix mojibake** on `/admin/products` and hunt for other pages
   showing `â€"`.
5. **Fix 404** on some patient name clicks from `/admin/patients`.
