# TCH Placements — System Build Brief

## What this is

TCH Placements is a caregiver placement business in South Africa (Gauteng). We recruit, train, and place caregivers with private families and care providers. The site will be hosted at **tch.intelligentae.co.uk** on a **LAMP stack** (Linux, Apache, MySQL, PHP). Git repo is already set up.

## What we have

Two Excel workbooks:

### 1. TCH_Payroll_Analysis_v5.xlsx (the raw source)

This is the original operational data. It has 19 tabs:

- **Caregiver Pipeline** — Tuniti training pipeline (161 rows). Caregiver names, intake tranche, course dates, average scores, qualification status.
- **Client Summary** — Monthly income per client in a pivot format (Nov 2025–Apr 2026). **129 cell comments** providing an audit trail — each comment references the exact source sheet, row, and column the number came from.
- **Caregiver Summary** — Monthly pay per caregiver in a pivot format. **135 cell comments** with the same audit trail structure (source sheet, row, column, daily rate, days worked).
- **Margin Summary** — Consolidated P&L view.
- **Caregiver Banking Details** — Bank name, account number, account type, daily rate per caregiver.
- **Clients** — Master client list with patient name, client name, day rate, billing frequency, shift type, and whether they're NPC or TCH.
- **6 monthly "Clients" tabs** (Nov 2025 – Apr 2026) — Raw monthly client income data. Each tab has client blocks side by side with dates and payment amounts.
- **6 monthly "Caregiver" tabs** (Nov 2025 – Apr 2026) — Raw monthly rosters. Columns are caregivers, rows are dates, cells contain the client name they worked for that day. Row 2 has each caregiver's daily rate.

### 2. TCH_Data_Workbook.xlsx (the cleaned version)

This was extracted from the raw source into six structured tabs:

- **Clients** (64 records) — client_id, client_name, first_seen, last_seen, months_active, status
- **Caregivers** (140 records) — Full caregiver profiles including personal details, training tranche, source (many are "Tuniti"), course dates, assessment scores, qualification status, billing name, total billed, placement status
- **Client Revenue** (129 records) — client_name, month, income, expense, margin, margin_pct, source_sheet
- **Caregiver Cost** (135 records) — caregiver_name, month, amount, days_worked, daily_rate, source_sheet
- **Name Lookup** (140 records) — Cross-reference matching caregivers across training names, legal/PDF names, and billing/payroll names. Includes fuzzy match scores. None approved yet.
- **Daily Roster** (1,619 records) — date, day_of_week, caregiver_name, client_assigned, daily_rate, source_sheet

The cell comments in the raw source (v5) are the audit trail that links the cleaned data back to its origin. This matters for data integrity.

## First question for you

Before you do anything: what format do you want these files delivered in? We currently have two .xlsx files. Do you want the raw Excel files as-is, CSVs per tab, JSON, SQL dumps, or something else? Tell us and we'll prepare it.

## Phase 1 — Data ingestion and relationships

Ingest the data and set up the data layer with proper relationships. Specifically:

### Name reconciliation (critical)
The same caregiver appears under different names depending on the source:
- **Training name** (from intake sheets, e.g. "Ndaizivei Mapenyenye")
- **Legal/PDF name** (from identity documents)
- **Billing name** (from payroll, e.g. "Ndai Mapenyenye")

All name variants must resolve to a single **canonical name** that becomes the system-wide identifier. The Name Lookup tab in the Data Workbook has started this work with fuzzy match scores, but every suggested match must be flagged for **human approval** before it becomes active. Nothing auto-approves.

### Client accounts
Clients are referenced by name across the Clients tab and Client Revenue tab, but there's no unique identifier. Assign account numbers to every client so we have a reliable key linking client records to revenue history, regardless of how the name appears (e.g. "Johnstons" vs "Johnstons- monthly").

### Caregiver cost mapping
The Caregiver Cost tab records monthly pay per caregiver. These names need to map to the canonical caregiver name established through name reconciliation.

### Rate management
The Daily Roster shows actual billing rates per shift, and these vary by caregiver and over time. We need the ability to set and maintain a **standard daily rate** per caregiver in a caregivers table. This lets us compare what was actually billed vs what should have been billed, and flag discrepancies.

### Audit trail
The cell comments in TCH_Payroll_Analysis_v5.xlsx (Client Summary and Caregiver Summary tabs) are the provenance records. When ingesting data, preserve the link between each summarised figure and its raw source location so the data remains auditable.

## Phase 2 — User management

Build a user system with:
- Roles (e.g. admin, operations, finance, investor/read-only)
- Login credentials
- Permissions controlling who sees what data

Different roles see different things. Investors see dashboards and performance metrics. Operations sees rosters and caregiver details. Finance sees margins and costs. Admin sees everything.

## Phase 3 — Budgeting

Provide a way to input a **company revenue budget** (monthly or quarterly) so we can compare actual performance against targets. This is essential for the investor view.

## Phase 4 — Dashboards and reporting

Once the data layer and user management are in place, we need performance dashboards and reports. Happy to receive your suggestions, but the investor audience cares about things like:

- Pipeline health (caregivers in training vs placed vs inactive)
- Revenue vs budget
- Client growth and retention
- Margin per client and per caregiver
- Placement conversion rates (especially the Tuniti pipeline)
- Caregiver utilisation (days worked vs available)

Suggest what you think makes sense and we'll refine together.

## Important notes

- **LAMP stack** — Linux, Apache, MySQL, PHP
- **Domain** — tch.intelligentae.co.uk
- **Git** — repo is already initialised
- **Start with Phase 1.** Don't build any frontend until the data layer is solid and relationships are verified.
- **Both workbooks must be provided to you** — the raw source (v5) for audit trail, and the cleaned version (Data Workbook) for structured data.
