# TCH Placements — Platform Vision

**Version:** 1.0
**Date:** 9 April 2026
**Author:** Claude (for review by Ross)
**Status:** Draft — for discussion next session

---

## What TCH Placements Is

TCH Placements is the commercial arm of the Tuniti Care Hero ecosystem. Tuniti trains caregivers through a QCTO-registered programme; TCH places them with families and care providers across Gauteng, earning placement fees and ongoing margin on every day worked.

The business has two sides:
- **Supply:** A pipeline of trained, vetted caregivers sourced primarily through Tuniti intakes
- **Demand:** Private families and care agencies needing reliable in-home care

The strategic advantage is vertical integration: TCH controls quality by training its own supply. Most placement businesses compete for the same pool of workers. TCH creates its own.

---

## What We're Building

A single platform at **tch.intelligentae.co.uk** that serves three purposes, in priority order:

### Priority 1 — Investor Reporting (contractual obligation)

Investors have a committed reporting framework:
- **Monthly accounts** by Working Day 7
- **Weekly training reports** within 3 working days of each class
- **Per-tranche** visibility across the full lifecycle: classroom → on-the-job training → revenue generation

This is non-negotiable and drives the initial build. Every feature must feed this reporting capability or not be built yet.

### Priority 2 — Operational Platform (business necessity)

The business is growing and can't run on spreadsheets. The platform needs to capture and manage:
- Caregiver onboarding and training progress
- Client relationships and care requirements
- Daily roster / shift assignments
- Billing and pay calculations
- Compliance documentation

This is where the Starbright Hero Hub specs (Phases 1–4) are relevant — but filtered through what TCH actually needs, not what a generic marketplace would look like.

### Priority 3 — Commercial Engine (future scale)

When TCH outgrows manual placement:
- Engagement/placement records as first-class objects
- Automated invoice and pay generation from roster data
- Margin tracking per engagement
- Lead management (replacing Leadtrekker)
- Potentially caregiver availability and client self-service

This is not immediate but the data model and architecture should not prevent it.

---

## What TCH Is NOT

- **Not a gig marketplace.** Caregivers aren't Uber drivers. Placements are managed, ongoing relationships.
- **Not a tech product for sale.** This is an internal operations platform that happens to produce investor reports.
- **Not a generic CRM.** It's purpose-built for caregiver placement with care-specific data (patient medical profiles, compliance docs, training hours).

---

## Architecture Principles

1. **Investor reporting is the forcing function.** If a feature doesn't eventually feed a report an investor will read, question whether it belongs in this phase.

2. **The roster is the heart.** Every financial number — revenue, cost, margin, pay — derives from "who worked where, when, at what rate." Get this right and everything else follows.

3. **Data entry replaces spreadsheets progressively.** Don't try to replace all spreadsheets at once. Each screen we build should eliminate one manual process and produce cleaner data than before.

4. **Human approval on anything that affects money.** Auto-calculate, human-confirm. No auto-approvals on name reconciliation, pay runs, or invoices.

5. **Simple stack, no dependencies.** LAMP (PHP 8, MySQL, Apache). No JavaScript frameworks, no external APIs, no SaaS dependencies. If the hosting bill is R12/month, we're doing it right.

6. **Own everything.** Code in our repo, data on our server, no vendor lock-in. The opposite of the Starbright model.

---

## Platform Phases

### Phase A — Investor Reporting (NOW)
*Status: In progress. Landing page and 3 reports live.*

- ~~Data ingestion from Excel workbooks~~
- ~~Landing page with brand identity~~
- ~~Admin login~~
- ~~Dashboard with live KPIs~~
- ~~Report: Caregiver Earnings by Month~~
- ~~Report: Client Billing by Month~~
- ~~Report: Days Worked by Caregiver~~
- ~~Name Reconciliation screen~~
- Data reconciliation (Jan 2026 gap + caregiver mismatches)
- Caregiver onboarding form (data entry replacing paper)
- Training schedule and weekly attendance/scores
- Investor report pack (formatted for WD7 delivery)

### Phase B — Operational Data Entry (NEXT)
*Status: Requirements captured, blocked on onboarding form from Ross.*

- Caregiver onboarding screen (matching paper form)
- Document uploads (ID, qualifications, compliance)
- Training week scheduling (10 weeks classroom + OJT, rooms/locations)
- Weekly attendance and score entry
- Client management (expanded: billing address, rate type, care type, product)
- Product/service catalogue
- Daily roster entry (supporting hours, half-day, daily, 24/7, multi-carer)

### Phase C — Financial Automation (THEN)
*Status: Design phase. Depends on Phase B roster being operational.*

- Auto-calculate caregiver pay from roster (review + confirm)
- Auto-calculate client invoices from roster (review + confirm)
- Per-engagement margin calculation
- Monthly financial close workflow
- Budget vs actual comparison (investor view)

### Phase D — Commercial Engine (FUTURE)
*Status: Requirements captured, not yet scoped for build.*

- Engagement/placement as first-class record
- Lead capture and sales pipeline (replacing Leadtrekker)
- Caregiver compliance tracking with expiry alerts
- Patient/care requirement profiles (from Starbright Phase 4 spec)
- Caregiver availability management
- Performance tracking

---

## Data Model (current + planned)

**Live now:**
`clients`, `caregivers`, `caregiver_banking`, `name_lookup`, `client_revenue`, `caregiver_costs`, `daily_roster`, `caregiver_rate_history`, `audit_trail`, `margin_summary`, `users`, `login_log`

**Needed for Phase B:**
`training_modules`, `training_scores`, `training_attendance`, `training_schedule`, `documents`, `products`, `engagements`

**Needed for Phase D:**
`leads`, `lead_activities`, `patient_profiles`, `patient_emergency_contacts`, `caregiver_availability`, `engagement_ratings`, `incidents`

---

## Commercial Context

- **Hosting:** Forth Hosting (StackCP), intelligentae.co.uk subdomain, ~R12/month
- **Development:** Claude Code (AI agent), no developer fees
- **Previous vendor:** Starbright quoted R4,950 + R2,027/month SaaS with no code ownership. We rejected this model.
- **Investor commitment:** Monthly reporting by WD7, weekly during training, per-tranche

---

## Success Criteria

1. Investors receive accurate, timely reports without Ross manually building spreadsheets
2. Operations team can onboard a caregiver, schedule training, and assign shifts without spreadsheets
3. Monthly pay and billing can be generated from system data with one-click review
4. All financial data reconciles — every rand in revenue traces back to a roster entry
5. The system runs on infrastructure Ross controls, with code Ross owns
