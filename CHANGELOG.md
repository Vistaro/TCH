# Changelog

All notable changes to the TCH Placements project.

## [0.9.3-dev] - 2026-04-11

### Changed — Activity log coverage gaps closed (A1.5)

Followup to v0.9.2-dev. Ross accepted the coverage audit recommendations and
authorised the three high-value gap closures plus the two cosmetic
backfills. The standing rule for this project (and all future projects) is
now: **every user-triggered mutation on a transactional site must be
captured in the activity log with a field-level before/after snapshot.**
Added to `C:\ClaudeCode\CLAUDE.md` as a top-level standing order.

**Gaps closed:**

1. **Failed logins now appear in `activity_log`** (`includes/auth.php`,
   `attemptLogin()`):
   - Unknown email / inactive account → `login_failed` with reason in
     summary. entity_id is the matched user id if any, else null.
   - Already-locked account attempt → `login_failed` with the existing
     `locked_until` in the summary.
   - Wrong password → `login_failed` with `failed_login_count` before/after
     in the diff. Super Admin (role_id 1) is still exempt from the count
     increment per the existing lockout spec but the attempt is still
     logged.
   - The existing `login_log` table is untouched — it still carries every
     attempt and is used by the lockout logic. `activity_log` is now a
     superset so admins can answer "I didn't do that" from the main audit
     UI in one place.

2. **Account lockouts now emit a dedicated `account_locked` entry** in
   addition to the `login_failed` entry (`includes/auth.php`,
   `attemptLogin()`). Separating them makes lockouts filterable and gives
   a clean hook for future alerting. Before/after snapshots the
   `locked_until` field.

3. **Every email send now emits an `email_sent` activity entry**
   (`includes/mailer.php`, `Mailer::send()`). entity_type is `email_log`
   and entity_id is the corresponding outbox row, so the activity detail
   page can deep-link back to the full outbox entry. The diff captures
   template, recipient, subject, and final status (sent/failed).
   `logActivity()` has its own internal try/catch so a logging failure
   cannot break mail delivery.

**Cosmetic backfills (same pass):**

4. **`user_unlocked` now carries a before/after snapshot** of
   `failed_login_count` and `locked_until`
   (`templates/admin/users_detail.php`, `unlock` action). Previously a
   summary-only entry with no diff.

5. **`password_reset_forced` now carries a before/after snapshot** of
   the `must_reset_password` flag
   (`templates/admin/users_detail.php`, `force_reset` action).

### Explicitly NOT changed

- `login_log` table (still the source of truth for lockout counting).
- The existing `logActivity()` signature.
- Any call site outside the five listed above.
- Schema — no migrations.

### Known noise trade-off

- `login_failed` with `reason = unknown email` will be emitted for every
  probe attempt with a non-existent email address, not only for real user
  accounts. At Ross's volume this is fine and the signal is more valuable
  than the noise (probe activity is itself forensically interesting). If
  the log ever gets spammy from this, a simple filter on the list page
  (or a retention rule that archives `action='login_failed' AND
  entity_id IS NULL` after N days) will tame it without losing the data.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via scp over SSH:
  `includes/auth.php`, `includes/mailer.php`,
  `templates/admin/users_detail.php`.
- Server-side `php -l` clean on all three.
- No schema migrations.
- Not yet promoted to prod — held for Ross sign-off.

## [0.9.2-dev] - 2026-04-11

### Changed — Activity log field-level diff is now inline on the list view

Ross flagged that the TCH activity log wasn't showing what he wanted to see at
a glance: when a record was edited, the list row showed only a summary line
and he had to click "View" to see *which fields actually changed*. The Nexus
CRM activity log shows the `old → new` diff inline on the list row, colour-
coded (red strikethrough → green). This release brings TCH to the same shape.

**What the user sees on `/admin/activity`:**

- Every row that captured a before/after snapshot now shows a small
  `▶ N fields changed` disclosure triangle under the summary cell.
- Click to expand → one line per changed field, rendered as
  `field: <del>old</del> → <ins>new</ins>`. Old is red strikethrough, new
  is green. Matches the Nexus visual convention.
- Login/logout/public-form rows render nothing extra (no snapshot = nothing
  to show). Keeps the table scannable.
- The existing **View** button on each row is unchanged — it still opens
  `/admin/activity/{id}` with the full forensic detail (IP, user agent,
  link-throughs to the affected entity, raw JSON).

**What changed in the code:**

- New `includes/activity_log_render.php` — shared helpers for snapshot
  decoding, per-field value rendering, diff computation, and the new
  inline-diff renderer used by the list view.
- `templates/admin/activity_log.php` — replaced the placeholder
  "(field-level diff available)" hint with a real collapsible inline diff
  block. View button preserved.
- `templates/admin/activity_detail.php` — refactored to use the shared
  helpers (removed duplicated private `decode_snapshot()` / `render_value()`
  / diff-loop). Detail page Was/Now cells are now tinted red/green to match
  the inline view.
- `public/assets/css/style.css` — added `.activity-inline-diff`,
  `.diff-was`, `.diff-now`, `.diff-arrow`, and `.diff-was-cell` /
  `.diff-now-cell` rules.

**Explicitly NOT changed:**

- No schema change to `activity_log`. The single-table + JSON blob design
  stays.
- No changes to `logActivity()` or any call site — coverage is the same as
  v0.9.1.
- No retention policy, no source/route column, no tamper protection — all
  still outstanding and tracked in `docs/TCH_Ross_Todo.md`.

**Also in this commit:**

- `.gitignore` now excludes `.last-backup-timestamp` (written by the
  cross-device SessionEnd hook; never meant to be committed).
- `docs/TCH_Ross_Todo.md` — added the "Activity Log — full audit + revert
  capability" section with 4 planned work items (A1 audit sweep, A2 single-
  field revert, A3 whole-record rollback, A4 undelete) and workload
  estimates.

### Deployment

- Files uploaded to `~/public_html/dev-TCH/dev/` via rsync over SSH.
- No schema migrations.
- Not yet promoted to prod — held for Ross sign-off after dev smoke test.

## [0.9.1] - 2026-04-10 — SHIPPED TO PROD

### Prod deploy

**Production deploy of v0.7.0 + v0.8.0 + v0.9.0 + v0.9.1 as a single block.**
First prod release of the new email-based login + RBAC + audit system.

Deploy steps executed:
1. Cleaned up test data on shared DB (deleted testmanager user, related
   user_invites row, "Audit Sweep Test" enquiry). activity_log and
   email_log left intact as audit/forensic data.
2. Server-side prod files backup:
   `~/public_html/tch_backup_pre_v0.9.1_2026-04-10/` (18MB).
3. DB tables backup:
   `~/public_html/dev-TCH/dev/database/backups/user_mgmt_pre_v0.9.1_prod_2026-04-10.sql`
   (users, roles, pages, role_permissions, user_invites, password_resets,
   email_log, activity_log — 329 lines).
4. Fast-forward `main` from `4b4ad0f` (v0.6.0+hamburger) to `966755a`
   (v0.9.1). 36 files, +5,437/-67 lines. Pushed.
5. Tagged `v0.9.1` on the merged commit, pushed tag to GitHub.
6. Server-side rsync `~/public_html/dev-TCH/dev/` → `~/public_html/tch/`
   excluding `.env`, `database/backups/`, `tools/intake_parser/output/`,
   `.git/`. 26 templates updated/created.
7. Smoke tests on prod (https://tch.intelligentae.co.uk):
   - `/`, `/login`, `/forgot-password`, `/reset-password?token=invalid`,
     `/setup-password?token=invalid` → all 200
   - `/admin` (unauthed) → 302 to /login
   - `/login` form contains `name="email"` (not username — confirms new code is live)
   - POST `/login` with `ross@intelligentae.co.uk` / `TchAdmin2026x` → 302 → /admin
   - All 14 authed admin pages → 200:
     `/admin`, `/admin/users`, `/admin/users/invite`, `/admin/users/1`,
     `/admin/roles`, `/admin/roles/2/permissions`, `/admin/activity`,
     `/admin/email-log`, `/admin/people/review`, `/admin/enquiries`,
     `/admin/names`, three reports

**OUTSTANDING POST-DEPLOY ACTION FOR ROSS:**
* **Purge CDN cache** via StackCP > CDN > Edge Caching, or use Development
  Mode. Until purged, anonymous browsers may see the cached old `/login`
  page with the username field. Edge cache typically clears within
  minutes anyway, but a manual purge accelerates it.

### Added — Activity Log Field-Level Diff View

Ross noticed the activity log captured `before_json` / `after_json` columns
but the viewer didn't render them. This patch adds a per-entry detail page
that renders mutations as a field-by-field diff (Field / Was / Now), and
backfills the three remaining mutations that didn't yet capture proper
before/after snapshots.

**New: `/admin/activity/{id}` detail page (`templates/admin/activity_detail.php`):**

* Full row metadata: when, action, real actor, impersonator (when set),
  page, entity (with click-through to /admin/users/{id} or /admin/enquiries?id=
  when applicable), summary, IP, user agent.
* **Changes section** — decodes `before_json` and `after_json`, computes
  the set of changed fields, and renders them as a 3-column table:

  | Field      | Was            | Now                       |
  |------------|----------------|---------------------------|
  | full_name  | Test Manager   | Test Manager (Renamed)    |
  | users.read | 0              | 1                         |

* Smart value rendering: nulls show as `(empty)`, booleans as `true`/`false`,
  arrays/objects as inline JSON, long strings escaped.
* Identical-snapshot detection: if before == after the page says "no fields
  actually changed" instead of an empty table.
* For events without before/after (login, logout, public submission,
  token-based flows): the page explicitly says "did not capture a
  field-level diff" so it's clear it's a known intentional gap.
* Collapsible "Raw JSON snapshots" `<details>` block at the bottom for
  forensic inspection (pretty-printed JSON of both snapshots).

**Front controller:** new parametric route
`^admin/activity/(\d+)$ → activity_detail.php`, gated on `activity_log.read`.

**Activity log list (`templates/admin/activity_log.php`):**

* New "View" button on every row → links to `/admin/activity/{id}`.
* Summary cell shows a small "(field-level diff available)" hint when the
  row has a non-empty before_json or after_json, so users know which entries
  are worth clicking through.
* Existing colspan adjusted from 8 to 9 for the empty state.

### Backfilled before/after captures

Three mutations that previously logged only a summary string now capture
proper field-level diffs:

* **`users_list.php` deactivate/reactivate** — captures `is_active` flip.
  Reactivate also includes `failed_login_count` and `locked_until` reset.
* **`enquiries.php` add_note** — captures the appended note line as
  `note_appended: null → "<text>"`. Notes are append-only on the column,
  so the diff records *what was added*, not the full growing notes blob.
* **`roles_permissions.php` matrix update** — previously stored a placeholder
  string ("see role_permissions") in `after_json` instead of the actual
  diff. Replaced with a flat snapshot of the form `{pagecode}.{verb} → 0|1`,
  so the activity detail page renders one row per *changed* permission
  (e.g. `users.read: 0 → 1`, `enquiries.create: 1 → 0`, etc).
  - Also fixed a latent bug: the previous snapshot used `PDO::FETCH_KEY_PAIR`
    which only captures 2 columns, so the original `$before` array would
    have been malformed even if it had been used. The new
    `$snapshotMatrix()` closure pulls all 4 verbs per page properly.
  - Summary line now reports the change count, e.g. "Updated permission
    matrix for Manager (3 field changes)".

### End-to-end verification on dev

* Edited Test Manager's full_name from "Test Manager" → "Test Manager (Renamed)"
  via /admin/users/2.
* Activity row 19 (action=user_edited) → /admin/activity/19 renders:
  ```
  Field        Was             Now
  full_name    Test Manager    Test Manager (Renamed)
  ```
* Submitted a permission matrix change for the Manager role enabling
  users/roles read across the board. Activity row 20
  (action=role_permissions_updated) → /admin/activity/20 renders ~16
  rows of `{page}.{verb}: 0 → 1` diffs. Summary says "16 field changes".
* Verified the Manager role permissions restore round-trip: after putting
  Manager perms back, `GET /admin/users` as testmanager returns 403 again.
* Restored Test Manager's full_name back to "Test Manager".

## [0.9.0] - 2026-04-10

### Added — Audit Log Integration Sweep (Session C of 3)

Final session of the locked 3-session User Management + RBAC + Audit +
Impersonation build. Session C closes the audit-log integration gap by
mechanically sweeping every remaining mutation path that Sessions A and
B did not already cover.

**Sweep methodology:**

* Grepped every PHP file in the repo for `INSERT|UPDATE|DELETE` statements
* Cross-referenced against files that already contain `logActivity()` calls
* Three uncovered mutation paths identified:
  1. `templates/public/enquire_handler.php` — public form submission
  2. `templates/admin/names.php` — name lookup approve/reject
  3. `database/seeds/create_admin.php` — CLI admin password upsert

**`templates/public/enquire_handler.php`:**

* Added `logActivity('enquiry_submitted', ...)` after the INSERT.
* Anonymous submission — `real_user_id=NULL` (the form is public, no
  session). The summary captures the submitter's name and care type so
  the audit log is useful even without a user link.

**`templates/admin/names.php`:**

* Added `logActivity('name_lookup_approved', ...)` and
  `logActivity('name_lookup_rejected', ...)` with before/after JSON
  snapshots of the `approved` flag.
* Mutation block now gates on `userCan('names_reconcile', 'edit')`.
* **Latent bug fixed**: the file referenced `$user['username']` to set
  `name_lookup.approved_by`, but `$user` was never defined in this template
  (it's only set in `templates/layouts/admin.php`, which is included AFTER
  the mutation block runs). The bug caused approved_by to be silently
  populated as null/undefined for every approval. Replaced with
  `currentEffectiveUser()` returning a proper email-or-fallback label.

**`database/seeds/create_admin.php`:**

* Added `require auth.php` so `logActivity()` is available in the CLI context.
* Logs `admin_password_set_cli` when updating an existing user, or
  `admin_user_created_cli` when creating from scratch. Both anonymous
  (no session in CLI), with `entity_type='users'` and `entity_id=ross_id`.
* Fixed a pre-existing issue where the INSERT path didn't set `role_id`
  or `email_verified_at` — now correctly creates ross as Super Admin
  (role_id=1) with `email_verified_at=NOW()`.

**Out of scope (deferred — see session notes):**

* `database/seeds/ingest.php` and `database/seeds/reconcile.php` are
  one-shot historical bulk ingest scripts. They already provide their
  own provenance via the existing `audit_trail` table and `import_notes`
  columns. Adding `logActivity()` per row would generate tens of
  thousands of entries from a single ingest run with no operational value.
  These scripts have done their job and are unlikely to run again.

### End-to-end audit verification

Triggered one mutation of each new type on dev and verified the
`activity_log` captured them with the right actor / entity / summary:

| id | action                  | real | imp  | entity      | summary                                  |
|---:|-------------------------|-----:|-----:|-------------|------------------------------------------|
| 15 | enquiry_status_changed  | 1    | NULL | enquiries#1 | Status: new -> contacted                 |
| 14 | admin_password_set_cli  | NULL | NULL | users#1     | create_admin.php CLI updated ross pw     |
| 13 | enquiry_submitted       | NULL | NULL | enquiries#1 | Public enquiry from Audit Sweep Test     |

The activity log viewer (`/admin/activity`) renders all three new entries
and the action filter dropdown picks up the new action types automatically.

### Distinct action types currently exercised

10 distinct actions captured in the dev log after Sessions A + B + C testing:
`admin_password_set_cli`, `enquiry_status_changed`, `enquiry_submitted`,
`impersonate_start`, `impersonate_stop`, `login`, `password_reset_completed`,
`password_reset_requested`, `user_invite_accepted`, `user_invited`.

The remaining defined action types (`logout`, `user_edited`, `user_deactivated`,
`user_reactivated`, `user_unlocked`, `password_reset_forced`, `person_approved`,
`person_rejected`, `enquiry_note_added`, `name_lookup_assigned`,
`name_lookup_approved`, `name_lookup_rejected`, `role_permissions_updated`,
`admin_user_created_cli`) all have `logActivity()` calls in their handlers
and will appear in the log when their UI actions are triggered.

### Deployment

* Files uploaded to `~/public_html/dev-TCH/dev/` via scp.
* No new schema migrations.
* **Held from prod deploy pending Ross sign-off.** v0.7.0 + v0.8.0 + v0.9.0
  are now ready to ship to prod as a single block via dev → prod rsync.

## [0.8.0] - 2026-04-10

### Added — Admin UIs, Impersonation, Permission Retrofit (Session B of 3)

Second session of the locked 3-session User Management + RBAC + Audit +
Impersonation build. Session B ships the admin user-management UIs, the
roles/permissions matrix editor, the activity log + email outbox viewers,
the full impersonation flow with persistent banner, and retrofits all
existing handlers to use `requirePagePermission()` and `logActivity()`.

**Front controller (`public/index.php`):**

* Two-pass routing: parametric routes (`/admin/users/{id}`,
  `/admin/users/{id}/impersonate`, `/admin/roles/{id}/permissions`,
  `/admin/email-log/{id}`) matched via preg_match BEFORE the static switch.
* New static routes: `/admin/users`, `/admin/users/invite`,
  `/admin/impersonate/stop`, `/admin/roles`, `/admin/activity`,
  `/admin/email-log`.
* Every existing admin route migrated from `requireAuth()` to
  `requirePagePermission($pageCode, $action)`. Manager logins now correctly
  hit 403 on `/admin/users` and `/admin/roles` while still being able to
  access `/admin`, `/admin/people/review`, `/admin/enquiries`, etc.

**Admin layout (`templates/layouts/admin.php`):**

* Sidebar nav is now fully permission-driven via `userCan()` — users only
  see the pages they actually have access to. New "Admin" section appears
  for users with `users`, `roles`, `activity_log`, or `email_log` access.
* Persistent impersonation banner injected at the very top of every admin
  page when `isImpersonating()` returns true. Shows the real user's email,
  the impersonated user's identity + role, and a one-click "End impersonation"
  link. Sticky-positioned, red background, z-index above the sidebar.
* `admin-user` block in the header now falls back gracefully to
  email/username when `full_name` is empty.

**`/admin/users` — `templates/admin/users_list.php`:**

* Lists every user visible via `getVisibleUserIds()` (Super Admin/Admin
  see all; Manager sees their hierarchy).
* Filters: search by email/name, role dropdown, active/inactive status.
* Stats cards: active user count, pending invite count.
* Per-row actions: View / Deactivate (or Reactivate). Self-deactivation
  is blocked with a flash message.
* Status badges: Active / Locked / Unverified / Inactive.
* "Invite User" button visible only to users with `users.create`.
* Every mutation logs via `logActivity()`.

**`/admin/users/invite` — `templates/admin/users_invite.php`:**

* Form: email, full name, role, optional manager, optional linked
  caregiver/client IDs.
* Refuses if an active user with that email already exists.
* Generates a SHA-256 token, INSERTs into `user_invites`, calls
  `Mailer::send('invite', ...)`. 72-hour expiry.
* On success, displays the dev fallback link (the raw setup-password URL)
  inline so the developer can copy it directly even if shared-host
  `mail()` drops the message.
* `logActivity('user_invited', ...)` records the invite.

**`/admin/users/{id}` — `templates/admin/users_detail.php`:**

* Profile section: edit full name, role, manager, linked caregiver/client.
  Save uses transactional UPDATE with before/after JSON snapshots in the
  audit log.
* Account actions:
    - Send Password Reset Email (creates `password_resets` row, calls
      mailer, sets `must_reset_password=1`, shows dev fallback URL)
    - Unlock (clears `failed_login_count` and `locked_until`, only shown
      when the account is currently locked)
    - Impersonate User (Super Admin only, hidden when target is the
      current user or inactive)
* Recent Activity panel: 20 most recent rows from `activity_log` where
  this user was either the actor (real_user_id) OR the target of
  impersonation (impersonator_user_id).
* Email column is read-only in the UI — email changes are not supported
  in v1.

**`/admin/users/{id}/impersonate` — `templates/admin/users_impersonate.php`:**

* Two-step flow: GET renders the re-auth form, POST calls `startImpersonation()`.
* Hard pre-checks before showing the form: must be Super Admin, must not
  already be impersonating, target cannot be self.
* Re-auth: Super Admin must enter their own current password.
* On success: redirects to `/admin` where the persistent banner appears.
* `/admin/impersonate/stop` route handles ending the session and redirects
  back to `/admin`. Both start and stop log to activity_log.

**`/admin/roles` + `/admin/roles/{id}/permissions` — roles UI:**

* `roles_list.php` — lists all 5 system roles with user count, "pages with
  any access" count, and Edit Permissions button. Role creation/deletion
  is intentionally not exposed in v1 — the 5 system roles are fixed; only
  the matrix is editable.
* `roles_permissions.php` — full pages × CRUD checkbox grid (17 × 4 = 68
  checkboxes). UPSERTs every row in a single transaction. Captures
  before/after via SELECT...PIVOT for the audit log.
* Hard guard: the Super Admin role (id 1) cannot have its permissions
  modified through this UI even if `roles.edit` is granted. The form
  renders read-only (disabled inputs) and POSTs against role_id=1 are
  rejected with a flash error. This prevents anyone from accidentally
  locking themselves (and everyone else) out of the system.

**`/admin/activity` — `templates/admin/activity_log.php`:**

* Filters: action dropdown (populated from DISTINCT actions), entity_type
  dropdown, user_id (matches both real and impersonator), date range.
* Pagination: 50 entries per page.
* Columns: When, Actor, Impersonator (badge), Action, Page, Entity, Summary, IP.
* The Impersonator column makes it visually obvious which actions
  happened under impersonation — answers the "who really did this?"
  question at a glance.

**`/admin/email-log` + `/admin/email-log/{id}` — email outbox:**

* List view: filterable by status (queued/sent/failed) and template.
  Pagination 50/page. Status badges. Click to view the full body.
* Detail view: full envelope (from/to/subject/template/status/timing) +
  the email body verbatim in a `<pre>` block. Reset and invite links can
  be copied directly from here when shared-host `mail()` fails to deliver.

**Existing handler retrofit:**

* `templates/admin/enquiries.php` — both POST handlers now gate on
  `userCan('enquiries', 'edit')` and call `logActivity()` with action
  `enquiry_status_changed` (with old/new status snapshot) or
  `enquiry_note_added`. Replaced legacy `$user['username']` with
  `$user['email']`.
* `templates/admin/people_review.php` — approve/reject mutations gated
  on `userCan('people_review', 'edit')` and log as `person_approved` /
  `person_rejected` with before/after import_review_state.
* `templates/admin/names_assign.php` — name lookup updates log as
  `name_lookup_assigned` with before/after billing_name snapshot.

**CSS additions (`public/assets/css/style.css`):**

* `.impersonation-banner` + `.impersonation-banner-inner` +
  `.impersonation-stop` — sticky red banner.
* `.badge-danger` — for failed email statuses.
* `.alert-info` — for the dev fallback link blocks on the invite form.

### End-to-end verification on dev

Smoke tests run during the deploy:

* All 9 new admin pages return 200 as ross (Super Admin):
  `/admin/users`, `/admin/users/invite`, `/admin/users/1`,
  `/admin/roles`, `/admin/roles/1/permissions`, `/admin/roles/2/permissions`,
  `/admin/activity`, `/admin/email-log`, `/admin/email-log/1`
* All 7 existing admin pages still return 200 (legacy handlers under
  `requirePagePermission()`): `/admin`, `/admin/people/review`,
  `/admin/enquiries`, `/admin/names`, three reports.

End-to-end invite + permission gating + impersonation:

1. Created Test Manager user via `/admin/users/invite` (role_id=3)
2. Extracted `setup-password` URL from `email_log`
3. Walked through `/setup-password?token=...` → password set → "account is ready"
4. Logged in as `testmanager@example.com` / `TestMgr2026Pwd`
5. Manager session: `/admin` → 200, `/admin/people/review` → 200,
   `/admin/enquiries` → 200, `/admin/activity` → 200,
   **`/admin/users` → 403, `/admin/roles` → 403** ← permission gating works
6. Logged back in as ross (Super Admin)
7. `GET /admin/users/2/impersonate` → re-auth form
8. `POST` with ross's password → 302 to `/admin`
9. `GET /admin` → 200 with **"Impersonation active" banner present**
10. While impersonating Manager: `GET /admin/users` → **403** (Manager
    doesn't have that page — impersonation correctly inherits the target's
    permissions)
11. `GET /admin/impersonate/stop` → 302 → back to ross
12. `GET /admin/users` → 200 (Super Admin again)

Activity log final state (12 rows):

| id | action                  | real | imp  |
|---:|-------------------------|-----:|-----:|
| 11 | impersonate_stop        | 1    | NULL |
| 10 | impersonate_start       | 2    | 1    | ← real_user_id = effective (mgr), imp = ross
|  9 | login                   | 2    | NULL |
|  8 | user_invite_accepted    | NULL | NULL |
|  7 | user_invited            | 1    | NULL |
| 1-6| (Session A test events) |      |      |

The audit convention works: row 10 records the impersonated session
correctly with `real_user_id=2` (the effective identity, Test Manager)
and `impersonator_user_id=1` (the human at the keyboard, Ross). A query
of `WHERE real_user_id = 2 OR impersonator_user_id = 2` returns
"everything that happened to/as Test Manager" including the impersonation
session.

### Test data left on dev

The Test Manager user (`testmanager@example.com` / `TestMgr2026Pwd`) was
left on the dev database so Ross can try the impersonation flow himself.
Delete via SQL or the deactivate button on `/admin/users` whenever convenient.

### Deployment

* Files uploaded to `~/public_html/dev-TCH/dev/` via scp
* No new schema migrations (all schema work landed in 005 in Session A)
* NOT yet promoted to prod — held until Session C completes the final
  audit-log integration sweep

### Out of scope this session (deferred to Session C)

* Audit-log integration sweep across the database seed scripts and any
  remaining mutation paths Session B didn't touch
* Hierarchy filtering on additional list pages — currently a no-op since
  the only users are Super Admin/Admin who bypass hierarchy. Will be
  retrofitted if/when real Manager users are invited.

## [0.7.0] - 2026-04-10

### Added — User Management Foundation (Session A of 3)

First session of the locked 3-session User Management + RBAC + Audit +
Impersonation build. This session ships the schema, auth library, mailer,
and public auth flows. Admin UIs land in Session B; the audit-log integration
sweep across existing handlers lands in Session C.

**New schema (`database/005_users_roles_hierarchy.sql`):**

* `roles` table — 5 seeded system roles: Super Admin, Admin, Manager, Caregiver,
  Client. `is_system=1` flag prevents UI deletion.
* `pages` table — 17 pages registered (dashboard, caregivers, clients, roster,
  billing, people_review, names_reconcile, enquiries, three reports, users,
  roles, activity_log, email_log, config, self_service). Each page is the
  unit of permission gating.
* `role_permissions` table — page × role × CRUD verb (read / create / edit / delete).
  Default matrix seeded: Super Admin and Admin get full CRUD on everything;
  Manager gets full CRUD on everything except `users` and `roles`; Caregiver
  and Client get read+edit on `self_service` only. 51 rows total.
* `users` table extended (additive) with: `role_id` (FK roles), `manager_id`
  (self-FK for hierarchy), `linked_caregiver_id` (FK caregivers), `linked_client_id`
  (FK clients), `email_verified_at`, `failed_login_count`, `locked_until`,
  `must_reset_password`. Email made `NOT NULL UNIQUE`. Legacy `username` and
  `role` columns retained for back-compat.
* `user_invites` table — pending invitations. Token stored as SHA-256 hash;
  raw token only ever appears in the email body. Includes `manager_id`,
  `linked_caregiver_id`, `linked_client_id` so an invite carries the full
  identity setup forward into the created user row.
* `password_resets` table — same SHA-256 hash pattern. `requested_ip` recorded
  for forensics. Single-use: `used_at` is set on success, plus all other
  outstanding tokens for the same user are invalidated in the same transaction.
* `email_log` table — outbox. Every send attempt is INSERTed as `queued`
  before `mail()` is called, then flipped to `sent` or `failed`. Guarantees
  the developer can always retrieve the link from the table even when
  shared-host `mail()` silently drops messages.
* `activity_log` table — mutation audit log. Records action, page_code,
  entity_type, entity_id, summary, before/after JSON, IP, user agent. Both
  `real_user_id` (effective identity / actor as logged) AND `impersonator_user_id`
  (the human at the keyboard, only set when impersonation is active) are
  stored, so impersonated actions trace back to the real human. Page views
  are NOT logged in v1 — mutations only.
* Migration is idempotent: every column add is conditional via INFORMATION_SCHEMA
  checks, every INSERT uses `INSERT IGNORE`, every constraint add is conditional.
  Safe to re-run on the shared dev/prod database.
* Existing `ross` user row migrated in place: `role_id=1` (Super Admin),
  `email_verified_at=NOW()`. Password and email unchanged.

**New auth library (`includes/auth.php` — rewritten):**

* `attemptLogin($email, $password)` — email is now the canonical login identifier.
  Lockout enforcement: 10 failed attempts in a row → 15-minute lockout. Super
  Admin (role_id 1) is exempt from lockout per the spec. Resets failed counter
  and `locked_until` on success.
* `fetchUserById()` / `fetchUserByEmail()` helpers — both join to `roles` for
  role_slug + role_name.
* Session keys added: `real_user_id`, `impersonator_user_id`, `email`, `role_id`,
  `role_slug`, `role_name`. Legacy `username` and `role` keys retained so the
  existing `requireAuth()` and `requireRole()` shims continue to work for the
  pages built before this session.
* `currentUser()` is now an alias for `currentEffectiveUser()` (returns the
  impersonated identity when impersonation is active).
* `currentRealUser()` always returns the human at the keyboard regardless of
  impersonation, by re-fetching from the DB using `real_user_id`.
* `isImpersonating()`, `startImpersonation($targetUserId, $reauthPassword)`,
  `stopImpersonation()` — full impersonation lifecycle. `startImpersonation()`
  enforces:
    - real user must be Super Admin (role_id 1)
    - cannot already be impersonating
    - cannot impersonate yourself
    - re-auth: caller must supply their own current password (`password_verify`)
    - target user must exist and be active
* `logout()` records a `logout` action to `activity_log` before destroying
  the session.

**New permissions library (`includes/permissions.php`):**

* `userCan($pageCode, $action)` — looks up the role × page × verb in
  `role_permissions`. Returns false for unauthenticated users or unknown
  pages.
* `requirePagePermission($pageCode, $action)` — calls `requireAuth()` first,
  then enforces the CRUD verb. Returns 403 if missing.
* `isSuperAdmin()` — gate for impersonation. Always checks the REAL user,
  never the effective one, so an impersonated session cannot start a
  nested impersonation.
* `getVisibleUserIds($forUserId)` — recursive BFS down `users.manager_id`.
  Super Admin and Admin (role_id 1, 2) bypass the hierarchy and see every
  user. Returns the manager + every direct/indirect report.
* `getVisibleCaregiverIds($forUserId)` — Super Admin/Admin see all; Caregiver
  (role_id 4) sees only their own `linked_caregiver_id`; Manager sees
  caregivers linked to any user in their visible-user set.
* `getVisibleClientIds($forUserId)` — same pattern as caregivers, mirrored
  for the Client role.
* `logActivity($action, $pageCode, $entityType, $entityId, $summary, $before, $after)` —
  central audit recorder. Wraps the INSERT in try/catch so an audit failure
  never breaks the user-facing flow. JSON-encodes before/after snapshots.
  Captures real_user_id + impersonator_user_id correctly under all session states.

**New mailer (`includes/mailer.php`):**

* `Mailer::send($template, $toEmail, $toName, $vars, $relatedUserId)` —
  outbox-first design. Always INSERTs into `email_log` as `queued` BEFORE
  attempting `mail()`. Updates row to `sent` or `failed` based on result.
* Template renderer: loads `templates/emails/<name>.php`, extracts vars
  into the local scope, the template defines `$subject` and `$body`.
* `From:` address comes from `MAIL_FROM_EMAIL` / `MAIL_FROM_NAME` env vars,
  with sensible defaults derived from `APP_URL`.
* RFC 2047 encoded-word for non-ASCII subjects.
* No HTML in v1 — text/plain only. Real provider (Mailgun / SES) is wired
  in a future session.
* Three templates seeded: `invite.php`, `reset.php`, `reset_confirm.php`.

**New public auth flows (`templates/auth/`):**

* `login.php` — REWRITTEN. Email field replaces username field. Validates
  email format. Shows distinct success messages for `?logged_out=1`,
  `?timeout=1`, and `?reset=1` (post-reset). New "Forgot your password?"
  link below the form.
* `forgot_password.php` — accepts email, INSERTs into `password_resets`,
  calls `Mailer::send('reset', ...)`. **Anti-enumeration**: always shows
  the same "if an account exists" success message regardless of whether
  the email matches a real user, so attackers cannot probe for valid
  accounts. Reset tokens expire in 2 hours.
* `reset_password.php` — accepts `?token=` from the email, validates
  (exists, not used, not expired, user still active), shows the password
  form. On submit: enforces 10-char minimum + match, hashes with bcrypt,
  resets `failed_login_count` and `locked_until`, marks the token used,
  invalidates all other outstanding reset tokens for the same user in the
  same transaction, sends a confirmation email, redirects to login with
  `?reset=1` flag.
* `setup_password.php` — same flow but for `user_invites` instead of
  `password_resets`. On accept, creates the user row with the role_id,
  manager_id, linked_caregiver_id, linked_client_id captured at invite
  time. Handles the rare case where a user with that email already exists
  (updates in place) so the invite is robust against races.
* All four templates use the existing CSRF token machinery and the existing
  `auth-card` styles, so they look native to the existing /login design.

**Front controller (`public/index.php`):**

* Four new public routes wired: `/login` (already existed), `/forgot-password`,
  `/reset-password`, `/setup-password`. None of these require authentication.

### End-to-end verification on dev

Smoke tests run during the deploy:

* `GET /login` → 200
* `GET /forgot-password` → 200
* `GET /reset-password?token=invalid` → 200 (shows "invalid or expired" alert)
* `GET /setup-password?token=invalid` → 200 (shows "invalid or expired" alert)
* `GET /admin` (unauthenticated) → 302 to /login
* `POST /login` with `email=ross@intelligentae.co.uk` + existing password
  → 302 to /admin; subsequent `GET /admin` → 200
* `POST /forgot-password` with ross's email → 200, success message rendered,
  `email_log` row id 1 created with status `sent`
* Reset link extracted from `email_log.body_text`, visited, new password
  POSTed → 200 with success alert
* `POST /login` with NEW password → 302 to /admin
* Password restored to documented `TchAdmin2026x` via existing `database/seeds/create_admin.php`
* `POST /login` with original password → 302 to /admin
* `GET /admin/people/review` and `GET /admin/enquiries` (authed) → both 200
* `activity_log` shows 5 entries spanning the test cycle: login, password_reset_requested,
  password_reset_completed, login (temp pass), login (restored pass)

### Backups taken

* `database/backups/users_pre_migration_005.sql` on dev — `users` + `login_log`
  table dumps before migration ran. 90 lines.

### Deployment

* Files uploaded to `~/public_html/dev-TCH/dev/` via scp. NOT yet promoted to prod.
* Migration ran against the shared dev/prod database, so the schema changes are
  also visible to prod — but prod still has the OLD `auth.php` / `index.php` /
  `templates/auth/login.php`, so prod login still uses the username field. Prod
  promotion is held back until Session B is complete and the admin user-mgmt
  pages are also tested.
* CDN cache purge not required — only dev was touched at the file level.

### Out of scope this session (deferred to B / C per the locked plan)

* Admin UIs: `/admin/users`, `/admin/roles`, `/admin/activity`, `/admin/email-log`,
  the impersonate button + banner. Session B.
* Wiring `requirePagePermission()` calls into existing handlers (dashboard,
  enquiries, people_review, names_reconcile, reports). Session B.
* Hierarchy filtering on caregiver/client list pages. Session B.
* Audit-log mutation sweep across every existing handler. Session C.

## [0.6.0] - 2026-04-10

### Added — Public-facing homepage rebuild

The TCH public homepage has been rewritten to be a real customer-facing
landing page rather than the placeholder marketing skeleton it was before.

**New schema (`database/004_regions_and_enquiries.sql`):**

* `regions` table — one row per geographic region TCH operates in.
  Holds phone numbers, emails, physical address, postal code, service-area
  description, hero headline override, office hours, and social URLs.
  Seeded with Gauteng (placeholder phone `XXX XXX XXXX`, placeholder email).
  The public homepage now loads its primary region from this table, so
  contact details are configurable without code changes. Future per-region
  pages (Western Cape, KZN, etc.) will reuse the same template with a
  different region row.
* `enquiries` table — captures public form submissions with full audit
  metadata (IP, user agent, referrer, source page), POPIA consent fields,
  and a status workflow (`new` → `contacted` → `converted`/`closed`/`spam`).
  Free-text notes append-only with audit stamps.

**New homepage (`templates/public/home.php`):**

* Hero — "Trusted Caregivers, Placed Where You Need Them" with the placeholder
  Tuniti-style hero background image (CSS gradient fallback when image is absent).
* Stats bar driven by live caregivers/clients counts.
* **Care Services** block — six cards covering the five named Tuniti services
  (Full-Time, Post-Op, Palliative, Respite, Errand) plus a "Not Sure" CTA.
* **Why TCH** block — four differentiators: Verified/Vetted/Trained,
  Matched-not-just-Sent, Cover When Life Happens, One Trusted Brand.
* **How It Works** — three-step process.
* **Trust block** — gradient panel: "You're not just hiring a person, you're
  joining a network."
* **Enquiry form** — full POPIA-compliant inquiry form with CSRF token,
  honeypot for bots, required-field validation, dropdown for care type,
  urgency selector, free-text message, mandatory consent checkbox.
* **Contact section** — phone / email / area, all from the regions table.

**Form handler (`templates/public/enquire_handler.php`):**

* Validates CSRF, drops bot submissions silently, server-side sanitises and
  truncates inputs, validates care type against an allow-list, captures
  audit metadata, writes to `enquiries`, redirects back to the homepage
  with `?enquiry=success#enquire` (or `?enquiry=error`).

**Admin enquiries inbox (`templates/admin/enquiries.php`):**

* List view filterable by status with badge counts dashboard.
* Detail view with full submitter details, audit metadata, status workflow
  (set status, add audit-stamped notes).
* New sidebar entry under "Inbox".

**Footer refresh:**

* Footer now reads phone, email, and service area from the regions table
  via `$footerRegion` variable. Falls back gracefully on standalone pages.

**Image prompts (`docs/Brand_Image_Prompts.md`):**

* Eight ChatGPT/DALL-E prompts for the imagery the homepage needs (hero +
  five service tiles + two optional support images). Each prompt includes
  the South African demographic guidance (caregivers predominantly Black,
  clients typically older White Afrikaans), tone guidance, anti-cliché
  rules, exact filenames, and aspect ratios. Page works without the images
  thanks to CSS gradient fallbacks — imagery is an upgrade not a blocker.

**Deployed to dev:**

* Migration 004 applied to the shared dev/prod DB
* New homepage live at `https://dev.tch.intelligentae.co.uk/`
* Admin enquiries inbox live at `/admin/enquiries`
* Form submission tested via route — POST handler responding correctly

## [0.5.2] - 2026-04-10

### Added — Tranches 2–9 enrichment (109 caregivers)

The remaining 8 Tuniti intake PDFs (Tranches 2–9) have been read, cross-matched
against the existing 109 caregivers in those tranches, and enriched with PDF
data. All 109 records now have:

* Full PDF data (title, initials, ID/passport, DOB, gender, nationality, home
  and other languages, mobile, secondary mobile where present, email, complex
  estate, full address, NoK details, lead source) adopted as canonical per
  Ross's locked-in decision.
* `import_review_state = 'pending'` so they appear in the admin review page.
* Two attachments per person — the source PDF page and the cropped portrait.

**New lead sources surfaced and added to the lookup:**

* `website` — used by 9 candidates across multiple tranches
* `advertisement` — used by 3 Tranche 3 candidates

**Cross-tranche observations flagged in `import_notes`:**

* Two records named "Nelly", three records with similar names ("Siphilisiwe",
  "Siphathisiwe", "Sthenjisiwe"), two "Thandi"s — confirmed as different people
  by DOB/ID, no merge.
* One record (Ntombifikile Octavia Mhlongo, id 103) had a clearly invalid PDF
  DOB of `0005-08-03` — DOB left as the existing DB value, flagged.
* Several records share addresses or nok contact numbers with other records —
  flagged for review (possible household links).
* Generic "Social_media" lead source on ~15 records left blank for review with
  a note (TODO: ask each candidate which platform).
* Numerous typos preserved verbatim (Pretoira, Pretroia, Johnesburg, Sweto,
  Acradia, Mamalodi, Bryaston, Spedi, Speed, Setswane, Yoryba, Xitsongo,
  Xitsomga, Hammenskraal, etc.) — each one flagged in `import_notes`.

**Schema/data files added:**

* `database/003c_tranches_2_9_enrichment.sql` — the one-shot enrichment script
  for all 8 tranches. Each tranche is its own transaction so a failure in one
  does not block the others.
* `tools/intake_parser/upload_photos.py` — staging script that reorganises the
  rendered portraits into per-person folders ready for SCP.

**Deployed to dev:**

* Migration 003c applied to the shared dev/prod database (109 UPDATEs + 218
  attachment INSERTs).
* All 9 source PDFs uploaded to `public/uploads/intake/`.
* All 109 cropped portraits uploaded to `public/uploads/people/TCH-NNNNNN/photo.png`.
* Pre-enrichment backup of `caregivers` and `attachments` tables preserved at
  `database/backups/caregivers_pre_tranches_2_9.sql` on the server.
* Post-load verification: 123 caregivers in `pending` review state, 246
  attachments total, all 9 tranches consistently labelled.

## [0.5.1] - 2026-04-10

### Added — Tranche 1 enrichment + admin review page

**Tranche 1 imported and enriched** against the existing 14 caregivers
(ids 1–14):

* All 14 Tranche 1 candidates from the Tuniti PDF were already in the
  caregivers table as name-only stubs (12 of 14) or with workbook data
  that conflicted with the PDF (Jolie / Mukuna). Per Ross's decision the
  Tuniti PDF data was adopted as canonical and the workbook values were
  preserved verbatim in `import_notes` for audit.
* Special handling for id 5 (Jovani Mukuna Tshibingu): the DB full_name
  "Jovani" was kept because the PDF title spells it "Jonvai" — a typo
  confirmed by the PDF's own Known As field.
* All 14 enriched rows set to `import_review_state = 'pending'` so they
  appear in the new admin review queue.
* 28 attachments inserted: 14 Original Data Entry Sheet rows pointing
  to the source PDF page, 14 Profile Photo rows pointing to the cropped
  portraits.

**Tranche label standardisation** (system-wide):

* `1st Intake` → `Tranche 1`, `2nd Intake` → `Tranche 2`, … `9th Intake`
  → `Tranche 9`. Affects 113 caregivers across all 9 cohorts. The `N/K`
  label is left alone — unknown remains unknown.

**New admin page: Person Review** (`/admin/people/review`):

* Lists all caregivers in `import_review_state = 'pending'`, filterable
  by tranche, with photo thumbnail, TCH ID, full name, known_as,
  student_id, attachment count and a notes flag.
* Detail view (`?id=N`) renders a person card styled to mirror the
  Tuniti intake PDF layout: photo top-left, two-column field grid
  (Personal / Contact / Address / Emergency Contact), attachments list,
  import-notes panel and human-notes panel.
* Approve / Reject actions, CSRF-protected. Approve clears
  `import_review_state` and appends an audit line; Reject sets the
  state to `rejected` and appends an audit line.
* Sidebar nav updated with "Person Review" entry under Data.

**Migration patches:**

* `database/003a_finish_migration.sql` — completes migration 003 after
  the original `tch_id` GENERATED column failed under MariaDB 10.6
  (auto-increment columns can't be referenced by generated columns).
  `tch_id` is now a regular VARCHAR(20) populated by application code,
  with a unique index. Existing 140 rows backfilled.
* `database/003b_tranche_1_enrichment.sql` — the one-shot enrichment
  script described above.

**Deployed to dev** (`https://dev.tch.intelligentae.co.uk/`):

* Migration 003 + 003a + 003b applied to the shared dev/prod database
* 14 photos uploaded to `public/uploads/people/TCH-NNNNNN/photo.png`
* Source PDF uploaded to `public/uploads/intake/Tranche 1 - Intake 1.pdf`
* Pre-migration backup of caregivers table preserved at
  `/tmp/caregivers_pre_migration_003.sql` on the server

## [0.5.0] - 2026-04-10

### Added — Person Database (foundation for unified caregiver record)

This release lays the foundation for collapsing student / caregiver / lookup-name
records into a single canonical Person record per individual. Goal: eliminate
the multi-name lookup as soon as the new model is fully populated.

**Schema migration `database/003_person_database.sql`** (additive where possible):

* New lookup tables (replace hard-coded ENUMs, ready for the future config admin page):
  * `person_statuses` — seeded with: Lead, Applicant, Student, In Training,
    Qualified, Available, Placed, Inactive
  * `lead_sources` — seeded with: Facebook, TikTok, Instagram, LinkedIn,
    Walked In, Phoned Us, Emailed Us, Referral, Word of Mouth, Other, Unknown
  * `attachment_types` — seeded with: Original Data Entry Sheet, Profile Photo,
    ID Document, Passport, Proof of Address, Qualification Certificate, Other
* New `attachments` table — files attached to a person (PDFs, ID copies,
  photos), typed via `attachment_types`. Files live on disk under
  `public/uploads/people/<tch_id>/`.
* `caregivers` table extended with all Tuniti intake fields:
  * Personal: `title`, `initials`
  * Contact: `secondary_number`, `complex_estate`
  * NoK: `nok_email`, plus full `nok_2_*` block for multi-value rows
  * Lead source: `lead_source_id` FK + `referred_by_name` / `referred_by_contact`
* `caregivers.tch_id` — immutable, human-facing person identifier (`TCH-000001`),
  generated column derived from `id`. Survives marriage / name corrections.
  Replaces `full_name` as the practical identity field.
* `caregivers.status` ENUM replaced with `status_id` FK → `person_statuses`.
  Existing values backfilled before drop.
* `caregivers.import_notes` (machine-generated) and `caregivers.notes` (human)
  added — split deliberately so audit data and human commentary stay separate.
* `caregivers.import_review_state` ENUM (`pending` / `approved` / `rejected`) —
  filters the import review queue. NULL for records not from import.
* Legacy `caregivers.source` column dropped per session decision (option C).
  Existing values are preserved into `import_notes` immediately before the drop.

**Tuniti intake PDF parser** (`tools/intake_parser/parse_intake.py`):

* Python + PyMuPDF, runs locally. Reads a Tuniti intake PDF and emits:
  JSON records, SQL load file, cropped portrait per candidate, full-page
  reference render per candidate.
* Auto mode tries text extraction; falls back to scaffold mode if the PDF has
  no text layer (current Tuniti exports are image-only).
* `--from-json` mode reads a hand-built or scaffolded records JSON, still
  renders photos and emits SQL.
* Output goes to `tools/intake_parser/output/`.

**Tranche 1 imported** (14 candidates):

* All 14 land with `status_id = 'lead'` and `import_review_state = 'pending'`,
  ready for human review on the new admin page before promotion to a real status.
* Each gets two attachments: Original Data Entry Sheet (PDF page reference)
  and Profile Photo (cropped portrait).
* `import_notes` flags the assumptions made during extraction: typos
  (Preotia, Johnnesburg, Pretoira West, Zimbabwan), geographic
  inconsistencies, the off-tranche student `202603-1`, the Akhona Mkize
  multi-NoK split, and three records with the generic "Social_media" lead
  source that needs follow-up.

**Updated dependent code** to match the new schema:

* `templates/admin/dashboard.php` — `placed` count now joins `person_statuses`
* `templates/admin/names.php` — `cg_status` display joins `person_statuses`
* `database/seeds/ingest.php` — INSERT no longer references the dropped
  `source` column; uses `status_id` lookup; preserves any workbook `source`
  value into `import_notes`

### Added — TODOs

Logged in `docs/TCH_Ross_Todo.md` (items 11–18):

* Config admin page for managing all lookups
* Status promotion gates (validation per status)
* Referrer / affiliate model
* Field-level role-based edit permissions
* Person record card view (mirroring the PDF layout)
* Retire `name_lookup` table once unified person model is complete
* `tch_id` immutable identifier (DONE in this release)
* Replace placeholder portraits with full-quality photos

### Manual rollback notes

If migration 003 needs to be rolled back without git:

1. The migration is wrapped in `SET FOREIGN_KEY_CHECKS = 0` / `1` blocks but
   not in a transaction (DDL in MySQL auto-commits). To revert manually:
   * `DROP TABLE attachments, attachment_types, lead_sources, person_statuses;`
   * `ALTER TABLE caregivers DROP COLUMN tch_id;`
   * `ALTER TABLE caregivers DROP COLUMN status_id;`
   * `ALTER TABLE caregivers ADD COLUMN status ENUM('In Training','Available','Placed','Inactive') NOT NULL DEFAULT 'In Training';`
   * `ALTER TABLE caregivers ADD COLUMN source VARCHAR(50) DEFAULT NULL;`
   * Drop the new caregivers columns: `title`, `initials`, `secondary_number`,
     `complex_estate`, `nok_email`, `nok_2_name`, `nok_2_relationship`,
     `nok_2_contact`, `nok_2_email`, `lead_source_id`, `referred_by_name`,
     `referred_by_contact`, `import_notes`, `notes`, `import_review_state`
2. Source values that were preserved into `import_notes` cannot be split back
   out automatically — they remain visible in the notes column.
3. Backfilled `status_id` mapping is reversible by reading the
   `person_statuses` codes before dropping the lookup table.

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
