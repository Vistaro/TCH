# TCH — Project-Local Standing Orders

Applies to TCH only. Layers on top of the global
`C:\ClaudeCode\CLAUDE.md`. Global wins on conflict.

Reasoning and examples for each rule live in commit history and
session notes under `docs/sessions/` — look there if an edge case
isn't obvious from the one-line form below.

---

## Every PROD push — MANDATORY six-step checklist

Before presenting the ship-event approval, walk through the six
steps below and report pass/fail per step inside the ship
presentation. If any step fails, pause and fix before shipping —
do not ship on a failing checklist.

### 1. PROD DB snapshot before any schema change

Use `scripts/migrate.sh prod <id>` — it snapshots to
`~/backups/pre-migration/` with a git-SHA manifest and aborts if
the snapshot is empty. **Never hand-apply SQL on PROD.** Rollback
unit = git SHA + paired DB dump.

### 2. DEV DB is not PROD; ask about refresh after ship

PROD = real customer data; DEV = playground. Code crosses
DEV→PROD on deploy; data does not. **On every PROD ship, ask
Ross whether to refresh DEV from PROD after the ship is green.**
Refresh is sanitised per `scripts/dev-db-sanitise.sql` (not yet
created as of 2026-04-21 — FR needed), and must preserve rows
marked `is_test_data = 1`. Default answer is probably yes, but
never auto-run — it's a confirm-action.

### 3. Test-data coverage for surfaces Ross will QA

Dummy-data tooling lives at `/admin/dev-tools/test-data`
(migration 044 + the `is_test_data` flag). **Before closing a
feature that Ross will test on DEV, confirm the tool can seed
the relevant surface** (pipeline, opportunities, quotes,
engagements, care-needs, loans, etc.). If it can't, extend the
tool in the same commit as the feature — don't ship a feature
that can't be exercised on empty DEV data.

### 4. Tuniti-role gating — `admin` sees only the agreed rollout

Diff PROD's `role_permissions` rows for role slug `admin` against
`docs/release-log.md`. The admin role MUST only see what's in
the ledger. **Enforcement:**

- New admin-visible surface in this ship → requires a
  `release-log.md` entry AND a grant migration in the same
  commit.
- New super_admin-only surface in this ship → confirm `admin`
  has no permission row for it.
- Any drift between PROD `role_permissions` and `release-log.md`
  → pause and reconcile before shipping.

### 5. User manual (`/admin/help`) is current + role-gated

`/admin/help` is a living document. Every page block is gated by
`userCan(<code>, 'read')` — users only see instructions for what
their role can actually reach. Before every ship:

- Every admin-visible page added in this ship has a block in
  `templates/admin/help.php`, gated by the correct `userCan()`
  check.
- Every super_admin-only page added in this ship is either
  absent from help or gated so `admin` can't see it.
- Footer's last-updated date stamp is refreshed to the ship date.

### 6. What's New page reflects user-visible changes

If any admin-visible feature ships (per step 4's diff), add the
entry to `templates/admin/whats_new.php` so logged-in users see
the change notification on next visit.

Super_admin-only features don't need a What's New entry — they're
invisible to normal users anyway.

---

## Ship presentation shape

Present the PROD ship to Ross as a single approval with:

- **Diff summary** — dev → prod code + migrations
- **CHANGELOG.md version entry** (drafted, ready to commit)
- **Six-step checklist** — pass/fail per step with evidence
- **Deploy + migrate command sequence** — exact commands
- **Post-ship verification plan** — smoke URLs, migration
  manifest check, role_permissions spot-check

One approval = whole sequence runs end-to-end without re-asking
per step (per global `CLAUDE.md` §Behaviour).

## After every ship

- Offer DEV-from-PROD refresh (step 2 above)
- Flag pre-migration backup retention for the +14d cleanup date
- Update HANDOFF.md with what shipped and any follow-ups
- Mailbox-ack to governance if they're tracking the thread

---

## Local session rules

- **`.claude/settings.local.json`** is intentionally untracked
  and will always show as modified. Ignore it in ship-readiness
  checks.
- **`docs/sessions/`** holds per-session notes + any git-backed
  backup copies of governance mailbox messages (insurance
  against `/restore` + robocopy `/MIR` eating local writes,
  per the 2026-04-21 incident).
