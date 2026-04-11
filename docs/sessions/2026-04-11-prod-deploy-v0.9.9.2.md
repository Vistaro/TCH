# Prod deploy — v0.9.9.2

**Date:** 2026-04-11
**Branch:** `dev` → fast-forward merged into `main` → tagged `v0.9.9.2` → deployed to prod
**Deployed by:** TCH Agent at Ross's explicit request
**Prior prod version:** v0.9.1 (shipped 2026-04-10)

---

## What shipped

Eleven dev increments bundled into a single prod deploy. Every version
was developed and committed individually on `dev` through the course
of one long working session, then merged as a block at the end.

| Version | What it added |
|---------|----------------|
| **v0.9.2** | Activity log inline field-level diff viewer on the list row (collapsible `N fields changed` disclosure with red-strikethrough → green rendering) |
| **v0.9.3** | Audit coverage gap closures (A1.5): failed logins, account lockouts, and every email send now emit `activity_log` entries. Also backfilled before/after snapshots for `user_unlocked` and `password_reset_forced`. Standing rule added to global `C:\ClaudeCode\CLAUDE.md`: **every mutation on a transactional site must be logged with before/after.** |
| **v0.9.4** | A2 — single-field revert. Button per changed field on the activity log detail page; gated to `activity_log.edit` permission; refuses if the field has been changed since. |
| **v0.9.5** | A3 — whole-record rollback. Inline preview with intermediate-edit warnings; Super Admin only. |
| **v0.9.6** | A4 — undelete + `activity_log_delete()` helper. Standing rule: never call `DELETE FROM` directly, always use the helper. |
| **v0.9.7** | In-app Bug/FR reporter widget → Nexus Hub. Floating Help button bottom-right of every admin page. Server-side proxy holds the Hub API token; activity log integration; confirmation email. |
| **v0.9.7.1** | Hotfix: reporter handler missed `initSession()` and `require_once includes/mailer.php`. Silent auth fail + silent email fail. Caught in smoke test. |
| **v0.9.8** | Short description field on the reporter widget + B3 migration. Seven Person Database FRs moved from `docs/TCH_Ross_Todo.md` to the Hub as FR-0058 through FR-0064. |
| **v0.9.9** | Shared sortable + filterable table component (`tch-data-table`). Applied to every admin list page. Three matrix reports rebuilt to close FR-0056, FR-0057, FR-0066. |
| **v0.9.9.1** | Matrix reports column ordering: newest month first, MMM-YY labels. |
| **v0.9.9.2** | Drill-down honest empty state + tolerant matching. FR-0069 filed on Hub for the underlying client name reconciliation data gap. |

**Full detail per version:** see `CHANGELOG.md` from the `[0.9.2-dev]`
entry downwards.

---

## Pre-deploy safety

1. **Server-side prod files backup:**
   ```bash
   cp -a ~/public_html/tch ~/public_html/tch_backup_pre_v0.9.9.2_20260411_131926
   # 19MB
   ```

2. **Test data cleanup:** none needed. The smoke-test records from dev
   (BUG-0031 on the Hub, some `email_log` entries, activity log rows)
   live in the shared dev/prod database and were already visible in
   prod before the deploy. None were code artifacts that needed
   scrubbing.

3. **No schema migrations.** All eleven versions are pure
   code/template/asset changes. Migration 005 (from v0.9.1) is the
   current schema on prod, unchanged.

4. **No DB table backup** — shared DB, no schema change, no risk.

---

## Deploy steps executed

### 1. Git: fast-forward merge, tag, push

```bash
git checkout main
git merge dev --ff-only        # 966755a → b8d0671, 32 files, +4866/-448
git tag v0.9.9.2 -m "v0.9.9.2: Activity log revert/rollback/undelete + Hub reporter + sortable tables + matrix reports"
git push origin main
git push origin v0.9.9.2
git checkout dev
```

12 commits rolled up:
- `6cd16c6` v0.9.2-dev
- `534e395` v0.9.3-dev
- `b21162a` v0.9.4-dev
- `9704911` v0.9.5-dev
- `95396ff` v0.9.6-dev
- `5a40082` v0.9.7-dev
- `b00e005` v0.9.7.1-dev
- `b2ff321` v0.9.8-dev
- `e1755d3` v0.9.9-dev
- `31da556` v0.9.9.1-dev
- `b8d0671` v0.9.9.2-dev
- plus the v0.9.1 docs follow-up commit `7172be3` that was sitting on dev

### 2. Prod `.env` — add Nexus Hub config

Appended three new keys to `~/public_html/tch/.env` (gitignored, not
in rsync):

```
NEXUS_HUB_URL=https://hub.intelligentae.co.uk
NEXUS_HUB_PROJECT_SLUG=tch
NEXUS_HUB_TOKEN=<redacted — scoped to the tch project>
```

Same token as dev — the Hub token is project-scoped and works across
environments.

### 3. Server-side rsync dev → prod

```bash
rsync -av --delete \
  --exclude='.env' \
  --exclude='database/backups/' \
  --exclude='tools/intake_parser/output/' \
  --exclude='.git/' \
  --exclude='.last-backup-timestamp' \
  ~/public_html/dev-TCH/dev/ ~/public_html/tch/
# ~330KB of updates across 35 files
```

Post-rsync cleanup: removed two dev-specific `.env` backup files
that rsync had copied over from dev (harmless, but tidy).

### 4. Server-side PHP lint

`php -l` clean on every PHP file touched: config, auth, mailer, the
two activity_log helpers, index.php, all modified templates, both
handler files, the three matrix reports, the two layout files. No
errors, no warnings.

### 5. Live prod smoke test

```
https://tch.intelligentae.co.uk/           → 200
https://tch.intelligentae.co.uk/login      → 200
https://tch.intelligentae.co.uk/admin      → 302 (redirect to /login — unauth'd)
POST /ajax/report-issue (no auth)          → 401 {"ok":false,"error":"Not authenticated."}
```

All four green. Prod is up.

---

## What was NOT smoke-tested end-to-end on dev before shipping

Ross explicitly authorised the deploy. For transparency, these are
the features that shipped without a full browser smoke test on dev
(they all lint clean and don't affect existing happy-path flows):

- **A1.5** — failed login / account lockout / email_sent activity log
  entries. Defensive additions inside existing handlers; protected by
  try/catch in the logger.
- **A2** — single-field revert UI. New button on activity log detail
  page; only fires on click.
- **A3** — whole-record rollback preview + apply. New amber button;
  only fires on click; gated to Super Admin.
- **A4** — undelete + `activity_log_delete()` helper. The helper
  exists but is not called from any existing code path (no delete
  handlers in TCH today), so A4 is effectively inert until a delete
  handler is added.

**What WAS tested on dev:**
- Reporter widget end-to-end (FR-0056, FR-0057, FR-0066 created
  through the widget)
- Matrix reports (all three, including column order + MMM-YY labels)
- Client billing drill-down (caught the `No roster records found`
  data-gap issue, fixed in v0.9.9.2, filed FR-0069 for the underlying
  reconciliation work)
- Sort + filter UI on every admin list page

---

## Outstanding for Ross post-deploy

1. **Purge CDN cache** on StackCP > CDN > Edge Caching so anonymous
   users hit the latest CSS/JS. This is the standing post-deploy
   action per `docs/TCH_Ross_Todo.md` item 9.
2. **Test the features that didn't get a dev smoke test** (A1.5
   onwards) on prod now that they're live. Recommended order:
   - Try a bad password on `/login` → check `/admin/activity` for a
     `login_failed` entry
   - Click a `Revert` button on any activity log entry with a diff →
     confirm it works
   - Click the amber `Restore whole record to this point` button →
     confirm the preview panel renders and the Apply button works
3. **Optionally close out low-priority items** in the Hub backlog.
   FR-0058 through FR-0074 are all now visible in the TCH project.

---

## Hub backlog snapshot at time of deploy

Created during the session (in filing order):
- FR-0058 Person record card view matching Tuniti PDF layout — HIGH
- FR-0059 System config admin page for all lookup lists — medium
- FR-0060 Status promotion gates — medium
- FR-0061 Field-level role-based edit permissions — medium
- FR-0062 Retire name_lookup table once all PDFs matched — medium
- FR-0063 Referrer / affiliate model — low
- FR-0064 Replace placeholder portraits with full-quality photos — low
- FR-0065 Centralise the in-app reporter widget on the Hub — medium
- FR-0069 Client name reconciliation — daily_roster ↔ client_revenue — HIGH
- FR-0070 Unified Person canonical domain model — HIGH
- FR-0071 Engagement as first-class object — HIGH
- FR-0072 Product / Service catalogue — medium
- FR-0073 Person record card — editable CRUD with role-based permissions — HIGH
- FR-0074 Edit caregiver records BEFORE Tuniti approval — medium

Closed during the session:
- FR-0056 Caregiver earnings matrix — implemented in v0.9.9
- FR-0057 Client billing matrix — implemented in v0.9.9
- FR-0066 Days worked matrix — implemented in v0.9.9
- BUG-0031 (smoke test bug) — closed with note

---

## Mailbox activity during the session

The cross-session agent mailbox
(`C:\ClaudeCode\_global\output\agent-messages\`) went live and
delivered its first real cross-project exchange:

1. `2026-04-11-1030-tch-to-all-mailbox-now-live.md` — broadcast
   announcing the mailbox to all future agents on all projects
2. `2026-04-11-1200-tch-to-nexus-crm-add-short-description-field.md`
   — direct message to Nexus CRM agent asking them to mirror the
   short-description reporter change
3. `2026-04-11-1309-nexus-crm-to-tch-short-description-acknowledged.md`
   — Nexus CRM agent's reply confirming they are shipping the mirror
   change and noting an unrelated already-fixed `project_slug` bug
   + an unrelated broken deploy pipeline on their side (filed as
   Hub BUG-0033 on the `nexus-hub` project)

---

## Rollback

If something breaks on prod, the rollback path is:

```bash
# On the server
rsync -av --delete \
  --exclude='.env' \
  ~/public_html/tch_backup_pre_v0.9.9.2_20260411_131926/ \
  ~/public_html/tch/
```

Then revert locally and force-push main:

```bash
# Local
git checkout main
git reset --hard 966755a      # v0.9.1
git push origin main --force  # DANGEROUS — coordinate with Ross first
git tag -d v0.9.9.2
git push origin :refs/tags/v0.9.9.2
```

**Do NOT execute the git reset + force push without Ross's explicit
say-so.** The server-side rsync rollback is non-destructive by itself
and can be done first to get prod working again; the git rollback
can follow once Ross has authorised it.
