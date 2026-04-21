# Handoff — TCH — 2026-04-21 18:00

> **Project state lives in [`docs/PROJECT.md`](docs/PROJECT.md), not here.**
> This file is a thin per-session cover sheet. Read PROJECT.md first.

---

## This session (2026-04-21 pm) — what changed

Cross-device recovery + v0.9.26 shipped.

- **Sync-incident recovery** — session opened on the desktop with the
  TCH clone 21 commits behind origin; OneDrive mirror in a split-brain
  state (working tree post-wrap, `.git/refs` pre-wrap). Root cause
  diagnosed with Governance as (a) `.git/` inside OneDrive violating
  the standing order, (b) `/restore` running robocopy `/MIR` which
  silently deletes local-only files, (c) backup script not excluding
  `.git/` — all three fixed at portfolio level during this session.
  TCH-specific recovery: `git pull --ff-only origin dev` (clean
  fast-forward, no local work at risk), then moved
  `C:/Users/Intel/OneDrive/Claude/TCH/.git` to
  `C:/Users/Intel/OneDrive/Claude/_quarantine/TCH-git-2026-04-21/`.
- **Project-local `CLAUDE.md`** — Ross's new local rules codified: every
  PROD push runs through a mandatory six-step checklist (pre-migration
  DB snapshot, DEV-refresh prompt, test-data coverage, Tuniti-role
  gating diff, `/admin/help` currency, What's New parity). Lives at
  repo root; layered on top of the global standing orders.
- **v0.9.26 shipped to PROD.** Bundled the 2026-04-20 Phase A-D work
  (migrate retention fix, caregiver loan ledger + mig 047, Nominatim
  geocoding, patient care-needs + emergency contacts + mig 048) plus
  today's governance commit. All new admin surfaces super_admin-only —
  Tuniti sees nothing new. Release commit `c348ba2`. Snapshots taken
  per migration (`20260421T164944Z-047-prod.sql.gz`,
  `20260421T165055Z-048-prod.sql.gz`). Smoke tests green on
  `/admin/caregiver-loans`, `/admin/patients/1`, public home.

---

## Dev vs PROD state (end of session)

| | Code | Migrations | Notes |
|---|---|---|---|
| **DEV** | `c348ba2` | 034–048 applied | v0.9.26 on DEV, not yet refreshed from PROD |
| **PROD** | `c348ba2` / v0.9.26 | 034–048 applied | Live, 2026-04-21 17:51 |

Working tree clean bar `.claude/settings.local.json` (harness config,
always untracked). DEV and PROD SHAs match.

**DEV data**: NOT refreshed from PROD this session. Deferred to next
session per Ross's call — `scripts/dev-db-sanitise.sql` doesn't yet
exist, and the two new tables are empty either side so the gap is
cosmetic for now. Write the sanitise script + run first refresh as
priority #2 next session (see below).

---

## Open at session end — 6 items

### 🔴 1. Forth Host malware triage — STILL FIRST ACTION NEXT SESSION

Carried forward from the morning's handoff; not yet actioned. Governance
confirmed 2026-04-21 11:00 that the 2 files Forth flagged are TCH's own
backup archives at
`/home/sites/9a/7/72a61afa93/db-backups/tch/prod-webroot-pre-09*-20260413-*.tgz`.
Signature shape (`$_COOKIE` + `HTTP_USER_AGENT` + `file_get_contents` +
`tempnam` + `file_put_contents` + `unlink`) is consistent with the
legitimate upload handler `includes/onboarding_upload.php`. Next
session: SSH, extract archive to scratch, `grep -rl` for six-token
signature, verify each hit is legitimate, draft whitelist explanation
for Forth support. ~15 min if false positive.

PHP mail still disabled account-wide at Forth until rescan. Affects
Nexus-CRM (live). TCH pre-live, unaffected.

### 🔴 2. Write `scripts/dev-db-sanitise.sql` + refresh DEV from PROD

Accepted post-ship as first-up next session per Ross's (c) call on the
DEV-refresh question. Script must sanitise per the global standing
order's minimum ruleset (names → fakes, `user{id}@dev.invalid`,
`+44-DEV-xxxx`, DOBs ±30d shift, tokens nulled, free-text PII
truncated — plus TCH-specific: GDPR Art.9 data sweep for any
clinical/care-needs text fields since migration 048 landed). Preserve
rows with `is_test_data = 1`. Once script exists, snapshot PROD →
restore to DEV → apply sanitise → verify. ~30-45 min once script is
written.

### 🟡 3. v0.9.26 follow-ups — both super_admin-only

From CLAUDE.md checklist option (ii) ship-with-gaps (`docs/TCH_Ross_Todo.md`
last section):
- `TODO-testdata-047-048` — extend `/admin/dev-tools/test-data` to
  seed the three new tables.
- `TODO-help-047-048` — add `/admin/help` blocks for the three new
  super_admin surfaces.

Both LOW priority. ~30 min combined. Nil Tuniti impact.

### 🟡 4. Deploy-script cross-device brittleness

Surfaced during this session's ship: `scripts/deploy.sh` hard-codes
`$HOME/.ssh/intelligentae_deploy_ed25519` as the SSH key path.
Workaround was to copy the key from `_global/keys/` into `~/.ssh/`.
Durable fix: allow an env-var override (e.g. `DEPLOY_SSH_KEY`) or
check both locations. Also applies to `migrate.sh` SSH invocations
if they ever move into a script.

### 🟢 5. Save-and-send button on quote builder (Ross_Todo #20)

Awaiting Tuniti usage feedback. ~15 min when approved. Unchanged
from morning handoff.

### 🟢 6. Backward stage moves on opportunity detail (Ross_Todo #21)

Awaiting Ross's call on three documented options. ~5 min. Unchanged
from morning handoff.

---

## Counts

- **Bugs:** Hub query still blocked by Ross_Todo #12 (Hub token path).
- **FRs:** 19 FRs A-S in `docs/TCH_Quote_And_Portal_Plan.md`.
  Delivered: A, B, E, F Phase 1, L, N Phase 1+2. In flight: none.
- **ToDos:** ~22 in `docs/TCH_Ross_Todo.md` (morning's ~20 + 2 new
  for v0.9.26 post-ship gaps).
- **Blockers:** none on TCH's side.

---

## Next session should

1. **Forth triage first** — item 1.
2. **DEV-refresh pipeline** — item 2 (write sanitise script, run
   first refresh). This is the first live exercise of the post-ship
   DEV-refresh rule in the new project-local CLAUDE.md.
3. **Pick the next strategic build** from PROJECT.md §4a: FR-O
   LeadTrekker, FR-S caregiver portal shell, FR-N Phase 3
   (operating-radius hard blocks on scheduling).

---

## Next session entry pattern

1. `docs/PROJECT.md` §6 — open items (6 items above).
2. `docs/PROJECT.md` §7 — risks.
3. `docs/release-log.md` — what Tuniti can see (unchanged
   post-v0.9.26; no admin grants in this ship).
4. `CLAUDE.md` (new) — the six-step PROD-push checklist.
5. This file.
6. Forth triage → DEV refresh → next strategic build.
