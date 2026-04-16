# Handoff — TCH — 2026-04-16 21:15

## State

**Live on PROD at v0.9.24.** Two releases shipped this session —
v0.9.23 (outage-recovery + long-pending onboarding dashboard that
was waiting for deploy) and v0.9.24 (Quote & Portal Plan Phase 1:
multi-unit product pricing + per-line contract dates + schema prep
for quote-builder state machine). DEV and PROD code are semantically
identical; migrations 034, 035, 036, 037, 038 all applied on PROD.
Documentation debt closed — CHANGELOG backfilled with rollback
recipes per commit, ARCHITECTURE.md reflects the v0.9.24 data-model
shifts.

## Last session did

1. **Closed out the 2026-04-15 Anthropic outage recovery** — picked
   up 8 uncommitted files from the dead session, split them into 7
   coherent commits (migrations 034 + 035, display-label polish,
   hourly billing freq, silent-truncate fix, recon cascade +
   permission swap, caregiver patterns, upload screens), applied
   fixes from governance cold-read (B1/B2/B3/W1/W2), shipped the
   lot as v0.9.23. Material side-finding that governance highlighted:
   the `caregiver_view.edit` → `onboarding.edit` permission swap
   turned out to be an empirical bug fix (nobody on DEV or PROD held
   `caregiver_view.edit`, so Super Admin + Admin were silently
   locked out of the recon + caregiver-patterns edit UIs before
   the swap).
2. **Drafted and filed the 11-FR Quote & Portal Plan** at
   `docs/TCH_Quote_And_Portal_Plan.md` — end-state vision for
   quote-to-contract-to-scheduling covering multi-unit pricing,
   quote builder, state machine, PDF + email + portal delivery,
   client/patient user classes, mid-contract changes, and caregiver
   availability as a parallel track.
3. **Shipped v0.9.24 — Quote & Portal Plan Phase 1** — migration 036
   introduced `product_billing_rates` (multi-unit pricing child
   table, backfilled from the legacy single-default columns),
   migration 037 added per-line `start_date` + `end_date` to
   `contract_lines`, migration 038 prepped the `contracts` table
   for the quote state machine (4 new status values, 5 new
   quote-workflow columns) and widened `contract_lines.billing_freq`
   to include `'hourly'`. UI: `/admin/onboarding/products`
   rewritten as card-per-product with 6 billing-unit rows;
   `contracts_detail.php` renders per-line dates with "ongoing"
   label; `contracts_create.php` reads product picker prefills
   from the new table (partial FR-A2). Plus a drive-by fix on
   `contracts_detail.php` that 500'd on any contract view due to
   a pre-existing reference to non-existent `users.first_name`.
4. **Two ship events run under the new one-approval rule.** Per
   governance's 12:45 standing-order change, each ship presented
   the full sequence once and executed end-to-end. No mid-sequence
   re-asks needed.
5. **Closed documentation debt** — CHANGELOG.md backfilled with
   per-commit rollback recipes for the six v0.9.23 sub-commits
   that shipped without their own entries (governance's 12:00
   ask). ARCHITECTURE.md updated to describe the new
   `product_billing_rates`, per-line contract dates, and
   contracts-as-quote state.
6. **Supersede housekeeping** — Item 16 in `docs/TCH_Ross_Todo.md`
   (care proposal + email acceptance workflow) marked SUPERSEDED
   and pointed at the Quote & Portal Plan, which covers the same
   scope as FR-C/D/G/H/I.

## In flight (not finished)

Nothing. All session work committed, pushed, deployed. DEV and PROD
match. Mailbox cleaned (3 informational read, 1 reply shipped,
2 actionable flagged for next session below).

## Open items needing attention

### Quote & Portal Plan — Phases 2-5 (next session priorities)

- **FR-C** — Quote builder screen. Foundation + schema now ready.
  Natural next piece; estimated 2-3 days of focused work.
- **FR-D** — Quote state machine. Schema ready from migration 038;
  needs the handler + transition UI.
- **FR-E** — Quoter rate-override permission. Small commit; ships
  with or right after FR-C.
- **FR-F** — Quote PDF boilerplate (Dompdf) + Tuniti onboarding task
  to supply a branded template.
- **FR-G** — Email delivery + client preference field.
- **FR-H** — Client/Patient user classes + invite-on-accept.
- **FR-I** — Portal tokenised acceptance flow.
- **FR-J** — Mid-contract term change workflow.
- **FR-K** — Caregiver availability profile + diary (parallel
  track; prerequisite for scheduling Phase 3).
- **FR-A2 remainder** — retrofit `/admin/products` full CRUD +
  drop legacy `products.default_billing_freq` / `.default_price`.
  Partial cutover shipped (`contracts_create.php`); `products.php`
  CRUD still reads legacy columns.
- **FR-B2** — decide fate of `contracts.start_date` / `.end_date`:
  compute from lines on read and retire, or keep as cache.

### Governance asks sitting in the mailbox (marked read, awaiting work)

- **Schema audit + surplus-field sweep** (2026-04-16 13:25). Full
  ER diagram + per-table docs into ARCHITECTURE.md + audit for
  stored derivations that violate single-source-of-truth.
  ToDo-level, no Hub FR; multi-hour job.
- **User manual FR draft** (2026-04-16 13:30). Ross wants an
  online user manual for TCH. Draft as a three-layer Hub FR
  following the Work-Item Detail standard; governance reviews
  before it lands in Hub.
- **I1 portfolio-pattern FR** — bulk-handler audit-logging weakness
  (applies to TCH + Nexus-CRM). Draft and file with the
  `portfolio-pattern` tag per the earlier governance thread.

### Pre-existing backlog from `docs/TCH_Ross_Todo.md`

- **HIGH:** `UAT-tuniti` (structured UAT checklist for Tuniti),
  `UAT-product-remap` (1,619 historical roster rows to correct
  product — Tuniti action), `BUG-sticky-header` (sticky headers
  scroll out of view on list pages)
- **MEDIUM:** `FR-client-expenses`, `FR-caregiver-loans`,
  `FR-admin-config` (config landing page)
- **LOW:** `FR-help-report`, `FR-pagination`
- **Scheduling / ongoing:** Item 18 (patient-first schedule input
  wizard), Item 15 (patient re-assign Phase-2 timestamped),
  Item 14 (historic backfill to split conflated client/patient
  rows), Item 12 (Hub API token path fix — portfolio concern)

### Data quality

- **DQ0** — table audit for stored derivations
- **DQ1** — `clients.id=47 Morrison` has R0 from 2 revenue rows
- **DQ2** — 7 slash-named rows need human review

### Ross actions carried forward

- **CDN cache purge** in StackCP panel after the PROD deploys
  (both v0.9.23 and v0.9.24 shipped without a purge; static
  assets are cache-busted via `?v=<filemtime>` so likely no-op,
  but Ross may want to do it belt-and-braces)
- **Backup cleanup** on or after 2026-04-30 — three
  pre-migration backups on the PROD server at `~/db-backups/tch/`
  (retention policy: 14 days)

### Counts

- **Bugs (Hub):** blocked from query until Item 12 (Hub token path)
  is fixed. Per HANDOFF 2026-04-14: BUG-0031 (smoke-test leftover,
  ignore), BUG-0037 (cosmetic, likely done in `420e24f`).
- **FRs (Hub):** blocked from query. Known: FR-0065, FR-0071,
  FR-0074, FR-0077, FR-0078, FR-0079 per earlier handoff.
- **ToDos (repo):** ~20 items in `docs/TCH_Ross_Todo.md` + the
  11 FRs in `docs/TCH_Quote_And_Portal_Plan.md`. See categorised
  list above.
- **Blockers:** none on TCH's side. Tuniti inputs (contract list,
  1,619 historical row remap) are the limiting factor on some
  Tuniti-facing work, but plenty of independent dev work
  available.

## Next session should

1. **FR-C — Quote builder screen.** Natural next piece now that
   the foundation is in place. Build `/admin/quotes` list +
   `/admin/quotes/new` form with line-item editor pulling allowed
   units from `product_billing_rates` + per-line date inputs.
   Audit log per mutation. State transitions deferred to FR-D.
2. **OR: Schema audit + surplus-field sweep** (governance ask, 13:25).
   If Ross prefers to close the doc debt before the next big build,
   this is the shorter detour — ER diagram + per-table docs +
   surplus sweep into ARCHITECTURE.md.
3. **OR: I1 portfolio-pattern FR draft** — quickest win; one
   three-layer FR on bulk-handler audit logging, filed to Hub
   with the `portfolio-pattern` tag.
4. Any pre-existing Ross_Todo HIGH item if Tuniti UAT is imminent:
   `UAT-tuniti` (build the checklist) or `BUG-sticky-header`
   (diagnose + fix the sticky-header regression).
