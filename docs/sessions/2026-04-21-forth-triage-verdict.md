# 2026-04-21 — Forth malware triage verdict (git-backed copy)

Git-backed copy of the verdict filed to Governance at
`_global/output/agent-messages/2026-04-21-2055-tch-to-governance-forth-triage-false-positive.md`.
Preserved here for repo durability.

---

## TL;DR

**FALSE POSITIVE.** Flagged file is
`vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Worksheet/Drawing.php`
from the PhpSpreadsheet Composer package (v2.0, `^2.0` pin). Four
of the six signature tokens match but the two attacker-control
tokens (`$_COOKIE`, `HTTP_USER_AGENT`) are absent — no
command-dispatch surface, no webshell use-case. File handles image
embedding in Excel exports.

## Evidence

### Token analysis

Scanner signature: `$_COOKIE` + `HTTP_USER_AGENT` + `file_get_contents` +
`tempnam|tmpfile` + `file_put_contents` + `unlink` (six-token set).

In `vendor/phpoffice/phpspreadsheet/src/PhpSpreadsheet/Worksheet/Drawing.php`:

| Token | Match |
|---|---|
| `$_COOKIE` | NO |
| `$_SERVER['HTTP_USER_AGENT']` | NO |
| `file_get_contents()` | yes (line 133) |
| `tempnam()` | yes (line 135) |
| `tmpfile()` | NO |
| `file_put_contents()` | yes (line 137) |
| `unlink()` | yes (line 143) |

The four matches are within 10 consecutive lines performing a
single coherent image-stage-and-cleanup operation.

### Context at lines 133–143

```php
$imageContents = @file_get_contents($path, false, $ctx);
// ...
    $filePath = tempnam(sys_get_temp_dir(), 'Drawing');
// ...
        $put = @file_put_contents($filePath, $imageContents);
// ...
            unlink($filePath);
```

The `'Drawing'` prefix on `tempnam()` is the PhpSpreadsheet library's
signature for this specific routine.

### File provenance

- Composer package: `phpoffice/phpspreadsheet: ^2.0`.
- ~13M Composer downloads, actively maintained by phpoffice org.
- Used in TCH for the `/admin/roster` CSV/Excel export (per
  ARCHITECTURE.md) and likely other report-export surfaces.
- Not TCH-authored code. Recoverable deterministically by `composer
  install`.

### Why both archives hit

`prod-webroot-pre-0917-20260413-105958.tgz` (10.0 MB, 10:59 on
2026-04-13) and `prod-webroot-pre-0920-20260413-160734.tgz`
(13.1 MB, 16:07 on 2026-04-13) both contain the same `vendor/`
subtree, so the same file matched in both. Same false positive,
two archives.

## Draft paste-back text for Forth support

See the filed Governance message for the exact text. Summary: asks
Forth to whitelist `vendor/phpoffice/phpspreadsheet/**` (ideally) or
the specific path, and to re-scan the account so PHP mail re-enables.

## Retention audit finding

Backup folder `/home/sites/.../db-backups/tch/` has 14 files
totalling ~22.9 MB, with no automatic rotation visible. 2026-04-13
archives (including the two flagged files) are 8 days old and due
for cleanup on/after 2026-04-27 per the 14-day retention standing
intent. Follow-up: add a cron-driven `find` to enforce.

## Outcome for TCH

- No action on TCH code.
- No change to v0.9.26 PROD or to the current deployment.
- Awaits Forth rescan before PHP mail re-enables (Nexus-CRM impact,
  not TCH).

## Triage method (reproducible)

```bash
ssh ... <host> 'mkdir -p ~/forth-triage && cd ~/forth-triage && \
   tar -xzf /home/sites/9a/7/72a61afa93/db-backups/tch/prod-webroot-pre-0920-20260413-160734.tgz'

# Cascade from weakest-indicator tokens (action cluster) rather than
# $_COOKIE (since $_COOKIE in docblocks can mislead):
grep -rlE "tempnam|tmpfile" --include="*.php" . | \
    xargs grep -l "file_put_contents" | \
    xargs grep -l "unlink"

# Confirm each match by inspecting context and checking
# file provenance (vendor/ = third-party, ruled out as
# TCH-authored compromise).
```

Total triage time: ~10 minutes.

## Files involved

- `_global/output/agent-messages/2026-04-21-1100-governance-to-tch-forth-flagged-files-are-your-backup-archives.md`
  — Governance confirmation of paths + signature.
- `_global/output/agent-messages/2026-04-21-2055-tch-to-governance-forth-triage-false-positive.md`
  — this verdict, filed to Governance.
- Triage scratch folder on server: `~/forth-triage/` (unpacked
  `prod-webroot-pre-0920-*` for inspection; can be removed once
  Forth closes).
