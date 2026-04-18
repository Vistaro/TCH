# Autonomous cleanup pass — 2026-04-18

Ross was away; auto-mode carried on. Four jobs:

1. Full code review + clean-up (archive, don't delete)
2. Static UX review with obvious fixes
3. Browser smoke-test (**not possible** — I have no browser tool)
4. User-manual page wired into the site

---

## Commits pushed this session

| Hash | Message |
|------|---------|
| `18d6331` | `refactor(cleanup): archive dead names UI + extract stage-transition helper` |
| `278d4a6` | `ux(pipeline): live column totals + hide counts on closed-won/lost cols` |
| `0823d5b` | `feat(help): /admin/help — living user manual for first-time admins` |

All on `dev`. Migrations from the earlier session (039 + 040 + 041) still pending your DEV DB run.

---

## 1 — Code review & clean-up

### Files moved to `_archive/`

Never deleted; fully restorable via `git mv`. See `_archive/README.md` for context + one-line restore recipe per file.

| Original                                 | Now at                                          | Why                                                                               |
|------------------------------------------|-------------------------------------------------|-----------------------------------------------------------------------------------|
| `templates/admin/names.php`              | `_archive/templates/admin/names.php`            | Name-reconciliation UI retired v0.9.15. Route already redirects. No live refs.    |
| `templates/admin/names_assign.php`       | `_archive/templates/admin/names_assign.php`     | Handler for the same retired page.                                                |

### Refactor — shared stage-transition helper

The FR-L stage-advance logic existed twice:
- `opportunities_detail.php` (form POST from the button bar)
- `opp_stage_move_handler.php` (AJAX from Kanban drag-drop)

Both did: validate stage → enforce Closed-Lost reason → activate linked contract on Closed-Won → audit log → return result.

Extracted into `advanceOpportunityStage()` in `includes/opportunities.php` (~120 lines). Both callers now ~10 lines each. Single code path, two UIs, no risk of drift.

Bonus: the helper returns a structured result (`ok` + `message` + `activated_contract_id`) so the JSON (AJAX) and HTML (form) surfaces present errors identically.

### What I deliberately did NOT touch

- **Legacy `products.default_price` / `products.default_billing_freq` columns.** Mig 036 comment says "STAY in place for now as backwards-compat; a follow-up migration retires them once every read site has cut over to product_billing_rates." FR-A2 cutover is partial (per HANDOFF 2026-04-16). Don't retire until cutover is complete — left for a deliberate future migration.
- **TODO #15 markers in `patient_view.php`.** Point at the Ross_Todo backlog — valid contextual comments, not rot.
- **`onboarding_jan2026_ack.php`.** One-shot, but still referenced from the Tuniti onboarding dashboard until the Jan 2026 date-serial bug is fully acknowledged. Leave alone.
- **FR-X references in the new-code docblocks.** They map code to the plan doc (`TCH_Quote_And_Portal_Plan.md`) — genuinely useful for a new dev, not task-noise.

---

## 2 — Static UX review

### Fixed in code

All on `/admin/pipeline`:

1. **Closed-Won / Closed-Lost columns no longer show count + R total** (always 0 by design — opps leave the board on close). Now show `drop to close` label.
2. **Column counts + totals now recompute live** after a drop. Previously went stale until page reload.
3. **"Drop cards here" placeholder** now toggles via `display:none` — reappears when a column empties after a drag-out.

### Flagged for Ross (need your eyeballs / business call)

These I did NOT fix because they need your judgment:

| # | Issue | Suggested call |
|---|-------|----------------|
| 1 | **Kanban is desktop-only.** HTML5 drag-drop doesn't work reliably on touch devices. Tuniti on a phone gets a broken experience. | Two options: (a) add a long-press touch fallback (more code); (b) explicitly route mobile users to `/admin/opportunities` list view instead of the Kanban. I've noted option (b) in the user guide. |
| 2 | **Quote builder — no "save and send" button.** Currently it's save-draft-then-go-to-detail-and-click-Send. Two clicks. | Add a "Save & mark as Sent" button on the builder? Or leave as-is (two clicks is deliberate — forces you to review before sending). |
| 3 | **Opp detail stage-transition bar shows ALL other stages.** So you can "move back" from Quoted to Qualifying. This is legitimate (if something went wrong), but clutters the UI with counter-intuitive buttons. | Hide "backward" moves, or leave as-is (explicit freedom). |
| 4 | **Caregiver-type enquiries reachable via URL.** The "+ Convert to Opportunity" button hides for non-client enquiries, but `/admin/opportunities/new?from_enquiry=X` bypasses the check. | Block server-side with a check on enquiry_type when the query param is used? Low-risk in practice (admin users only), but tightens the rail. |
| 5 | **`/admin/help` access permission.** Bound to `dashboard.read` so everyone sees it. Fine for now, but when/if caregivers get a portal, they shouldn't see the admin guide. | Not urgent — caregiver portal is FR-K+. Worth noting when the portal lands. |

### UX smoke-test I couldn't run

I have no browser tool in this environment — I can't click through the site. The UX review above is **code-level only**. A real browser walkthrough (desktop + phone) still needs to happen before you trust the workflow end-to-end. The user guide at `/admin/help` doubles as a walkthrough checklist.

---

## 3 — Browser test in Claude for Web / cowork

Not achievable from this environment — I'm the CLI agent, no browser. If "brwiose in cowork" means opening the dev site in your browser-equipped Claude elsewhere, that would complement this work: open `/admin/pipeline`, `/admin/quotes/new?opportunity_id=1`, `/admin/help` and walk the flows.

---

## 4 — User manual page

Shipped as **`/admin/help`** — commit `0823d5b`.

Contents:
- Overview of the Acquire / Manage / Exit lifecycle
- Per-page block for every admin surface: **what this is / what you do here / tips**
- Glossary of TCH vocabulary (client vs patient, stage vs status, rate override, OPP-/Q- references, bill-payer guardrail, etc.)
- How to report bugs

Nav link: "User Guide" sits just above Sign Out in the sidebar, visible to every logged-in admin.

Version-stamped footer makes it clearly a living document. When workflows change, the expectation is the help page updates in the same commit.

---

## Open follow-ups on the 11-FR plan

Still to build (from `docs/TCH_Quote_And_Portal_Plan.md`):

| FR | Status | Priority |
|----|--------|----------|
| FR-A2 remainder | partial — `contracts_create.php` cut over; `products.php` CRUD still reads legacy columns | medium |
| FR-B2 | decide fate of `contracts.start_date`/`.end_date` (compute from lines or cache) | low |
| **FR-D** | quote state machine — mostly done via quote-detail action bar; remaining is polish + the formal transition diagram | low |
| **FR-F** | quote PDF (Dompdf + generic TCH template; Tuniti branded version as a follow-up onboarding task) | **high (next)** |
| **FR-G** | email delivery + client preference | **high (after FR-F)** |
| FR-H | Client/Patient user classes + invite-on-accept | medium |
| FR-I | Portal tokenised acceptance flow | medium |
| FR-J | Mid-contract term change workflow | low |
| FR-K | Caregiver availability profile + diary | parallel track — separate from quote flow |
| **FR-L** | ✅ shipped this session |
| FR-M | Acquire / Manage / Exit reporting | depends on FR-L being in use + some data |

Next natural build: **FR-F (quote PDF)**. You already asked me about this earlier in the session — I can build a generic TCH-branded template blind (placeholders you fill in later) or wait for Tuniti's letterhead. Your call when you're back.

---

## Task list at hand-off

All 21 tasks marked complete. Nothing left in-flight.

---

## What's still waiting on you

1. **Run the three migrations on DEV** (039 + 040 + 041). One SSH session, three `mysql <` commands. Until then `/admin/opportunities`, `/admin/pipeline`, `/admin/quotes` all 500 on first query.
2. **Browser walk-through** of the new pages. I can't do this; use the `/admin/help` page as your checklist.
3. **Decide on the 5 flagged UX issues** above. Some are one-line fixes; some are meaningful design calls.
4. **FR-F (quote PDF) next, blind or after Tuniti supplies letterhead?** — either works, just need your steer.
