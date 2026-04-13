# Client + Patient Profiles — Design Spec

**Status:** Draft for Ross's review before next session.
**Authored:** 13 April 2026
**Author:** Claude
**Trigger:** Ross's request to make Clients + Patients first-class records (create, edit, archive, link), mirroring the Student profile work but with multi-phone, multi-email, dedup-on-create, and a "same person" link between client and patient.

---

## What we're building

Two parallel records — Client (the bill-payer) and Patient (the cared-for) — each with:

- A full profile page (edit-in-place per section, mirroring the Student pattern)
- Multiple phone numbers and multiple email addresses (no fixed cap)
- Multiple addresses (home, work, billing — optional after MVP)
- Country defaulting to South Africa, all the existing edit/save/audit affordances
- A Notes timeline (the same `activities` table we use everywhere else)
- An **Archive** action — never delete; archived rows hide from default lists but are recoverable
- A **dedup check on create** — refuse silently to duplicate if a likely-same record already exists; offer "use existing" or "create anyway"
- Bidirectional link: a Client can have many Patients; a Patient is billed to exactly one Client. A "**Same person**" button when the human is both client *and* patient

---

## Schema changes

### 1. Multi-phone / multi-email — new tables

```sql
-- 022_person_contact_methods.sql
CREATE TABLE person_phones (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id    INT UNSIGNED NOT NULL,
    label        VARCHAR(40) NULL,        -- e.g. "Mobile", "Work", "After hours"
    phone        VARCHAR(40) NOT NULL,    -- E.164
    is_primary   TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pp_person (person_id),
    KEY idx_pp_phone  (phone),
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);
CREATE TABLE person_emails (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id    INT UNSIGNED NOT NULL,
    label        VARCHAR(40) NULL,
    email        VARCHAR(150) NOT NULL,
    is_primary   TINYINT(1) NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pe_person (person_id),
    KEY idx_pe_email  (email),
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE
);
```

**Backfill:** copy `persons.mobile` → `person_phones (label='Mobile', is_primary=1)`. Copy `persons.email` → `person_emails (label='Primary', is_primary=1)`. Keep the legacy columns for one release as a fallback while the UI moves.

### 2. Name parts — split full_name properly

```sql
-- 023_persons_name_parts.sql
ALTER TABLE persons
    ADD COLUMN salutation  VARCHAR(20)  NULL AFTER full_name,
    ADD COLUMN first_name  VARCHAR(80)  NULL AFTER salutation,
    ADD COLUMN middle_names VARCHAR(120) NULL AFTER first_name,
    ADD COLUMN last_name   VARCHAR(80)  NULL AFTER middle_names;
```

`full_name` stays as the canonical display string (auto-built from parts). On edit, present 4 fields. On save, recompose `full_name = TRIM(CONCAT_WS(' ', salutation, first_name, middle_names, last_name))`.

**Backfill:** best-effort split of existing `full_name`. Confidence column tracks "split was clean / needs review". Existing single-name records (e.g. "Nelly") leave first_name = "Nelly", everything else null.

### 3. Archive

```sql
-- 024_persons_archive.sql
ALTER TABLE persons
    ADD COLUMN archived_at TIMESTAMP NULL AFTER updated_at,
    ADD COLUMN archived_by_user_id INT UNSIGNED NULL AFTER archived_at,
    ADD COLUMN archived_reason VARCHAR(255) NULL AFTER archived_by_user_id,
    ADD KEY idx_archived (archived_at);
```

All list queries gain a default `WHERE archived_at IS NULL`. A toggle "Show archived" reveals them with a muted style. Archive action sets the three columns + writes an `activity_log` row + drops a Note.

### 4. Client ↔ Patient link

Already partly in place via `patients.client_id`. Confirm:
- Patient has `client_id` (the bill-payer). One client per patient.
- Client can have many Patients (1-to-many).
- When the same human is both: one `persons` row, `person_type` SET contains both `'client'` and `'patient'`. The `clients` row + `patients` row both reference the same `persons.id`.

The "**Same person**" toggle on either profile flips this:
- Client → "Set patient = this person too" creates a `patients` row with `person_id = this client's person_id` and adds `'patient'` to `person_type`.
- Patient → mirrored.

---

## Dedup on create

Before inserting a new client or patient, run a similarity scan against existing un-archived persons of the same role:

**Match signals (any one triggers a "possible duplicate" prompt):**
- `LOWER(full_name)` similar (Levenshtein ≤ 3 OR `metaphone()` match) within same role
- Any of the new phone numbers exactly matches an existing `person_phones.phone`
- Any of the new emails exactly matches an existing `person_emails.email` (case-insensitive)
- ID/Passport exact match

**UI flow:**
1. User fills the New Client form, hits Save
2. Server runs the scan
3. If matches → modal: "We found {N} possibly matching record(s)" with each match's name, TCH ID, role, and key fields (phone, email, address). Three buttons per match: **Use this one** / **Merge — same person** / **Not the same**
4. **Use this one** → cancel the create, redirect to the existing record's profile
5. **Merge** → mark "Same person" (sets person_type SET) and redirect
6. **Not the same** → record a "checked: not duplicate" flag and proceed with create
7. If user dismisses the modal entirely → silent fall-through to plain create

Log every dedup decision in the new record's Notes timeline so it's visible later.

---

## Client profile page — `/admin/clients/{id}`

Mirrors student detail page exactly. Sections:

| Section | Fields | Edit-in-place |
|---|---|---|
| Header | photo, full name, TCH ID, account number, status badge | photo replace |
| Personal | salutation, first, middle, last, gender, dob | yes |
| Phone numbers | label + number for each, "primary" radio, **+ Add phone** | yes |
| Email addresses | label + address for each, "primary" radio, **+ Add email** | yes |
| Address | street, suburb, city, province, postcode, country (SA default) | yes |
| Billing | account_number, billing_entity, default rates, currency | yes |
| Patients linked | list of patients billed to this client (clickable) | "+ Link patient" / "+ Create new patient and link" |
| Notes | timeline (existing `activities` widget) | yes |

Top-right of header: **Archive** button (with reason prompt). **+ Add new patient under this client** link.

---

## Patient profile page — `/admin/patients/{id}`

Same structure as Client, plus:

- **Billed To** badge in header showing current client (clickable)
- **GPS distance** column (calculated from a configured TCH reference point — see separate design note)
- **Care notes** as a separate section if the existing `activities` panel isn't enough — TBD whether we need a "clinical note" type with extra fields like vital-sign captures. **Defer to a later session — start with one notes panel.**

---

## Routing additions

```
/admin/clients                    — list (with filter: include archived)
/admin/clients/new                — create form (with dedup prompt)
/admin/clients/{id}               — profile, view + edit-in-place
/admin/clients/{id}/archive       — POST, archive with reason
/admin/clients/{id}/link-patient  — POST, link existing patient
/admin/clients/{id}/same-person   — POST, set person_type to client+patient

/admin/patients                   — list
/admin/patients/new               — create form (with dedup prompt)
/admin/patients/{id}              — profile
/admin/patients/{id}/archive      — POST
```

Route registration follows the existing parametric pattern in `public/index.php`.

---

## Migration order (for the build session)

1. **022** — `person_phones`, `person_emails` + backfill from existing columns
2. **023** — name parts on `persons` + best-effort backfill
3. **024** — archive columns on `persons`
4. New page registrations (client_view, patient_view) + role permissions
5. New helper `includes/dedup.php` with a single `findPossibleDuplicates(person_type, fields)` function
6. New templates: `client_view.php`, `client_create.php`, `patient_view.php`, `patient_create.php`
7. Update `clients_list.php` + `patients_list.php` — clickable rows, archive toggle
8. Update `student_view.php` + `student_create.php` — switch to multi-phone/multi-email rendering for consistency (no schema break)

---

## What's NOT in this spec (deferred)

- **Multiple addresses per person** — start with one address, add `person_addresses` table later if needed.
- **Clinical notes structure** — the generic Notes timeline covers it for now.
- **Bulk merge tooling** — manual "Same person" + dedup at create time covers the day-to-day case. Bulk merge of historical duplicates is its own one-off job.
- **Self-service portals** — clients/patients logging in to see their own data is way out of scope here.
- **Custom fields per client type** — not asked for; if needed later, add a JSON column rather than a table per type.

---

## Estimated effort

Roughly a full day of focused build:
- Schema migrations + backfill: 1 hour
- Dedup helper + UI flow: 2 hours
- Client profile + create: 2 hours
- Patient profile + create: 1.5 hours
- Multi-phone / multi-email render + edit: 1 hour
- Archive + list filters: 0.5 hour
- Updates to existing student/caregiver views to use new contact-method tables: 1 hour
- Smoke testing + tweaks: 0.5 hour

If shipped in one go, suggest doing it on dev only and giving Tuniti a day to use it on dev before promoting.

---

## Open questions for Ross before build

1. **Patient ↔ Client cardinality** — confirm one client can bill for many patients, but each patient is billed to exactly one client. Yes?
2. **Dedup threshold** — Levenshtein ≤ 3 + phone/email exact + name metaphone — agree, or want it tighter/looser?
3. **Archive vs deactivate** — same thing or different? I've assumed archive is the only state. If you want a "paused but coming back" state too, we add `status` enum.
4. **Patient billing history** — when a patient changes from one client to another, do we keep history (old client → end_date, new client → start_date) or just overwrite? Affects schema (probably `patient_client_history` table).
5. **Address-as-record** — accept one address per person now and break out into `person_addresses` later, or break out from the start? My recommendation: break out from the start (cheaper than migrating mid-flight).
