#!/usr/bin/env python3
"""
Upload all 109 Tranche 2-9 photos into a staging tree organised by TCH ID,
ready for SCP to the server. Run after parse_intake.py has rendered all the
Intake_N_pNN.png files.

Tranche → DB id mapping:
    Tranche 2: pages 1-17 → ids 15-31  (page N → id 14+N)
    Tranche 3: pages 1-12 → ids 32-43  (page N → id 31+N)
    Tranche 4: pages 1-14 → ids 44-57  EXCEPT page 10 → id 54, page 11 → id 53 (swapped)
    Tranche 5: pages 1-14 → ids 58-71
    Tranche 6: pages 1-14 → ids 72-85
    Tranche 7: pages 1-13 → ids 86-98
    Tranche 8: pages 1-12 → ids 99-110
    Tranche 9: pages 1-13 → ids 111-123

Output: tools/intake_parser/output/upload_staging/people/TCH-NNNNNN/photo.png
"""
import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PHOTOS = ROOT / "output" / "photos"
STAGING = ROOT / "output" / "upload_staging" / "people"

# Tranche -> page -> id mapping
TRANCHE_OFFSET = {
    2: 14,   # T2 page N → id 14+N
    3: 31,
    5: 57,
    6: 71,
    7: 85,
    8: 98,
    9: 110,
}

# Tranche 4 has the page 10/11 swap
T4_MAP = {
    1: 44, 2: 45, 3: 46, 4: 47, 5: 48, 6: 49, 7: 50, 8: 51, 9: 52,
    10: 54, 11: 53,
    12: 55, 13: 56, 14: 57,
}


def page_to_id(tranche: int, page: int) -> int:
    if tranche == 4:
        return T4_MAP[page]
    return TRANCHE_OFFSET[tranche] + page


def main() -> int:
    if STAGING.exists():
        shutil.rmtree(STAGING)
    STAGING.mkdir(parents=True)

    counts = {}
    for tranche in [2, 3, 4, 5, 6, 7, 8, 9]:
        # All photos for this tranche
        glob_pattern = f"Intake_{tranche}_p*.png"
        files = sorted(PHOTOS.glob(glob_pattern))
        # Filter out the *_full.png ones — only the cropped portraits
        files = [f for f in files if not f.stem.endswith("_full")]
        if not files:
            print(f"WARNING: no files for Tranche {tranche}")
            continue
        for f in files:
            # Extract page number from filename: Intake_2_p15.png → 15
            page_str = f.stem.split("_p")[-1]
            page = int(page_str)
            cg_id = page_to_id(tranche, page)
            tch_id = f"TCH-{cg_id:06d}"
            target_dir = STAGING / tch_id
            target_dir.mkdir(parents=True, exist_ok=True)
            target = target_dir / "photo.png"
            shutil.copy2(f, target)
            counts[tranche] = counts.get(tranche, 0) + 1

    print("Staged photo counts per tranche:")
    for t, c in sorted(counts.items()):
        print(f"  Tranche {t}: {c}")
    print(f"Total: {sum(counts.values())}")
    print(f"Staging dir: {STAGING}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
