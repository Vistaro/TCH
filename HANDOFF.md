# Handoff — TCH — 2026-04-13 16:30

## State

Live on prod at https://tch.intelligentae.co.uk. Everything from v0.9.18 + v0.9.19
promoted to prod today (rsync + prod-webroot snapshot). Dev has one extra commit
ahead of prod: the sticky-header CSS fix + products seed + copy tidy.

Overall shape: **feature-complete for Students** (profile, create, edit, print,
graduate, approval, Notes, source-data card, photo replace). **Client/Patient
profile cards are fully specced but not yet built** — that's the headline item
for next session.

## Last session did

- Full 9-item batch on dev: password policy, student edit/create/print/graduate,
  user avatar, user currency + live FX rates, phone display formatting,
  Yes/No on Students list, mojibake repair in 1,216 attendance notes, pending
  invite list + revoke, reporter button rename + position fix.
- Built the Tuniti attendance import end-to-end: 109 students got avg_score,
  1,216 weekly P/A rows, 1,982 source-cited Notes.
- Split all 9 cohort intake PDFs into 123 per-student single-page PDFs.
- Backfilled 16 missing student/enrollment rows so counts now reconcile:
  139 caregivers = 139 students = 139 enrollments.
- Renamed menu + copy: Engagements → Care Scheduling, Roster Input → Care Approval.
- Investigated + explained the R 17,472 wages drift between ledgers — logged as
  D3 / FR-roster-rebuild (HIGH).
- Wrote DESIGN_client_patient_profiles.md full spec (schema, dedup, archive,
  multi-phone/email, client↔patient link, "same person" toggle). All open
  questions answered by Ross.
- Created README.md, ARCHITECTURE.md, DECISIONS.md at repo root.
- Seeded 5 products from the public site (Full-Time, Post-Operative, Palliative,
  Respite, Errand). Day Rate retained for backfill.
- Set up `system_settings` table + Tuniti office GPS
  (-25.861856, 28.258634) for the patient-distance feature.

## In flight (not finished)

- **BUG-sticky-header** — last attempt (border-collapse separate) deployed just
  before wrap. Ross to confirm after a hard browser refresh (Ctrl+F5).
  Fallback if still broken: switch to numbered pagination.

## Open items needing attention

- **HIGH:**
  - BUG-sticky-header (verify after cache bust)
  - UAT-tuniti (build the test plan, give Tuniti dev access)
  - FR-roster-rebuild (single source of truth for caregiver wages)
  - UAT-product-remap (Tuniti to reclassify the 1,619 historical shifts
    from default Day Rate to the correct product)
- **Build queued (next session headline):** Client/Patient profile cards.
  Spec in `docs/DESIGN_client_patient_profiles.md`.
- **MEDIUM:** FR-admin-config (landing + system_settings UI), FR-client-expenses,
  FR-caregiver-loans, D1/D2/D3 (engagement + roster redesign), DQ0–DQ2 data
  quality sweeps, UI1 edit client↔patient relationship.
- **Blockers waiting on Ross:** genuinely none for the next session. Stale
  items (onboarding PDF, attachment list, training data) all superseded by
  work already done. Product list was the only real one — now seeded.

## Next session should

1. **Build the Client + Patient profile cards** per
   `docs/DESIGN_client_patient_profiles.md`. All open design questions
   confirmed by Ross: 1 client → many patients; one client status enum
   (active + archived); break out `person_addresses` table from the start;
   dedup threshold = Levenshtein ≤3 OR metaphone match OR phone/email/ID
   exact. Estimated ~1 day.
2. After Client/Patient: **FR-roster-rebuild / D3** — re-ingest from the
   Client Billing Spreadsheet so wages come from one source.
3. If BUG-sticky-header is still reported after hard refresh, implement
   pagination as the fallback.
4. Kick off the UAT pack for Tuniti — structured test tasks on dev.
