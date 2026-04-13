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

**Live in production** at https://tch.intelligentae.co.uk as of
April 2026.

What's working now:
- 139 caregivers, 123 in approved cohorts 1–9, full profile records
- Live student detail page with per-section edit and Notes timeline
- Attendance captured per student per week (1,216 rows imported from
  Tuniti's attendance spreadsheet)
- Monthly revenue, caregiver pay, and gross margin reports by client
- Bug/FR reporter forwarding into the central Nexus Hub

What's next (rough, not a contract):
- Investor "Month X Report" export in one click
- On-the-job-training placement tracking with hours worked
- Forward-looking engagements (scheduled shifts → auto roster → auto
  bill/pay) per the D1 + D2 design notes in `docs/TCH_Ross_Todo.md`
- Drop the read-only "tranche sharing bank account" requirement (done)
  and replace with per-tranche virtual accounting reports

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
