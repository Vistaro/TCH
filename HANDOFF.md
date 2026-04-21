# Handoff — TCH — 2026-04-21 12:00

> **Project state lives in [`docs/PROJECT.md`](docs/PROJECT.md), not here.**
> This file is a thin per-session cover sheet. Read PROJECT.md first.

---

## Last session (2026-04-20 → 21) — what changed

**2026-04-20** — Ross said "do all 4" and went away. Auto-mode delivered:

- **Phase A — plumbing.** `scripts/migrate.sh` retention tightened so ad-hoc
  dumps aren't pruned. `.gitattributes` added to force LF line endings
  (CRLF-in-shell-script bit us mid-session).
- **Phase B — caregiver loan ledger (Phase 6.4).** Migration 047 +
  `/admin/caregiver-loans` event-sourced advance/repayment ledger.
- **Phase C — FR-N Phase 2 geocoding.** `includes/geocode.php` (Nominatim
  1 req/s), auto-hook on patient save, `/admin/dev-tools/geocode-backfill`
  for catch-up. Patient list Distance column becomes live as rows geocode.
- **Phase D — patient care-needs + emergency contacts (Phase 4.4).**
  Migration 048 + two new card sections on patient detail. Medical /
  physical / cognitive / preferences / summary categories + DNR badge.
  Many-per-patient emergency contacts with PRIMARY + POA flags.

5 commits, ~1,300 lines, 2 dev migrations, 4 new admin surfaces.
All new pages super_admin-only — Tuniti sees nothing new.

**2026-04-21** — wrap session:
- Updated ARCHITECTURE.md + DECISIONS.md + HANDOFF with 2026-04-20 additions.
- 5 actioned mailbox messages archived.
- Queued next-session open items.

---

## Open at session end — 5 items (in priority order)

### 🔴 1. Forth Host malware triage — FIRST ACTION NEXT SESSION

Governance confirmed 2026-04-21 11:00 ([msg](../\_global/output/agent-messages/2026-04-21-1100-governance-to-tch-forth-flagged-files-are-your-backup-archives.md))
that the 2 files Forth flagged are TCH's own backup archives:

```
/home/sites/9a/7/72a61afa93/db-backups/tch/prod-webroot-pre-0920-20260413-160734.tgz
/home/sites/9a/7/72a61afa93/db-backups/tch/prod-webroot-pre-0917-20260413-105958.tgz
```

Scanner is hitting a PHP file inside the archives with a webshell-shape
signature (`$_COOKIE` + `$_SERVER[HTTP_USER_AGENT]` + `file_get_contents` +
`tempnam` + `file_put_contents` + `unlink`). That shape is **also** the
legitimate shape of an upload handler — most likely candidate is
`includes/onboarding_upload.php`.

**Next-session action:**
1. `ssh` in, extract `prod-webroot-pre-0920-20260413-160734.tgz` to a
   scratch folder outside webroot.
2. `grep -rl` for the six-token signature — should surface 1-2 files.
3. Open each candidate, verify each of the 6 tokens has a legitimate
   reason.
4. If false positive (likely): draft a one-paragraph explanation for
   Ross to send Forth support requesting a whitelist + rescan, and
   audit the backup folder's retention policy.
5. If genuine injection: escalate per Governance's guidance — check
   access logs for 2026-04-13, verify v0.9.25 PROD doesn't carry the
   same code, consider credential rotation.

Urgency: PHP mail still disabled account-wide until Forth rescans.
Nexus-CRM (live) is affected. TCH itself is pre-live.

### 🟡 2. PROD ship v0.9.26 (Phases A–D from 2026-04-20)

On `dev` at `725f736`, not yet on PROD. Same 8-step chain as v0.9.25.
Only mig files 047 + 048 to apply. All super_admin-only — no Tuniti-
visible changes. See Ross_Todo #23.

### 🟢 3. Save-and-send button on quote builder (ToDo #20)
Awaiting Tuniti usage feedback. ~15 min when approved.

### 🟢 4. Backward stage moves on opportunity detail (ToDo #21)
Awaiting Ross's call. 3 options documented. ~5 min.

### 🟢 5. Governance follow-ups — nothing blocking
`v0.9.25 ship complete` message filed 2026-04-20 09:45; no reply yet.
Portfolio-wide `.env` management question also pending their answer.

---

## Dev vs PROD state

| | Code | Migrations | Notes |
|---|---|---|---|
| **DEV** | `725f736` | 034–048 applied | Today's Phase A-D live + test data |
| **PROD** | `130ca42` / v0.9.25 | 034–046 applied | Awaiting v0.9.26 ship |

Working tree clean. `0 ahead / 0 behind` origin/dev.

---

## Counts

- **Bugs:** Hub query blocked by Ross_Todo #12 (Hub token path). Known: BUG-0031 (smoke-test, ignore), BUG-0037 (done in `420e24f`).
- **FRs:** 19 FRs A-S in `docs/TCH_Quote_And_Portal_Plan.md`. Delivered: A, B, E, F Phase 1, L, N Phase 1+2 foundation. In flight: none. Largest undelivered: C (quote builder — shipped, but not released to Tuniti yet), S (caregiver portal), P (WhatsApp), Q (shift workflow).
- **ToDos:** ~20 in `docs/TCH_Ross_Todo.md`. Two 🔴 at the top: #22 Forth paths (now superseded by the 04-21 triage action above), #23 v0.9.26 PROD ship.
- **Blockers:** none on TCH's side of the fence. Forth rescan is a hosting-level blocker for PHP mail, not for TCH code.

---

## Next session should

1. **Forth triage first** — action item 1 above. SSH in, extract archive, grep, verdict. ~15 min if false positive.
2. **PROD ship v0.9.26** — after Forth is off the risk register.
3. Pick the next strategic build from PROJECT.md §4a: FR-O LeadTrekker integration, or FR-S caregiver portal shell, or FR-N Phase 3 (caregiver-side distance + operating-radius hard blocks on scheduling).

---

## Next session entry pattern

1. `docs/PROJECT.md` §6 — the 5 open items above.
2. `docs/PROJECT.md` §7 — risks.
3. `docs/release-log.md` — what Tuniti can see.
4. This file.
5. Forth triage, then ship v0.9.26, then next strategic build.
