# Handoff — TCH — 2026-04-14 16:00

## State

**Live on prod at v0.9.22** (massive prod cut today). DEV / PROD now on
separate databases. Single-source-of-truth financial model delivered:
every report computes from `daily_roster` with full provenance back to
the source Excel cell. First-class contracts model stood up ready for
Tuniti to populate.

## Last session did

- **DEV / PROD DB split** — closes FR-0076. Dev now on `sdb-61`,
  prod unchanged on `shareddb-y`.
- **D1 — billing defaults off persons onto clients** (migration 028).
- **D3 Phase 1 — alias admin** at `/admin/config/aliases`: 151 aliases
  mapped (42 caregivers + 56 patients + 53 clients) from the Apr-26
  workbooks. Auto-promote flow for students → caregivers.
- **D3 Phase 2 — wipe-and-rebuild ingest** of `daily_roster` from
  Timesheet (cost) + Panel (bill). 1,622 shifts, cost R729k, bill R902k,
  R220k landing in Unbilled Care. Every row traces to one Excel cell.
- **Unbilled Care umbrella** — sentinel client + red dashboard tile +
  `/admin/unbilled-care` drill-down. 24 orphan patients surface for
  Ross to link to real bill-payers.
- **All financial reports cut over** — Client Profitability / Caregiver
  Earnings / Client Billing / Dashboard now read only from
  `daily_roster`. `client_revenue` + `caregiver_costs` retained as
  historical snapshots.
- **Roster View `/admin/roster`** — patient-centric monthly grid,
  colour-coded caregivers, +N for multi-caregiver days, Unbilled
  patients flagged red, sticky columns/headers, weekend tint, print
  landscape + CSV export.
- **Contracts first-class** — `/admin/contracts` + create + edit +
  detail. Contract = commercial agreement; engagement = caregiver
  assignment; roster = delivery. Auto-renew + supersede chain wired.
- **Column alignment standard** — `.number` / `.center` / default.
  Applied to Unbilled Care page; rollout across 12 other admin tables
  queued.
- **Tuniti reconciliation email materials** prepared at
  `_global/output/TCH/` — R16,231 of discrepancies across 56 items,
  ready for Ross to send.

## In flight (not finished)

- **Tuniti contract list** — contracts table is empty, waiting for
  Tuniti to provide her current contracts.
- **Tuniti reconciliation reply** — email drafted but not yet sent
  (Ross to send).
- **24 orphan patient → real client links** — Unbilled Care will
  shrink from R220k toward 0 as Ross links each patient to their
  actual bill-payer via the patient profile UI.
- **Governance mailbox** — 4 messages from governance, all
  informational responses; to be archived at wrap.

## Open items needing attention

- **Bugs (Hub):** 2 open — BUG-0031 (smoke-test leftover, ignore);
  BUG-0037 (cosmetic: sort arrows on `/admin/config/aliases`).
- **FRs (Hub):** FR-0074, FR-0071, FR-0065, FR-0077/78/79. FR-0076 now
  closed (DB split done).
- **ToDos (repo — docs/TCH_Ross_Todo.md):**
  - Tuniti: send contract list for ingest
  - Tuniti: fill product billing_freq + min_term defaults
  - Tuniti: review caregiver working_pattern values
  - Tuniti: 56-line reconciliation response
  - Tuniti: Linda/Christina alias disambiguation
  - Tuniti: Jan 2026 date serials fix
  - Us: `/admin/onboarding` wizard (replaces email for Tuniti todos)
  - Us: scheduling UI `/admin/schedule/{contract_id}`
  - Us: alias re-map trigger
  - Us: column-alignment rollout to 12 tables
  - Us: Xero API integration
- **Blockers:** none — Tuniti inputs are the limiting factor but
  plenty of independent dev work.

## Next session should

1. **Run alias re-map trigger build + BUG-0037 cosmetic fix** — both
   technical, ~45 min combined, bundle with the column-alignment
   rollout as a tidy-up pass.
2. **Design + scope the `/admin/onboarding` Tuniti wizard** — six
   steps, replaces email ping-pong. First big UX win for Tuniti's
   daily workflow.
3. **Start on scheduling UI** if Tuniti sends her contracts — that's
   the big Phase 3 build (caregiver availability algorithm, calendar
   assignment, auto-generate roster rows).
4. **When Ross has time** — manually link the 24 orphan patients to
   real bill-payers (20-30 min of his knowledge, I re-run
   apportionment, Unbilled Care shrinks meaningfully).
