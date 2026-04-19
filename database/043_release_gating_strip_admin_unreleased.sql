-- ─────────────────────────────────────────────────────────────
--  043_release_gating_strip_admin_unreleased.sql
--
--  Release-gating policy (FR-R, established 2026-04-19):
--  Tuniti users are assigned the `admin` role. They only see what
--  has been "officially released" to them — recorded in
--  `docs/release-log.md`. Internal-only tooling stays gated behind
--  `super_admin` until release.
--
--  My migrations 039 (FR-L pipeline + opportunities) and 041 (FR-C
--  quotes + FR-E rate override) accidentally granted admin role
--  read+create+edit on those new pages. They are NOT yet released
--  to Tuniti. This migration strips the inadvertent grants.
--
--  After this runs:
--    super_admin — full access to opportunities/pipeline/quotes/
--      quotes_rate_override (unchanged)
--    admin       — NO access (Tuniti users won't see them in nav)
--
--  When we choose to release any of these pages to Tuniti, a future
--  migration (or manual grant via the Roles UI) re-enables them and
--  the release-log.md entry records the date and rationale.
--
--  Rollback: re-INSERT the four role_permissions rows that
--  migration 039 + 041 originally created (read=create=edit=1,
--  delete=0 for admin).
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

DELETE rp FROM role_permissions rp
  JOIN roles r ON r.id = rp.role_id
  JOIN pages p ON p.id = rp.page_id
 WHERE r.slug = 'admin'
   AND p.code IN ('opportunities', 'pipeline', 'quotes', 'quotes_rate_override');
