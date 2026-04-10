#!/usr/bin/env python3
"""
TCH Placements — Tuniti Intake PDF Parser
==========================================

Parses a single Tuniti "Intake" PDF (one page per candidate) and produces:
  * <output>/<basename>.json    — extracted records, easy to eyeball
  * <output>/<basename>.sql     — INSERT statements ready to apply
  * <output>/photos/*.png       — rendered photos and full-page references

The Tuniti PDFs are image-based (no text layer), so this tool can run in
two modes:

  1. AUTO mode (default) — tries PyMuPDF text extraction. If the PDF has a
     text layer the records are filled in automatically. If not (the case
     for current Tuniti exports), it produces a SCAFFOLD JSON with photos
     populated and other fields blank, plus a full-page render of each page
     for visual reference.

  2. --from-json PATH — skips extraction entirely and reads records from a
     pre-built JSON file (one Claude built using vision, or a corrected
     scaffold). Photos are still rendered/cropped from the PDF and SQL is
     emitted at the end.

Usage:
    py tools/intake_parser/parse_intake.py "C:/path/to/Intake 1.pdf" \
        --tranche "Tranche 1"

    py tools/intake_parser/parse_intake.py "C:/path/to/Intake 1.pdf" \
        --tranche "Tranche 1" \
        --from-json tools/intake_parser/output/Intake_1.records.json

The script DOES NOT touch the database. Inserts are written to a .sql file
that Ross applies to the dev DB after review.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from dataclasses import dataclass, field, asdict
from pathlib import Path
from typing import Any

import fitz  # PyMuPDF


# ─────────────────────────────────────────────────────────────────────────────
# Field labels we expect on every page (in roughly top-down order).
# The script looks for each label as a literal string and grabs the text
# immediately to its right (same row, within a Y-tolerance).
# ─────────────────────────────────────────────────────────────────────────────
LABELS = [
    # Personal block (left column, upper)
    ("Known As",           "known_as"),
    ("Title",              "title"),
    ("Initials",           "initials"),
    ("ID / Passport",      "id_passport"),  # may wrap as "ID / Passport Number"
    ("ID/Passport",        "id_passport"),
    ("Date of Birth",      "dob"),
    ("Gender",             "gender"),
    ("Nationality",        "nationality"),
    ("Home Language",      "home_language"),
    ("Home",               "home_language"),  # when wrapped as "Home Language"
    ("Other Language",     "other_language"),
    ("Other",              "other_language"),

    # Contact block (right column, upper)
    ("Mobile Number",      "mobile"),
    ("Mobile",             "mobile"),
    ("Secondary Number",   "secondary_number"),
    ("Secondary",          "secondary_number"),
    ("How did you hear",   "lead_source_raw"),  # value of "How did you hear about us?"
    ("How did you",        "lead_source_raw"),

    # Address block (left column, lower)
    ("Complex/Estate",     "complex_estate"),
    ("Street Address",     "street_address"),
    ("Suburb",             "suburb"),
    ("City",               "city"),
    ("Province",           "province"),
    ("Postal Code",        "postal_code"),

    # NoK block (right column, lower) — uses generic labels disambiguated by Y position
    ("Relationship",       "nok_relationship"),
    ("Contact Number",     "nok_contact"),
]

# These two labels appear twice on the page. Order is enforced by Y position.
DUPLICATE_LABELS = {
    "Email": ["email", "nok_email"],
    "Name":  [None,    "nok_name"],   # the first "Name" is the title (no label)
}

# Lead source raw value → lookup code (matches lead_sources seed in migration 003)
LEAD_SOURCE_MAP = {
    "facebook":      "facebook",
    "tiktok":        "tiktok",
    "instagram":     "instagram",
    "linkedin":      "linkedin",
    "walk_in":       "walk_in",
    "walked_in":     "walk_in",
    "walked in":     "walk_in",
    "phone":         "phone",
    "phoned_us":     "phone",
    "called_us":     "phone",
    "email":         "email",
    "referral":      "referral",
    "referred":      "referral",
    "word_of_mouth": "word_of_mouth",
    "word of mouth": "word_of_mouth",
    "social_media":  "facebook",  # generic — will get flagged in import_notes
    "other":         "other",
}

# Pattern for the candidate System ID (e.g. 202507-10, 202603-1)
SYSTEM_ID_RE = re.compile(r"^\d{6}-\d{1,3}$")


@dataclass
class PersonRecord:
    """One extracted person, ready for SQL emission."""
    source_pdf: str
    source_page: int

    # Core identity
    student_id: str | None = None
    full_name: str | None = None
    known_as: str | None = None
    title: str | None = None
    initials: str | None = None

    # Personal
    id_passport: str | None = None
    dob: str | None = None
    gender: str | None = None
    nationality: str | None = None
    home_language: str | None = None
    other_language: str | None = None

    # Contact
    mobile: str | None = None
    mobile_2: str | None = None
    secondary_number: str | None = None
    email: str | None = None
    email_2: str | None = None

    # Lead source (raw + resolved)
    lead_source_raw: str | None = None
    lead_source_code: str | None = None

    # Address
    complex_estate: str | None = None
    street_address: str | None = None
    suburb: str | None = None
    city: str | None = None
    province: str | None = None
    postal_code: str | None = None

    # NoK 1
    nok_name: str | None = None
    nok_relationship: str | None = None
    nok_contact: str | None = None
    nok_email: str | None = None

    # NoK 2 (only used when a NoK field cramps in multiple values)
    nok_2_name: str | None = None
    nok_2_relationship: str | None = None
    nok_2_contact: str | None = None
    nok_2_email: str | None = None

    # Attachments
    photo_file: str | None = None  # relative path to extracted photo

    # Audit
    import_notes: list[str] = field(default_factory=list)


# ─────────────────────────────────────────────────────────────────────────────
# Text extraction helpers
# ─────────────────────────────────────────────────────────────────────────────

def get_spans(page: fitz.Page) -> list[dict[str, Any]]:
    """Return every text span on the page with its bounding box."""
    spans: list[dict[str, Any]] = []
    raw = page.get_text("dict")
    for block in raw.get("blocks", []):
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span.get("text", "").strip()
                if not text:
                    continue
                bbox = span.get("bbox")
                spans.append({
                    "text": text,
                    "x0": bbox[0],
                    "y0": bbox[1],
                    "x1": bbox[2],
                    "y1": bbox[3],
                    "size": span.get("size", 0),
                    "font": span.get("font", ""),
                })
    return spans


def find_value_to_right(label_span: dict[str, Any],
                         all_spans: list[dict[str, Any]],
                         y_tolerance: float = 6.0,
                         max_x_gap: float = 350.0) -> str | None:
    """Given a label span, find the closest text span to its right on the same row."""
    label_y_center = (label_span["y0"] + label_span["y1"]) / 2
    label_right    = label_span["x1"]

    candidates = []
    for s in all_spans:
        if s is label_span:
            continue
        s_y_center = (s["y0"] + s["y1"]) / 2
        if abs(s_y_center - label_y_center) > y_tolerance:
            continue
        if s["x0"] < label_right + 1:
            continue  # not to the right
        if s["x0"] - label_right > max_x_gap:
            continue
        candidates.append(s)

    if not candidates:
        return None
    candidates.sort(key=lambda s: s["x0"])
    val = candidates[0]["text"]
    # Treat the literal "--" placeholder as empty
    if val.strip() in ("--", "—", "-"):
        return None
    return val


def find_label_spans(label_text: str,
                     all_spans: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Find every span whose text starts with the given label string."""
    out = []
    for s in all_spans:
        if s["text"].startswith(label_text):
            out.append(s)
    return out


# ─────────────────────────────────────────────────────────────────────────────
# Per-page extraction
# ─────────────────────────────────────────────────────────────────────────────

def extract_title_and_id(spans: list[dict[str, Any]]) -> tuple[str | None, str | None]:
    """The big page title (full name) and the system ID below it.

    Both are visually larger than the body text. Title typically size > 18,
    system ID typically size between 12 and 17 and matches \\d{6}-\\d+.
    """
    full_name = None
    student_id = None

    # System ID first (regex is unambiguous)
    for s in spans:
        if SYSTEM_ID_RE.match(s["text"]):
            student_id = s["text"]
            break

    # Largest text span on the page that isn't the system ID is the title
    candidates = [s for s in spans if s["text"] != student_id]
    if candidates:
        candidates.sort(key=lambda s: -s["size"])
        if candidates[0]["size"] > 14:  # body text is around 9-11
            full_name = candidates[0]["text"]
            # Sometimes the name is split across two spans because of styling.
            # Check for adjacent same-size spans on the same Y row.
            top = candidates[0]
            top_y = (top["y0"] + top["y1"]) / 2
            adjacent = [
                s for s in spans
                if s is not top
                and abs(((s["y0"] + s["y1"]) / 2) - top_y) < 5
                and abs(s["size"] - top["size"]) < 1
                and s["text"] != student_id
            ]
            if adjacent:
                # Combine left to right
                pieces = [top] + adjacent
                pieces.sort(key=lambda s: s["x0"])
                full_name = " ".join(p["text"] for p in pieces).strip()

    return full_name, student_id


def parse_page(page: fitz.Page,
               page_num: int,
               source_pdf: str,
               photo_dir: Path) -> PersonRecord:
    spans = get_spans(page)

    rec = PersonRecord(source_pdf=source_pdf, source_page=page_num)

    # Title + System ID
    rec.full_name, rec.student_id = extract_title_and_id(spans)
    if rec.student_id is None:
        rec.import_notes.append("System ID not found on page — investigate.")

    # Single-occurrence labels
    for label_text, field_name in LABELS:
        # Skip if we already filled this field via an alternate label form
        existing = getattr(rec, field_name, None)
        if existing:
            continue
        matches = find_label_spans(label_text, spans)
        if not matches:
            continue
        # Use the first match by Y position
        matches.sort(key=lambda s: s["y0"])
        value = find_value_to_right(matches[0], spans)
        if value is not None:
            setattr(rec, field_name, value)

    # Duplicate labels (Email, Name) — disambiguated by Y order
    for label_text, field_order in DUPLICATE_LABELS.items():
        matches = find_label_spans(label_text, spans)
        matches.sort(key=lambda s: s["y0"])
        for idx, match in enumerate(matches):
            if idx >= len(field_order):
                break
            target_field = field_order[idx]
            if target_field is None:
                continue  # the title 'Name' has no label, this is just a sanity slot
            if getattr(rec, target_field, None):
                continue
            value = find_value_to_right(match, spans)
            if value is not None:
                setattr(rec, target_field, value)

    # Map lead_source_raw → lead_source_code
    if rec.lead_source_raw:
        key = rec.lead_source_raw.lower().strip().replace("-", "_")
        rec.lead_source_code = LEAD_SOURCE_MAP.get(key)
        if rec.lead_source_code is None:
            rec.import_notes.append(
                f'Lead source "{rec.lead_source_raw}" did not match any known code; '
                'left blank for review.'
            )
        elif key == "social_media":
            rec.import_notes.append(
                'Lead source recorded as "Social_media" on the PDF — defaulted to '
                '"facebook" in the lookup. Confirm channel with the candidate.'
            )

    # Split multi-value contact fields
    rec.mobile, rec.mobile_2, mob_note = split_multi(rec.mobile)
    if mob_note:
        rec.import_notes.append(mob_note)

    rec.nok_name, rec.nok_2_name, nok_name_note = split_multi(rec.nok_name)
    if nok_name_note:
        rec.import_notes.append(nok_name_note)

    rec.nok_relationship, rec.nok_2_relationship, nok_rel_note = split_multi(rec.nok_relationship)
    if nok_rel_note:
        rec.import_notes.append(nok_rel_note)

    rec.nok_contact, rec.nok_2_contact, nok_con_note = split_multi(rec.nok_contact)
    if nok_con_note:
        rec.import_notes.append(nok_con_note)

    # Render photo (and full-page reference)
    rec.photo_file = render_page_assets(page, page_num, source_pdf, photo_dir, rec)

    return rec


def split_multi(value: str | None) -> tuple[str | None, str | None, str | None]:
    """If a single field contains two values separated by ' / ', split them.
    Returns (first, second, note_or_None).
    """
    if not value:
        return None, None, None
    # Common separators seen in the source data
    for sep in [" / ", "/", " ; ", ";", " | ", "|"]:
        if sep in value and len([p for p in value.split(sep) if p.strip()]) >= 2:
            parts = [p.strip() for p in value.split(sep) if p.strip()]
            if len(parts) >= 2:
                note = (
                    f'Field originally contained multiple values: "{value}". '
                    f'Split into 1st and 2nd entries.'
                )
                return parts[0], " ".join(parts[1:]), note
    return value, None, None


# ─────────────────────────────────────────────────────────────────────────────
# Photo extraction
# ─────────────────────────────────────────────────────────────────────────────

def render_page_assets(page: fitz.Page,
                       page_num: int,
                       source_pdf: str,
                       photo_dir: Path,
                       rec: PersonRecord) -> str | None:
    """Render the page region containing the candidate photo, plus a full
    page reference render.

    Returns the relative filename of the cropped photo (or None on failure).
    The full-page render is saved alongside as <base>_full.png and is used
    by the admin review screen as a visual fallback.
    """
    photo_dir.mkdir(parents=True, exist_ok=True)
    safe_pdf = re.sub(r"[^\w]+", "_", Path(source_pdf).stem).strip("_")
    out_name_base = f"{safe_pdf}_p{page_num:02d}"

    # Full-page render (for the review screen)
    try:
        full_pix = page.get_pixmap(matrix=fitz.Matrix(2, 2))
        full_path = photo_dir / f"{out_name_base}_full.png"
        full_pix.save(str(full_path))
    except Exception as e:
        rec.import_notes.append(f"Full-page render failed: {e}")

    # Photo crop. The Tuniti template puts the headshot in the top-left of the
    # page. Page is 595x841 PDF points. The photo circle sits within roughly
    # (45, 165) - (170, 285) — a 125x120 region. Rendered at 5x for clarity.
    try:
        clip = fitz.Rect(48, 168, 128, 248)
        pix = page.get_pixmap(clip=clip, matrix=fitz.Matrix(5, 5))
        out_path = photo_dir / f"{out_name_base}.png"
        pix.save(str(out_path))
        return out_path.name
    except Exception as e:
        rec.import_notes.append(f"Photo crop failed: {e}")
        return None


# ─────────────────────────────────────────────────────────────────────────────
# SQL emission
# ─────────────────────────────────────────────────────────────────────────────

def sql_str(value: str | None) -> str:
    if value is None:
        return "NULL"
    escaped = value.replace("\\", "\\\\").replace("'", "''")
    return f"'{escaped}'"


def sql_date(value: str | None) -> str:
    if not value:
        return "NULL"
    # Accept YYYY-MM-DD as-is. Anything else passes through unchanged so the DB rejects it.
    if re.match(r"^\d{4}-\d{2}-\d{2}$", value):
        return f"'{value}'"
    return sql_str(value)


def sql_gender(value: str | None) -> str:
    if not value:
        return "NULL"
    v = value.strip().lower()
    if v.startswith("m"):
        return "'Male'"
    if v.startswith("f"):
        return "'Female'"
    return "'Other'"


def emit_sql(records: list[PersonRecord], tranche: str | None, source_pdf: str) -> str:
    """Generate the SQL load file for one PDF's worth of records."""
    lines: list[str] = []
    lines.append(f"-- Generated by tools/intake_parser/parse_intake.py")
    lines.append(f"-- Source PDF: {source_pdf}")
    lines.append(f"-- Tranche:    {tranche or '(unspecified)'}")
    lines.append(f"-- Records:    {len(records)}")
    lines.append("--")
    lines.append("-- Each record creates one row in caregivers, plus two attachments")
    lines.append("-- (Original Data Entry Sheet + Profile Photo). All caregivers land")
    lines.append("-- with status_id = 'lead' and import_review_state = 'pending'.")
    lines.append("--")
    lines.append("-- Photos must be uploaded to public/uploads/people/<tch_id>/ before")
    lines.append("-- this script runs, OR after — the file_path is the relative target.")
    lines.append("")
    lines.append("START TRANSACTION;")
    lines.append("")

    for i, rec in enumerate(records, start=1):
        lines.append(f"-- ── Record {i}: {rec.full_name or '(unknown)'} ──")
        notes_text = "\n".join(rec.import_notes) if rec.import_notes else None
        # Build INSERT for caregivers
        cols = [
            "full_name", "student_id", "known_as", "title", "initials",
            "tranche", "lead_source_id",
            "gender", "dob", "nationality", "id_passport",
            "home_language", "other_language",
            "mobile", "secondary_number", "email",
            "complex_estate", "street_address", "suburb", "city", "province", "postal_code",
            "nok_name", "nok_relationship", "nok_contact", "nok_email",
            "nok_2_name", "nok_2_relationship", "nok_2_contact", "nok_2_email",
            "status_id", "import_review_state", "import_notes",
        ]
        ls_sub = (
            f"(SELECT id FROM lead_sources WHERE code = '{rec.lead_source_code}')"
            if rec.lead_source_code else "NULL"
        )
        st_sub = "(SELECT id FROM person_statuses WHERE code = 'lead')"
        values = [
            sql_str(rec.full_name),
            sql_str(rec.student_id),
            sql_str(rec.known_as),
            sql_str(rec.title),
            sql_str(rec.initials),
            sql_str(tranche),
            ls_sub,
            sql_gender(rec.gender),
            sql_date(rec.dob),
            sql_str(rec.nationality),
            sql_str(rec.id_passport),
            sql_str(rec.home_language),
            sql_str(rec.other_language),
            sql_str(rec.mobile),
            sql_str(rec.secondary_number or rec.mobile_2),
            sql_str(rec.email),
            sql_str(rec.complex_estate),
            sql_str(rec.street_address),
            sql_str(rec.suburb),
            sql_str(rec.city),
            sql_str(rec.province),
            sql_str(rec.postal_code),
            sql_str(rec.nok_name),
            sql_str(rec.nok_relationship),
            sql_str(rec.nok_contact),
            sql_str(rec.nok_email),
            sql_str(rec.nok_2_name),
            sql_str(rec.nok_2_relationship),
            sql_str(rec.nok_2_contact),
            sql_str(rec.nok_2_email),
            st_sub,
            "'pending'",
            sql_str(notes_text),
        ]
        lines.append(f"INSERT INTO caregivers ({', '.join(cols)}) VALUES")
        lines.append(f"  ({', '.join(values)});")
        lines.append("SET @new_person = LAST_INSERT_ID();")

        # Attach the source PDF page (Original Data Entry Sheet)
        lines.append(
            "INSERT INTO attachments "
            "(person_id, attachment_type_id, file_path, original_filename, "
            "source_pdf, source_page, uploaded_by) VALUES ("
            "@new_person, "
            "(SELECT id FROM attachment_types WHERE code = 'original_data_entry_sheet'), "
            f"CONCAT('people/', (SELECT tch_id FROM caregivers WHERE id = @new_person), '/intake_sheet.pdf'), "
            f"{sql_str(Path(source_pdf).name)}, "
            f"{sql_str(Path(source_pdf).name)}, "
            f"{rec.source_page}, "
            "'system_import'"
            ");"
        )

        # Attach the photo
        if rec.photo_file:
            lines.append(
                "INSERT INTO attachments "
                "(person_id, attachment_type_id, file_path, original_filename, uploaded_by) VALUES ("
                "@new_person, "
                "(SELECT id FROM attachment_types WHERE code = 'profile_photo'), "
                f"CONCAT('people/', (SELECT tch_id FROM caregivers WHERE id = @new_person), '/photo.png'), "
                f"{sql_str(rec.photo_file)}, "
                "'system_import'"
                ");"
            )

        lines.append("")

    lines.append("COMMIT;")
    lines.append("")
    return "\n".join(lines)


# ─────────────────────────────────────────────────────────────────────────────
# Main
# ─────────────────────────────────────────────────────────────────────────────

def records_from_json(json_path: Path) -> list[PersonRecord]:
    """Load a hand-built or scaffolded records JSON and return PersonRecord objects."""
    payload = json.loads(json_path.read_text(encoding="utf-8"))
    out: list[PersonRecord] = []
    for raw in payload.get("records", []):
        # Drop keys that are not PersonRecord fields (forward compat)
        allowed = PersonRecord.__dataclass_fields__.keys()
        clean_raw = {k: v for k, v in raw.items() if k in allowed}
        # import_notes must be a list
        if "import_notes" in clean_raw and not isinstance(clean_raw["import_notes"], list):
            clean_raw["import_notes"] = [clean_raw["import_notes"]] if clean_raw["import_notes"] else []
        out.append(PersonRecord(**clean_raw))
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description="Parse a Tuniti intake PDF.")
    ap.add_argument("pdf", help="Path to the intake PDF")
    ap.add_argument("--tranche",   default=None, help="Tranche label, e.g. 'Tranche 1'")
    ap.add_argument("--output",    default="tools/intake_parser/output", help="Output dir")
    ap.add_argument("--from-json", default=None,
                    help="Skip text extraction and read records from this JSON file. "
                         "Photos are still rendered and SQL is still emitted.")
    args = ap.parse_args()

    pdf_path = Path(args.pdf).resolve()
    if not pdf_path.exists():
        print(f"ERROR: PDF not found: {pdf_path}", file=sys.stderr)
        return 1

    out_dir = Path(args.output).resolve()
    out_dir.mkdir(parents=True, exist_ok=True)
    photo_dir = out_dir / "photos"

    print(f"Reading: {pdf_path}")
    doc = fitz.open(pdf_path)
    print(f"Pages:   {doc.page_count}")
    print(f"Tranche: {args.tranche}")

    if args.from_json:
        from_json_path = Path(args.from_json).resolve()
        print(f"Records: loaded from {from_json_path}")
        records = records_from_json(from_json_path)
        if len(records) != doc.page_count:
            print(f"WARNING: JSON has {len(records)} records but PDF has "
                  f"{doc.page_count} pages.")
        # Render photos for each page that has a matching record (by index).
        for i, page in enumerate(doc, start=1):
            if i - 1 < len(records):
                records[i - 1].source_pdf = str(pdf_path)
                records[i - 1].source_page = i
                records[i - 1].photo_file = render_page_assets(
                    page, i, str(pdf_path), photo_dir, records[i - 1]
                )
    else:
        print()
        records = []
        for i, page in enumerate(doc, start=1):
            rec = parse_page(page, i, str(pdf_path), photo_dir)
            records.append(rec)
            ident = rec.full_name or "(no text layer — scaffold only)"
            sid = rec.student_id or "—"
            try:
                print(f"  Page {i:2d}: {sid:12s}  {ident}")
            except UnicodeEncodeError:
                print(f"  Page {i:2d}: {sid:12s}  (name contains chars the console can't render)")

    doc.close()

    base = pdf_path.stem
    json_path = out_dir / f"{base}.json"
    sql_path  = out_dir / f"{base}.sql"

    payload = {
        "source_pdf": str(pdf_path),
        "tranche":    args.tranche,
        "page_count": len(records),
        "records":    [
            {**asdict(r), "import_notes": r.import_notes}
            for r in records
        ],
    }
    json_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"\nWrote JSON: {json_path}")

    sql = emit_sql(records, args.tranche, str(pdf_path))
    sql_path.write_text(sql, encoding="utf-8")
    print(f"Wrote SQL:  {sql_path}")

    # Duplicate check by student_id
    seen: dict[str, int] = {}
    dups: list[str] = []
    for r in records:
        if r.student_id:
            seen[r.student_id] = seen.get(r.student_id, 0) + 1
    for sid, count in seen.items():
        if count > 1:
            dups.append(f"  {sid} appears {count} times")
    if dups:
        print("\nWARNING - duplicate student IDs in this PDF:")
        for d in dups:
            print(d)
    else:
        print("\nNo duplicate student IDs in this PDF.")

    print(f"\nDone. {len(records)} records.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
