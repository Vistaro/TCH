# Release Log — Tuniti-facing releases

**Append-only ledger of features released to Tuniti users (`admin` role).**

Per the FR-R release-gating policy in `docs/PROJECT.md` §2, what we have built ≠ what Tuniti can see. This log is the canonical record of every grant from `super_admin`-only to `admin`-visible.

Each entry records:
- **Date** of the grant
- **What** was released (page codes + actions)
- **Why now** — the reason this slice was opened up
- **Audience** — which Tuniti-facing role(s) got the grant (`admin` is the default; others as portals come online)
- **Migration / commit** — the database change that enacted the grant

Read top-down. Newest releases at the top.

---

## 2026-04-19 — Enquiries inbox + Roster + Care Scheduling + Care Approval

Released to: `admin`

First real exercise of the release-gating workflow. Four surfaces opened up so Tuniti can start working leads and operations end-to-end within the system, not via spreadsheets.

| Page | Actions granted | Why this slice |
|---|---|---|
| `enquiries` | read + create + edit | She has ~29 client enquiries visible only to us; needs to work them and log phone/walk-ins manually |
| `roster` | read | Patient-centric monthly grid — headline from the 4-day sprint; real operational visibility |
| `engagements` | read + create + edit | Primary scheduling surface — assign caregivers to contracts. Bill-payer guardrail protects against missing clients |
| `roster_input` | read + edit | Approve delivered shifts so they feed billing |

**What stays gated:** opportunities / pipeline / quotes / quotes_rate_override (internal sales tools), contracts (released when she starts actively quoting), unbilled_care + back-office pages.

Migration: `045_release_to_tuniti_inbox_roster_scheduling.sql` · Commit: (see git log for hash after commit)

---

## 2026-04-19 — Baseline snapshot

Establishing the release log; recording what `admin` role *currently* has access to today, before any further grants.

This isn't a new "release event" — it's the line in the sand. Future entries describe deltas from here.

### Currently visible to Tuniti (`admin` role)

| Page code | Label | Actions granted |
|---|---|---|
| `dashboard` | Dashboard | read |
| `student_tracking` | Student Tracking | read |
| `student_view` | Student Detail | read |
| `caregivers_list` | Caregivers list | read |
| `caregivers` | Caregivers | read |
| `clients_list` | Clients list | read |
| `clients` | Clients | read |
| `client_view` | Client profile | read |
| `patients_list` | Patients list | read |
| `patient_view` | Patient profile | read |
| `reports_caregiver_earnings` | Report — Caregiver Earnings | read |
| `reports_client_profitability` | Report — Client Profitability | read |
| `reports_client_billing` | Report — Client Billing | read |
| `reports_days_worked` | Report — Days Worked | read |
| `products` | Products | read |
| `onboarding` | Tuniti Onboarding | read+create+edit+delete |
| `onboarding_review` | Onboarding Review | read+create+edit+delete |
| `whats_new` | What's New | read |
| `releases_admin` | Manage Releases | read |
| `activity_log` | Activity Log | read |
| `config_activity_types` | Activity Types | read |
| `names_reconcile` | Name Reconciliation | read |
| `billing` | Billing | read |
| `self_service` | My Profile | create+edit+delete |

### Currently built but NOT released

These are live in the codebase + DB but `admin` has no permissions on them. Hidden from the nav and 403 if URL-hacked:

- `pipeline` — Sales pipeline Kanban (FR-L)
- `opportunities` — Opportunities list / detail (FR-L)
- `quotes` — Quote list / builder / detail (FR-C)
- `quotes_rate_override` — Rate override permission (FR-E)
- `enquiries` — Public enquiry inbox + manual create
- `engagements` — Care Scheduling
- `roster` — Roster View
- `roster_input` — Care Approval
- `contracts` — Contracts list / detail / create
- `unbilled_care` — (auth-only, no role check; effectively visible to anyone logged in — needs review)
- `email_log` — Email Outbox
- `users` — User Management
- `roles` — Roles & Permissions
- `config_fx_rates` — FX Rates
- `config_aliases` — Timesheet Aliases
- `people_review` — Pending Approvals queue
- `caregiver_loans` — page registered, no table yet
- `patient_expenses` — table exists, no admin page yet

Relevant migration: `043_release_gating_strip_admin_unreleased.sql` strips the inadvertent grants of opportunities/pipeline/quotes/quotes_rate_override that landed in 039 + 041.

---

## How to record a new release

When Ross greenlights releasing a feature to Tuniti:

1. Write a tiny migration that grants `admin` the relevant permissions:
   ```sql
   INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
   SELECT r.id, p.id, 1, 0, 0, 0
     FROM roles r
     JOIN pages p ON p.code IN ('page_code_1', 'page_code_2')
    WHERE r.slug = 'admin';
   ```
2. Run it via `scripts/migrate.sh dev <id>` then `prod` after Ross confirms.
3. Append a new entry at the **top** of this file:
   ```markdown
   ## YYYY-MM-DD — <Short title of release>

   Released to: `admin`

   | Page | Actions | Why |
   |---|---|---|
   | <code> | <r/c/e/d> | <one-line reason> |

   Migration: `0NN_<name>.sql` · Commit: `<sha>`
   ```
4. (Optional, once FR-P WhatsApp is live) ping Tuniti via WhatsApp / email.
5. (Optional) update `templates/admin/whats_new.php` so the user-facing What's New picks it up too.
