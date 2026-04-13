"""
Split each cohort intake PDF into per-student single-page PDFs.

Mapping rule:
  - Cohort N PDF has N pages, one per student.
  - The Nth page of "Intake N.pdf" matches the student with the Nth-lowest
    person_id in cohort N (parser inserts in source_page order; person_id
    auto-increments).
  - Cohort 1 verified by name (Jolie Mpunga = page 1 = TCH-000001).
  - Cohorts 2-9: same mapping rule, needs spot-check by Ross post-run.

Output:
  - Per-student PDF: <STAGING>/people/<TCH-ID>/intake_sheet.pdf
  - Run the upload + DB-update steps in companion shell commands.
"""
from __future__ import annotations
import csv
import shutil
import subprocess
from pathlib import Path
import fitz

PDF_DIR    = Path(r"C:/Users/Intel/OneDrive/Claude/TCH/docs/PDF Imports")
STAGING    = Path(r"C:/Users/Intel/AppData/Local/Temp/tch_pdf_split")
ROSTER_TSV = Path(r"C:/Users/Intel/AppData/Local/Temp/students_live.tsv")  # from earlier session
MAPPING_OUT = Path(r"C:/ClaudeCode/_global/output/TCH/intake_pdf_split_mapping_apr-26.csv")


def students_per_cohort() -> dict[int, list[tuple[int, str, str]]]:
    """Returns {cohort_num: [(person_id, tch_id, full_name), ...]} sorted by person_id ASC."""
    out: dict[int, list[tuple[int, str, str]]] = {}
    rows = ROSTER_TSV.read_text(encoding="utf-8").splitlines()[1:]
    for line in rows:
        parts = line.split("\t")
        if len(parts) < 3:
            continue
        tch_id, full_name, cohort = parts[0].strip(), parts[1].strip(), parts[2].strip()
        if not cohort.lower().startswith("cohort "):
            continue
        try:
            n = int(cohort.split()[1])
        except (IndexError, ValueError):
            continue
        # Need person_id — derive from tch_id (TCH-000001 → 1)
        try:
            pid = int(tch_id.replace("TCH-", "").lstrip("0") or "0")
        except ValueError:
            continue
        out.setdefault(n, []).append((pid, tch_id, full_name))
    for n in out:
        out[n].sort(key=lambda r: r[0])
    return out


def split_one(cohort: int, students: list[tuple[int, str, str]]) -> list[dict]:
    pdf_path = PDF_DIR / f"Intake {cohort}.pdf"
    src = fitz.open(pdf_path)
    if src.page_count != len(students):
        print(f"  ⚠ Cohort {cohort}: {src.page_count} PDF pages vs {len(students)} students — skipping")
        src.close()
        return []

    rows = []
    for page_idx, (pid, tch_id, full_name) in enumerate(students):
        out_dir = STAGING / "people" / tch_id
        out_dir.mkdir(parents=True, exist_ok=True)
        out_pdf = out_dir / "intake_sheet.pdf"
        single = fitz.open()
        single.insert_pdf(src, from_page=page_idx, to_page=page_idx)
        single.save(out_pdf)
        single.close()
        rows.append({
            "cohort": cohort,
            "page": page_idx + 1,
            "person_id": pid,
            "tch_id": tch_id,
            "full_name": full_name,
            "rel_path": f"people/{tch_id}/intake_sheet.pdf",
        })
    src.close()
    print(f"  Cohort {cohort}: {len(rows)} per-student PDFs written")
    return rows


def main():
    if STAGING.exists():
        shutil.rmtree(STAGING)
    STAGING.mkdir(parents=True, exist_ok=True)

    cohorts = students_per_cohort()
    all_rows = []
    for n in sorted(cohorts.keys()):
        all_rows.extend(split_one(n, cohorts[n]))

    # Mapping CSV for Ross to spot-check
    MAPPING_OUT.parent.mkdir(parents=True, exist_ok=True)
    with MAPPING_OUT.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["cohort", "page", "person_id", "tch_id", "full_name", "rel_path"])
        w.writeheader()
        w.writerows(all_rows)

    # SQL update file
    sql_lines = ["-- AUTO-GENERATED — apply via mysql to point attachments at the per-student PDFs.",
                 "START TRANSACTION;"]
    for r in all_rows:
        sql_lines.append(
            f"UPDATE attachments SET file_path = 'people/{r['tch_id']}/intake_sheet.pdf' "
            f"WHERE person_id = {r['person_id']} "
            f"AND attachment_type_id = (SELECT id FROM attachment_types WHERE code='original_data_entry_sheet') "
            f"AND is_active = 1;"
        )
    sql_lines.append("COMMIT;")
    sql_path = STAGING / "update_attachments.sql"
    sql_path.write_text("\n".join(sql_lines), encoding="utf-8")

    print(f"\nStaging dir : {STAGING}")
    print(f"Mapping CSV : {MAPPING_OUT}")
    print(f"SQL script  : {sql_path}")
    print(f"Total split : {len(all_rows)} per-student PDFs")


if __name__ == "__main__":
    main()
