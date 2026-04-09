# TCH Placements — End-to-End Build Plan

**Version:** 1.0
**Date:** 9 April 2026

---

## Phase A — Investor Reporting (Current)

### A1. Data Fixes (1 session)
- [ ] Investigate and fix Jan 2026 roster gap (biggest reconciliation issue)
- [ ] Review and resolve large caregiver cost mismatches (Linda, Matshidiso, Adaobi, Mzee, Joyce, Siphilisiwe)
- [ ] Re-run reconciliation script to confirm fixes
- [ ] Document remaining known discrepancies as source data issues

### A2. Caregiver Onboarding Form (1–2 sessions)
- [ ] Ross provides blank paper onboarding form
- [ ] Map all fields to database schema, add missing columns
- [ ] Build onboarding data entry screen
- [ ] Build document upload system (ID copy + standard attachments)
- [ ] Migrate existing 140 caregivers to new schema where data exists

### A3. Training Schedule & Tracking (1–2 sessions)
- [ ] Design training_modules, training_schedule, training_attendance, training_scores tables
- [ ] Build training schedule screen (10 weeks classroom + OJT per tranche, dates, rooms)
- [ ] Build weekly attendance entry (present/absent per caregiver per week)
- [ ] Build weekly score entry
- [ ] Build status tracking (on-track / at risk)
- [ ] Import historical training data (Ross has messy source — needs cleanup)

### A4. Investor Report Pack (1 session)
- [ ] Design formatted investor report matching the 3-phase commitment
- [ ] Phase 1 view: onboarding data + weekly attendance + scores + status per tranche
- [ ] Phase 2 view: OJT placement + hours + qualified status per caregiver
- [ ] Phase 3 view: revenue per caregiver per client + pay + margin
- [ ] Monthly export / print-ready format for WD7 delivery

---

## Phase B — Operational Data Entry

### B1. Client Management Expansion (1 session)
- [ ] Add fields: billing address, rate type (hourly/half-day/daily/24-7), care type, product
- [ ] Build client add/edit screen
- [ ] Link to product catalogue

### B2. Product / Service Catalogue (1 session)
- [ ] Design products table
- [ ] Seed with current TCH service offerings (Ross to provide list)
- [ ] Link products to client engagements and billing

### B3. Roster / Shift Entry Redesign (2 sessions)
- [ ] Support shift types: hours, half-day, full day, 24/7 live-in, multi-shift
- [ ] Support multiple caregivers per client per day
- [ ] Weekly grid view: caregivers x days, assign clients
- [ ] Daily rate auto-populated from caregiver standard rate, overridable
- [ ] Validation: flag conflicts (double-booked caregiver)

### B4. OJT Tracking (1 session)
- [ ] Track OJT placement location per caregiver
- [ ] Hours towards 288-hour target
- [ ] Performance feedback (free text)
- [ ] Graduation status and reason if not qualified

---

## Phase C — Financial Automation

### C1. Caregiver Pay Auto-Calc (1 session)
- [ ] Monthly pay run: pull from roster, calculate days x rate
- [ ] Review screen: show calculated vs any manual adjustments
- [ ] Confirm/approve workflow before finalising
- [ ] Generate payout statement per caregiver

### C2. Client Billing Auto-Calc (1 session)
- [ ] Monthly invoice generation from roster
- [ ] Per-client breakdown by caregiver
- [ ] Review, adjust, confirm workflow
- [ ] Track payment status (paid/outstanding)

### C3. Budget vs Actual (1 session)
- [ ] Monthly/quarterly revenue budget input
- [ ] Dashboard: actual vs budget with variance
- [ ] Investor view: performance against targets

### C4. Monthly Close (1 session)
- [ ] Lock a month's data after review
- [ ] Generate margin summary
- [ ] Reconciliation check (automated, run reconcile.php equivalent)
- [ ] Flag unresolved issues

---

## Phase D — Commercial Engine (Future)

### D1. Engagement Records
- [ ] Engagement as first-class object (caregiver + client + service + rate + dates + status)
- [ ] Status lifecycle: Requested → Active → Completed → Cancelled
- [ ] Margin per engagement

### D2. Lead Management (replacing Leadtrekker)
- [ ] Lead capture (source, client details, care need)
- [ ] Assign to salesperson
- [ ] Pipeline stages: lead → qualified → proposal → won/lost
- [ ] Import historical leads from Leadtrekker (if needed)

### D3. Compliance & Documents
- [ ] Caregiver qualification tracking with expiry dates
- [ ] Approval status per document (Approved / Pending / Rejected)
- [ ] Alerts for expiring documents

### D4. Patient Profiles (from Starbright Phase 4)
- [ ] Full medical/care requirement profile
- [ ] Emergency contacts
- [ ] Care environment details

### D5. Caregiver Availability
- [ ] Day-of-week availability
- [ ] Shift type preferences
- [ ] Available/unavailable status

---

## Estimated Effort

| Phase | Sessions | Depends on |
|-------|----------|------------|
| A1. Data Fixes | 1 | Nothing |
| A2. Onboarding Form | 1–2 | Ross: paper form |
| A3. Training Tracking | 1–2 | Ross: training data |
| A4. Investor Report Pack | 1 | A2 + A3 |
| B1. Client Management | 1 | Nothing |
| B2. Product Catalogue | 1 | Ross: service list |
| B3. Roster Redesign | 2 | B2 |
| B4. OJT Tracking | 1 | A3 |
| C1–C4. Financial | 4 | B3 |
| D1–D5. Commercial | TBD | C complete |

A "session" = one Claude Code conversation, typically 2–4 hours of work.
