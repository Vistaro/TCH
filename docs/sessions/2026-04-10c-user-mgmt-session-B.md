# Session: 2026-04-10 (C) — User Management Session B

**Owner:** Ross
**Project:** TCH Placements
**Branch worked on:** `dev`
**Outcome:** v0.8.0 — Admin user-management UIs, roles + permissions matrix
editor, activity log + email outbox viewers, full impersonation flow with
persistent banner, and existing-handler retrofit. NOT yet promoted to prod
(held until Session C closes the audit-log integration sweep).
**Position in plan:** Session B of 3 in the locked User Management +
RBAC + Audit + Impersonation build.

---

## Agenda (in order)

1. Wire new admin routes (parametric + static) in `public/index.php`
2. Update sidebar nav in `templates/layouts/admin.php` to be permission-driven
3. Add persistent impersonation banner to admin layout
4. Build `/admin/users` (list, filter, deactivate, invite button)
5. Build `/admin/users/invite` (form + Mailer + dev fallback link)
6. Build `/admin/users/{id}` (detail/edit + force reset + impersonate button)
7. Build `/admin/users/{id}/impersonate` (re-auth form + start handler)
8. Build `/admin/roles` (list)
9. Build `/admin/roles/{id}/permissions` (CRUD checkbox grid + audit logging)
10. Build `/admin/activity` (log viewer with filters + pagination)
11. Build `/admin/email-log` + `/admin/email-log/{id}` (outbox)
12. Add CSS for banner, badges, info alerts
13. Retrofit existing handlers (`enquiries`, `people_review`, `names_assign`)
    with `userCan()` gates and `logActivity()` mutation calls
14. Migrate every existing route in `index.php` from `requireAuth()` to
    `requirePagePermission(pageCode, action)`
15. Upload, syntax-check, smoke test
16. End-to-end test: invite → setup → login as Manager → permission gating →
    impersonation flow → audit trail
17. Update CHANGELOG, write these notes

---

## What was built

### Front controller — `public/index.php`

Two-pass routing now: parametric routes via `preg_match` BEFORE the static
switch, then the switch handles flat routes.

Parametric routes:
- `^admin/users/(\d+)$` → `users_detail.php`
- `^admin/users/(\d+)/impersonate$` → `users_impersonate.php`
- `^admin/roles/(\d+)/permissions$` → `roles_permissions.php`
- `^admin/email-log/(\d+)$` → `email_log_detail.php`

Static routes added:
- `/admin/users`, `/admin/users/invite`, `/admin/impersonate/stop`
- `/admin/roles`
- `/admin/activity`
- `/admin/email-log`

Every existing admin route migrated from `requireAuth()` to
`requirePagePermission($pageCode, $action)` with the appropriate verb
(read for all GETs; the names/assign route uses `edit`).

### Admin layout — `templates/layouts/admin.php`

- Sidebar nav now wraps every group + every link in `userCan()` checks.
  Users only see the pages they actually have access to.
- New "Admin" section with Users / Roles / Activity Log / Email Outbox.
- Persistent impersonation banner injected at the very top whenever
  `isImpersonating()` returns true. Sticky-positioned, red, z-index 1000.
  Shows real user → impersonated identity → "End impersonation" link.
- `admin-user` block in the header now falls back to email/username when
  `full_name` is empty (defensive — happens for newly invited users
  before they set up).

### `/admin/users` — `users_list.php`

- Lists every user via `getVisibleUserIds()` (Super Admin/Admin see all,
  Manager sees their hierarchy).
- Filter UI: search (email/name), role dropdown, active/inactive.
- Stats cards: active count, pending invite count.
- Per-row actions: View, Deactivate / Reactivate.
- Self-deactivation blocked with flash error.
- Status badges: Active / Locked / Unverified / Inactive.
- "Invite User" button gated on `users.create`.
- Mutations log to `activity_log`.

### `/admin/users/invite` — `users_invite.php`

- Form fields: email, full_name, role_id, optional manager_id,
  optional linked_caregiver_id, optional linked_client_id.
- Refuses if an active user with that email already exists.
- Generates raw token + SHA-256 hash, INSERTs `user_invites`,
  calls `Mailer::send('invite', ...)`. 72-hour expiry.
- On success, displays the **dev fallback link** (raw setup-password URL)
  inline so the developer can copy it directly even when shared-host
  `mail()` drops the message.

### `/admin/users/{id}` — `users_detail.php`

- Header card with avatar (initial), full name, email, role badge,
  status badge (Active/Locked/Unverified/Inactive), last login.
- **Profile section**: editable form for full_name, role, manager,
  linked caregiver/client. Email is read-only (changes not supported in v1).
  UPDATE captures before/after JSON snapshots in the audit log.
- **Account Actions section**:
    - Send Password Reset Email (creates `password_resets` row, calls mailer,
      sets `must_reset_password=1`, shows dev fallback URL in flash)
    - Unlock (only shown when `locked_until` > NOW())
    - Impersonate User (Super Admin only, hidden when target is current
      user or inactive)
- **Recent Activity panel**: 20 rows from `activity_log` where the user is
  either `real_user_id` OR `impersonator_user_id`. Catches both their own
  actions and any time someone impersonated them.

### `/admin/users/{id}/impersonate` — `users_impersonate.php`

- GET renders re-auth form (warning about persistent banner +
  audit logging of both identities).
- POST calls `startImpersonation($targetId, $reauthPassword)`.
- Hard pre-checks: must be Super Admin, must not already be impersonating,
  cannot impersonate self.
- On success → redirect to `/admin` where the banner appears.
- `/admin/impersonate/stop` (handled directly in front controller) calls
  `stopImpersonation()` and redirects back.

### `/admin/roles` + `/admin/roles/{id}/permissions`

- `roles_list.php` — table of all 5 roles with user count, "pages with
  any access" count, Edit/View Permissions button.
- `roles_permissions.php` — full pages × CRUD checkbox grid (17 × 4 = 68
  checkboxes per role). UPSERT every row in a transaction. Captures
  before/after via parallel SELECTs for the audit log.
- **Hard guard**: Super Admin role (id 1) cannot have permissions modified
  through this UI even if `roles.edit` is granted. Form renders with
  disabled inputs; POSTs against role_id=1 are rejected with a flash.
  Prevents anyone from accidentally locking everyone (including
  themselves) out of the system.
- v1 does NOT expose role create/delete — the 5 system roles are fixed.

### `/admin/activity` — `activity_log.php`

- Filters: action dropdown (DISTINCT actions from log), entity_type
  dropdown, user_id (matches both real and impersonator), date range.
- 50 entries per page, prev/next pagination preserving filters.
- Columns: When, Actor (real), Impersonator (badge if set), Action,
  Page, Entity, Summary, IP.
- The Impersonator column makes "who really did this?" obvious at a glance.

### `/admin/email-log` + `/admin/email-log/{id}` — outbox

- List view: filterable by status (queued/sent/failed) + template.
  50/page pagination. Status badges. Click View → detail.
- Detail view: full envelope (from/to/subject/template/status/timing) +
  email body verbatim in a `<pre>` block. Reset/invite links can be
  copied directly when SMTP fails to deliver.

### Existing handler retrofit

- `enquiries.php`: both POST handlers (set_status, add_note) gate on
  `userCan('enquiries', 'edit')` and call `logActivity()` with action
  `enquiry_status_changed` (with old/new snapshot) or `enquiry_note_added`.
  Replaced legacy `$user['username']` with `$user['email']` in the audit
  stamp lines.
- `people_review.php`: approve/reject mutations gated on
  `userCan('people_review', 'edit')` and log as `person_approved` /
  `person_rejected` with before/after import_review_state.
- `names_assign.php`: name lookup updates log as `name_lookup_assigned`
  with before/after billing_name snapshot.

### CSS additions (`public/assets/css/style.css`)

- `.impersonation-banner` + inner + stop link — sticky red banner.
- `.badge-danger` — for failed email statuses.
- `.alert-info` — for the dev fallback link blocks on the invite form.

---

## End-to-end verification

### All admin pages return 200 (as ross / Super Admin)

```
GET /admin/users                       → 200
GET /admin/users/invite                → 200
GET /admin/users/1                     → 200
GET /admin/roles                       → 200
GET /admin/roles/1/permissions         → 200
GET /admin/roles/2/permissions         → 200
GET /admin/activity                    → 200
GET /admin/email-log                   → 200
GET /admin/email-log/1                 → 200
GET /admin                             → 200  (legacy retrofit works)
GET /admin/people/review               → 200
GET /admin/enquiries                   → 200
GET /admin/names                       → 200
GET /admin/reports/caregiver-earnings  → 200
GET /admin/reports/client-billing      → 200
GET /admin/reports/days-worked         → 200
```

### Invite + permission gating + impersonation cycle

1. POST `/admin/users/invite` (email=testmanager@example.com, role=Manager)
   → "Invitation sent" + dev fallback link displayed
2. Extracted setup URL from `email_log` (template=invite)
3. GET `/setup-password?token=...` → "Welcome, Test Manager"
4. POST setup with password `TestMgr2026Pwd` → "account is ready"
5. POST `/login` as testmanager → 302 → /admin
6. As Manager:
   - `/admin` → 200
   - `/admin/people/review` → 200
   - `/admin/enquiries` → 200
   - `/admin/activity` → 200 (Manager has all admin pages except users/roles)
   - **`/admin/users` → 403** ← gating works
   - **`/admin/roles` → 403** ← gating works
7. POST `/login` as ross → back to Super Admin session
8. GET `/admin/users/2/impersonate` → re-auth form rendered
9. POST `/admin/users/2/impersonate` with ross's password → 302 → /admin
10. GET `/admin` → 200 + **"Impersonation active" banner present in HTML**
11. While impersonating Test Manager:
    - **`/admin/users` → 403** ← effective permissions are Manager's
12. GET `/admin/impersonate/stop` → 302 → /admin
13. GET `/admin/users` → 200 ← Super Admin restored

### Activity log audit verification

After all the above, `activity_log` has 12 rows:

| id | action                   | real | imp  | summary                                      |
|---:|--------------------------|-----:|-----:|----------------------------------------------|
| 11 | impersonate_stop         | 1    | NULL | Impersonation stopped: was testmanager       |
| 10 | impersonate_start        | 2    | 1    | Impersonation started: ross -> testmanager   |
|  9 | login                    | 2    | NULL | Logged in: testmanager                       |
|  8 | user_invite_accepted     | NULL | NULL | Invite accepted for testmanager              |
|  7 | user_invited             | 1    | NULL | Invited testmanager as Manager               |
| 1-6| (Session A events)       |      |      |                                              |

Row 10 is the key one: `real_user_id=2` (effective = Test Manager),
`impersonator_user_id=1` (Ross). A query
`WHERE real_user_id = 2 OR impersonator_user_id = 2` correctly returns
everything that happened TO/AS Test Manager, including the impersonation
session.

---

## Files created or modified

### Front controller
- `public/index.php` — two-pass routing, all admin routes migrated to
  `requirePagePermission()`, new routes wired

### Layout
- `templates/layouts/admin.php` — permission-driven sidebar + impersonation banner

### CSS
- `public/assets/css/style.css` — banner, badge-danger, alert-info

### New admin templates (9)
- `templates/admin/users_list.php`
- `templates/admin/users_invite.php`
- `templates/admin/users_detail.php`
- `templates/admin/users_impersonate.php`
- `templates/admin/roles_list.php`
- `templates/admin/roles_permissions.php`
- `templates/admin/activity_log.php`
- `templates/admin/email_log_list.php`
- `templates/admin/email_log_detail.php`

### Retrofitted templates
- `templates/admin/enquiries.php` — userCan gates + logActivity
- `templates/admin/people_review.php` — userCan gates + logActivity
- `templates/admin/names_assign.php` — logActivity

### Docs
- `CHANGELOG.md` — v0.8.0 entry
- `docs/sessions/2026-04-10c-user-mgmt-session-B.md` — this file

---

## Test data left on dev

`testmanager@example.com` / `TestMgr2026Pwd` (Manager role) was created
during the smoke test and left on the dev database so Ross can try the
impersonation flow himself.

The activity log on dev has the 12 events from the test cycle. These are
useful test data for the activity log viewer.

Cleanup: delete via `/admin/users` deactivate button or by hand:
```sql
DELETE FROM users WHERE email = 'testmanager@example.com';
DELETE FROM user_invites WHERE email = 'testmanager@example.com';
```

---

## What's next — Session C

Per the locked plan:

1. Mechanical sweep through every existing handler in `templates/admin/`
   and `database/seeds/` to add `logActivity()` calls for every mutation
   that Session B didn't already cover
2. Add detailed before/after JSON snapshots where missing
3. Verify the activity log viewer shows everything (no gaps)
4. Deploy to dev → test → promote v0.7.0 + v0.8.0 + v0.9.0 (Session C) to
   prod as a single block

Things to consider for Session C:
- The existing `database/seeds/ingest.php` and `database/seeds/reconcile.php`
  scripts are CLI tools that bypass the web layer entirely — `logActivity()`
  there would log against `real_user_id=NULL` (anonymous). That's fine
  since they document themselves via `import_notes` columns. Probably
  out of scope for the audit log sweep.
- The remaining mutation paths in the public flows are already logged
  in Session A (login, logout, password_reset_requested, password_reset_completed,
  user_invite_accepted).
- Dashboard, reports, names list (read-only views) don't need logActivity.
- Hierarchy filtering could be retrofitted to dashboard if/when Manager
  users start being used in earnest.

---

## Re-entry instructions for next session

1. Branch is `dev`. v0.8.0 is committed and pushed but NOT yet on prod.
2. Schema is unchanged from Session A — no new migration required.
3. Test Manager user exists on dev for impersonation testing.
4. Session C is pure retrofit + sweep work — no new pages.
5. After Session C, deploy v0.7.0+v0.8.0+v0.9.0 as a single block to prod
   via rsync from dev → tch/. Take a server-side backup first.
6. Dev login: `ross@intelligentae.co.uk` / `TchAdmin2026x`
7. Dev test login: `testmanager@example.com` / `TestMgr2026Pwd`

---

## Notes / lessons captured

- **Two-pass routing** (parametric regex before static switch) is a clean
  pattern for adding numeric URL segments to a flat router without
  rewriting the whole controller. Reusable for any future `/admin/X/{id}`.
- **Permission-driven sidebar** is the right pattern — users see exactly
  what they can use, no dead links. The `userCan()` calls in the layout
  are cheap (one DB query per check, with the same prepared statement
  reused) and the page renders normally even if every `if` is false.
- **Hard guard on Super Admin role permissions** — the matrix UI explicitly
  refuses to mutate role_id=1 even if `roles.edit` is checked. Without
  this guard, an admin could accidentally lock everyone out by clearing
  Super Admin permissions. The guard is in addition to the auth.php-level
  guarantee that Super Admin is exempt from lockout.
- **Impersonation correctly inherits the target's permissions** — verified
  by trying to GET /admin/users while impersonating a Manager, which
  returns 403 because Manager doesn't have that page. The session's
  `role_id` and `role_slug` are swapped to the target's during impersonation,
  so `userCan()` and `requirePagePermission()` Just Work.
- **Dev fallback link on the invite form** is essential — shared-host
  `mail()` is unreliable enough that without an inline copy of the URL
  the admin would have to go dig in `email_log` after every invite.
  Ditto on the force-reset action.
- **The audit convention** (real_user_id = effective, impersonator_user_id
  = the human, only set when they differ) was the right call — it makes
  the user-detail page's "Recent Activity" query a single OR clause that
  catches everything relevant.
