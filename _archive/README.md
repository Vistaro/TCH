# `_archive/` — Archived Files (Don't Delete)

Files here are no longer loaded by the application but are preserved in
case the code that referenced them needs to be revived or re-read for
context. Never delete from here without Ross's sign-off.

If you need to restore something, it's a plain `git mv` back to the
original path — the top of each archived file has its old location in
a comment, and the CHANGELOG entry for the archive move below also
names each original path.

---

## 2026-04-18 — Cleanup pass after FR-L + FR-C ship

Context: Ross asked for a code review / cleanup after the last few
days of rapid FR-A / FR-B / FR-L / FR-C delivery. Archived the
obsolete name-reconciliation UI that was bypassed at the router in
v0.9.15.

### Files moved

| Original path                              | Archived path                                       | Why                                                                                                                               |
|--------------------------------------------|-----------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------|
| `templates/admin/names.php`                | `_archive/templates/admin/names.php`                | Name-reconciliation UI retired v0.9.15. Route in `public/index.php` redirects `/admin/names` → dashboard. No other live refs.     |
| `templates/admin/names_assign.php`         | `_archive/templates/admin/names_assign.php`         | Handler for the retired name-reconciliation page. Same reason.                                                                    |

### Restore recipe

```
git mv _archive/templates/admin/names.php        templates/admin/names.php
git mv _archive/templates/admin/names_assign.php templates/admin/names_assign.php
```

Then remove the redirect block in `public/index.php` (search for
`Name reconciliation retired`) and re-add the `case 'admin/names':`
routes that dispatch to these templates.
