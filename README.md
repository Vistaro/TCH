# TCH Placements

## What this is

TCH Placements is the internal operations platform for **Tuniti Care Hero /
The Care Hero**, a South African business that trains caregivers and places
them with private families and care agencies across Gauteng.

The business has two sides:
- **Supply** — a pipeline of trained, vetted caregivers sourced primarily
  through Tuniti's own QCTO-registered training course.
- **Demand** — private clients and care agencies needing reliable in-home
  care (frail care, post-op, live-in, day shifts, etc.).

This repo is the software that keeps the business running day-to-day
and — just as important — proves to the business's investors each month
that the money they put in is becoming real caregivers on real shifts
earning real revenue.

## Who it's for

Three distinct audiences, in order of priority:

1. **CapRaisers / Tuniti investors** — need a clean monthly picture of
   each funding tranche: students trained, student attendance, OJT
   placements, graduation outcomes, and revenue-per-caregiver once they
   start working. Contractual reporting obligation by Working Day 7
   every month.
2. **Tuniti training staff** — onboard new students, approve imported
   records, track weekly attendance, record module scores, sign off
   practical placements.
3. **TCH operations** — run the placement side: clients, patients,
   engagements, daily roster, billing, caregiver pay, gross margin per
   engagement.

## What problem it solves

Before this platform, the whole business lived in a stack of Excel
workbooks that different people owned in different places. Revenue came
from one sheet, attendance from another, caregiver pay from a third.
There was no single version of the truth, audit was by memory, and
assembling the monthly investor pack took days.

The platform replaces the spreadsheet stack progressively. Every screen
that ships replaces one manual process and produces cleaner data than
the sheet it came from.

## Current status

**Live in production** at https://tch.intelligentae.co.uk — latest
prod cut **v0.9.22** (14 April 2026). Dev and prod now on separate
databases.

What's working now:
- 139 caregivers, 123 in approved cohorts 1–9, full profile records
- Client + Patient profile pages with multi-row phones/emails/addresses,
  dedup detection, archive
- Attendance captured per student per week (1,216 rows imported)
- **Single source of truth for cost + revenue** — every financial
  report computes from `daily_roster` with full provenance back to
  the source Excel cell
- **Roster View** (`/admin/roster`) — patient-centric monthly grid
  that replaces the at-a-glance function of Tuniti's Caregiver
  Timesheet workbook, with print/CSV export
- **Unbilled Care umbrella** — surfaces care delivered without an
  invoice as a visible dashboard tile (currently ~R220k over 5 months)
- **Contracts infrastructure** — `/admin/contracts` for the
  commercial contract model (client × patient × products × dates),
  ready for Tuniti to populate
- Bug/FR reporter forwarding into the central Nexus Hub

What's next (rough, not a contract):
- Tuniti to send contract list for ingest
- Scheduling UI (`/admin/schedule/{contract_id}`) — caregiver
  assignment against contract, generates roster rows
- Xero API integration (invoice-on-contract-save + sync back)
- `/admin/onboarding` wizard so Tuniti can self-serve through setup
  todos inline (product defaults, working patterns, alias reviews,
  reconciliation answers) instead of email ping-pong
- Site-wide column-alignment rollout across remaining 12 admin tables

## Where to read more

- **Technical detail** — see `ARCHITECTURE.md`
- **Big design decisions and why** — see `DECISIONS.md`
- **Vision and long-term direction** — `docs/TCH_Platform_Vision.md`
- **Build plan + backlog** — `docs/TCH_Plan.md` and
  `docs/TCH_Ross_Todo.md`
- **Week-by-week progress** — `docs/sessions/`
- **Live bug / feature tracker** — https://hub.intelligentae.co.uk (TCH
  project)

## Contact

Ross (Vistaro / Intelligentae) owns the project. All commits flow via
Claude Code sessions; no human engineers work on this repo directly.
