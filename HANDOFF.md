# Handoff — TCH

> **Project state lives in [`docs/PROJECT.md`](docs/PROJECT.md), not here.**
> This file is a thin per-session cover sheet. Read PROJECT.md first.

---

## Last session (2026-04-20) — what changed

Ross said "do all 4" and went away. Auto-mode delivered four cohesive chunks:

- **Phase A — plumbing.** `scripts/migrate.sh` retention tightened so ad-hoc
  dumps aren't pruned by the per-env "last 5" rule. `.gitattributes` added
  to force LF line endings on shell/SQL/PHP (caught a CRLF breakage mid-session).
- **Phase B — caregiver loan ledger (Phase 6.4).** Migration 047 +
  `/admin/caregiver-loans`: event-sourced advance/repayment ledger,
  per-caregiver balance table, record-event form. super_admin only.
- **Phase C — FR-N Phase 2 geocoding.** `includes/geocode.php` (Nominatim,
  1 req/s throttle), auto-geocode hook on patient save + create,
  `/admin/dev-tools/geocode-backfill` for catch-up sweeps. Patient list
  Distance column (from Phase 1) becomes live as data populates.
- **Phase D — patient care-needs profile + emergency contacts (Phase 4.4).**
  Migration 048 + two new card sections on patient detail. Medical /
  physical / cognitive / preferences / summary categories + DNR badge.
  Many-per-patient emergency contacts with PRIMARY + POA flags.

5 commits, ~1,300 lines, 2 dev migrations, 4 new admin surfaces.
All new pages super_admin-only — zero change to what Tuniti sees.

---

## Open at session end — 4 items

See `docs/PROJECT.md` §6 and `docs/TCH_Ross_Todo.md` #22/#23/#20/#21.

1. **Forth Host malware paths** — Ross needs to pull 2 file paths from
   `cp.forthhost.com`. Blocking the triage.
2. **PROD ship v0.9.26** — today's 4 phases ready to go when greenlit.
3. **"Save & Send" on quote builder** — awaiting Tuniti-usage feedback.
4. **Backward stage moves on opp detail** — awaiting Ross's call.

---

## Dev vs PROD

| | Code | Migrations | Notes |
|---|---|---|---|
| **DEV** | `9f50430` | 034–048 all applied | +test data + Phase A-D live |
| **PROD** | `130ca42` / v0.9.25 | 034–046 all applied | Phases A-D awaiting ship |

---

## Next session entry pattern

1. `docs/PROJECT.md` §6 — read the 4 open items first.
2. `docs/PROJECT.md` §7 — check risk register.
3. `docs/release-log.md` — what Tuniti can see.
4. This file.
5. Pick up where left off.
