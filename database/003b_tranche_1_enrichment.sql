-- TCH Placements — Migration 003b: Tranche 1 enrichment from Tuniti PDF
--
-- One-shot data load. Run AFTER 003 + 003a are complete.
--
-- This script:
--   1. Standardises tranche labels system-wide:
--        '1st Intake' → 'Tranche 1', ..., '9th Intake' → 'Tranche 9'
--        ('N/K' is left alone — unknown remains unknown.)
--   2. Enriches the 14 existing Tranche 1 caregivers (ids 1-14) with the
--      data from the Tuniti PDF "Intake 1 (3).pdf" (Tranche 1).
--   3. Sets each enriched row to import_review_state='pending' so it shows
--      in the new admin review queue.
--   4. Inserts two attachments per enriched person:
--        - Original Data Entry Sheet (the PDF page reference)
--        - Profile Photo (the cropped portrait extracted by parse_intake.py)
--
-- Decisions locked in the 2026-04-10 session:
--   - PDF data is canonical for ids 1-14 ("take from Tuniti PDF").
--   - Existing values for Jolie (id 1) and Mukuna (id 2) — DOB / mobile /
--     email / NoK / etc. — are overwritten by PDF; the old values are
--     preserved verbatim in import_notes for audit.
--   - For id 5 (Jovani Mukuna Tshibingu) the DB full_name "Jovani" is KEPT
--     because the PDF title spells it "Jonvai" (typo confirmed by the PDF's
--     own Known As = "Jovani").
--   - The "Maman Mukuna" nickname is replaced with "Giselle" (PDF) and the
--     old known_as is noted.
--
-- Reviewed by Ross before this script ran.

START TRANSACTION;

-- ============================================================
-- 1. Tranche label standardisation (system-wide)
-- ============================================================
UPDATE caregivers SET tranche = 'Tranche 1' WHERE tranche = '1st Intake';
UPDATE caregivers SET tranche = 'Tranche 2' WHERE tranche = '2nd Intake';
UPDATE caregivers SET tranche = 'Tranche 3' WHERE tranche = '3rd Intake';
UPDATE caregivers SET tranche = 'Tranche 4' WHERE tranche = '4th Intake';
UPDATE caregivers SET tranche = 'Tranche 5' WHERE tranche = '5th Intake';
UPDATE caregivers SET tranche = 'Tranche 6' WHERE tranche = '6th Intake';
UPDATE caregivers SET tranche = 'Tranche 7' WHERE tranche = '7th Intake';
UPDATE caregivers SET tranche = 'Tranche 8' WHERE tranche = '8th Intake';
UPDATE caregivers SET tranche = 'Tranche 9' WHERE tranche = '9th Intake';
-- 'N/K' retained as-is.

-- Reusable helper variables for the attachment INSERTs
SET @pdf_filename     = 'Intake 1 (3).pdf';
SET @pdf_relative     = 'intake/Tranche 1 - Intake 1.pdf';
SET @att_type_sheet   = (SELECT id FROM attachment_types WHERE code = 'original_data_entry_sheet');
SET @att_type_photo   = (SELECT id FROM attachment_types WHERE code = 'profile_photo');
SET @ls_referral      = (SELECT id FROM lead_sources WHERE code = 'referral');
SET @ls_word_of_mouth = (SELECT id FROM lead_sources WHERE code = 'word_of_mouth');

-- ============================================================
-- 2. Per-record enrichment
-- ============================================================

-- ── id 1: Jolie Mpunga (PDF: 202507-10) ──────────────────────
UPDATE caregivers SET
    student_id = '202507-10',
    known_as = 'Jolie',
    title = 'Miss',
    initials = 'J',
    id_passport = '8811251242261',
    dob = '1988-11-25',
    gender = 'Female',
    nationality = 'DRC',
    home_language = 'French',
    other_language = 'English',
    mobile = '0632399863',
    email = 'Joliemayombo258@gmail.com',
    street_address = '66 Onderstre Street',
    suburb = 'Krugerdorp West',
    city = 'Preotia',
    province = 'Gauteng',
    nok_name = 'Gracia Ntumba',
    nok_relationship = 'Sister',
    nok_contact = '0710739686',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10. PDF adopted as canonical per Ross''s decision (option 1: trust the PDF entirely).',
        CONCAT('Pre-enrichment values preserved (workbook source):',
               '\n  student_id: 202509-1',
               '\n  dob: 1983-01-20',
               '\n  nationality: Congolese',
               '\n  mobile: 0616413814',
               '\n  email: jolie.mpunga4@gmail.com',
               '\n  city: Johannesburg',
               '\n  nok_name: Patrick Mpunga'),
        'PDF data flags:',
        ' - City field is ''Preotia'' on the PDF — almost certainly meant ''Pretoria''. Preserved as written; verify with candidate.',
        ' - Street ''66 Onderstre Street'' in suburb ''Krugerdorp West'' but city ''Pretoria'' — geographic inconsistency, the address may belong to Krugersdorp not Pretoria.')
WHERE id = 1;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (1, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 1, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (1, @att_type_photo, 'people/TCH-000001/photo.png', 'Intake_1_3_p01.png', 'system_import');

-- ── id 2: Mukuna Mbuyi (PDF: 202507-11, known_as: Giselle) ───
UPDATE caregivers SET
    student_id = '202507-11',
    known_as = 'Giselle',
    title = 'Mrs.',
    initials = 'M',
    id_passport = 'JHBCOG06200209',
    dob = '1983-12-22',
    gender = 'Female',
    nationality = 'DRC',
    home_language = 'French',
    other_language = 'English',
    mobile = '0610932278',
    email = 'giselle67mbuyi@gmail.com',
    street_address = '63 Frere Road',
    suburb = 'Judith Paarl',
    city = 'Johannesburg',
    province = 'Gauteng',
    nok_name = 'Felly',
    nok_relationship = 'Brother',
    nok_contact = '0610932278',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10. PDF adopted as canonical per Ross''s decision (option 1: trust the PDF entirely).',
        CONCAT('Pre-enrichment values preserved (workbook source):',
               '\n  student_id: 202509-2',
               '\n  known_as: Maman Mukuna',
               '\n  dob: 1976-05-06',
               '\n  nationality: Congolese',
               '\n  mobile: 0682555283',
               '\n  email: mukuna.banza@yahoo.com',
               '\n  nok_name: Mbuyi Joseph'),
        'PDF data flags:',
        ' - NoK contact number (0610932278) is identical to candidate''s own mobile — likely a data-entry error. Verify.')
WHERE id = 2;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (2, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 2, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (2, @att_type_photo, 'people/TCH-000002/photo.png', 'Intake_1_3_p02.png', 'system_import');

-- ── id 3: Nelly Nachilongo (PDF: 202603-1) ───────────────────
-- Existing DB row had only 'Nelly' as full_name; surname adopted from PDF.
UPDATE caregivers SET
    full_name = 'Nelly Nachilongo',
    student_id = '202603-1',
    known_as = 'Nelly',
    title = 'Miss',
    initials = 'N',
    id_passport = 'ZN997207',
    dob = '1993-06-07',
    gender = 'Female',
    nationality = 'South African',
    home_language = 'Shona',
    mobile = '0779789171',
    complex_estate = '47 Eight Str',
    suburb = 'Atteridgeville',
    city = 'Pretoria',
    province = 'Gauteng',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'full_name surname added: ''Nelly'' → ''Nelly Nachilongo''.',
        'PDF data flags:',
        ' - Student ID ''202603-1'' uses prefix 202603 (March 2026) while every other record in this PDF uses 202507 (July 2025). This person may have been assigned to this PDF/tranche after the original cohort. Confirm tranche assignment.',
        ' - Nationality ''South African'' but home language ''Shona'' — Shona is a Zimbabwean language. May indicate dual nationality or a data error.',
        ' - Address split is unusual: ''Complex/Estate'' contains ''47 Eight Str'' which looks like a street address; ''Street Address'' is blank. Likely the form was filled in the wrong field.',
        ' - No emergency contact captured. No email captured. Lead source blank.',
        ' - Single-digit suffix ''-1'' rather than the ''-NN'' format used by all other records — confirm the ID.')
WHERE id = 3;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (3, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 3, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (3, @att_type_photo, 'people/TCH-000003/photo.png', 'Intake_1_3_p03.png', 'system_import');

-- ── id 4: Sylvie Lubamba Mbaya (PDF: 202507-13) ──────────────
UPDATE caregivers SET
    student_id = '202507-13',
    known_as = 'Sylvie',
    title = 'Mrs.',
    initials = 'SL',
    id_passport = '7605041186262',
    dob = '1976-05-04',
    gender = 'Female',
    nationality = 'DRC',
    home_language = 'French',
    other_language = 'English',
    mobile = '0658326520',
    email = 'sylvieklubamba@gmail.com',
    complex_estate = 'Gallo Manor',
    street_address = '7 Letaba Drive',
    suburb = 'Sandton',
    city = 'Johnnesburg',
    province = 'Gauteng',
    nok_name = 'Kevin Mbaya',
    nok_relationship = 'Son',
    nok_contact = '0680067992',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'PDF data flags:',
        ' - City ''Johnnesburg'' on the PDF — typo for ''Johannesburg''. Preserved as written.')
WHERE id = 4;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (4, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 4, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (4, @att_type_photo, 'people/TCH-000004/photo.png', 'Intake_1_3_p04.png', 'system_import');

-- ── id 5: Jovani Mukuna Tshibingu (PDF: 202507-14) ───────────
-- IMPORTANT: PDF title spells "Jonvai" but PDF Known As is "Jovani". DB
-- already has the correct spelling "Jovani" — we KEEP the DB full_name and
-- do not adopt the PDF typo.
UPDATE caregivers SET
    student_id = '202507-14',
    known_as = 'Jovani',
    title = 'Mr.',
    initials = 'JM',
    id_passport = 'JHBCOD01510209',
    dob = '2006-06-30',
    gender = 'Male',
    nationality = 'DRC',
    home_language = 'French',
    other_language = 'English',
    mobile = '0659644809',
    email = 'jovanimukuna1@gmail.com',
    street_address = '67 Shannon Road',
    suburb = 'Noordheywel Villa',
    city = 'Krugersdorp',
    province = 'Gauteng',
    nok_name = 'Felly',
    nok_relationship = 'Father',
    nok_contact = '0677030346',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'full_name kept as ''Jovani Mukuna Tshibingu'' (DB version). The PDF title shows ''Jonvai'' but the PDF Known As is ''Jovani'' — PDF title is a typo, DB version was correct.',
        'PDF data flags:',
        ' - DOB 2006-06-30 makes the candidate 19 at intake (July 2025) — confirm this is correct, may be the youngest in the cohort.',
        ' - NoK first name ''Felly'' is the same as the NoK on record id 2 (Mukuna Mbuyi) — possibly the same person. May indicate a family link between records 2 and 5.')
WHERE id = 5;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (5, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 5, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (5, @att_type_photo, 'people/TCH-000005/photo.png', 'Intake_1_3_p05.png', 'system_import');

-- ── id 6: Tanyanyiwa Mahere (PDF: 202507-15) ─────────────────
UPDATE caregivers SET
    student_id = '202507-15',
    known_as = 'Tanyanyiwa',
    title = 'Mr.',
    initials = 'T',
    id_passport = '43126965K43',
    dob = '1980-12-24',
    gender = 'Male',
    nationality = 'Zimbabwean',
    home_language = 'Shona',
    other_language = 'English',
    mobile = '0694019542',
    email = 'Maheretanyanywia80@gmail.com',
    street_address = '30 Masopha Street',
    suburb = 'Sulsville',
    city = 'Atteridgeville',
    province = 'Gauteng',
    nok_name = 'Monica Ndaba',
    nok_relationship = 'Wife',
    nok_contact = '0789861597',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'PDF data flags:',
        ' - Email contains ''tanyanywia'' (transposed letters) — preserved as written.')
WHERE id = 6;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (6, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 6, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (6, @att_type_photo, 'people/TCH-000006/photo.png', 'Intake_1_3_p06.png', 'system_import');

-- ── id 7: Elly Dhlabo (PDF: 202507-16) ───────────────────────
UPDATE caregivers SET
    student_id = '202507-16',
    known_as = 'Elly',
    title = 'Mr.',
    initials = 'E',
    id_passport = '7903205797085',
    dob = '1979-03-20',
    gender = 'Male',
    nationality = 'South African',
    home_language = 'Xhosa',
    other_language = 'English',
    mobile = '0797036843',
    email = 'edhlabo@gmail.com',
    street_address = '24 Becker Road',
    suburb = 'Olifantsfontein',
    city = 'Pretoria',
    province = 'Gauteng',
    nok_name = 'Agnes Dhlabo',
    nok_relationship = 'Mother',
    nok_contact = '0842837769',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.')
WHERE id = 7;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (7, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 7, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (7, @att_type_photo, 'people/TCH-000007/photo.png', 'Intake_1_3_p07.png', 'system_import');

-- ── id 8: Wisani Precious Mashaba (PDF: 202507-18) ───────────
-- DB had 'Wisani Precious Mash' (truncated). Surname completed from PDF.
UPDATE caregivers SET
    full_name = 'Wisani Precious Mashaba',
    student_id = '202507-18',
    known_as = 'Precious',
    title = 'Miss',
    initials = 'WP',
    id_passport = '0306090854085',
    dob = '2003-06-09',
    gender = 'Female',
    nationality = 'South African',
    home_language = 'Tsonga',
    other_language = 'English',
    mobile = '0810826696',
    email = 'Mashaba.precious@gmail.com',
    street_address = '430 Sisulu Street',
    suburb = 'Central CBD',
    city = 'Pretoria',
    province = 'Gauteng',
    nok_name = 'Tusoh',
    nok_relationship = 'Sister',
    nok_contact = '0732376712',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'full_name completed: ''Wisani Precious Mash'' → ''Wisani Precious Mashaba'' (DB row was truncated).',
        'PDF data flags:',
        ' - Page 8 in the PDF holds student_id 202507-18, while page 9 holds 202507-17 — the pages in this PDF are not in student_id order. Records 17 and 18 are swapped relative to numeric order. No action needed; flagged for awareness.')
WHERE id = 8;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (8, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 8, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (8, @att_type_photo, 'people/TCH-000008/photo.png', 'Intake_1_3_p08.png', 'system_import');

-- ── id 9: Miriam Mehlomakulu (PDF: 202507-17) ────────────────
UPDATE caregivers SET
    student_id = '202507-17',
    known_as = 'Mimi',
    title = 'Mrs.',
    initials = 'M',
    id_passport = '86060761E86',
    dob = '1990-05-15',
    gender = 'Female',
    nationality = 'Zimbabwean',
    home_language = 'Shona',
    other_language = 'English',
    mobile = '0749346519',
    email = 'Miriam.mimi@icloud.com',
    street_address = '367 Christoffel Street',
    suburb = 'Pretoira West',
    city = 'Pretoria',
    province = 'Gauteng',
    nok_name = 'Kudzai Edmore Gwese',
    nok_relationship = 'Husband',
    nok_contact = '0742305877',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'PDF data flags:',
        ' - Suburb ''Pretoira West'' on the PDF — typo for ''Pretoria West''. Preserved as written.')
WHERE id = 9;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (9, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 9, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (9, @att_type_photo, 'people/TCH-000009/photo.png', 'Intake_1_3_p09.png', 'system_import');

-- ── id 10: Minenhle Nkazimulo Fuyane (PDF: 202507-19) ────────
UPDATE caregivers SET
    student_id = '202507-19',
    known_as = 'Enhle',
    title = 'Miss',
    initials = 'MN',
    id_passport = '292054009J28',
    dob = '2003-08-29',
    gender = 'Female',
    nationality = 'Zimbabwan',
    home_language = 'Shona',
    other_language = 'English',
    mobile = '0751758999',
    email = 'nkosazanaenthle@gmail.com',
    complex_estate = '2190/13 Parklands Estate',
    street_address = 'Antimony Street',
    suburb = 'Midrand',
    city = 'Pretoria',
    province = 'Gauteng',
    nok_name = 'Sihle Radebe',
    nok_relationship = 'Mother',
    nok_contact = '0765390756',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'PDF data flags:',
        ' - Nationality ''Zimbabwan'' on the PDF — typo for ''Zimbabwean''. Preserved as written.',
        ' - Lead source ''Social_media'' — generic. Not mapped to a specific channel; left blank for review (TODO: ask candidate which platform).',
        ' - Suburb ''Midrand'' but city ''Pretoria'' — Midrand is in Johannesburg metro, not Pretoria. Address inconsistency, verify.')
WHERE id = 10;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (10, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 10, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (10, @att_type_photo, 'people/TCH-000010/photo.png', 'Intake_1_3_p10.png', 'system_import');

-- ── id 11: Mafusi Elizabeth Mofokeng (PDF: 202507-20) ────────
UPDATE caregivers SET
    student_id = '202507-20',
    known_as = 'Elizabeth',
    title = 'Miss',
    initials = 'ME',
    id_passport = '8610290680089',
    dob = '1986-10-29',
    gender = 'Female',
    nationality = 'South African',
    home_language = 'Sotho',
    other_language = 'English',
    mobile = '0838983313',
    email = 'Elimokokeng86@gmail.com',
    street_address = '420 Tshepo Extension',
    suburb = 'Tembisa',
    city = 'Pretoria',
    province = 'Gauteng',
    postal_code = '1632',
    nok_name = 'Geelbooi Skosana',
    nok_relationship = 'Partner',
    nok_contact = '0739856267',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'PDF data flags:',
        ' - Lead source ''Social_media'' — generic. Not mapped; left blank for review.',
        ' - Suburb ''Tembisa'' but city ''Pretoria'' — Tembisa is in the Ekurhuleni metro, not Pretoria. Address inconsistency.')
WHERE id = 11;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (11, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 11, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (11, @att_type_photo, 'people/TCH-000011/photo.png', 'Intake_1_3_p11.png', 'system_import');

-- ── id 12: Ramogohlo Daphney Aphane (PDF: 202507-21) ─────────
UPDATE caregivers SET
    student_id = '202507-21',
    known_as = 'Daphney',
    title = 'Mrs.',
    initials = 'RD',
    id_passport = '7709010571080',
    dob = '1977-09-01',
    gender = 'Female',
    nationality = 'South African',
    home_language = 'Sepedi',
    other_language = 'English',
    mobile = '0828300662',
    email = 'Molebogengabigari1271@gmail.com',
    street_address = 'PH1-1383 Schrome street',
    suburb = 'Waterkloof',
    city = 'Mamelodi East',
    province = 'Gauteng',
    nok_name = 'Mogotladi Aphane',
    nok_relationship = 'Sister',
    nok_contact = '0760609768',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'PDF data flags:',
        ' - Suburb ''Waterkloof'' but city ''Mamelodi East'' — these are different parts of Pretoria, not contained within each other. Address may need re-checking.')
WHERE id = 12;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (12, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 12, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (12, @att_type_photo, 'people/TCH-000012/photo.png', 'Intake_1_3_p12.png', 'system_import');

-- ── id 13: Akhona Nomonde Mkize (PDF: 202507-22) ─────────────
-- Multi-value NoK row split into nok_* and nok_2_*
UPDATE caregivers SET
    student_id = '202507-22',
    known_as = 'Nomonde',
    title = 'Miss',
    initials = 'AN',
    id_passport = '0204241122082',
    dob = '2002-04-24',
    gender = 'Female',
    nationality = 'South African',
    home_language = 'IsiZulu',
    other_language = 'English',
    mobile = '0679779903',
    email = 'mkhizeakhonanomonde@gmail.com',
    street_address = '31 Protea Road',
    suburb = 'Wyohwood',
    city = 'Germiston',
    province = 'Gauteng',
    postal_code = '1401',
    nok_name = 'Fana',
    nok_relationship = 'Brother',
    nok_contact = '081 864 2125',
    nok_2_name = 'Trinity',
    nok_2_relationship = 'Sister in law',
    nok_2_contact = '079 222 9006',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'PDF showed two next-of-kin contacts crammed into one row: Name ''Fana / Trinity'', Relationship ''Brother / Sister in law'', Contact Number ''081 864 2125 / 079 222 9006''. Split into nok_* and nok_2_* per agreed multi-value handling.',
        'PDF data flags:',
        ' - Lead source ''Social_media'' — generic. Not mapped; left blank for review.')
WHERE id = 13;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (13, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 13, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (13, @att_type_photo, 'people/TCH-000013/photo.png', 'Intake_1_3_p13.png', 'system_import');

-- ── id 14: Siphiwe Nelly Ezeadum (PDF: 202507-23) ────────────
UPDATE caregivers SET
    student_id = '202507-23',
    known_as = 'Nelly',
    title = 'Mrs.',
    initials = 'SN',
    id_passport = '7808260549083',
    dob = '1978-08-26',
    gender = 'Female',
    nationality = 'South African',
    home_language = 'English',
    mobile = '0661607011',
    email = 'Nellyezeadum76@gmail.com',
    street_address = '9 Summerson Street',
    suburb = 'Minor Brone',
    city = 'Brakpan',
    province = 'Gauteng',
    postal_code = '1549',
    nok_name = 'Bishop Chidozie Ezeadum',
    nok_relationship = 'Husband',
    nok_contact = '0748588813',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n',
        NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 1 PDF on 2026-04-10.',
        'PDF data flags:',
        ' - Lead source ''Social_media'' — generic. Not mapped; left blank for review.',
        ' - Two records in this PDF have known_as ''Nelly'' (this one and 202603-1, id 3). Different people; no merge.')
WHERE id = 14;

INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by)
VALUES (14, @att_type_sheet, @pdf_relative, @pdf_filename, @pdf_filename, 14, 'system_import');
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, uploaded_by)
VALUES (14, @att_type_photo, 'people/TCH-000014/photo.png', 'Intake_1_3_p14.png', 'system_import');

COMMIT;

-- ============================================================
-- Post-load checks (run as separate statements after the commit)
-- ============================================================
-- SELECT COUNT(*) AS pending_review FROM caregivers WHERE import_review_state = 'pending';
-- Expected: 14
--
-- SELECT COUNT(*) AS attachment_rows FROM attachments;
-- Expected: 28 (14 sheets + 14 photos)
--
-- SELECT tranche, COUNT(*) FROM caregivers GROUP BY tranche ORDER BY tranche;
-- Expected: Tranche 1..9 + N/K, NO 'Nth Intake' values remain
