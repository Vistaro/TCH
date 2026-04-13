# TCH Placements — Decisions Log

Append-only. One entry per non-obvious design choice. Format:

```
## YYYY-MM-DD — <title>
**Chose:** X
**Over:** Y, Z
**Because:** …
```

New entries go at the top.

---

## 2026-04-13 — Structured source-citation columns on `activities`

**Chose:** Add `source`, `source_ref`, `source_batch` columns to the
`activities` table and display `source_ref` as a muted "Source: …" line
under each imported Note.
**Over:** Embedding source info in the free-text `notes` body
(what Nexus CRM does today), or adding a separate `import_provenance`
table keyed on activity_id.
**Because:** Free text rots (people edit note bodies) and doesn't
query — we can't filter "show me every value sourced from sheet X".
A separate table is over-engineered for what is really just three
short strings per row. Nexus CRM agent agreed and will likely adopt
the same pattern; TCH ships it first.

## 2026-04-13 — Reject button removed from Tuniti approval flow

**Chose:** Only an **Approve** action on the student detail page. If
the imported data is wrong, the approver edits the fields via the
existing per-section edit, then approves.
**Over:** Keeping Approve + Reject (the CRM-style "reject takes it out
of the workflow" pattern).
**Because:** Rejecting doesn't help anyone — the student still exists,
they still need processing, and Reject just creates a dead-letter
queue someone has to re-process. Edit-then-approve keeps the record
moving in one direction.

## 2026-04-13 — Notes panel as single source of truth, not per-screen free-text columns

**Chose:** Move every existing `persons.import_notes` /
`student_enrollments.notes` free-text field into one unified Notes
timeline (backed by the `activities` table). Keep the source columns
for now but stop rendering them.
**Over:** Leaving each table with its own notes column and rendering
them in separate sections.
**Because:** Users had three places to look for "why is this record
the way it is". A single reverse-chronological timeline per entity is
how Nexus CRM does it and how Ross expects it to look. Matches his
muscle memory, removes "which notes column?" as a question.

## 2026-04-13 — Shared DEV/PROD DB — documented exception

**Chose:** Both `dev.tch.intelligentae.co.uk` and
`tch.intelligentae.co.uk` point at the same MariaDB database.
**Over:** Separate dev and prod databases (the standing global rule).
**Because:** TCH has no real customer activity yet — every row was
entered by Ross or Claude. The cost of maintaining two schemas would
exceed the risk. Tracked as FR-0076 on the Nexus Hub. The exception
expires the moment the first real caregiver self-service login, the
first real client billing event, or the first real Tuniti approval
happens in prod. At that trigger, the FR becomes HIGH priority.

## 2026-04-11 — Drop cached summary columns that had drifted during dedup

**Chose:** Compute `first_seen`, `last_seen`, `months_active`, and
`status` for clients at read time from `client_revenue`. Migration 008
dropped the stored columns.
**Over:** Keeping the columns and maintaining them via triggers or
recompute-on-write.
**Because:** The 2026-04-11 patient dedup exposed silent drift — after
merges repointed revenue rows, the cached columns on the surviving
client still said "1 month, Inactive" while the client was actually
billing R30k/month. The data was correct; the cache was the lie. Rule
promoted to global standing order ("single source of truth — no stored
derivations"): derivable values must be computed, not cached, unless a
profiler proves a real performance problem.

## 2026-04-11 — Nexus Hub as central bug/FR tracker across projects

**Chose:** All bugs and FRs for TCH (and Nexus CRM, and future
projects) filed on https://hub.intelligentae.co.uk via a shared API.
**Over:** Keeping each project's backlog in markdown files in its own
repo.
**Because:** Markdown backlogs don't survive context switches. Once
there's more than one project, a single cross-project tracker with
per-project scoping is how the work stays legible. TCH got the
in-app floating Help widget first; other projects follow.

## 2026-04-10 — LAMP stack, no framework, no JS build

**Chose:** Bare PHP 8 + MariaDB + Apache. Front controller dispatches
via switch/preg_match on `?route=`. Vanilla JS where needed; most
interactivity is server-rendered.
**Over:** Laravel, Symfony, or a JS-SPA front end.
**Because:** This is an internal ops tool for low traffic (dozens to
low hundreds of users, never thousands). A framework adds upgrade
churn, a build step, and dependencies we don't need. The whole stack
is one `git pull` + one `rsync`. Hosting bill is R12/month. Every
feature so far has shipped in under a day of work; the stack isn't
the bottleneck.

## 2026-04-10 — Polymorphic `entity_type`/`entity_id` on activities + audit tables

**Chose:** Use a string enum + integer id for any table that hangs off
multiple other tables (`activities`, `activity_log`, `attachments`).
**Over:** Separate join tables per entity pair, or inheritance via
superclass tables.
**Because:** We want one code path for rendering the timeline on any
entity, not one per entity type. The polymorphic shape comes at the
cost of no FK enforcement on `entity_id` — acceptable because the
app-side insert sites are few and they control what they pass. Nexus
CRM uses the same shape.

## 2026-04-09 — TCH IDs (`TCH-000001`) as stable identifiers, independent of names

**Chose:** Every person gets an immutable `tch_id` on creation. All
cross-references (reports, attachments, URLs) use the TCH ID, not the
name.
**Over:** Using `full_name` as the external identifier.
**Because:** Names change — marriage, correction of typos, legal
transliteration fixes. The dedup process exposed seven students whose
names had been recorded three different ways across three
spreadsheets. A TCH ID survives all of that. Names survive editing;
the ID survives migration.
