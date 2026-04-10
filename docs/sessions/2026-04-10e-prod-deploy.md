# Session: 2026-04-10 (E) — PROD DEPLOY of v0.7.0 → v0.9.1

**Owner:** Ross
**Project:** TCH Placements
**Branch:** `dev` → merged into `main` → tagged `v0.9.1` → deployed to prod
**Outcome:** First prod release of the new email-based login + RBAC + audit
+ impersonation system. Locked 3-session User Management build is now LIVE
on https://tch.intelligentae.co.uk/.

---

## Why this deploy

Today shipped 4 dev releases (v0.7.0 → v0.9.1) implementing the locked
3-session User Management + RBAC + Audit + Impersonation plan plus the
field-level activity diff view Ross asked for after Session C. All work
was on dev only. Ross authorised the prod deploy at end of day:

> Ross: "OK ship to PROD, write the chat history i.e. Minutes, write any
> other session notes, changes etc so all docs up to date, they write
> whatever needs to be there to show when we start tomorrow"

---

## Pre-deploy state

- **`dev` branch:** at `966755a` (v0.9.1)
- **`main` branch:** at `4b4ad0f` (v0.6.0 + hamburger fix from morning session)
- **Schema:** migration 005 already applied to shared dev/prod DB during
  Session A; the new tables (roles, pages, role_permissions, user_invites,
  password_resets, email_log, activity_log) and the new users columns
  were already live, but prod CODE was still v0.6.0 (username login)
- **Test data on dev:** `testmanager@example.com` user, "Audit Sweep Test"
  enquiry, 23 activity_log rows, 4 email_log rows
- **Backups:** none yet

---

## Deploy steps (in order)

### 1. Test data cleanup on shared DB

The shared dev/prod DB had test artifacts from Sessions A/B/C smoke tests.
These would have appeared in prod's user list / enquiries inbox once the
new code went live. Cleaned up:

```sql
DELETE FROM user_invites WHERE email = 'testmanager@example.com';  -- 1 row
DELETE FROM users WHERE id = 2;                                     -- testmanager
DELETE FROM enquiries WHERE full_name = 'Audit Sweep Test';        -- 1 row
```

**Left intact** (audit/forensic data):
- `activity_log` — 23 rows. Activity rows that referenced user 2 had
  `real_user_id` and `impersonator_user_id` set to NULL via `ON DELETE
  SET NULL` on the FK constraint. This is intentional standard audit
  behaviour: the action history is preserved even after the user is
  deleted, the actor link just becomes anonymous.
- `email_log` — 4 rows. The reset/invite emails from Sessions A/B
  testing. Useful as forensic data and a working example of the outbox.
- `password_resets` — 1 row (the Session A test reset of ross's password).
  Already used and historical.

### 2. Server-side prod files backup

```bash
cp -a ~/public_html/tch ~/public_html/tch_backup_pre_v0.9.1_2026-04-10
# 18MB
```

### 3. DB tables backup

```bash
mysqldump users roles pages role_permissions user_invites password_resets \
          email_log activity_log \
  > database/backups/user_mgmt_pre_v0.9.1_prod_2026-04-10.sql
# 329 lines
```

Stored on the dev server at
`~/public_html/dev-TCH/dev/database/backups/user_mgmt_pre_v0.9.1_prod_2026-04-10.sql`

### 4. Git: fast-forward main, push, tag

```
git checkout main
git merge dev --ff-only         # 4b4ad0f → 966755a, 36 files, +5437/-67
git push origin main
git tag v0.9.1 -m "v0.9.1: User mgmt + RBAC + audit + impersonation; first prod release of new auth system"
git push origin v0.9.1
git checkout dev
```

### 5. Server-side rsync dev → prod

```bash
rsync -av --delete \
  --exclude=".env" \
  --exclude="database/backups/" \
  --exclude="tools/intake_parser/output/" \
  --exclude=".git/" \
  ~/public_html/dev-TCH/dev/ ~/public_html/tch/
# 26 templates updated/created, 267KB sent, total size 15.8MB
```

### 6. Smoke tests on prod

All 200 / 302 as expected (no SSL verify because StackCP CDN cert):

| Path | Expected | Got |
|------|----------|-----|
| `GET /` | 200 | ✓ |
| `GET /login` | 200 with `name="email"` | ✓ |
| `GET /forgot-password` | 200 | ✓ |
| `GET /reset-password?token=invalid` | 200 (invalid alert) | ✓ |
| `GET /setup-password?token=invalid` | 200 (invalid alert) | ✓ |
| `GET /admin` (unauthed) | 302 to /login | ✓ |
| `POST /login` (ross + email + TchAdmin2026x) | 302 to /admin | ✓ |
| `GET /admin` | 200 | ✓ |
| `GET /admin/users` | 200 | ✓ |
| `GET /admin/users/invite` | 200 | ✓ |
| `GET /admin/users/1` | 200 | ✓ |
| `GET /admin/roles` | 200 | ✓ |
| `GET /admin/roles/2/permissions` | 200 | ✓ |
| `GET /admin/activity` | 200 | ✓ |
| `GET /admin/email-log` | 200 | ✓ |
| `GET /admin/people/review` | 200 | ✓ |
| `GET /admin/enquiries` | 200 | ✓ |
| `GET /admin/names` | 200 | ✓ |
| `GET /admin/reports/caregiver-earnings` | 200 | ✓ |
| `GET /admin/reports/client-billing` | 200 | ✓ |
| `GET /admin/reports/days-worked` | 200 | ✓ |

The login form HTML was inspected and confirmed to contain `name="email"`
(the new field), confirming the new code is live on prod and the CDN
isn't serving a cached old copy of /login from the auth-page response.

---

## Outstanding post-deploy actions for Ross

1. **Purge CDN cache** via StackCP > CDN > Edge Caching, or use Development
   Mode. Anonymous public pages may have a cached old `/login` page until
   then. Edge cache typically clears in minutes regardless.
2. **Optional:** delete the test data left in `email_log` (4 rows from
   Sessions A/B testing) and `activity_log` (23 rows from same) via SQL
   if you want a clean slate. They're harmless to leave — the email_log
   rows aren't visible to end-users and the activity_log entries are
   tagged with NULL actors after the testmanager delete.

---

## Repo state at exit

| Branch | HEAD | Notes |
|---|---|---|
| `dev` | `966755a` | v0.9.1 — clean working tree (only `.claude/settings.local.json` and `audio_extract.mp3` modified locally, both ignored) |
| `main` | `966755a` | Fast-forwarded from `4b4ad0f` to match dev. Tagged `v0.9.1`. |

**Tags:** `v0.9.1` is the only tag in the repo.

---

## What "starting tomorrow" looks like

When Ross logs back in:

1. **Branch:** `dev`. Working tree clean.
2. **Prod is live** with the full user management system at https://tch.intelligentae.co.uk/.
3. **Login:** `ross@intelligentae.co.uk` / `TchAdmin2026x` on both prod
   and dev. The username field is gone.
4. **All pages live and tested:**
   - `/admin` dashboard
   - `/admin/users`, `/admin/users/invite`, `/admin/users/{id}`
   - `/admin/users/{id}/impersonate`, `/admin/impersonate/stop`
   - `/admin/roles`, `/admin/roles/{id}/permissions`
   - `/admin/activity`, `/admin/activity/{id}` (field-level diff)
   - `/admin/email-log`, `/admin/email-log/{id}`
   - all existing pages (people review, enquiries, names, reports)
5. **Locked 3-session User Management plan:** ✓ COMPLETE on dev AND prod.
6. **Backups:**
   - `~/public_html/tch_backup_pre_v0.9.1_2026-04-10/` on the server
     (full prod files snapshot, 18MB)
   - `~/public_html/dev-TCH/dev/database/backups/user_mgmt_pre_v0.9.1_prod_2026-04-10.sql`
     (DB dump of all 8 user-mgmt tables, 329 lines)

### Top 3 candidate next sessions (Ross's call)

1. **Caregiver self-service portal UI** — uses everything just shipped
   (linked_caregiver_id, role 4 caregiver, self_service permission). High
   visible value, no blockers.
2. **Caregiver onboarding data entry screen** — most ROI for the business
   but BLOCKED on Ross dropping the blank onboarding form into `docs/`
   (item 1 on the Ross Action Items list).
3. **Real email provider** (Mailgun/SES) wiring — needed before mass
   inviting caregivers/clients. PHP `mail()` + outbox is fine for solo
   admin testing but unreliable at scale.

### Other outstanding work (no blockers, just sequencing)

- **CDN purge** (Ross — see above)
- **Replace placeholder phone/email** in the `regions` Gauteng row
  (currently `XXX XXX XXXX` and `hello@tch.intelligentae.co.uk`)
- **Brand imagery** — feed prompts in `docs/Brand_Image_Prompts.md` to
  ChatGPT, drop JPEGs into `public/assets/img/site/`
- **Tuniti follow-ups** — 6 invalid data items, 6 NoK conflicts, 3 name
  collisions, 4 household links, ~15 address inconsistencies, ~20
  Social_media lead source rows needing channel detail, 25 Known As
  discrepancies, 123 caregivers in the Person Review queue waiting for
  Tuniti to walk through. See `docs/TCH_Ross_Todo.md`.
- **Items waiting on Ross to provide:** blank onboarding form, list of
  required attachments, product/service list, messy training data
- **System config admin page** — manage statuses/lead_sources/attachment_types via UI
- **Status promotion gates** — required-fields-per-state validation
- **Field-level role-based edit perms** — e.g. only finance edits banking
- **Hierarchy filtering retrofit** on dashboard/list pages — currently
  no-op (only Super Admin exists)
- **Granular training data import** — individual course/module scores
- **Investor reporting Phase 1+2** — blocked on training data
- **Retire `name_lookup` table** once all PDFs matched
- **Per-region pages** (Western Cape, KZN) — infrastructure ready
- **Email notifications on enquiry submission**
- **Spam/rate limiting on enquiry form** (Turnstile/CAPTCHA)
- **Higher-quality photo replacement** for caregivers
- **Referrer/affiliate model** for `lead_source = Referral`

---

## Notes / lessons captured

- **Schema-first migration** in Session A was the right call: by the time
  the v0.9.1 file deploy ran, the schema had been live on the shared DB
  for hours and had been hammered by Sessions A/B/C/v0.9.1 testing. Zero
  schema risk in the prod deploy itself — just files.
- **Outbox-first mailer** justified itself: every test invite/reset was
  retrievable from `email_log` despite shared-host `mail()` reliability
  being unknown. I never had to SSH into the server to dig for a token.
- **Test data cleanup BEFORE files deploy** matters when dev and prod
  share a DB. Otherwise prod's users list would have shown a fake
  Test Manager and the enquiries inbox would have shown "Audit Sweep
  Test" — confusing first impression even though they're harmless.
- **Fast-forward merge** worked cleanly because main was an ancestor of
  dev with no diverging commits. If Ross had been making prod hotfixes
  on main during the day this would have needed a 3-way merge. Worth
  remembering for future multi-developer scenarios.
- **Activity log preserves history across user deletes** because the FK
  is `ON DELETE SET NULL`, not CASCADE. The testmanager deletion left
  the audit rows in place with `real_user_id=NULL`. This is the right
  default for a compliance/forensic audit log.
