# Changelog

All notable changes to the TCH Placements project.

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
