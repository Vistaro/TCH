# Ross — Action Items

**As of:** 10 April 2026

---

## Blocking Next Session

| # | Item | Priority | Notes |
|---|------|----------|-------|
| 1 | **Drop blank caregiver onboarding form into `docs/`** | HIGH | Scan, photo, or PDF of the paper form caregivers currently fill in. Needed to build the data entry screen. |
| 2 | **List standard attachments required per caregiver** | HIGH | ID copy + what else? (qualifications, proof of address, police clearance, etc.) |
| 3 | **Provide product/service list** | MEDIUM | What services does TCH currently offer? Needed for the product database. E.g. "Day Shift Care", "Live-In Care", "Post-Operative Care" etc. |

## Blocking Later Phases

| # | Item | Priority | Notes |
|---|------|----------|-------|
| 4 | **Provide messy training data** | MEDIUM | Individual course/module scores, weekly attendance — whatever format you have. I'll clean it. |
| 5 | **Confirm room/location names** | LOW | Are training rooms fixed names (dropdown) or free text? |
| 6 | **Confirm OJT duration** | LOW | Investor doc says weeks 11–14. Is that always 4 weeks? |
| 7 | **Review and confirm vision doc** | MEDIUM | `docs/TCH_Platform_Vision.md` — read through, flag anything wrong |
| 8 | **Review and confirm build plan** | MEDIUM | `docs/TCH_Plan.md` — check priority order makes sense |

## Ongoing

| # | Item | Notes |
|---|------|-------|
| 9 | **Purge CDN cache after deployments** | StackCP > CDN > Edge Caching. Only needed until we go to production and set proper cache rules. |
| 10 | **DB credentials** | Stored in server `.env`. Username: `tch_admin`, DB: `tch_placements-313539d33a`, host: `shareddb-y.hosting.stackcp.net` |

## Person Database Build (added 10 April 2026)

Decisions locked in this session for unifying student/caregiver into a single Person record:

| # | Item | Priority | Notes |
|---|------|----------|-------|
| 11 | **System config admin page** | MEDIUM | One UI to manage all lookup lists: `person_statuses`, `lead_sources`, `attachment_types`, future `relationships`, etc. Replaces hard-coded ENUMs. |
| 12 | **Status promotion gates** | MEDIUM | Define required-fields-per-status and enforce in app layer when status is changed (e.g. cannot move to `Qualified` without DOB, ID number, course completion). DB stays permissive. |
| 13 | **Referrer / affiliate model** | LOW (build later) | When `lead_source = Referral`, capture who referred them. Future-proof for incentive payments. Today: free-text `referred_by_name` + `referred_by_contact` on the person record. Later: full referrer table + payout tracking. |
| 14 | **Field-level role-based edit permissions** | MEDIUM | Per-field edit/view rules based on user role. E.g. only finance role can edit banking, only admin can change `tch_id`. |
| 15 | **Person record card view** | HIGH (this build) | Card view styled to mirror the Tuniti PDF intake page: photo top-left, two columns of structured fields, NoK block, attachments list. Used for the import review screen and for the standard person profile screen. |
| 16 | **Retire `name_lookup` table** | MEDIUM | Once all 9 PDFs imported and matched, backfill all legacy FKs (caregiver_costs, daily_roster, etc.) and drop `name_lookup`. End state = single canonical person record, no name strings used as identity anywhere. |
| 17 | **`tch_id` immutable identifier** | DONE in migration 003 | Format `TCH-000001`. Auto-assigned on insert. Used in URLs and as the human-facing person identifier. Survives marriage / name changes. |
| 18 | **Replace placeholder portraits with full-quality photos** | LOW | Current portraits are crops from the Tuniti intake PDFs — adequate but low resolution. Source higher-quality originals from Tuniti or re-photograph. Each replacement lands as a NEW `profile_photo` attachment (history preserved). |

## Access Details (for reference)

- **Site:** https://tch.intelligentae.co.uk/
- **Admin login:** https://tch.intelligentae.co.uk/login
  - Username: `ross`
  - Password: `TchAdmin2026x`
- **Git branch:** `dev` (8 commits, nothing on `main` yet)
