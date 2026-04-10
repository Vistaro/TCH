# Session: 2026-04-10 (B) — User Management Session A

**Owner:** Ross
**Project:** TCH Placements
**Branch worked on:** `dev`
**Outcome:** v0.7.0 — Schema, auth library, mailer, and public auth flows
shipped to dev. Email-based login works end-to-end with the existing
`ross@intelligentae.co.uk` credential. Reset flow verified via the email
outbox table. Not yet promoted to prod (held until Session B is complete).
**Position in plan:** Session A of 3 in the locked User Management +
RBAC + Audit + Impersonation build (`docs/TCH_Ross_Todo.md`).

---

## Agenda (in order)

1. Re-read previous session notes + locked build plan
2. Read existing auth/users/routing code to understand the surface area
3. Write migration `005_users_roles_hierarchy.sql`
4. Rewrite `includes/auth.php` for email login + impersonation
5. Create `includes/permissions.php` (CRUD checks, hierarchy, logActivity)
6. Create `includes/mailer.php` (outbox-first, PHP mail())
7. Create three email templates (invite, reset, reset_confirm)
8. Rewrite login template; create forgot/reset/setup-password templates
9. Wire new routes in `public/index.php`
10. Upload + syntax-check on server
11. Backup + run migration
12. End-to-end smoke test of login, forgot, reset, restore
13. Update CHANGELOG and write these session notes

---

## What was built

### Migration 005 (`database/005_users_roles_hierarchy.sql`)

Idempotent additive migration. Re-runnable. All column adds use
INFORMATION_SCHEMA conditional checks; all INSERTs use `INSERT IGNORE`;
all FK adds are conditional.

New tables created:

| Table | Purpose |
|---|---|
| `roles` | 5 system roles seeded: super_admin, admin, manager, caregiver, client |
| `pages` | 17 pages registered for permission gating |
| `role_permissions` | role × page × CRUD verb (51 rows seeded by default matrix) |
| `user_invites` | Pending invitations; SHA-256 token hash; carries role/manager/links |
| `password_resets` | Reset tokens; SHA-256 hash; single-use; records requested_ip |
| `email_log` | Outbox of every send attempt (queued → sent/failed) |
| `activity_log` | Mutations audit log (action, page, entity, before/after JSON, IPs) |

Existing `users` table extended (additive):
- `role_id` (FK roles)
- `manager_id` (self-FK for hierarchy)
- `linked_caregiver_id` (FK caregivers)
- `linked_client_id` (FK clients)
- `email_verified_at`
- `failed_login_count` / `locked_until`
- `must_reset_password`
- Email made `NOT NULL UNIQUE`
- Foreign key constraints `fk_users_role`, `fk_users_manager`,
  `fk_users_caregiver`, `fk_users_client`

The existing `ross` user row was migrated in place: `role_id=1`,
`email_verified_at=NOW()`. Username, password, and email column unchanged.

Default permission matrix (51 rows):
- **Super Admin** (id 1): full CRUD on all 17 pages
- **Admin** (id 2): full CRUD on all 17 pages
- **Manager** (id 3): full CRUD on 15 pages (everything except `users` and `roles`)
- **Caregiver** (id 4): read+edit on `self_service` only
- **Client** (id 5): read+edit on `self_service` only

Page list (with stable codes for `requirePagePermission()`):
`dashboard`, `caregivers`, `clients`, `roster`, `billing`, `people_review`,
`names_reconcile`, `enquiries`, `reports_caregiver_earnings`,
`reports_client_billing`, `reports_days_worked`, `users`, `roles`,
`activity_log`, `email_log`, `config`, `self_service`.

### `includes/auth.php` (rewritten)

Key changes from the old version:
- `attemptLogin()` now takes an email (was username)
- Lockout enforcement: 10 failed attempts → 15-min lockout. Super Admin
  exempt per the spec
- Session keys added: `real_user_id`, `impersonator_user_id`, `email`,
  `role_id`, `role_slug`, `role_name`. Old `username` and `role` keys
  retained as legacy compat
- `currentUser()` now aliases `currentEffectiveUser()` (returns the
  impersonated identity when impersonation is active)
- `currentRealUser()` always returns the human at the keyboard by
  re-fetching from the DB using `real_user_id`
- `isImpersonating()`, `startImpersonation()`, `stopImpersonation()` —
  full impersonation lifecycle. `start` enforces:
    - Real user must be Super Admin
    - Cannot already be impersonating
    - Cannot impersonate yourself
    - Re-auth: caller must supply their own current password
    - Target must exist and be active
- `requireAuth()` and `requireRole()` retained as legacy shims so the
  pages built before this session keep working (dashboard, enquiries,
  people_review, names, reports)
- `logout()` now records a `logout` action to `activity_log`

### `includes/permissions.php` (new)

- `userCan($pageCode, $action)` — looks up role × page × verb
- `requirePagePermission($pageCode, $action)` — auth gate + permission check + 403
- `isSuperAdmin()` — checks the REAL user, not the effective one (so an
  impersonated session cannot start a nested impersonation)
- `getVisibleUserIds($forUserId)` — recursive BFS down `users.manager_id`.
  Super Admin + Admin bypass and see everyone
- `getVisibleCaregiverIds()` / `getVisibleClientIds()` — apply hierarchy
  via `linked_caregiver_id` / `linked_client_id` joins. Caregiver/client
  self-service users see only their own linked record
- `logActivity()` — central audit recorder. Wraps the INSERT in try/catch
  so an audit failure never breaks the user flow. Captures `real_user_id`
  + `impersonator_user_id` correctly for both normal and impersonated
  sessions

### `includes/mailer.php` (new)

Outbox-first design: every send attempt is INSERTed into `email_log` as
`queued` BEFORE `mail()` is called, then flipped to `sent` or `failed`
based on the result. This means a developer can ALWAYS retrieve the link
from the email_log table even when shared-host `mail()` silently drops
messages, which is the routine failure mode here.

- `Mailer::send($template, $toEmail, $toName, $vars, $relatedUserId)`
- Templates live in `templates/emails/<name>.php` and define `$subject` + `$body`
- From address derived from `MAIL_FROM_EMAIL` / `MAIL_FROM_NAME` env vars
  with sensible defaults from `APP_URL`
- RFC 2047 encoded-word for non-ASCII subjects
- text/plain only for v1 — real provider in a future session

### Email templates (`templates/emails/`)

- `invite.php` — "you are invited" with setup-password link, hours-to-expiry
- `reset.php` — reset link with hours-to-expiry and request IP
- `reset_confirm.php` — confirmation that the password was changed, with
  event time + IP, and instructions to contact admin if unexpected

### Public auth flows (`templates/auth/`)

- `login.php` — REWRITTEN. Email field replaces username field. Email
  format validation. New flash messages for `?logged_out=1`, `?timeout=1`,
  `?reset=1`. New "Forgot your password?" link.
- `forgot_password.php` — accepts email, creates `password_resets` row,
  emails the link via `Mailer::send('reset', ...)`. **Anti-enumeration**:
  always shows the same success message regardless of whether the email
  matches a real account.
- `reset_password.php` — accepts `?token=` from email, validates, shows
  password form. On submit: 10-char minimum + match check, bcrypt hash,
  resets failed counter and lockout, marks token used, invalidates all
  other outstanding reset tokens for the same user in the same transaction,
  sends confirmation email, redirects to `/login?reset=1`.
- `setup_password.php` — same flow but for `user_invites` instead of
  `password_resets`. Creates the user row carrying forward the role_id,
  manager_id, linked_caregiver_id, linked_client_id captured at invite time.
  Handles the rare case where a user with the same email already exists
  (updates in place).

### Front controller (`public/index.php`)

Three new public routes wired alongside the existing `/login`:
`/forgot-password`, `/reset-password`, `/setup-password`. None require auth.

---

## End-to-end verification (on dev)

```
GET  /login                       → 200
GET  /forgot-password             → 200
GET  /reset-password?token=bogus  → 200 ("invalid or expired" alert)
GET  /setup-password?token=bogus  → 200 ("invalid or expired" alert)
GET  /admin (unauthenticated)     → 302 to /login

POST /login (ross@intelligentae.co.uk + existing pw)
                                  → 302 to /admin
GET  /admin (authed)              → 200

POST /forgot-password (ross's email)
                                  → 200, success message rendered
                                    email_log row 1 created, status=sent

→ Reset URL extracted from email_log.body_text:
  https://dev.tch.intelligentae.co.uk/reset-password?token=5a0c5e99...

GET  /reset-password?token=...    → 200, "Setting a new password for
                                    ross@intelligentae.co.uk"
POST /reset-password              → 200, "Your password has been updated"

POST /login with NEW password     → 302 to /admin

→ Restored original password via:
  php database/seeds/create_admin.php TchAdmin2026x

POST /login with ORIGINAL pw      → 302 to /admin
GET  /admin                       → 200
GET  /admin/people/review         → 200  (legacy requireAuth() shim works)
GET  /admin/enquiries             → 200  (legacy requireAuth() shim works)
```

`activity_log` final state (5 rows):

| id | action                    | real | imp  | summary |
|---:|---------------------------|-----:|-----:|---------|
| 5  | login                     | 1    | NULL | Logged in: ross |
| 4  | login                     | 1    | NULL | Logged in: ross |
| 3  | password_reset_completed  | NULL | NULL | Reset completed |
| 2  | password_reset_requested  | NULL | NULL | Reset link sent |
| 1  | login                     | 1    | NULL | Logged in: ross |

NULL real_user_id on rows 2 and 3 is correct: those happen via token,
not an authenticated session.

---

## Files created or modified

### Database
- `database/005_users_roles_hierarchy.sql` (created)

### Includes
- `includes/auth.php` (rewritten)
- `includes/permissions.php` (created)
- `includes/mailer.php` (created)

### Templates
- `templates/auth/login.php` (rewritten — email field)
- `templates/auth/forgot_password.php` (created)
- `templates/auth/reset_password.php` (created)
- `templates/auth/setup_password.php` (created)
- `templates/emails/invite.php` (created)
- `templates/emails/reset.php` (created)
- `templates/emails/reset_confirm.php` (created)

### Front controller
- `public/index.php` (3 new routes added)

### Docs
- `CHANGELOG.md` (v0.7.0 entry added)
- `docs/sessions/2026-04-10b-user-mgmt-session-A.md` (this file)

---

## Database migration applied (shared dev/prod DB)

| File | What it did |
|---|---|
| `005_users_roles_hierarchy.sql` | All new tables + users column adds + 5 roles + 17 pages + 51 permission rows + ross migrated to Super Admin |

**Important:** schema changes are visible to prod (shared DB), but prod
still has the OLD code (auth.php, login.php, index.php) so prod login
still uses the username field. The v0.7.0 file deploy is held until
Session B is complete and the admin user-mgmt pages are also tested.

---

## Server backups created

| Path | Contents |
|---|---|
| `~/public_html/dev-TCH/dev/database/backups/users_pre_migration_005.sql` | `users` + `login_log` table dumps just before 005 ran (90 lines) |

---

## What's next — Session B

Per the locked plan in `docs/TCH_Ross_Todo.md`:

1. `/admin/users` — list, filter, invite button, deactivate
2. `/admin/users/invite` — invite form
3. `/admin/users/N` — detail, edit role/manager, force reset, impersonate button
4. `/admin/roles` — list of roles, edit permissions matrix per role
5. `/admin/roles/N/permissions` — pages × CRUD checkbox grid
6. `/admin/activity` — activity log viewer with filters
7. `/admin/email-log` — outbox view
8. Impersonation flow — re-auth modal, persistent banner, end button
9. Update `public/index.php` and existing handlers to call
   `requirePagePermission()` and `logActivity()`
10. Apply hierarchy filtering to caregiver/client list pages
11. Deploy to dev. Test. Promote to prod.

## Re-entry instructions for next session

1. Branch `dev`. The Session A code is on dev only — NOT on prod files.
2. Migration 005 is already applied to the shared DB; the new tables are
   live but unused by prod code.
3. Build Session B starting with `/admin/users` and `/admin/users/invite`,
   then iterate through the rest. The new helpers in `includes/permissions.php`
   are ready: `requirePagePermission()`, `userCan()`, `getVisibleUserIds()`,
   `logActivity()`.
4. Wire `requirePagePermission('users', 'read')` etc. into the new admin
   pages, and start retrofitting the existing pages (`dashboard`, `enquiries`,
   `people_review`, `names`, the three reports).
5. Impersonation needs a session-wide banner — add to `templates/layouts/admin.php`.
6. Don't forget to call `logActivity()` from any place that mutates data.
   Session C will be a sweep, but the new admin pages should call it from day one.
7. Dev login is `ross@intelligentae.co.uk` / `TchAdmin2026x`.

---

## Notes / lessons captured

- **MariaDB conditional ALTER pattern** worked cleanly with prepared statements
  inside SET @sql IF blocks. Reusable for any future additive migration.
- **Outbox-first mailer** was the right call — `mail()` did report success on
  this host but I have no way to verify SMTP delivery actually happened. The
  email_log table is the source of truth for development; reset URLs can be
  fetched from `body_text` directly.
- **Anti-enumeration on forgot-password** matters for an admin tool — even
  though the user list is small, leaking which emails are valid accounts
  is an unnecessary attack surface.
- **Legacy shim approach** (keeping `requireAuth()` + `requireRole()` working
  alongside the new `requirePagePermission()`) means Sessions B and C can
  retrofit pages incrementally without a big-bang rewrite. Existing pages
  keep working throughout.
- **Activity log under impersonation**: the convention I settled on is
  `real_user_id` = effective identity (the actor as logged), `impersonator_user_id`
  = the human at the keyboard, only set when they differ. Cleaner for queries
  like "show me everything user X did" — that's just `WHERE real_user_id = X`
  and includes both their own actions and times someone impersonated them.
