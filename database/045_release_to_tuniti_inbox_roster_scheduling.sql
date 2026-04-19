-- ─────────────────────────────────────────────────────────────
--  045_release_to_tuniti_inbox_roster_scheduling.sql
--
--  First real exercise of the FR-R release-gating workflow.
--
--  Releases to Tuniti (the `admin` role):
--    enquiries       — inbox of public + manual enquiries; she has
--                      ~29 client enquiries visible to Ross only today
--    enquiries       (create) — + New Enquiry button works
--    roster          — patient-centric monthly roster view
--    engagements     — Care Scheduling (assign caregivers to contracts)
--    roster_input    — Care Approval workflow
--
--  Already visible to admin (for reference, not changed here):
--    dashboard, student_tracking/view, caregivers[_list], clients[_list]/view,
--    patients_list/view, 4 reports, products, onboarding, whats_new, activity_log,
--    config_activity_types, releases_admin (read), billing, self_service.
--
--  Still gated from admin after this migration:
--    opportunities, pipeline, quotes, quotes_rate_override (internal-only
--      sales tools per FR-R);
--    contracts, unbilled_care (operations-only — released when Tuniti
--      starts actively quoting);
--    email_log, users, roles, config_fx_rates, config_aliases,
--      people_review, dev_tools_* (back-office only).
--
--  Audit: this migration's run + rationale is also logged in
--  docs/release-log.md as the 2026-04-19 release entry.
--
--  Rollback:
--    DELETE from role_permissions where role_id = (admin) and page_id
--      in ('enquiries','roster','engagements','roster_input').
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

-- Enquiries — full CRUD (they need to work through + create manual ones)
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 0
  FROM roles r
  JOIN pages p ON p.code = 'enquiries'
 WHERE r.slug = 'admin';

-- Roster view — read-only (data comes from the ingest pipeline, not admin edits)
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 0, 0, 0
  FROM roles r
  JOIN pages p ON p.code = 'roster'
 WHERE r.slug = 'admin';

-- Care Scheduling (engagements) — full CRUD; this is their primary operations surface
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 1, 1, 0
  FROM roles r
  JOIN pages p ON p.code = 'engagements'
 WHERE r.slug = 'admin';

-- Care Approval (roster_input) — read + edit (approve delivered shifts)
INSERT IGNORE INTO role_permissions (role_id, page_id, can_read, can_create, can_edit, can_delete)
SELECT r.id, p.id, 1, 0, 1, 0
  FROM roles r
  JOIN pages p ON p.code = 'roster_input'
 WHERE r.slug = 'admin';
