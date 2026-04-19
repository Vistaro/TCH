# Handoff — TCH

> **As of 2026-04-19, project state lives in [`docs/PROJECT.md`](docs/PROJECT.md), not here.**
> This file is now a thin per-session cover sheet. Read PROJECT.md first for the real state.

---

## Last session (2026-04-19) — what changed

Project moved to formal PM tracking after Ross's call for "more than vision + proposal-vs-delivered". New living docs:

- **`docs/PROJECT.md`** — single source of truth. Master scope vs delivery, backlog (3 buckets), in-flight, risk register, release-gating policy.
- **`docs/release-log.md`** — append-only ledger of what's been released to Tuniti users (`admin` role). Initialised with current state.
- **`docs/TCH_Quote_And_Portal_Plan.md`** — six new FRs added (N geo+travel, O LeadTrekker, P WhatsApp comms, Q WhatsApp+GPS shift, R release-gating policy, S caregiver portal). Now 19 FRs total (A–S).

Schema:
- **Migration 043** — strips inadvertent grants of opportunities/pipeline/quotes/quotes_rate_override from `admin` role per release-gating. Tuniti users no longer see the in-build sales/quote tooling. Run on DEV.

Code on dev (deployed via `scripts/deploy.sh dev`):
- All FR-L (sales pipeline + Kanban), FR-C (quote builder), FR-E (rate override), FR-F Phase 1 (PDF print view) live and working — but hidden from `admin` role per FR-R.
- `/admin/help` user guide live, role-aware (Tuniti only sees guidance for what they can access).
- `/admin/enquiries/new` manual enquiry create form live.
- Backfilled 12 missing client account numbers (mig 042).

Tooling:
- `scripts/migrate.sh` (governance's portfolio skeleton) and `scripts/deploy.sh` (rsync/tar-over-ssh fallback) both proven.

---

## Open at session end

- **Mig 043 needs deploying to PROD** when Ross greenlights — it removes admin-role grants on opportunities/pipeline/quotes which currently aren't on PROD anyway (032/033 onward never ran on PROD per HANDOFF history). Effectively low-risk on PROD until the FR-L/C code itself is shipped to PROD.
- **Governance prod sequence on `tch-post-outage-2026-04-16` thread** — 034/035 + D/D' rsync still awaiting Ross's explicit ship-it call.
- **5 UX issues flagged in `docs/sessions/2026-04-18-autonomous-cleanup.md`** still need Ross's eyeballs — mobile Kanban (Hub bug filed), save-and-send button (ToDo 20), backward stage moves (ToDo 21), help-page RBAC at portal-launch.

---

## Next session entry pattern

1. Read `docs/PROJECT.md` §6 (in-flight) — what was being worked on.
2. Read `docs/PROJECT.md` §7 (risks) — anything fresh.
3. Read `docs/release-log.md` — what Tuniti can currently see.
4. Read this file (HANDOFF.md) for the last-session-state cover.
5. Pick up where the previous session left off.

If a new instruction comes in from Ross at session start, route through normal flow and update `PROJECT.md` as work progresses.
