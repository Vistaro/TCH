# PROJECT.md — TCH Hero Hub Platform

**Single living source of truth for project state.** Updated continuously as work progresses. Read this first; everything else (HANDOFF, CHANGELOG, plan documents, session notes) supports it.

**Last updated:** 2026-04-19
**Current dev SHA:** see `git log -1` on `dev` branch
**Live PROD version:** v0.9.24 (2026-04-16)
**Live DEV URL:** https://dev.tch.intelligentae.co.uk
**Live PROD URL:** https://tch.intelligentae.co.uk

---

## 1. The contract we're delivering

We are delivering the **Hero Hub Portal** for Tuniti Care Hero per the proposal `Hero Hub Portal - Proposal - Intelligentae to Tuniti Care Hero - 2026-04-15 v1.2.pdf`. Headline:

- **Fixed price:** USD 87,500 across 7 phases
- **Code ownership** transfers to Tuniti on final payment
- **Single point of contact:** Ross Thomasson, Intelligentae Ltd
- **Delivery model:** iterative two-week increments with phase-gate sign-off
- **Standard timeline:** 26 weeks; **Accelerated:** 12 weeks (same fixed price)

The full scope breakdown lives in `docs/TCH_Quote_And_Portal_Plan.md` (FRs A through M, with N–S added 2026-04-19 as new in-scope items).

---

## 2. Release-gating policy (the headline operating rule)

Tuniti will be released features in phases, not all at once. **What we have built ≠ what Tuniti can see.**

### Mechanism
- Tuniti users get the `admin` role.
- Internal team (Ross + Intelligentae staff) get `super_admin`.
- Each new feature is built and tested **without granting admin** the relevant page permissions.
- When we decide to release a feature to Tuniti, we grant `admin` on that page (via migration or the Roles UI) and record the grant in `docs/release-log.md`.
- The `/admin/help` user guide is role-aware — Tuniti only sees guidance for what they have access to. They literally don't *know* the rest exists.

### Why
- Insulates against scope creep. As Tuniti uses the system they will naturally ask for more. When they ask for something already built, we flip a flag (no extra dev cost) and log it as a release. When they ask for something genuinely new, it goes through proper change-control.
- Lets us release foundational pieces and gather feedback before exposing the next layer. Phased adoption is more digestible than a 7-phase big-bang.
- Keeps our internal tooling (sales pipeline, quote builder before invoicing engine is done) hidden from the customer until it's coherent.

### Current release state to Tuniti

See `docs/release-log.md` for the canonical record. Summary as of 2026-04-19:

| Released to Tuniti? | Surface |
|---|---|
| ✅ Yes | Dashboard, Students, Caregivers, Clients, Patients, Reports (4), Products, Onboarding, What's New, Activity Log (read), Help, Releases admin (read) |
| ❌ Not yet (built, gated) | Pipeline, Opportunities, Quotes, Quote builder, Rate-override, Enquiries, Engagements (Care Scheduling), Roster View, Care Approval, Contracts, Unbilled Care, Email Outbox, Roles+Users, all Config pages |

---

## 3. Master scope vs delivery

The headline status table comparing every line item in the Tuniti proposal against what's actually live on dev today.

### Phase 1 — Foundation and Ownership (140h, USD 12,000)

| # | Item | Status | Notes |
|---|---|---|---|
| 1.1 | User & role management + permission matrix | ✅ Delivered | `/admin/users`, `/admin/roles` |
| 1.2 | Authentication (login, password reset, lockout, sessions) | ✅ Delivered | Live, with audit log |
| 1.3 | Student admin — CRUD, profile photo, ID upload | ⚠️ Partial | Basic CRUD live; cohort/module fields lighter than proposal |
| 1.4 | Course administration — cohort groups, study materials | ❌ Not yet | No `courses` table |
| 1.5 | Student↔course linking, costs, payment tracking | ❌ Not yet | Depends on course module |
| 1.6 | Test score capture per module | ❌ Not yet | — |
| 1.7 | Admin dashboards (active students, balances, cohort averages) | ⚠️ Partial | Dashboard exists; specific cohort metrics missing |
| 1.8 | Starbright data migration | ❌ Not yet | Pending Starbright export |
| 1.9 | Handover documentation + training | ⚠️ Partial | `/admin/help` user guide live; no formal training session |

### Phase 2 — Facility and Practical Operations (100h, USD 8,500)

| # | Item | Status | Notes |
|---|---|---|---|
| 2.1 | Facility database | ❌ Not yet | — |
| 2.2 | Timesheet request workflow (student → facility manager → confirmation) | ❌ Not yet | Current: monthly Excel ingest |
| 2.3 | Practical-hour running total vs 288h target | ❌ Not yet | — |
| 2.4 | Course-material attachments | ❌ Not yet | Depends on course module |
| 2.5 | Automated cohort reminders | ❌ Not yet | No scheduler |

### Phase 3 — Caregiver Workforce Activation (120h, USD 10,000)

| # | Item | Status | Notes |
|---|---|---|---|
| 3.1 | Student → caregiver promotion workflow | ❌ Not yet | Manual today |
| 3.2 | Credential & qualification verification + expiry tracking | ❌ Not yet | `qualification_certificate` is just an attachment type today |
| 3.3 | Caregiver lifecycle states | ⚠️ Partial | `status_id` exists; specific states not seeded |
| 3.4 | Banking + payroll details with manager approval | ❌ Not yet | — |
| 3.5 | Availability profile (days, day/night, live-in) | ⚠️ Partial | `working_pattern` compact string exists; structured availability tracked as future FR-K |
| 3.6 | Mobile availability toggle | ❌ Not yet | Depends on FR-S caregiver portal |
| 3.7 | Caregiver quality score | ❌ Not yet | — |

### Phase 4 — Marketplace Data Layer (100h, USD 8,500)

| # | Item | Status | Notes |
|---|---|---|---|
| 4.1 | Caregiver languages | ❌ Not yet | — |
| 4.2 | Caregiver bio + introduction video | ❌ Not yet | — |
| 4.3 | Client records (contact, billing address, patient relationship) | ✅ Delivered | Full CRUD with dedup |
| 4.4 | Patient records — comprehensive care-needs profile | ⚠️ Partial | Basic fields live; rich care-needs taxonomy (allergies, medications, mobility, cognitive, DNR) **not built — major gap** |
| 4.5 | Patient emergency contacts | ❌ Not yet | — |
| 4.6 | Care-location lat/long | ⚠️ Partial | Addresses captured; no lat/long column |

### Phase 5 — Placement and Matching Engine (220h, USD 18,500)

| # | Item | Status | Notes |
|---|---|---|---|
| 5.1 | Rule-based matching (availability, language, shift, location) | ❌ Not yet | — |
| 5.2 | Scoring + ranking | ❌ Not yet | Inputs missing |
| 5.3 | Manual override with reason | ❌ Not yet | — |
| 5.4 | Mobile placement acceptance/decline | ❌ Not yet | Depends on FR-S |
| 5.5 | Shift scheduling UI (recurring, one-off, cancel/reschedule, notifications) | ⚠️ Partial | `/admin/engagements` schedules basic; no recurring/notifications |
| 5.6 | Replacement / substitution workflow | ❌ Not yet | — |
| 5.7 | Shift confirmation workflow → unlocks invoicing | ⚠️ Partial | Manual approval at `/admin/roster/input` |

### Phase 6 — Revenue, Billing and Margin Engine (180h, USD 15,000)

| # | Item | Status | Notes |
|---|---|---|---|
| 6.1 | Product + service catalogue with rates | ✅ Delivered | `/admin/products` + `product_billing_rates` (multi-unit pricing per FR-A) |
| 6.2 | Client invoicing — auto monthly + adjustments + credit notes | ⚠️ Partial | Manual invoice fields; no auto-generation |
| 6.3 | Caregiver pay bands + payroll run | ❌ Not yet | — |
| 6.4 | Caregiver loan ledger | ❌ Not yet | Page registered, no table |
| 6.5 | Margin calculation (client / patient / cohort / period) | ⚠️ Partial | Client+period via Client Profitability report |
| 6.6 | Revenue dashboards (current-month, outstanding, MoM, cohort profitability) | ⚠️ Partial | Client Billing + Profitability reports cover most |
| 6.7 | Xero integration | ❌ Not yet | — |

### Phase 7 — Safety, Compliance and Scale (180h, USD 15,000)

| # | Item | Status | Notes |
|---|---|---|---|
| 7.1 | Shift check-in/out (timestamped photo + GPS) | ❌ Not yet | Depends on FR-Q |
| 7.2 | Panic + escalation alerts (WhatsApp) | ❌ Not yet | Depends on FR-P |
| 7.3 | Incident reporting | ❌ Not yet | — |
| 7.4 | QA workflows | ❌ Not yet | — |
| 7.5 | Multi-branch scaling (regional filtering, regional manager scoping) | ⚠️ Partial | `regions` table exists; no per-region role scoping |

### Cross-cutting (referenced throughout proposal)

| # | Item | Status | Notes |
|---|---|---|---|
| X.1 | Admin portal (desktop-first) | ✅ Delivered | Whole `/admin/*` |
| X.2 | Caregiver portal (mobile-first PWA) | ❌ Not yet | FR-S |
| X.3 | Student portal | ❌ Not yet | — |
| X.4 | Client portal (lean, phase 6+) | ❌ Not yet | — |
| X.5 | Field-level audit log | ✅ Delivered | `activity_log`, `logActivity()`, `/admin/activity` |
| X.6 | POPIA-aware data handling | ⚠️ Partial | Soft-delete + role-based access live; encrypted backups not formally documented |
| X.7 | DEV / PROD environment split | ✅ Delivered | FR-0076 closed |
| X.8 | WhatsApp Cloud API | ❌ Not yet | FR-P |
| X.9 | Email infrastructure | ✅ Delivered | mailer.php, email-log, password reset live |
| X.10 | Public marketing site | ✅ Delivered | tch.intelligentae.co.uk + enquiry form |
| X.11 | In-app bug/FR reporter | ✅ Delivered | Floating button, Hub proxy |
| X.12 | Release notes infrastructure | ✅ Delivered | `/admin/whats-new`, `/admin/releases` |
| X.13 | Person database (canonical, multi-contact, history-preserving) | ✅ Delivered | `persons`, `person_phones`, `person_emails`, `person_addresses`, `patient_client_history` |
| X.14 | First-class contracts model | ✅ Delivered | `contracts` + `contract_lines` (FR-A multi-unit, FR-B per-line dates) |
| X.15 | Roster view | ✅ Delivered | Patient-centric monthly grid |
| X.16 | Reporting suite | ✅ Delivered | All four reports live |
| X.17 | Name resolution layer | ✅ Delivered | Timesheet alias admin |
| X.18 | Migration runner / deploy automation | ✅ Delivered | `scripts/migrate.sh` + `scripts/deploy.sh` |

### Summary counts

| Phase | Delivered | Partial | Not yet | Total |
|---|---|---|---|---|
| 1 — Foundation | 2 | 3 | 4 | 9 |
| 2 — Facility & Practical | 0 | 0 | 5 | 5 |
| 3 — Workforce Activation | 0 | 2 | 5 | 7 |
| 4 — Marketplace Data | 1 | 2 | 3 | 6 |
| 5 — Placement & Matching | 0 | 2 | 5 | 7 |
| 6 — Revenue & Billing | 1 | 3 | 3 | 7 |
| 7 — Safety & Compliance | 0 | 1 | 4 | 5 |
| Cross-cutting | 12 | 1 | 5 | 18 |
| **Totals** | **16** | **14** | **34** | **64** |

We are roughly **25% delivered, 22% partial, 53% not yet** by line count. **But** what's done is the load-bearing foundation: auth, people, contracts, reporting, and the new pipeline/quote tooling. Everything else stands on top of that.

---

## 4. Backlog — three buckets

### 4a. In-scope (covered by the $87,500 proposal — work to do)

Pulled from §3 above where status is ❌ or ⚠️. Priority is set by what unblocks the next phase, not by line order:

**High priority (foundation / unblocks others):**
- Phase 4 — Patient care-needs profile (4.4) + emergency contacts (4.5) + lat/long (4.6) — unblocks matching engine
- Phase 4 — Caregiver languages (4.1) — unblocks matching
- Phase 3 — Caregiver lifecycle states (3.3) seed + qualification expiry (3.2) — unblocks workforce visibility
- Phase 6 — Auto-invoice generation (6.2) — first commercial revenue feature

**Medium priority (parallel tracks, can run alongside):**
- Phase 1 — Course administration module (1.4 → 1.5 → 1.6)
- Phase 2 — Facility database (2.1) and timesheet workflow (2.2)
- Phase 6 — Caregiver loan ledger (6.4) — table missing, page registered
- FR-K — Caregiver availability profile + diary (parallel track from existing plan)

**Lower priority (depends on portals being built):**
- Phase 3 — Mobile availability toggle (3.6) — depends on FR-S
- Phase 5 — Mobile placement accept/decline (5.4) — depends on FR-S
- Phase 7 — Shift check-in/out (7.1) — depends on FR-S + FR-Q

### 4b. Out-of-scope, free (already delivered or planned, no extra charge)

Goodwill items that exceed proposal scope. These are scope-creep insulation — accommodating real needs without billing for them. Each item recorded with date, rationale, and the in-scope item it relates to.

| Item | Delivered | Why we did it | Cost insulation |
|---|---|---|---|
| FR-L — Sales pipeline (opportunities + Kanban) | 2026-04-18 | Tuniti needs sales workflow before quoting; sits between enquiry and quote. Pattern lifted from Nexus-CRM. | Counts against Phase 5 scope |
| FR-C — Quote builder | 2026-04-18 | Internal quoting tool pre-Phase 6 invoicing engine. | Counts against Phase 6 scope |
| FR-E — Rate-override permission | 2026-04-18 | Audit-trail discipline on quote-rate changes. | Counts against Phase 6 |
| FR-F Phase 1 — Quote PDF print view | 2026-04-18 | Manual quote-to-PDF without waiting for full invoicing engine. | Counts against Phase 6 |
| Manual enquiry create form | 2026-04-19 | Phone/walk-in enquiries — not in original proposal. | Counts against Phase 4 |
| Tuniti monthly onboarding dashboard | 2026-04-15 | Guided monthly data ingestion (Timesheet + Revenue + reconciliation). Operational tool to bridge to full Phase 5/6. | Implicit in Phases 1-2 |
| Unbilled Care surface | 2026-04-14 | Money-on-the-table visibility from existing roster data. | Implicit in Phase 6 reporting |
| `/admin/help` user guide (role-aware) | 2026-04-19 | Phase 1.9 handover documentation, but built earlier than originally scheduled. | Counts against Phase 1.9 |
| `scripts/migrate.sh` + `scripts/deploy.sh` | 2026-04-19 | Internal tooling — not visible to Tuniti, but reduces our delivery cost across all phases. | Internal only |

### 4c. Out-of-scope, billable (genuinely new — needs a quote when scoped)

Items that emerged after proposal sign-off and represent real new scope. None right now — every "extra" so far has been accommodated as in-scope or in 4b. Future items land here when they exceed the proposal envelope materially.

---

## 5. New FRs added 2026-04-19

Drafted into `docs/TCH_Quote_And_Portal_Plan.md` (appendix). Each in three-layer format. Headline:

| FR | Title | In/out of proposal scope? | Notes |
|---|---|---|---|
| FR-N | Geo + operating radius + travel charging | **In scope** — Phase 4 (location) + Phase 6 (charging model) | Tuniti GPS coords already stored in `system_settings`; nothing uses them yet |
| FR-O | LeadTrekker integration | **In scope** — Phase 4 (lead pipe into enquiries) | leadtrekker.com inbound; Tuniti currently receives leads by email/WhatsApp |
| FR-P | WhatsApp outbound comms | **In scope** — Phase 7 (panic + escalation) but used cross-phase | Cloud API free under 1000 conv/mo |
| FR-Q | WhatsApp shift workflow with GPS check-in/out | **In scope** — Phase 7 (shift check-in/out) + Phase 5 (acceptance) | Combines FR-N + FR-P |
| FR-R | Release-gating policy (admin role gating) | **Free / internal** — operational tooling, not customer-visible | Already implemented via existing role matrix; this FR is the policy doc |
| FR-S | Caregiver portal (mobile-first PWA) | **In scope** — Phase 3 + Phase 5 + Phase 7 mobile components | Hosts FR-Q's flows + schedule + earnings + history |

---

## 6. In-flight (current session)

Active build. Updated as work progresses.

| Item | Owner | Status | Started |
|---|---|---|---|
| PROJECT.md (this doc) creation | Agent | In progress | 2026-04-19 |
| Mig 043 (release-gating cleanup) | Agent | In progress | 2026-04-19 |
| FR-N…S draft into plan doc | Agent | Queued | — |
| release-log.md initialisation | Agent | Queued | — |
| Governance message (PM + release-gating pattern) | Agent | Queued | — |

---

## 7. Risk register

Live risks with mitigations. Reviewed at every session start.

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Starbright withholds DB export, blocks Phase 1 migration | Medium | Medium | Build doesn't depend on it for forward features; only affects historic continuity. Worst case: greenfield Tuniti data. |
| R2 | Tuniti UAT feedback delays beyond 5 business days | Medium | Low/Medium | Day-for-day timeline slip per proposal terms; doesn't change price. Mitigated by building well ahead of release-gate so we have headroom. |
| R3 | WhatsApp volume exceeds 1000 conv/month free tier | Low | Low | Per-conversation cost is small; budget line. Telegram fallback noted in proposal. |
| R4 | Direct instruction from Tuniti to engineering team bypassing Ross | Medium | High | Proposal §9 explicitly conditions fixed price on single-point-of-contact. Surface immediately if it happens. |
| R5 | Scope creep absorbed silently as "small extras" | High | Medium | Backlog bucket 4b explicitly tracks every freebie with rationale. If 4b grows large, it's a signal to triage what should move to 4c (billable). |
| R6 | Pre-migration snapshot not actually restored from in anger | Low | High (if migration breaks data) | Monthly restore test per CLAUDE.md Standing Order — not yet operationalised for TCH. **Open action: schedule first restore test.** |
| R7 | Caregiver portal (FR-S) blocks Phases 3 + 5 + 7 mobile flows | High | Medium | Build FR-S as early-priority parallel track once core dataset (FR-N, languages, lifecycle states) lands. |
| R8 | Tuniti accidentally sees a built-but-not-released feature via URL hack | Low | Low | All routes server-side gate via `requirePagePermission()` — bypass would require role escalation, which is server-validated. |

---

## 8. Pointers to other living docs

| Doc | Purpose |
|---|---|
| `README.md` | Vision (non-technical reader) |
| `ARCHITECTURE.md` | Code brief (competent dev cold) |
| `DECISIONS.md` | Append-only design decisions log |
| `CHANGELOG.md` | Per-commit detail with rollback recipes |
| `HANDOFF.md` | Per-session state cover sheet (points here) |
| `docs/TCH_Quote_And_Portal_Plan.md` | Full FR backlog (A through S, three-layer format) |
| `docs/TCH_Ross_Todo.md` | Ross-facing action items + design queue |
| `docs/release-log.md` | Tuniti-facing release ledger (what's flipped on for them and when) |
| `docs/sessions/` | Per-session deep notes |
| `docs/hub-drafts/` | Bug/FR drafts staged for filing in Nexus Hub when API path is fixed |

---

## 9. Next session entry pattern

A future session opens `PROJECT.md` first, then:
1. Reads §6 (in-flight) to see what was being worked on.
2. Reads §7 (risks) for anything fresh.
3. Reads `docs/release-log.md` to know what Tuniti can currently see.
4. Reads `HANDOFF.md` for the last-session-state cover sheet.
5. Picks up where the previous session left off.

If the session starts with a new instruction from Ross instead, it routes through normal flow and updates `PROJECT.md` as work progresses.
