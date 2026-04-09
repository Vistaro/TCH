# Changelog

All notable changes to the TCH Placements project.

## [0.4.0] - 2026-04-09

### Added — Reports & Name Reconciliation

**Three reports under Reports menu** (all login-gated, with filters and drill-down):

1. **Caregiver Earnings by Month** (`/admin/reports/caregiver-earnings`)
   - Summary: caregiver name, tranche, month, days worked, daily rate, total amount
   - Drill-down: click any row to see each day worked — date, day of week, client, rate
   - Filters: caregiver name, tranche, date range (from/to month)

2. **Client Billing by Month** (`/admin/reports/client-billing`)
   - Summary: client name, account number, month, income, expense, margin
   - Drill-down: click any row to see caregivers who worked for that client — date, name, rate
   - Filters: client name, date range

3. **Days Worked by Caregiver** (`/admin/reports/days-worked`)
   - Summary: caregiver, tranche, month, days worked, clients served, avg rate, total value
   - Drill-down: each shift date, client assigned, daily rate
   - Filters: caregiver, client, tranche, date range

**Name Reconciliation screen** (`/admin/names`):
- Table showing all 140 name lookup records: canonical, training, PDF/legal, billing names with match scores
- Colour-coded scores (green >90%, amber >70%, red <70%)
- Approve/Revoke workflow per row — nothing goes live without human approval
- Unmatched billing names panel at top with dropdown to assign to canonical name
- Filters: status (pending/approved), tranche, free-text search across all name fields
- Stats cards: pending count, approved count, unmatched count

**Updated admin layout:**
- Shared sidebar layout (`templates/layouts/admin.php`) — DRY, consistent nav across all admin pages
- Sidebar now has Reports submenu and Data section with Name Reconciliation
- Dashboard updated: shows total revenue, gross margin, link to name review

**Bug fix:** Fixed PhpSpreadsheet `getComment()` call in ingestion script (use worksheet method, not cell method)

### Files changed
- `public/index.php` — added 5 new routes
- `public/assets/css/style.css` — report tables, filters, drill-down, name reconciliation styles
- `templates/layouts/admin.php` (new) — shared admin sidebar layout
- `templates/layouts/admin_footer.php` (new) — shared admin page close
- `templates/admin/dashboard.php` — refactored to use shared layout, added revenue/margin cards
- `templates/admin/reports/caregiver_earnings.php` (new)
- `templates/admin/reports/client_billing.php` (new)
- `templates/admin/reports/days_worked.php` (new)
- `templates/admin/names.php` (new) — name reconciliation screen
- `templates/admin/names_assign.php` (new) — billing name assignment handler
- `database/seeds/ingest.php` — fixed comment extraction for PhpSpreadsheet compatibility

## [0.3.0] - 2026-04-09

### Added — Landing Page, Admin Login & Dashboard

**Front controller** (`public/index.php`):
- Routes all requests: home, login, logout, admin/dashboard, 404

**Public landing page** (`templates/public/home.php`):
- On-brand design using Tuniti Care Hero colour palette (Teal #10B2B4, Charcoal #3A3839, Dark Charcoal #242424)
- Hero section with dual CTA (caregivers / clients)
- Stats bar (140+ caregivers, 60+ clients, Gauteng, QCTO)
- Services grid: Recruitment & Vetting, Certified Training, Placement & Matching
- Split CTA blocks for caregivers and clients
- How It Works 3-step flow
- Contact section, footer with Vistaro/Intelligentae credit
- Fully responsive (mobile-friendly)

**Admin login** (`templates/auth/login.php`):
- CSRF-protected login form
- Styled auth card matching brand
- Error/success alerts, logout confirmation

**Admin dashboard** (`templates/admin/dashboard.php`):
- Sidebar navigation (Dashboard, Caregivers, Clients, Roster, Revenue, Name Reconciliation)
- Live stats cards that pull from DB when available, fall back to placeholders
- Getting Started info panel

**User management foundation** (`database/002_seed_admin.sql`, `database/seeds/create_admin.php`):
- `users` table with username, password_hash (bcrypt), role, active flag
- `login_log` table for audit trail
- CLI script to create Ross as admin user with configurable password

**Shared assets**:
- `public/assets/css/style.css` — complete stylesheet with all brand colours
- `templates/layouts/header.php` / `footer.php` — shared layout
- `templates/errors/404.php` / `403.php` — error pages

### Files changed
- `public/index.php` (new) — front controller
- `public/assets/css/style.css` (new) — main stylesheet
- `templates/layouts/header.php` (new) — shared HTML head
- `templates/layouts/footer.php` (new) — shared footer
- `templates/public/home.php` (new) — landing page
- `templates/auth/login.php` (new) — login page
- `templates/admin/dashboard.php` (new) — admin dashboard
- `templates/errors/404.php` (new) — 404 page
- `templates/errors/403.php` (new) — 403 page
- `database/002_seed_admin.sql` (new) — users + login_log tables
- `database/seeds/create_admin.php` (new) — admin user creation script
- `CHANGELOG.md` — updated

## [0.2.0] - 2026-04-09

### Added — Phase 1: Data Layer & Ingestion

**Database schema** (`database/001_schema.sql`):
- `clients` — master client list with auto-generated account numbers (TCH-C0001 format), enriched with patient name, day rate, billing frequency, shift type, schedule, and entity (NPC/TCH) from the v5 master list
- `caregivers` — full caregiver profiles (140 records): personal details, training tranche/source, assessment scores, qualification status, standard daily rate, placement status
- `caregiver_banking` — banking details (sensitive, finance-role only in Phase 2): bank name, account number, account type, rate notes
- `name_lookup` — name reconciliation table mapping canonical ↔ PDF/legal ↔ training ↔ billing name variants with fuzzy match scores; enforces human approval before any match activates
- `client_revenue` — monthly income/expense/margin per client with source sheet traceability
- `caregiver_costs` — monthly pay per caregiver with days worked, daily rate, and source sheet
- `daily_roster` — 1,619 individual shift records: date, caregiver, client assigned, daily rate
- `caregiver_rate_history` — tracks rate changes over time per caregiver for billing comparison
- `audit_trail` — preserves 264 cell comments from TCH_Payroll_Analysis_v5.xlsx linking summary figures back to raw source sheet/row/column locations
- `margin_summary` — consolidated monthly P&L computed from revenue and cost data

**Ingestion script** (`database/seeds/ingest.php`):
- Reads both Excel workbooks using PhpSpreadsheet
- Populates all 10 tables with cross-referencing (client IDs, caregiver IDs, billing name lookups)
- Extracts audit trail comments from Client Summary (129) and Caregiver Summary (135) tabs
- Builds rate history from daily roster data, auto-sets each caregiver's current standard rate
- Computes margin summaries per month from actual revenue and cost data
- Reports unmatched records at completion for data quality review

**Project setup**:
- `composer.json` with phpoffice/phpspreadsheet dependency

### Files changed
- `database/001_schema.sql` (new) — full MySQL schema, 10 tables
- `database/seeds/ingest.php` (new) — data ingestion CLI script
- `composer.json` (new) — PHP dependency management
- `CHANGELOG.md` (new) — this file

## [0.1.0] - 2026-04-09

### Added — Project Scaffolding

- `.htaccess` — forces HTTPS, routes all traffic through `public/`
- `public/.htaccess` — front-controller routing
- `includes/config.php` — .env loader, app/db constants
- `includes/db.php` — PDO database connection (prepared statements, no emulation)
- `includes/auth.php` — authentication system: secure sessions, CSRF, login/logout, role-based access, login audit logging, bcrypt password hashing with auto-rehash
- `.env.example` — environment config template
- `.gitignore` — excludes secrets, IDE files, vendor, Chat History
