# Ross — Action Items

**As of:** 10 April 2026

---

## Blocking Next Session

| # | Item | Priority | Notes |
|---|------|----------|-------|
| 1 | **Drop blank caregiver onboarding form into `docs/`** | HIGH | Scan, photo, or PDF of the paper form caregivers currently fill in. Needed to build the data entry screen. |
| 2 | **List standard attachments required per caregiver** | HIGH | ID copy + what else? (qualifications, proof of address, police clearance, etc.) |
| 3 | **Provide product/service list** | MEDIUM | What services does TCH currently offer? Needed for the product database. E.g. "Day Shift Care", "Live-In Care", "Post-Operative Care" etc. |

## Blocking Later Phases

| # | Item | Priority | Notes |
|---|------|----------|-------|
| 4 | **Provide messy training data** | MEDIUM | Individual course/module scores, weekly attendance — whatever format you have. I'll clean it. |
| 5 | **Confirm room/location names** | LOW | Are training rooms fixed names (dropdown) or free text? |
| 6 | **Confirm OJT duration** | LOW | Investor doc says weeks 11–14. Is that always 4 weeks? |
| 7 | **Review and confirm vision doc** | MEDIUM | `docs/TCH_Platform_Vision.md` — read through, flag anything wrong |
| 8 | **Review and confirm build plan** | MEDIUM | `docs/TCH_Plan.md` — check priority order makes sense |

## Ongoing

| # | Item | Notes |
|---|------|-------|
| 9 | **Purge CDN cache after deployments** | StackCP > CDN > Edge Caching. Only needed until we go to production and set proper cache rules. |
| 10 | **DB credentials** | Stored in server `.env`. Username: `tch_admin`, DB: `tch_placements-313539d33a`, host: `shareddb-y.hosting.stackcp.net` |

## Person Database Build (added 10 April 2026)

Decisions locked in this session for unifying student/caregiver into a single Person record:

| # | Item | Priority | Notes |
|---|------|----------|-------|
| 11 | **System config admin page** | MEDIUM | One UI to manage all lookup lists: `person_statuses`, `lead_sources`, `attachment_types`, future `relationships`, etc. Replaces hard-coded ENUMs. |
| 12 | **Status promotion gates** | MEDIUM | Define required-fields-per-status and enforce in app layer when status is changed (e.g. cannot move to `Qualified` without DOB, ID number, course completion). DB stays permissive. |
| 13 | **Referrer / affiliate model** | LOW (build later) | When `lead_source = Referral`, capture who referred them. Future-proof for incentive payments. Today: free-text `referred_by_name` + `referred_by_contact` on the person record. Later: full referrer table + payout tracking. |
| 14 | **Field-level role-based edit permissions** | MEDIUM | Per-field edit/view rules based on user role. E.g. only finance role can edit banking, only admin can change `tch_id`. |
| 15 | **Person record card view** | HIGH (this build) | Card view styled to mirror the Tuniti PDF intake page: photo top-left, two columns of structured fields, NoK block, attachments list. Used for the import review screen and for the standard person profile screen. |
| 16 | **Retire `name_lookup` table** | MEDIUM | Once all 9 PDFs imported and matched, backfill all legacy FKs (caregiver_costs, daily_roster, etc.) and drop `name_lookup`. End state = single canonical person record, no name strings used as identity anywhere. |
| 17 | **`tch_id` immutable identifier** | DONE in migration 003 | Format `TCH-000001`. Auto-assigned on insert. Used in URLs and as the human-facing person identifier. Survives marriage / name changes. |
| 18 | **Replace placeholder portraits with full-quality photos** | LOW | Current portraits are crops from the Tuniti intake PDFs — adequate but low resolution. Source higher-quality originals from Tuniti or re-photograph. Each replacement lands as a NEW `profile_photo` attachment (history preserved). |

## Requires Tuniti Approval / Clarification

**Context:** All 123 caregivers in `import_review_state = 'pending'` were enriched
from Tuniti intake PDFs. The PDF data is canonical per Ross's decision but contains
data-quality issues that need Tuniti (or the candidates) to confirm/correct before
the records are approved. Each item below has a matching note in the relevant
caregiver's `import_notes` column on dev.

### Invalid / impossible data

| # | Person (TCH ID) | Issue | What Tuniti needs to do |
|---|---|---|---|
| T1 | TCH-000103 (Ntombifikile Octavia Mhlongo, T8) | PDF DOB literally `0005-08-03` (year 0005, impossible). Existing DB DOB left in place, NOT overwritten. ID number 7508030807080 suggests real DOB is 1975-08-03. | Confirm the correct DOB and re-issue the PDF if needed. |
| T2 | TCH-000060 (Esther Kawanzaruwa, T5) | NoK contact `078282933` is only 9 digits — missing one digit. | Provide correct 10-digit number. |
| T3 | TCH-000100 (Martha Kedibone Mashigo, T8) | NoK contact `07258455122` is 11 digits — one too many. | Provide correct 10-digit number. |
| T4 | TCH-000026 (Segethi Tabea Molefe, T2) | Email `molefetabea154@gmail` — missing `.com`. | Provide complete email. |
| T5 | TCH-000052 (Sara Mdaka, T4) | Email `sarahmdaka41@gmai.com` — missing the `l` (gmai → gmail). | Confirm correct email. |
| T6 | TCH-000090 (Josephine Olaide Olaleye, T7) | Email `josephinetoolz@outlool.com` — `outlool` not `outlook`. | Confirm correct email. |

### NoK contact identical to candidate's own mobile (likely data entry error)

| Person (TCH ID) | NoK | Both numbers |
|---|---|---|
| TCH-000002 (Mukuna Mbuyi / Giselle, T1) | Felly (Brother) | 0610932278 |
| TCH-000024 (Refiloe Khuzwayo, T2) | _(Lerato, Sister)_ | _(check on dev — flagged)_ |
| TCH-000059 (Beverly Lehabe, T5) | Grace (Mother) | 0837105468 |
| TCH-000099 (Maphefo Dinah Mogola, T8) | Aaron (Partner) | 0846473643 |
| TCH-000108 (Chipo Mujere, T8) | Stewart Marange (Husband) | 0718097605 |
| TCH-000119 (Juliet Tshakane Lekgothoane, T9) | Albert Soafo (Husband) | 0765283954 |

→ **Tuniti to provide correct, distinct NoK numbers for all six.**

### Possible name collisions (need confirmation these are different people)

| # | Records | Status |
|---|---|---|
| N1 | TCH-000003 (Nelly Nachilongo, T1) and TCH-000014 (Siphiwe Nelly Ezeadum, T1) | Two "Nelly"s in the same tranche — different DOBs and IDs, treated as different people. **Confirm with Tuniti.** |
| N2 | TCH-000030 (Thandi Ngobeni, T2) and TCH-000097 (Thandiwe Dhlodhlo, T7) | Two "Thandi"s — treated as different people. **Confirm.** |
| N3 | TCH-000028 (Siphilisiwe Nkala, T2), TCH-000067 (Siphathisiwe Nkala, T5), TCH-000105 (Sithenjisiwe Gumbi, T8) | Three similar names — different DOBs and IDs, treated as different people. **Confirm with Tuniti.** |

### Possible household / family links (need confirmation)

| # | Records | Observation |
|---|---|---|
| H1 | TCH-000002 (Mukuna Mbuyi, T1) and TCH-000005 (Jovani Mukuna Tshibingu, T1) | Both have NoK first name "Felly". Possibly same person (sibling/parent of one is parent of the other). |
| H2 | TCH-000087 (Bekithemba Mpofu, T7) and TCH-000105 (Sithenjisiwe Gumbi, T8) | Bekithemba's NoK contact (0652458862) is identical to Sithenjisiwe's own mobile. Bekithemba's NoK is named "Sthenjisiwe" — likely the same person. |
| H3 | TCH-000021 (Merriam Mashadi Maluleke, T2) and TCH-000078 (Phemela Rachel Maluleke, T6) | Identical mobile number 0798474060. Same surname. Likely related. |
| H4 | TCH-000109 (Janine Louise Jones, T8) and TCH-000114 (Marlise Louise Els, T9) | Same address: 147B Tammy Street, Grootfontein. Both have middle name "Louise". Possibly housemates or family. |

→ **Tuniti to confirm relationships so we know how to model them.**

### Tranche assignment / student ID anomalies

| # | Person | Issue |
|---|---|---|
| Y1 | TCH-000003 (Nelly Nachilongo) | Listed in Tranche 1 but student ID is `202603-1` (March 2026 prefix), not `202507-NN` like the other 13. Other `202603-*` IDs all live in Tranche 9. **Confirm correct tranche.** |
| Y2 | TCH-000003 | Single-digit suffix `-1` rather than `-NN` format used by every other record. **Confirm ID.** |

### Gender / first name mismatches (likely data entry errors)

| # | Person | Issue |
|---|---|---|
| G1 | TCH-000056 (Colin Khutso Nyalungu, T4) | First name "Colin" is typically masculine but the title is "Miss" and gender is Female. **Confirm with candidate.** |
| G2 | TCH-000054 (Hloniphani Moyo / Kelly, T4) | "Hloniphani" is typically a male Zimbabwean name; recorded as Female with title "Miss" and known_as "Kelly". **Confirm.** |

### Address inconsistencies (suburb/city mismatches in Gauteng metros)

These are not blockers but should be cleaned up. Each is a case where a suburb
in one metro is recorded with a city in a different metro:

* **Tembisa/Pretoria** (Tembisa is in Ekurhuleni): TCH-000010, 000011, 000054, 000056, 000091, 000113, 000116
* **Midrand/Pretoria** (Midrand is Johannesburg metro): TCH-000010
* **Benoni/Pretoria** (Benoni is in Ekurhuleni): TCH-000115
* **Kempton Park/Pretoria** (Kempton Park is in Ekurhuleni): TCH-000040
* **Waterkloof/Mamelodi East** (different parts of Pretoria, can't be both): TCH-000012
* **Mpumalanga as a city** (it's a province): TCH-000048, 000095
* **Gauteng as a city** (it's a province): TCH-000019

→ **Tuniti to clean up addresses with each candidate** and re-issue the form data.

### Generic "Social_media" lead source

The PDF lead source `Social_media` is not specific enough to map to a channel
(we have Facebook, TikTok, Instagram, LinkedIn as separate values). Currently
NULL with a note. Affects approximately 15 records:

TCH-000010, TCH-000011, TCH-000013, TCH-000014, TCH-000019, TCH-000028,
TCH-000039, TCH-000074, TCH-000081, TCH-000082, TCH-000083, TCH-000085,
TCH-000098, TCH-000102, TCH-000103, TCH-000113, TCH-000114, TCH-000115,
TCH-000116, TCH-000117

→ **Tuniti to ask each candidate which platform** (Facebook / TikTok / Instagram /
LinkedIn / other) and update the source data.

### Typos in source data (preserved as written)

Numerous typos in nationalities, languages, suburbs, cities, NoK names. Each one
is flagged in the relevant caregiver's `import_notes`. These are not blockers but
the candidate-facing source data should be cleaned up by Tuniti at some point.
Examples (not exhaustive):

* Cities: Preotia, Pretoira, Pretroia, Johnesburg, Johnnesburg, Jobrug, Sweto,
  Hammenskraal, Acradia
* Suburbs: Pretoira West, Klinkenberg Gradens, Bryaston, Tuffontain, Mamalodi
* Nationalities: Zimbabwean → Zimbabwen, Zimbabwan, Zimbabwe (country not
  nationality)
* Languages: Sepedi → Spedi, Speed; Setswana → Setswane; Xitsonga → Xitsongo,
  Xitsomga; Ndebele → Ndebeale; Yoruba → Yoryba
* NoK names: Husabnd, Darlignton, Mahlanlane, Suprise, Jocob

### Known As discrepancies (workbook vs PDF — PDF adopted)

The workbook had a different "Known As" value to the PDF on roughly 25 records.
PDF wins per Ross's decision. Each one is flagged in `import_notes`. **Tuniti
may want to confirm the PDF version is the candidate's actual preference.**

Highlights:
* TCH-000002: Maman Mukuna → Giselle
* TCH-000015: Hlengiwe → Mahle
* TCH-000026: Tabea → Mia
* TCH-000027: Spheto → Sphe
* TCH-000029: Delisile → Sylvia
* TCH-000032: Bongani → Bongo
* TCH-000039: Susan → Susie
* TCH-000044: Busisiwe → Busi
* TCH-000045: Dikeledi → Kgosigadi
* TCH-000046: Emilia → Emmy
* TCH-000047: Mariam → Joan
* TCH-000048: Martha → Mokgadi
* TCH-000049: Julia → Nare
* TCH-000050: Casey → Nipho
* TCH-000054: Hloniphani → Kelly
* TCH-000064: Glenda → Musa
* TCH-000069: Marcia → Busisiwe
* TCH-000071: Mondli → Collen
* TCH-000073: Nomvula → Christina
* TCH-000076: Memory → Cindy
* TCH-000078: Rachel → Phemela
* TCH-000084: Division → Lani
* TCH-000085: Kemi → Mary
* TCH-000087: Bekithemba → T Man
* TCH-000088: Blessing → Rofhiwa
* TCH-000089: Tshego → Imma
* TCH-000092: Margaret → Katso
* TCH-000094: Octovia → Tsundzu
* TCH-000096: Sibonokuhle → Bongi
* TCH-000097: Thandiwe → Thandi
* TCH-000100: Kedibone → Martha
* TCH-000101: Martha → Uke
* TCH-000109: Janine → Louise
* TCH-000113: Mamohlolo → Nompi
* TCH-000118: Siphesihle → Sihle
* TCH-000121: Mashudu → Thalitha
* TCH-000122: Tsakani → Philadelphia

## Access Details (for reference)

- **Site:** https://tch.intelligentae.co.uk/
- **Admin login:** https://tch.intelligentae.co.uk/login
  - Username: `ross`
  - Password: `TchAdmin2026x`
- **Git branch:** `dev` (8 commits, nothing on `main` yet)
