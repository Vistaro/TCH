-- TCH Placements — Migration 003c: Tranches 2-9 enrichment from Tuniti PDFs
--
-- One-shot data load. Run AFTER 003 + 003a + 003b are complete.
--
-- This script enriches the existing caregiver rows for Tranches 2-9 with the
-- data from the corresponding Tuniti intake PDFs (Intake 2.pdf .. Intake 9.pdf).
--
-- Approach (locked in 2026-04-10 session):
--   - PDF is canonical
--   - Pre-enrichment values are NOT enumerated per row in import_notes (too
--     verbose for 109 records). The pre-enrichment full DB state is captured
--     in database/backups/caregivers_pre_tranches_2_9.sql for audit.
--   - Each enriched row gets a brief audit note in import_notes flagging the
--     enrichment date, source PDF, and any data quality flags.
--   - Each row enters import_review_state='pending' so Ross can review.
--   - Two attachments inserted per row: Original Data Entry Sheet + Profile Photo.
--
-- Each tranche is wrapped in its own transaction so a failure in one
-- tranche does not block the others.

SET NAMES utf8mb4;

-- ============================================================
-- New lead sources surfaced by Tranches 2-9
-- ============================================================
INSERT INTO lead_sources (code, label, sort_order, requires_referrer) VALUES
    ('website',       'Website',       75, 0),
    ('advertisement', 'Advertisement', 85, 0)
ON DUPLICATE KEY UPDATE label = VALUES(label);

-- ============================================================
-- Reusable variables (set once at the top, valid for the whole session)
-- ============================================================
SET @att_type_sheet   = (SELECT id FROM attachment_types WHERE code = 'original_data_entry_sheet');
SET @att_type_photo   = (SELECT id FROM attachment_types WHERE code = 'profile_photo');
SET @ls_referral      = (SELECT id FROM lead_sources WHERE code = 'referral');
SET @ls_word_of_mouth = (SELECT id FROM lead_sources WHERE code = 'word_of_mouth');
SET @ls_other         = (SELECT id FROM lead_sources WHERE code = 'other');
SET @ls_website       = (SELECT id FROM lead_sources WHERE code = 'website');
SET @ls_facebook      = (SELECT id FROM lead_sources WHERE code = 'facebook');
SET @ls_advertisement = (SELECT id FROM lead_sources WHERE code = 'advertisement');

-- ============================================================
-- TRANCHE 2 (PDF: Intake 2.pdf, 17 records, DB ids 15-31)
-- ============================================================
START TRANSACTION;

SET @pdf_filename_2 = 'Intake 2.pdf';
SET @pdf_relative_2 = 'intake/Tranche 2 - Intake 2.pdf';

-- ── id 15: Hlengiwe Bongiwe Xulu (PDF: 202510-14, Known As: Mahle) ──
UPDATE caregivers SET
    student_id = '202510-14', known_as = 'Mahle', title = 'Miss', initials = 'HB',
    id_passport = '9202050256083', dob = '1992-02-05', gender = 'Female',
    nationality = 'South African', home_language = 'isizulu', other_language = 'English',
    mobile = '0726386051', email = 'hlengiwexulu342@gmail.com',
    street_address = '1914 Block D', suburb = 'Hammanskraal', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Phumulile Xulu', nok_relationship = 'Sister', nok_contact = '0658995583',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision). Pre-enrichment full DB state captured in database/backups/caregivers_pre_tranches_2_9.sql.',
        'known_as changed: prior DB value was different from PDF (PDF: ''Mahle'').')
WHERE id = 15;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (15, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 1, 'system_import'),
    (15, @att_type_photo, 'people/TCH-000015/photo.png', 'Intake_2_p01.png', NULL, NULL, 'system_import');

-- ── id 16: Jane Mokgaetsi Moekwa (PDF: 202510-17) ──
UPDATE caregivers SET
    student_id = '202510-17', known_as = 'Jane', title = 'Miss', initials = 'JM',
    id_passport = '8501240451087', dob = '1985-01-24', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi', other_language = 'English',
    mobile = '0814558249', email = 'janemiekwa0@gmail.com',
    street_address = 'ML 3320 Ghomora', suburb = 'Hercules', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Martha Moekwa', nok_relationship = 'Sister', nok_contact = '0614241825',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 16;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (16, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 2, 'system_import'),
    (16, @att_type_photo, 'people/TCH-000016/photo.png', 'Intake_2_p02.png', NULL, NULL, 'system_import');

-- ── id 17: Jenita Chemedzai Sithole (PDF: 202510-10) ──
UPDATE caregivers SET
    student_id = '202510-10', known_as = 'Jenita', title = 'Miss', initials = 'JC',
    id_passport = '24-199537T-58', dob = '1992-06-28', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0738972814', email = 'jennysithole977@gmail.com',
    street_address = '94 Ferreira Street', suburb = 'Tuffontain', city = 'Johnesburg', province = 'Gauteng',
    nok_name = 'Stephene', nok_relationship = 'Partner', nok_contact = '0781007447',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City ''Johnesburg'' is a typo for ''Johannesburg'' — preserved as written.')
WHERE id = 17;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (17, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 3, 'system_import'),
    (17, @att_type_photo, 'people/TCH-000017/photo.png', 'Intake_2_p03.png', NULL, NULL, 'system_import');

-- ── id 18: Lerato Elsie Ngwato (PDF: 202510-5) ──
UPDATE caregivers SET
    student_id = '202510-5', known_as = 'Lerato', title = 'Mrs.', initials = 'LE',
    id_passport = '9205300589088', dob = '1992-05-30', gender = 'Female',
    nationality = 'South African', home_language = 'Pedi', other_language = 'English',
    mobile = '0683535834', email = 'ngwatolerato9@gmail.com',
    street_address = '1149 Mogogelo Street', suburb = 'Hammanskraal', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Jonas Myaungu', nok_relationship = 'Husband', nok_contact = '0660868031',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 18;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (18, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 4, 'system_import'),
    (18, @att_type_photo, 'people/TCH-000018/photo.png', 'Intake_2_p04.png', NULL, NULL, 'system_import');

-- ── id 19: Mamojalefa Sophy Xaba (PDF: 202510-15) ──
UPDATE caregivers SET
    student_id = '202510-15', known_as = 'Sophy', title = 'Miss', initials = 'MS',
    id_passport = '0407191044087', dob = '2004-07-19', gender = 'Female',
    nationality = 'South African', home_language = 'English', other_language = 'Afrikaans little bit',
    mobile = '0782176946', email = 'Mamojalefasophy@gmail.com',
    complex_estate = 'Three Rivers Estate', street_address = '4 Tweed Drive',
    suburb = 'Vereeniging', city = 'Gauteng', province = 'Gauteng',
    nok_name = 'Rosina Xaba', nok_relationship = 'Mother', nok_contact = '0640927081',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City field literally says ''Gauteng'' (which is a province, not a city). Likely the form-filler put province in both fields. Probably should be ''Vereeniging''. Preserved as written.',
        'Lead source ''Social_media'' — generic; not mapped to a specific channel; left blank for review.')
WHERE id = 19;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (19, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 5, 'system_import'),
    (19, @att_type_photo, 'people/TCH-000019/photo.png', 'Intake_2_p05.png', NULL, NULL, 'system_import');

-- ── id 20: Matodzi Sylvia Mutavhatsindi (PDF: 202510-11) ──
UPDATE caregivers SET
    student_id = '202510-11', known_as = 'Sylvia', title = 'Miss', initials = 'MS',
    id_passport = '7405240941082', dob = '1974-05-24', gender = 'Female',
    nationality = 'South African', home_language = 'Venda', other_language = 'English/Afrikaans Little bit',
    mobile = '0780187618', email = 'mutavhatsindisylvia@gmail.com',
    street_address = '17 Malvern Street', suburb = 'East Gate', city = 'Johannesburg', province = 'Gauteng',
    postal_code = '2094',
    nok_name = 'Orifuna', nok_relationship = 'Daughter', nok_contact = '0608772834',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 20;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (20, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 6, 'system_import'),
    (20, @att_type_photo, 'people/TCH-000020/photo.png', 'Intake_2_p06.png', NULL, NULL, 'system_import');

-- ── id 21: Merriam Mashadi Maluleke (PDF: 202510-9) ──
UPDATE caregivers SET
    student_id = '202510-9', known_as = 'Merriam', title = 'Mrs.', initials = 'MM',
    id_passport = '7701013328089', dob = '1977-01-01', gender = 'Female',
    nationality = 'South African', home_language = 'Tsonga', other_language = 'English',
    mobile = '0713752232', email = 'Merriammashadi9@gmail.com',
    street_address = '3138 Prichard Mmotlha', suburb = 'Hammanskraal', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Phemglo', nok_relationship = 'Daughter', nok_contact = '0798474060',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 21;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (21, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 7, 'system_import'),
    (21, @att_type_photo, 'people/TCH-000021/photo.png', 'Intake_2_p07.png', NULL, NULL, 'system_import');

-- ── id 22: Mmamaswabi Emma Dhlamini (PDF: 202510-12) ──
UPDATE caregivers SET
    student_id = '202510-12', known_as = 'Emma', title = 'Miss', initials = 'M',
    id_passport = '7312161014081', dob = '1973-12-16', gender = 'Female',
    nationality = 'South African', home_language = 'Tsonga', other_language = 'English / Prefect Afrikaans',
    mobile = '0798159238', email = 'emmashibambol@gmail.com',
    street_address = '3782 Fika Street', suburb = 'Hammanskraal', city = 'Pretoria', province = 'Gauteng',
    postal_code = '0400',
    nok_name = 'Komogelo Dhlamini', nok_relationship = 'Son', nok_contact = '0638473455',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Other Language ''Prefect Afrikaans'' is a typo for ''Perfect Afrikaans'' — preserved as written.')
WHERE id = 22;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (22, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 8, 'system_import'),
    (22, @att_type_photo, 'people/TCH-000022/photo.png', 'Intake_2_p08.png', NULL, NULL, 'system_import');

-- ── id 23: Ndaizivei Mapenyenye (PDF: 202510-2) ──
UPDATE caregivers SET
    student_id = '202510-2', known_as = 'Ndai', title = 'Mrs.', initials = 'N',
    id_passport = '83169371P83', dob = '1989-07-07', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0848225005', email = 'slyginenyemba@gmail.com',
    street_address = '24 Siyahlala Street', suburb = 'Olivenhoutbosch', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Innocent', nok_relationship = 'Husband', nok_contact = '074 404 5665',
    lead_source_id = @ls_other,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 23;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (23, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 9, 'system_import'),
    (23, @att_type_photo, 'people/TCH-000023/photo.png', 'Intake_2_p09.png', NULL, NULL, 'system_import');

-- ── id 24: Refiloe Khuzwayo (PDF: 202510-1) ──
UPDATE caregivers SET
    student_id = '202510-1', known_as = 'Refiloe', title = 'Miss', initials = 'R',
    id_passport = '8408290663084', dob = '1984-08-29', gender = 'Female',
    nationality = 'South African', home_language = 'Setswana', other_language = 'English/Afrikaans little bit',
    mobile = '0632027207',
    street_address = '19 Catfish crescent', suburb = 'Lawley', city = 'Johannesburg', province = 'Gauteng',
    nok_name = 'Lerato', nok_relationship = 'Sister', nok_contact = '0749346417',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF email field is blank.')
WHERE id = 24;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (24, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 10, 'system_import'),
    (24, @att_type_photo, 'people/TCH-000024/photo.png', 'Intake_2_p10.png', NULL, NULL, 'system_import');

-- ── id 25: Rudo Susan Murire (PDF: 202510-4) ──
UPDATE caregivers SET
    student_id = '202510-4', known_as = 'Susan', title = 'Miss', initials = 'RS',
    id_passport = '631227415E13', dob = '1983-02-10', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0610116272', email = 'addmurire@gmail.com',
    street_address = '7 Long Street', suburb = 'Kempton Park', city = 'Johannesburg', province = 'Gauteng',
    nok_name = 'Addmore', nok_relationship = 'Brother', nok_contact = '0728767184',
    lead_source_id = @ls_other,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 25;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (25, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 11, 'system_import'),
    (25, @att_type_photo, 'people/TCH-000025/photo.png', 'Intake_2_p11.png', NULL, NULL, 'system_import');

-- ── id 26: Segethi Tabea Molefe (PDF: 202510-6, Known As: Mia) ──
UPDATE caregivers SET
    student_id = '202510-6', known_as = 'Mia', title = 'Miss', initials = 'ST',
    id_passport = '8206190400080', dob = '1982-06-19', gender = 'Female',
    nationality = 'South African', home_language = 'Tswana', other_language = 'English/Afrikaans',
    mobile = '0765603816', secondary_number = '0739239248', email = 'molefetabea154@gmail',
    street_address = '1914 Block D2', suburb = 'Hammanskraal', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Dipuo Ntsimane', nok_relationship = 'Sister', nok_contact = '0677284917',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Mia'').',
        'PDF data flags: Email ''molefetabea154@gmail'' is incomplete (missing .com) — preserved as written.',
        'PDF data flags: Has secondary mobile number — first time we have used this field.')
WHERE id = 26;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (26, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 12, 'system_import'),
    (26, @att_type_photo, 'people/TCH-000026/photo.png', 'Intake_2_p12.png', NULL, NULL, 'system_import');

-- ── id 27: Siphethokhuhle Nkomo (PDF: 202510-7, Known As: Sphe) ──
UPDATE caregivers SET
    student_id = '202510-7', known_as = 'Sphe', title = 'Mrs.', initials = 'S',
    id_passport = 'PTAZWE0011270113', dob = '1987-01-12', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0618633599', email = 'sphenkome@gmail.com',
    street_address = '400 Visage Street', suburb = 'Klinkenberg Gradens', city = 'Pretoria Central', province = 'Gauteng',
    nok_name = 'Thabo', nok_relationship = 'Husband', nok_contact = '0622470096',
    lead_source_id = @ls_other,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Sphe'').',
        'PDF data flags: Suburb ''Klinkenberg Gradens'' is likely a typo for ''Klinkenberg Gardens'' — preserved as written.')
WHERE id = 27;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (27, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 13, 'system_import'),
    (27, @att_type_photo, 'people/TCH-000027/photo.png', 'Intake_2_p13.png', NULL, NULL, 'system_import');

-- ── id 28: Siphilisiwe Nkala (PDF: 202510-16, Known As: Siphili) ──
UPDATE caregivers SET
    student_id = '202510-16', known_as = 'Siphili', title = 'Miss', initials = 'S',
    id_passport = '08740652H28', dob = '1980-07-08', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English/Afrikaans little bit',
    mobile = '0739969917', email = 'siphilihenkala15@gmail.com',
    street_address = '21760/59 Nombela Drive', suburb = 'Vosloo Rus- Spruit View', city = 'Alberton', province = 'Gauteng',
    nok_name = 'Jocob Bikwa', nok_relationship = 'Son', nok_contact = '0676179228',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Siphili'').',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: NoK name ''Jocob'' is likely a typo for ''Jacob'' — preserved as written.')
WHERE id = 28;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (28, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 14, 'system_import'),
    (28, @att_type_photo, 'people/TCH-000028/photo.png', 'Intake_2_p14.png', NULL, NULL, 'system_import');

-- ── id 29: Sylvia Delisile Nene (PDF: 202510-18, Known As: Sylvia) ──
UPDATE caregivers SET
    student_id = '202510-18', known_as = 'Sylvia', title = 'Miss', initials = 'SD',
    id_passport = '8005210702081', dob = '1980-05-21', gender = 'Female',
    nationality = 'South African', home_language = 'Zulu', other_language = 'English',
    mobile = '0607491726', email = 'nenes3278@gmail.com',
    street_address = 'B7732 Phumula Mgheshi', suburb = 'Lenasia South', city = 'Johannesburg', province = 'Gauteng',
    nok_name = 'Zinwe Ndimende', nok_relationship = 'Sister', nok_contact = '0783921038',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Sylvia'').')
WHERE id = 29;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (29, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 15, 'system_import'),
    (29, @att_type_photo, 'people/TCH-000029/photo.png', 'Intake_2_p15.png', NULL, NULL, 'system_import');

-- ── id 30: Thandi Ngobeni (PDF: 202510-8) ──
UPDATE caregivers SET
    student_id = '202510-8', known_as = 'Thandi', title = 'Miss', initials = 'T',
    id_passport = '8301130738085', dob = '1983-01-13', gender = 'Female',
    nationality = 'South African', home_language = 'Xitsonga', other_language = 'English/Afrikaans little bit',
    mobile = '0732390450', email = 'thandingobeni4@gmail.com',
    street_address = '1393 Luaname Street', suburb = 'Tshiawela', city = 'Sweto', province = 'Gauteng',
    postal_code = '1818',
    nok_name = 'Rito', nok_relationship = 'Son', nok_contact = '0720826292',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City ''Sweto'' is a typo for ''Soweto'' — preserved as written.')
WHERE id = 30;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (30, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 16, 'system_import'),
    (30, @att_type_photo, 'people/TCH-000030/photo.png', 'Intake_2_p16.png', NULL, NULL, 'system_import');

-- ── id 31: Tracy Nothando Towera Nyirenda (PDF: 202510-13) ──
UPDATE caregivers SET
    student_id = '202510-13', known_as = 'Tracy', title = 'Mrs.', initials = 'TNT',
    id_passport = '28-2005240J28C1TF', dob = '1997-09-20', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0843834178', email = 'tracynothando.dv@gmail.com',
    street_address = '374 Van Hardeern', suburb = 'Capital Park', city = 'Pretoira', province = 'Gauteng',
    nok_name = 'Elvis Kanyama', nok_relationship = 'Husband', nok_contact = '0742983611',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 2 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City ''Pretoira'' is a typo for ''Pretoria'' — preserved as written.')
WHERE id = 31;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (31, @att_type_sheet, @pdf_relative_2, @pdf_filename_2, @pdf_filename_2, 17, 'system_import'),
    (31, @att_type_photo, 'people/TCH-000031/photo.png', 'Intake_2_p17.png', NULL, NULL, 'system_import');

COMMIT;

-- ============================================================
-- TRANCHE 3 (PDF: Intake 3.pdf, 12 records, DB ids 32-43)
-- ============================================================
START TRANSACTION;

SET @pdf_filename_3 = 'Intake 3.pdf';
SET @pdf_relative_3 = 'intake/Tranche 3 - Intake 3.pdf';

-- ── id 32: Bongani Makhathulela (PDF: 202508-8, Known As: Bongo) ──
UPDATE caregivers SET
    student_id = '202508-8', known_as = 'Bongo', title = 'Mr.', initials = 'B',
    id_passport = '0511245621087', dob = '2005-11-24', gender = 'Male',
    nationality = 'South African', home_language = 'Ndebele', other_language = 'English',
    mobile = '0723314336', email = 'makhathulelabongani@gmail.com',
    street_address = '877 Refentse', suburb = 'Hammanskraal', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Lydia', nok_relationship = 'Mother', nok_contact = '0796135360',
    lead_source_id = @ls_advertisement,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Bongo'').')
WHERE id = 32;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (32, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 1, 'system_import'),
    (32, @att_type_photo, 'people/TCH-000032/photo.png', 'Intake_3_p01.png', NULL, NULL, 'system_import');

-- ── id 33: Magreth Chilangaza (PDF: 202508-17) ──
UPDATE caregivers SET
    student_id = '202508-17', known_as = 'Magreth', title = 'Miss', initials = 'M',
    id_passport = '83-216567P-83', dob = '1997-03-04', gender = 'Female',
    nationality = 'Zimbabwe', home_language = 'Shona', other_language = 'English',
    mobile = '0838986504', email = 'magrethchilangaza@gmail.com',
    street_address = '9B 18660 Extension', suburb = 'Soshanguve', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Justin Mudimu', nok_relationship = 'Boyfriend', nok_contact = '0847916297',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Nationality is ''Zimbabwe'' (country) rather than ''Zimbabwean'' (nationality) — preserved as written.')
WHERE id = 33;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (33, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 2, 'system_import'),
    (33, @att_type_photo, 'people/TCH-000033/photo.png', 'Intake_3_p02.png', NULL, NULL, 'system_import');

-- ── id 34: Mevis Chikomo (PDF: 202508-15) ──
UPDATE caregivers SET
    student_id = '202508-15', known_as = 'Mevis', title = 'Mrs.', initials = 'M',
    id_passport = '03105711X03', dob = '1979-03-17', gender = 'Female',
    nationality = 'Zimbabwe', home_language = 'Shona', other_language = 'English',
    mobile = '0769420365', email = 'mevischikomo@gmail.com',
    street_address = '32 Palm Court Suid Vos Street', suburb = 'Sunnyside', city = 'Pretoira', province = 'Gauteng',
    nok_name = 'Munyaradzi Gato', nok_relationship = 'Husband', nok_contact = '0623505875',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Nationality ''Zimbabwe'' (country) preserved as written. City ''Pretoira'' is a typo for ''Pretoria''.')
WHERE id = 34;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (34, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 3, 'system_import'),
    (34, @att_type_photo, 'people/TCH-000034/photo.png', 'Intake_3_p03.png', NULL, NULL, 'system_import');

-- ── id 35: Monalisa Layi (PDF: 202508-9) ──
UPDATE caregivers SET
    student_id = '202508-9', known_as = 'Monalisa', title = 'Mrs.', initials = 'M',
    id_passport = 'BE496400', dob = '1987-09-14', gender = 'Female',
    nationality = 'Zimbabwe', home_language = 'Shona', other_language = 'English',
    mobile = '0812628080', email = 'monalisalayi@yahoo.com',
    complex_estate = '3309 Centry Skye', street_address = '297 Muradi Avenue',
    suburb = 'Centurion', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Jean', nok_relationship = 'Husband', nok_contact = '0843414035',
    lead_source_id = @ls_advertisement,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Nationality ''Zimbabwe'' (country) preserved as written.')
WHERE id = 35;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (35, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 4, 'system_import'),
    (35, @att_type_photo, 'people/TCH-000035/photo.png', 'Intake_3_p04.png', NULL, NULL, 'system_import');

-- ── id 36: Patience Mukutyu (PDF: 202508-11) ──
UPDATE caregivers SET
    student_id = '202508-11', known_as = 'Patience', title = 'Mrs.', initials = 'P',
    id_passport = '07-196740S-07', dob = '1993-10-18', gender = 'Female',
    nationality = 'Zimbabwe', home_language = 'Shona', other_language = 'English',
    mobile = '0814417944', email = 'pmukutyu@gmail.com',
    street_address = '1 Oribi Street', suburb = 'Olifantsfontein', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Benefit', nok_relationship = 'Husband', nok_contact = '0842595141',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Nationality ''Zimbabwe'' (country) preserved as written.')
WHERE id = 36;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (36, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 5, 'system_import'),
    (36, @att_type_photo, 'people/TCH-000036/photo.png', 'Intake_3_p05.png', NULL, NULL, 'system_import');

-- ── id 37: Sebina Zwane (PDF: 202508-14) ──
UPDATE caregivers SET
    student_id = '202508-14', known_as = 'Sebina', title = 'Mrs.', initials = 'S',
    id_passport = '8402050548080', dob = '1984-02-05', gender = 'Female',
    nationality = 'South African', home_language = 'Zulu', other_language = 'English',
    mobile = '0665106562', email = 'sebinazwane@gmail.com',
    street_address = '2207 Pomolong Road', suburb = 'Thembina', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Gugalethe', nok_relationship = 'Daughter', nok_contact = '0762332376',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 37;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (37, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 6, 'system_import'),
    (37, @att_type_photo, 'people/TCH-000037/photo.png', 'Intake_3_p06.png', NULL, NULL, 'system_import');

-- ── id 38: Stephinah Khuduhane Manyala (PDF: 202508-16) ──
UPDATE caregivers SET
    student_id = '202508-16', known_as = 'Stephinah', title = 'Miss', initials = 'SK',
    id_passport = '8812180368086', dob = '1988-12-18', gender = 'Female',
    nationality = 'South African', home_language = 'Setswana', other_language = 'English',
    mobile = '0842097817', email = 'stephinahmanyala@gmail.com',
    street_address = '234 Matebelemg Section', suburb = 'Pankop', city = 'Hammenskraal', province = 'Gauteng',
    postal_code = '0414',
    nok_name = 'Dick Manyala', nok_relationship = 'Partner', nok_contact = '0793757418',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City ''Hammenskraal'' is a typo for ''Hammanskraal'' — preserved as written.')
WHERE id = 38;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (38, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 7, 'system_import'),
    (38, @att_type_photo, 'people/TCH-000038/photo.png', 'Intake_3_p07.png', NULL, NULL, 'system_import');

-- ── id 39: Susan Menya Odipo (PDF: 202508-1, Known As: Susie) ──
UPDATE caregivers SET
    student_id = '202508-1', known_as = 'Susie', title = 'Miss', initials = 'SM',
    id_passport = '1614356', dob = '1986-01-04', gender = 'Female',
    nationality = 'Kenya', home_language = 'English',
    mobile = '0624809518', email = 'menyasusan@gmail.com',
    complex_estate = '42 Santavo', street_address = '2 North Road',
    suburb = 'Glen Maris', city = 'Kempton Park', province = 'Gauteng', postal_code = '1619',
    nok_name = 'Kin Grace', nok_relationship = 'Friend', nok_contact = '0629673907',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Susie'').',
        'PDF data flags: Nationality is ''Kenya'' (country) rather than ''Kenyan'' — preserved as written.',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.')
WHERE id = 39;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (39, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 8, 'system_import'),
    (39, @att_type_photo, 'people/TCH-000039/photo.png', 'Intake_3_p08.png', NULL, NULL, 'system_import');

-- ── id 40: Thobile Queen Mlilo (PDF: 202508-18) ──
UPDATE caregivers SET
    student_id = '202508-18', known_as = 'Thobile', title = 'Mrs.', initials = 'TQ',
    id_passport = '8005210266087', dob = '1980-05-21', gender = 'Female',
    nationality = 'South African', home_language = 'Siswati', other_language = 'English',
    mobile = '0738845579', email = 'thobilemaziya924@gmail.com',
    street_address = '12 Sonoprit', suburb = 'Kempton Park West', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Botholezwe', nok_relationship = 'Husabnd', nok_contact = '0814964760',
    lead_source_id = @ls_advertisement,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: NoK relationship ''Husabnd'' is a typo for ''Husband'' — preserved as written.',
        'PDF data flags: Suburb ''Kempton Park West'' but city ''Pretoria'' — Kempton Park is in Ekurhuleni, not Pretoria. Address inconsistency.')
WHERE id = 40;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (40, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 9, 'system_import'),
    (40, @att_type_photo, 'people/TCH-000040/photo.png', 'Intake_3_p09.png', NULL, NULL, 'system_import');

-- ── id 41: Vuyolwethu Vivian Dyantyi (PDF: 202508-12) ──
UPDATE caregivers SET
    student_id = '202508-12', known_as = 'Vivian', title = 'Miss', initials = 'VV',
    id_passport = '9109180531089', dob = '1991-09-18', gender = 'Female',
    nationality = 'South African', home_language = 'English',
    mobile = '0611470063', email = 'viviandyantyi1@gmail.com',
    street_address = '28 Becker Street', suburb = 'Clayville West', city = 'Olifantsfontein', province = 'Gauteng',
    nok_name = 'Asanda Gqwaru', nok_relationship = 'Husband', nok_contact = '0782120304',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 41;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (41, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 10, 'system_import'),
    (41, @att_type_photo, 'people/TCH-000041/photo.png', 'Intake_3_p10.png', NULL, NULL, 'system_import');

-- ── id 42: Yvonne Kuseni (PDF: 202508-10) ──
UPDATE caregivers SET
    student_id = '202508-10', known_as = 'Yvonne', title = 'Miss', initials = 'Y',
    id_passport = '631538977J11', dob = '1993-05-27', gender = 'Female',
    nationality = 'Zimbabwe', home_language = 'Shona', other_language = 'English',
    mobile = '0815014245', email = 'kuseniyvonne@gmail.com',
    street_address = '8 Centaurus Avenue', suburb = 'Bloubosrand', city = 'Randburg', province = 'Gauteng',
    nok_name = 'Patricia Kasiroi', nok_relationship = 'Aunt', nok_contact = '0639214192',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Nationality ''Zimbabwe'' (country) preserved as written.')
WHERE id = 42;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (42, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 11, 'system_import'),
    (42, @att_type_photo, 'people/TCH-000042/photo.png', 'Intake_3_p11.png', NULL, NULL, 'system_import');

-- ── id 43: Yvvonette Nziane (PDF: 202508-13) ──
UPDATE caregivers SET
    student_id = '202508-13', known_as = 'Yvvonette', title = 'Miss', initials = 'Y',
    id_passport = '9110120838089', dob = '1991-10-12', gender = 'Female',
    nationality = 'South African', home_language = 'Xitsonga', other_language = 'English',
    mobile = '0715213066', email = 'nzianekhensani@gmail.com',
    street_address = '6002 Khanyile Lord Street', suburb = 'Ivory Park', city = 'Midrand', province = 'Gauteng',
    postal_code = '1682',
    nok_name = 'Ishmael Nxumalo', nok_relationship = 'Partner', nok_contact = '0734996208',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 3 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 43;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (43, @att_type_sheet, @pdf_relative_3, @pdf_filename_3, @pdf_filename_3, 12, 'system_import'),
    (43, @att_type_photo, 'people/TCH-000043/photo.png', 'Intake_3_p12.png', NULL, NULL, 'system_import');

COMMIT;

-- ============================================================
-- TRANCHE 4 (PDF: Intake 4.pdf, 14 records, DB ids 44-57)
-- ============================================================
START TRANSACTION;

SET @pdf_filename_4 = 'Intake 4.pdf';
SET @pdf_relative_4 = 'intake/Tranche 4 - Intake 4.pdf';

-- ── id 44: Busisiwe Mandiyanike (PDF: 202507-6, Known As: Busi) ──
UPDATE caregivers SET
    student_id = '202507-6', known_as = 'Busi', title = 'Mrs.', initials = 'B',
    id_passport = '8412041364083', dob = '1984-12-04', gender = 'Female',
    nationality = 'South African', home_language = 'English',
    mobile = '0837800983', email = 'mandiyanikebusisiwe@gmail.com',
    street_address = '42 Vosloo street', suburb = 'Birchleigh', city = 'Kempton Park', province = 'Gauteng',
    postal_code = '1619',
    nok_name = 'Ethel House', nok_relationship = 'Sister', nok_contact = '0618283886',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Busi'').')
WHERE id = 44;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (44, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 1, 'system_import'),
    (44, @att_type_photo, 'people/TCH-000044/photo.png', 'Intake_4_p01.png', NULL, NULL, 'system_import');

-- ── id 45: Dikeledi Eunice Musana (PDF: 202601-13, Known As: Kgosigadi) ──
UPDATE caregivers SET
    student_id = '202601-13', known_as = 'Kgosigadi', title = 'Miss', initials = 'DE',
    id_passport = '8702170658085', dob = '1987-02-17', gender = 'Female',
    nationality = 'South African', home_language = 'Tsonga', other_language = 'English/ Afrikaans a little bit',
    mobile = '0634794097', email = 'diteledi.musana94@gmail.com',
    street_address = '684 Ramotse', suburb = 'Section Tselapedi', city = 'Hammanskraal', province = 'Gauteng',
    postal_code = '0407',
    nok_name = 'Matshidiso', nok_relationship = 'Sister', nok_contact = '0683173214',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Kgosigadi'').')
WHERE id = 45;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (45, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 2, 'system_import'),
    (45, @att_type_photo, 'people/TCH-000045/photo.png', 'Intake_4_p02.png', NULL, NULL, 'system_import');

-- ── id 46: Emilia Masvosva (PDF: 202601-16, Known As: Emmy) ──
UPDATE caregivers SET
    student_id = '202601-16', known_as = 'Emmy', title = 'Miss', initials = 'E',
    id_passport = '80055118G80', dob = '1982-08-08', gender = 'Female',
    nationality = 'Zimbabwe', home_language = 'Zulu', other_language = 'English',
    mobile = '0842136627', email = 'emasuosua08@gmail.com',
    street_address = '1144 Albrastross Street', suburb = 'Rabie Ridge', city = 'Thembisa', province = 'Gauteng',
    nok_name = 'Justice Mhasvi', nok_relationship = 'Partner', nok_contact = '0616149939',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Emmy'').',
        'PDF data flags: Nationality ''Zimbabwe'' (country) preserved as written. Home language ''Zulu'' is unusual for a Zimbabwean — verify.')
WHERE id = 46;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (46, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 3, 'system_import'),
    (46, @att_type_photo, 'people/TCH-000046/photo.png', 'Intake_4_p03.png', NULL, NULL, 'system_import');

-- ── id 47: Mariam Joan Agunloye (PDF: 202601-14, Known As: Joan) ──
UPDATE caregivers SET
    student_id = '202601-14', known_as = 'Joan', title = 'Mrs.', initials = 'MJ',
    id_passport = 'A12562557', dob = '1984-11-16', gender = 'Female',
    nationality = 'Nigeria', home_language = 'English',
    mobile = '0842193621', email = 'dumebiemuh@gmail.com',
    street_address = '403 Tuin Street', suburb = 'Pretoria Gardens', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Darlignton', nok_relationship = 'Husband', nok_contact = '0655244721',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Joan'').',
        'PDF data flags: Nationality ''Nigeria'' (country) preserved as written. NoK name ''Darlignton'' is likely a typo for ''Darlington'' — preserved as written.')
WHERE id = 47;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (47, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 4, 'system_import'),
    (47, @att_type_photo, 'people/TCH-000047/photo.png', 'Intake_4_p04.png', NULL, NULL, 'system_import');

-- ── id 48: Mokgadi Martha Mahlahlane (PDF: 202601-12, Known As: Mokgadi) ──
UPDATE caregivers SET
    student_id = '202601-12', known_as = 'Mokgadi', title = 'Miss', initials = 'MM',
    id_passport = '7410061048082', dob = '1974-10-06', gender = 'Female',
    nationality = 'South African', home_language = 'Tsonga', other_language = 'English/ Afrikaans',
    mobile = '0725099664', secondary_number = '0818728945', email = 'marthamahlahlane@gmail.com',
    street_address = '974 Leeufontein', suburb = 'Digwale', city = 'Mpumalanga', province = 'Gauteng',
    nok_name = 'Dorothy Mahlanlane', nok_relationship = 'Mother', nok_contact = '0818728945',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Mokgadi'').',
        'PDF data flags: City ''Mpumalanga'' is a province, not a city — preserved as written. NoK name ''Mahlanlane'' likely typo for ''Mahlahlane''. NoK contact (0818728945) is identical to candidate''s secondary number — verify.')
WHERE id = 48;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (48, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 5, 'system_import'),
    (48, @att_type_photo, 'people/TCH-000048/photo.png', 'Intake_4_p05.png', NULL, NULL, 'system_import');

-- ── id 49: Nare Julia Ramorka (PDF: 202601-19, Known As: Nare) ──
UPDATE caregivers SET
    student_id = '202601-19', known_as = 'Nare', title = 'Miss', initials = 'NJ',
    id_passport = '7912110319083', dob = '1979-12-11', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi', other_language = 'English, Afrikaans & Izizulu',
    mobile = '0685538494', secondary_number = '0786781784', email = 'ramorokanare89@gmail.com',
    complex_estate = 'Central Park', street_address = '43 Kgoroto Str',
    suburb = 'Lotus', city = 'Attridgeville', province = 'Gauteng', postal_code = '0008',
    nok_name = 'Owen Ramoroka', nok_relationship = 'Brother', nok_contact = '0786592118', nok_email = 'owen.ramoroka@gmail.com',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Nare'').',
        'First record with all of: complex_estate, postal_code, secondary_number AND nok_email populated.')
WHERE id = 49;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (49, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 6, 'system_import'),
    (49, @att_type_photo, 'people/TCH-000049/photo.png', 'Intake_4_p06.png', NULL, NULL, 'system_import');

-- ── id 50: Nhlakanipho Casey Tshabalala (PDF: 202601-17, Known As: Nipho) ──
UPDATE caregivers SET
    student_id = '202601-17', known_as = 'Nipho', title = 'Miss', initials = 'NC',
    id_passport = '9408180931087', dob = '1994-08-18', gender = 'Female',
    nationality = 'South African', home_language = 'Zulu', other_language = 'English',
    mobile = '0794631373', email = 'nhlakanipho78@gmail.com',
    complex_estate = 'Eye of Africa Golf Estate', street_address = '1601 Panorama Street',
    suburb = 'Joburg South', city = 'Joburg', province = 'Gauteng',
    nok_name = 'Mlambo Mbewu', nok_relationship = 'Mother', nok_contact = '0769374145', nok_email = 'khanyimlambo706@gmail.com',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Nipho'').')
WHERE id = 50;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (50, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 7, 'system_import'),
    (50, @att_type_photo, 'people/TCH-000050/photo.png', 'Intake_4_p07.png', NULL, NULL, 'system_import');

-- ── id 51: Rebecca Shibambu (PDF: 202601-20) ──
UPDATE caregivers SET
    student_id = '202601-20', known_as = 'Rebecca', title = 'Mrs.', initials = 'R',
    id_passport = '6705090217082', dob = '1967-05-09', gender = 'Female',
    nationality = 'South African', home_language = 'Pedi', other_language = 'Afrikaans/ English',
    mobile = '0733694169',
    street_address = 'House 1750 Ext 3', suburb = 'Mookgophong', province = 'Gauteng',
    nok_name = 'Lucas Nkosi', nok_relationship = 'Husband', nok_contact = '0711233867',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City field is blank.')
WHERE id = 51;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (51, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 8, 'system_import'),
    (51, @att_type_photo, 'people/TCH-000051/photo.png', 'Intake_4_p08.png', NULL, NULL, 'system_import');

-- ── id 52: Sara Mdaka (PDF: 202601-21) ──
UPDATE caregivers SET
    student_id = '202601-21', known_as = 'Sara', title = 'Mrs.', initials = 'S',
    id_passport = '6208170489085', dob = '1962-08-17', gender = 'Female',
    nationality = 'South African', home_language = 'Zulu', other_language = 'Tsonga & English, Afrikaans a little bit',
    mobile = '0731736667', email = 'sarahmdaka41@gmai.com',
    street_address = '1644 Phiranah Circle', suburb = 'Lawley Ext 1, Ennerdale', city = 'Lenasia', province = 'Gauteng',
    postal_code = '1830',
    nok_name = 'Siphiwe', nok_relationship = 'Son', nok_contact = '0729124981', nok_email = 'Sbmdaka@gmail.com',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Email ''sarahmdaka41@gmai.com'' is missing the ''l'' (gmail) — preserved as written.')
WHERE id = 52;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (52, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 9, 'system_import'),
    (52, @att_type_photo, 'people/TCH-000052/photo.png', 'Intake_4_p09.png', NULL, NULL, 'system_import');

-- ── id 53: Zanele Princess Maphalala (PDF: 202601-15, page 11) ──
UPDATE caregivers SET
    student_id = '202601-15', known_as = 'Zanele', title = 'Mrs.', initials = 'ZP',
    id_passport = '7308310540083', dob = '1973-08-31', gender = 'Female',
    nationality = 'South African', home_language = 'Zulu', other_language = 'English',
    mobile = '0813915922', email = 'zanelemaphalala2001@gmail.com',
    street_address = '1 Dequar Road', suburb = 'Salvakolo', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Bongani', nok_relationship = 'Husband', nok_contact = '0815816801',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 53;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (53, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 11, 'system_import'),
    (53, @att_type_photo, 'people/TCH-000053/photo.png', 'Intake_4_p11.png', NULL, NULL, 'system_import');

-- ── id 54: Hloniphani Moyo (PDF: 202511-6, Known As: Kelly, page 10) ──
UPDATE caregivers SET
    student_id = '202511-6', known_as = 'Kelly', title = 'Miss', initials = 'H',
    id_passport = '08-906217V-41', dob = '1989-11-24', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Ndebele', other_language = 'English',
    mobile = '0745479245',
    street_address = '6242 Secretary Bird', suburb = 'Tembisa', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Nomsa', nok_relationship = 'Sister', nok_contact = '0617621325',
    lead_source_id = @ls_other,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Kelly'').',
        'PDF data flags: Suburb ''Tembisa'' but city ''Pretoria'' — Tembisa is in Ekurhuleni metro, not Pretoria. Address inconsistency.',
        'PDF data flags: Email field is blank.',
        'PDF data flags: Pages 10 and 11 in this PDF are out of order relative to DB id sequence (Hloniphani is page 10 / id 54, Zanele is page 11 / id 53).')
WHERE id = 54;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (54, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 10, 'system_import'),
    (54, @att_type_photo, 'people/TCH-000054/photo.png', 'Intake_4_p10.png', NULL, NULL, 'system_import');

-- ── id 55: Marion Goeda (PDF: 202512-1) ──
UPDATE caregivers SET
    student_id = '202512-1', known_as = 'Marion', title = 'Miss', initials = 'M',
    id_passport = '9810091080088', dob = '1998-10-09', gender = 'Female',
    nationality = 'South African', home_language = 'Afrikaans',
    mobile = '0691096908', email = 'thandazgoeda@gmail.com',
    street_address = '536 isivuno Crescent', suburb = 'Cosmo City', city = 'Randburg', province = 'Gauteng',
    nok_name = 'Goiden Sagawa', nok_relationship = 'Partner', nok_contact = '0730202991',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 55;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (55, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 12, 'system_import'),
    (55, @att_type_photo, 'people/TCH-000055/photo.png', 'Intake_4_p12.png', NULL, NULL, 'system_import');

-- ── id 56: Colin Khutso Nyalungu (PDF: 202512-5, Known As: Colin) ──
UPDATE caregivers SET
    student_id = '202512-5', known_as = 'Colin', title = 'Miss', initials = 'C',
    id_passport = '9101231400083', dob = '1991-01-23', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi',
    mobile = '0763206481', email = 'colinkhutso21@gmail.com',
    street_address = '2030 Winnie Mandela', suburb = 'Tembisa', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Salmina Malibe', nok_relationship = 'Mother', nok_contact = '0768636487',
    lead_source_id = @ls_other,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Colin'').',
        'PDF data flags: First name ''Colin'' is typically masculine but title is ''Miss'' and gender Female. Verify with candidate.',
        'PDF data flags: Suburb ''Tembisa'' but city ''Pretoria'' — Tembisa is in Ekurhuleni, not Pretoria. Address inconsistency.')
WHERE id = 56;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (56, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 13, 'system_import'),
    (56, @att_type_photo, 'people/TCH-000056/photo.png', 'Intake_4_p13.png', NULL, NULL, 'system_import');

-- ── id 57: Prisca Wachkwa (PDF: 202512-2) ──
UPDATE caregivers SET
    student_id = '202512-2', known_as = 'Prisca', title = 'Mrs.', initials = 'P',
    id_passport = '26065580B26', dob = '1970-02-26', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona',
    mobile = '0745680876', email = 'encubekanoncube@gmail.com',
    street_address = '276 Steve Biko Road', suburb = 'Acradia', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Edward Ncube', nok_relationship = 'Son', nok_contact = '0712791802',
    lead_source_id = @ls_other,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 4 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Suburb ''Acradia'' is a typo for ''Arcadia'' — preserved as written.')
WHERE id = 57;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (57, @att_type_sheet, @pdf_relative_4, @pdf_filename_4, @pdf_filename_4, 14, 'system_import'),
    (57, @att_type_photo, 'people/TCH-000057/photo.png', 'Intake_4_p14.png', NULL, NULL, 'system_import');

COMMIT;

-- ============================================================
-- TRANCHE 5 (PDF: Intake 5.pdf, 14 records, DB ids 58-71)
-- ============================================================
START TRANSACTION;

SET @pdf_filename_5 = 'Intake 5.pdf';
SET @pdf_relative_5 = 'intake/Tranche 5 - Intake 5.pdf';

-- ── id 58: Annie Mabuto (PDF: 202601-25) ──
UPDATE caregivers SET
    student_id = '202601-25', known_as = 'Annie', title = 'Miss', initials = 'A',
    id_passport = '44076929E44', dob = '1978-10-08', gender = 'Female',
    nationality = 'South African', home_language = 'Shona', other_language = 'English',
    mobile = '0737152028', email = 'anniemabuto20@gmail.com',
    street_address = '15 Stepelberg Ave', suburb = 'Eastlynne', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Faith Mabuto', nok_relationship = 'Sister', nok_contact = '0837620102',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Lead source field is blank. Nationality South African but home language Shona — verify.')
WHERE id = 58;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (58, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 1, 'system_import'),
    (58, @att_type_photo, 'people/TCH-000058/photo.png', 'Intake_5_p01.png', NULL, NULL, 'system_import');

-- ── id 59: Beverly Lehabe (PDF: 202601-28) ──
UPDATE caregivers SET
    student_id = '202601-28', known_as = 'Beverly', title = 'Miss', initials = 'B',
    id_passport = '9003071009083', dob = '1990-03-07', gender = 'Female',
    nationality = 'South African', home_language = 'Setswana', other_language = 'English',
    mobile = '0837105468', email = 'beverlylehabe@gmail.com',
    street_address = '583 London Road', suburb = 'Alexandra Township', province = 'Gauteng',
    nok_name = 'Grace', nok_relationship = 'Mother', nok_contact = '0837105468',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Lead source field is blank. City field is blank. NoK contact is identical to candidate own mobile — verify.')
WHERE id = 59;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (59, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 2, 'system_import'),
    (59, @att_type_photo, 'people/TCH-000059/photo.png', 'Intake_5_p02.png', NULL, NULL, 'system_import');

-- ── id 60: Esther Kawanzaruwa (PDF: 202601-22) ──
UPDATE caregivers SET
    student_id = '202601-22', known_as = 'Esther', title = 'Miss', initials = 'E',
    id_passport = '71112195N71', dob = '1985-03-25', gender = 'Female',
    nationality = 'South African', home_language = 'Shona', other_language = 'English',
    mobile = '0680134118', email = 'easterkawanzaruwa@gmail.com',
    street_address = '8 Derust Eeastynne Avennue', province = 'Gauteng',
    nok_name = 'Simbarashe Kawazaruwa', nok_relationship = 'Brother', nok_contact = '078282933',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Lead source blank. Suburb and City blank. NoK contact ''078282933'' is only 9 digits — typo, missing one digit. Nationality South African but home language Shona — verify.')
WHERE id = 60;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (60, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 3, 'system_import'),
    (60, @att_type_photo, 'people/TCH-000060/photo.png', 'Intake_5_p03.png', NULL, NULL, 'system_import');

-- ── id 61: Koketso Mmathapelo Aphane (PDF: 202601-31) ──
UPDATE caregivers SET
    student_id = '202601-31', known_as = 'Koketso', title = 'Miss', initials = 'KM',
    id_passport = '9104171066088', dob = '1991-04-17', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi', other_language = 'English',
    mobile = '0649548910', email = 'koketsoaphane434@gmail.com',
    street_address = '328 Juncith Street', suburb = 'Laudium', province = 'Gauteng',
    nok_name = 'Petuna Masemola', nok_relationship = 'Sister', nok_contact = '0663363121',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City field is blank.')
WHERE id = 61;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (61, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 4, 'system_import'),
    (61, @att_type_photo, 'people/TCH-000061/photo.png', 'Intake_5_p04.png', NULL, NULL, 'system_import');

-- ── id 62: Lina Raphahlela (PDF: 202601-23) ──
UPDATE caregivers SET
    student_id = '202601-23', known_as = 'Lina', title = 'Miss', initials = 'L',
    id_passport = '7510060632081', dob = '1975-10-06', gender = 'Female',
    nationality = 'South African', home_language = 'Sotho', other_language = 'English',
    mobile = '0661499414', email = 'lina75@gmail.com',
    street_address = '423 Raphahlela Plot', city = 'Winterueldi', province = 'Gauteng', postal_code = '0198',
    nok_relationship = 'Son', nok_contact = '0765666095',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Lead source blank. Suburb blank. NoK name field blank.')
WHERE id = 62;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (62, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 5, 'system_import'),
    (62, @att_type_photo, 'people/TCH-000062/photo.png', 'Intake_5_p05.png', NULL, NULL, 'system_import');

-- ── id 63: Matshidiso Leah Aphane (PDF: 202601-29) ──
UPDATE caregivers SET
    student_id = '202601-29', known_as = 'Leah', title = 'Miss', initials = 'ML',
    id_passport = '9908120553081', dob = '1999-08-12', gender = 'Female',
    nationality = 'South African', home_language = 'Speed', other_language = 'English, Zulu',
    mobile = '0728398594', email = 'matshidisoaphane0@gmail.com',
    street_address = '3668 Itsoseng Phase Str', suburb = 'Mabophne', city = 'Pretoria North', province = 'Gauteng',
    nok_name = 'Refiloe Aphane', nok_relationship = 'Sister', nok_contact = '0763243856',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Home language ''Speed'' is a typo for ''Sepedi'' — preserved as written.')
WHERE id = 63;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (63, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 6, 'system_import'),
    (63, @att_type_photo, 'people/TCH-000063/photo.png', 'Intake_5_p06.png', NULL, NULL, 'system_import');

-- ── id 64: Musa Glenda Zulu (PDF: 202601-26, Known As: Musa) ──
UPDATE caregivers SET
    student_id = '202601-26', known_as = 'Musa', title = 'Miss', initials = 'MG',
    id_passport = '9604020740080', dob = '1996-04-02', gender = 'Female',
    nationality = 'South African', home_language = 'Zulu', other_language = 'English',
    mobile = '0764797717', email = 'musazulu073@gmail.com',
    street_address = '1721 Milkwood Str', suburb = 'Protea Glen Ext 2', city = 'Soweto', province = 'Gauteng',
    postal_code = '1818',
    nok_name = 'Dorothy', nok_relationship = 'Mother', nok_contact = '0783245555',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Musa'').',
        'PDF data flags: Lead source blank.')
WHERE id = 64;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (64, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 7, 'system_import'),
    (64, @att_type_photo, 'people/TCH-000064/photo.png', 'Intake_5_p07.png', NULL, NULL, 'system_import');

-- ── id 65: Nurse Maphosa (PDF: 202601-24) ──
UPDATE caregivers SET
    student_id = '202601-24', known_as = 'Nurse', title = 'Miss', initials = 'N',
    id_passport = '8202051582181', dob = '1982-02-05', gender = 'Female',
    nationality = 'South African', home_language = 'Zulu', other_language = 'English',
    mobile = '0687324785', secondary_number = '0604727182', email = 'nursemapho@gmail.com',
    street_address = '3995 Cnr Newzeland & Solomon Ext 4', suburb = 'Cosmo City', city = 'Randburg', province = 'Gauteng',
    postal_code = '2188',
    nok_name = 'Fortunate Moyo', nok_relationship = 'Daughter', nok_contact = '0656544973',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: First name ''Nurse'' is unusual but appears genuine. Lead source blank.')
WHERE id = 65;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (65, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 8, 'system_import'),
    (65, @att_type_photo, 'people/TCH-000065/photo.png', 'Intake_5_p08.png', NULL, NULL, 'system_import');

-- ── id 66: Present Tema (PDF: 202601-27) ──
UPDATE caregivers SET
    student_id = '202601-27', known_as = 'Present', title = 'Miss', initials = 'P',
    id_passport = '9710011411084', dob = '1997-10-01', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi', other_language = 'English',
    mobile = '0673498947', secondary_number = '0813400303', email = 'temapresent50@gmail.com',
    street_address = '459 Tsakane', suburb = 'Brakpan', province = 'Gauteng', postal_code = '550',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Lead source blank. NoK fields all blank. City blank.')
WHERE id = 66;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (66, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 9, 'system_import'),
    (66, @att_type_photo, 'people/TCH-000066/photo.png', 'Intake_5_p09.png', NULL, NULL, 'system_import');

-- ── id 67: Siphathisiwe Nkala (PDF: 202601-30) ──
UPDATE caregivers SET
    student_id = '202601-30', known_as = 'Siphathisiwe', title = 'Mrs.', initials = 'S',
    id_passport = '08708789Z21', dob = '1973-07-11', gender = 'Female',
    nationality = 'South African', home_language = 'Isindebele', other_language = 'English',
    mobile = '0672222863',
    province = 'Gauteng',
    nok_name = 'Sana', nok_relationship = 'Aunt', nok_contact = '0727849379',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Address fields (street, suburb, city) all blank. Email blank.',
        'NOTE: ''Siphathisiwe Nkala'' (this row) and ''Siphilisiwe Nkala'' (id 28, Tranche 2) are similar names — verified to be different people based on different DOBs and IDs.')
WHERE id = 67;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (67, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 10, 'system_import'),
    (67, @att_type_photo, 'people/TCH-000067/photo.png', 'Intake_5_p10.png', NULL, NULL, 'system_import');

-- ── id 68: Beauty Mantwe Masopye (PDF: 202601-32) ──
UPDATE caregivers SET
    student_id = '202601-32', known_as = 'Beauty', title = 'Miss', initials = 'BM',
    id_passport = '7612252042085', dob = '1976-12-25', gender = 'Female',
    nationality = 'South African', home_language = 'Tswana', other_language = 'Zulu, Afrikaans a little',
    mobile = '0659792024', email = 'mantwabeauty4@gmail.com',
    street_address = '602 Karienhof', suburb = 'Danie Theron', city = 'Pretoria North', province = 'Gauteng',
    nok_name = 'Faith Masupye', nok_relationship = 'Daughter', nok_contact = '0676473999',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 68;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (68, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 11, 'system_import'),
    (68, @att_type_photo, 'people/TCH-000068/photo.png', 'Intake_5_p11.png', NULL, NULL, 'system_import');

-- ── id 69: Busisiwe Marcia Ntuli (PDF: 202601-48, Known As: Busisiwe) ──
UPDATE caregivers SET
    student_id = '202601-48', known_as = 'Busisiwe', title = 'Miss', initials = 'BM',
    id_passport = '8802210459088', dob = '1988-02-21', gender = 'Female',
    nationality = 'South African', home_language = 'Ndebele', other_language = 'English, Tswana, Zulu, Little bit of Afrikaans',
    mobile = '0764913788',
    street_address = '304 Uhururama', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Monica Molekwane', nok_relationship = 'Sister', nok_contact = '0792148156',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Marcia'', PDF: ''Busisiwe''.',
        'PDF data flags: Suburb blank. Email blank.')
WHERE id = 69;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (69, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 12, 'system_import'),
    (69, @att_type_photo, 'people/TCH-000069/photo.png', 'Intake_5_p12.png', NULL, NULL, 'system_import');

-- ── id 70: Dibakazi Gwiji (PDF: 202601-39) ──
UPDATE caregivers SET
    student_id = '202601-39', known_as = 'Dibakazi', title = 'Miss', initials = 'D',
    id_passport = '7610070986087', dob = '1976-10-07', gender = 'Female',
    nationality = 'South African', home_language = 'Xhosa',
    mobile = '0739204465',
    street_address = '530 A Senzanigakhona Str', city = 'Soweto', province = 'Gauteng',
    nok_relationship = 'Brother', nok_contact = '0795991418',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Suburb, email, NoK name all blank.')
WHERE id = 70;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (70, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 13, 'system_import'),
    (70, @att_type_photo, 'people/TCH-000070/photo.png', 'Intake_5_p13.png', NULL, NULL, 'system_import');

-- ── id 71: Gcinokwakhe Mondli Collen Ndlovu (PDF: 202601-38, Known As: Collen) ──
UPDATE caregivers SET
    student_id = '202601-38', known_as = 'Collen', title = 'Mr.', initials = 'GMC',
    id_passport = '8812275369080', dob = '1988-12-27', gender = 'Male',
    nationality = 'South African', home_language = 'Siswati', other_language = 'English',
    mobile = '0737228781', email = 'collenndlovugmc7@gmail.com',
    street_address = '30 Reynold', suburb = 'Kensington', city = 'Johannesburg', province = 'Gauteng',
    nok_name = 'Caswell Ndhlovu', nok_relationship = 'Brother', nok_contact = '0791874389',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 5 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Mondli'', PDF: ''Collen''.')
WHERE id = 71;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (71, @att_type_sheet, @pdf_relative_5, @pdf_filename_5, @pdf_filename_5, 14, 'system_import'),
    (71, @att_type_photo, 'people/TCH-000071/photo.png', 'Intake_5_p14.png', NULL, NULL, 'system_import');

COMMIT;

-- ============================================================
-- TRANCHE 6 (PDF: Intake 6.pdf, 14 records, DB ids 72-85)
-- ============================================================
START TRANSACTION;

SET @pdf_filename_6 = 'Intake 6.pdf';
SET @pdf_relative_6 = 'intake/Tranche 6 - Intake 6.pdf';

-- ── id 72: Amina Mfinda Ndongala (PDF: 202601-4) ──
UPDATE caregivers SET
    student_id = '202601-4', known_as = 'Amina', title = 'Mrs.', initials = 'AM',
    id_passport = '7711221093185', dob = '1977-11-22', gender = 'Female',
    nationality = 'South African', home_language = 'French', other_language = 'English',
    mobile = '0635207229', email = 'ndongalamfindaamina@gmail.com',
    street_address = '7 Kirkbi Road', suburb = 'Bedfordview Garden', city = 'Jobrug', province = 'Gauteng',
    nok_name = 'Chirac', nok_relationship = 'Husband', nok_contact = '0817643035',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Nationality ''South African'' but home language ''French'' — likely Congolese descent. City ''Jobrug'' is a typo for ''Joburg/Johannesburg''.')
WHERE id = 72;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (72, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 1, 'system_import'),
    (72, @att_type_photo, 'people/TCH-000072/photo.png', 'Intake_6_p01.png', NULL, NULL, 'system_import');

-- ── id 73: Christina Nomvula Mtshweni (PDF: 202601-9, Known As: Christina) ──
UPDATE caregivers SET
    student_id = '202601-9', known_as = 'Christina', title = 'Miss', initials = 'CN',
    id_passport = '9705200784085', dob = '1997-05-20', gender = 'Female',
    nationality = 'South African', home_language = 'Ndebele', other_language = 'Spedi English Venda',
    mobile = '0678991951', email = 'mtshwenichristina7@gmail.com',
    street_address = '1256 Block L', suburb = 'Soshanguve', province = 'Gauteng',
    nok_name = 'Johanna Mtshweni', nok_relationship = 'Grandmother', nok_contact = '0665985400',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Christina'').',
        'PDF data flags: City field is blank. ''Spedi'' likely typo for ''Sepedi''.')
WHERE id = 73;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (73, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 2, 'system_import'),
    (73, @att_type_photo, 'people/TCH-000073/photo.png', 'Intake_6_p02.png', NULL, NULL, 'system_import');

-- ── id 74: Danita Conradie (PDF: 202601-6) ──
UPDATE caregivers SET
    student_id = '202601-6', known_as = 'Danita', title = 'Miss', initials = 'D',
    id_passport = '7809190002086', dob = '1978-09-19', gender = 'Female',
    nationality = 'South African', home_language = 'Afrikaans', other_language = 'English',
    mobile = '0824543668', email = 'danitaconradie@gmail.com',
    street_address = '313 Soutpansberg Road', suburb = 'Rietondale', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Natasha Conradie', nok_relationship = 'Sister', nok_contact = '0825369609',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.')
WHERE id = 74;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (74, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 3, 'system_import'),
    (74, @att_type_photo, 'people/TCH-000074/photo.png', 'Intake_6_p03.png', NULL, NULL, 'system_import');

-- ── id 75: Kgomotso Lufuno Matloa (PDF: 202601-8) ──
UPDATE caregivers SET
    student_id = '202601-8', known_as = 'Kgomotso', title = 'Miss', initials = 'KL',
    id_passport = '0502010485089', dob = '2005-02-01', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi', other_language = 'English',
    mobile = '0739473709', email = 'lefonokgomotso019@gmail.com',
    street_address = '1251 Block L', suburb = 'Soshanguve', province = 'Gauteng', postal_code = '0152',
    nok_name = 'Onnica', nok_relationship = 'Mother', nok_contact = '0826392665',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City field is blank.')
WHERE id = 75;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (75, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 4, 'system_import'),
    (75, @att_type_photo, 'people/TCH-000075/photo.png', 'Intake_6_p04.png', NULL, NULL, 'system_import');

-- ── id 76: Memory Banda (PDF: 202601-3, Known As: Cindy) ──
UPDATE caregivers SET
    student_id = '202601-3', known_as = 'Cindy', title = 'Mrs.', initials = 'M',
    id_passport = '6614688/2', dob = '1998-08-10', gender = 'Female',
    nationality = 'Malawi', home_language = 'Chewa', other_language = 'English',
    mobile = '0651579974', email = 'memorycindybanda437@gmail.com',
    street_address = '84 Troye Street', suburb = 'Sunnyside', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Jumah Kazembe', nok_relationship = 'Husband', nok_contact = '0631214903',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was different from PDF (PDF: ''Cindy'').',
        'PDF data flags: Nationality ''Malawi'' (country) preserved as written.')
WHERE id = 76;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (76, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 5, 'system_import'),
    (76, @att_type_photo, 'people/TCH-000076/photo.png', 'Intake_6_p05.png', NULL, NULL, 'system_import');

-- ── id 77: Molapateng Theora Rambau (PDF: 202601-10) ──
UPDATE caregivers SET
    student_id = '202601-10', known_as = 'Theora', title = 'Miss', initials = 'MT',
    id_passport = '0208210388087', dob = '2002-08-21', gender = 'Female',
    nationality = 'South African', home_language = 'Pedi', other_language = 'English',
    mobile = '0631837984', email = 'molapatengrambau@gmail.com',
    street_address = '274 West Avenue', suburb = 'Centurion', province = 'Gauteng',
    nok_name = 'Samkelo Khumalo', nok_relationship = 'Partner', nok_contact = '0746278564',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City field is blank.')
WHERE id = 77;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (77, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 6, 'system_import'),
    (77, @att_type_photo, 'people/TCH-000077/photo.png', 'Intake_6_p06.png', NULL, NULL, 'system_import');

-- ── id 78: Phemela Rachel Maluleke (PDF: 202601-7, Known As: Phemela) ──
UPDATE caregivers SET
    student_id = '202601-7', known_as = 'Phemela', title = 'Miss', initials = 'PR',
    id_passport = '0211150931080', dob = '2002-11-15', gender = 'Female',
    nationality = 'South African', home_language = 'Xitsonga', other_language = 'English',
    mobile = '0798474060', email = 'Phemelamaluleke74@gmail.com',
    street_address = '3138 Prichard', suburb = 'Motle', city = 'Hammanskraal', province = 'Gauteng',
    nok_name = 'Kgothatso Maluleke', nok_relationship = 'Sister', nok_contact = '0720127630',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Rachel'', PDF: ''Phemela''.',
        'PDF data flags: Mobile (0798474060) is identical to Tranche 2 record id 21 (Merriam Mashadi Maluleke). Possibly shared family number — verify.')
WHERE id = 78;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (78, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 7, 'system_import'),
    (78, @att_type_photo, 'people/TCH-000078/photo.png', 'Intake_6_p07.png', NULL, NULL, 'system_import');

-- ── id 79: Pusheletso Mashego Mashabela (PDF: 202601-1) ──
UPDATE caregivers SET
    student_id = '202601-1', known_as = 'Pusheletso', title = 'Miss', initials = 'PM',
    id_passport = '9109271148087', dob = '1991-09-27', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi', other_language = 'English',
    mobile = '0763419263', email = 'pheladikgathane@gmail.com',
    street_address = '261 Jeff Masemola Street', suburb = 'Pretoria Central', city = 'Pretoria', province = 'Gauteng',
    postal_code = '0002',
    nok_name = 'Moremadi Mashabela', nok_relationship = 'Sister', nok_contact = '0609539447',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 79;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (79, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 8, 'system_import'),
    (79, @att_type_photo, 'people/TCH-000079/photo.png', 'Intake_6_p08.png', NULL, NULL, 'system_import');

-- ── id 80: Queen Ramokone Sibanda (PDF: 202601-18) ──
UPDATE caregivers SET
    student_id = '202601-18', known_as = 'Queen', title = 'Mrs.', initials = 'QR',
    id_passport = '9204161279087', dob = '1992-04-16', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi', other_language = 'English',
    mobile = '0762979979', email = 'que.eliz9204@gmail.com',
    street_address = '24 Laetor Street', suburb = 'Mamelodi', province = 'Gauteng',
    nok_name = 'Godfrey Manaka', nok_relationship = 'Husband', nok_contact = '0765980562',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City field is blank.')
WHERE id = 80;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (80, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 9, 'system_import'),
    (80, @att_type_photo, 'people/TCH-000080/photo.png', 'Intake_6_p09.png', NULL, NULL, 'system_import');

-- ── id 81: Rita Ncube (PDF: 202601-2) ──
UPDATE caregivers SET
    student_id = '202601-2', known_as = 'Rita', title = 'Mrs.', initials = 'R',
    id_passport = '56-097456V-56', dob = '1987-10-01', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Ndebeale', other_language = 'Engilsh',
    mobile = '0720172414', email = 'ncuberita03@gmail.com',
    street_address = '6 Streatham Crescent', suburb = 'Bryaston', city = 'Sandton', province = 'Gauteng',
    nok_name = 'Lawrence Dube', nok_relationship = 'Husband', nok_contact = '0790902770',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: ''Ndebeale'' typo for ''Ndebele'', ''Engilsh'' typo for ''English'', ''Bryaston'' typo for ''Bryanston'' — preserved as written.')
WHERE id = 81;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (81, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 10, 'system_import'),
    (81, @att_type_photo, 'people/TCH-000081/photo.png', 'Intake_6_p10.png', NULL, NULL, 'system_import');

-- ── id 82: Violet Labvu (PDF: 202601-5) ──
UPDATE caregivers SET
    student_id = '202601-5', known_as = 'Violet', title = 'Mrs.', initials = 'V',
    id_passport = '32-145380E-45', dob = '1984-12-16', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona',
    mobile = '0695330792', email = 'labvuv@gmail.com',
    street_address = 'Unit 2 Midrand Ridge', suburb = 'Complex Noordwyk', city = 'Midrand', province = 'Gauteng',
    nok_name = 'Tichaona Dambaza', nok_relationship = 'Husband', nok_contact = '0671994284',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.')
WHERE id = 82;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (82, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 11, 'system_import'),
    (82, @att_type_photo, 'people/TCH-000082/photo.png', 'Intake_6_p11.png', NULL, NULL, 'system_import');

-- ── id 83: Bella Kobela Ramaphakela (PDF: 202601-43) ──
UPDATE caregivers SET
    student_id = '202601-43', known_as = 'Bella', title = 'Miss', initials = 'BK',
    id_passport = '8510280557083', dob = '1985-10-28', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi', other_language = 'English',
    mobile = '0728295368', email = 'maphaksramaphakela@gmail.com',
    street_address = '398 Sunnyside', suburb = 'Pretoria Central', province = 'Gauteng',
    nok_name = 'Linah Ramaphakela', nok_relationship = 'Sister', nok_contact = '0798291723',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: City field is blank.')
WHERE id = 83;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (83, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 12, 'system_import'),
    (83, @att_type_photo, 'people/TCH-000083/photo.png', 'Intake_6_p12.png', NULL, NULL, 'system_import');

-- ── id 84: Hlamalani Division Ngobeni (PDF: 202601-42, Known As: Lani) ──
UPDATE caregivers SET
    student_id = '202601-42', known_as = 'Lani', title = 'Miss', initials = 'HD',
    id_passport = '8203220565081', dob = '1982-03-22', gender = 'Female',
    nationality = 'South African', home_language = 'Xitsongo', other_language = 'English',
    mobile = '0827489661', email = 'hlamalanidivision@gmail.com',
    street_address = '773 Midhopper Street', suburb = 'Kaalfontein Ext 1', city = 'Midrand', province = 'Gauteng',
    postal_code = '1686',
    nok_name = 'Hlayisani Ngoveni', nok_relationship = 'Sister', nok_contact = '0767440599',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Division'', PDF: ''Lani''.',
        'PDF data flags: ''Xitsongo'' likely typo for ''Xitsonga''.')
WHERE id = 84;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (84, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 13, 'system_import'),
    (84, @att_type_photo, 'people/TCH-000084/photo.png', 'Intake_6_p13.png', NULL, NULL, 'system_import');

-- ── id 85: Oluwakemi Mary Ojomo (PDF: 202601-47, Known As: Mary) ──
UPDATE caregivers SET
    student_id = '202601-47', known_as = 'Mary', title = 'Miss', initials = 'OM',
    id_passport = 'A13292704', dob = '1984-02-17', gender = 'Female',
    nationality = 'Nigerian', home_language = 'Yoryba', other_language = 'English',
    mobile = '0635146204', email = 'ojomokemi9@gmail.com',
    street_address = '240 Kraii', suburb = 'Pretoria West', province = 'Gauteng',
    nok_name = 'Andrew', nok_relationship = 'Uncle', nok_contact = '0617059087',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 6 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Kemi'', PDF: ''Mary''.',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: ''Yoryba'' is a typo for ''Yoruba'' — preserved as written.')
WHERE id = 85;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (85, @att_type_sheet, @pdf_relative_6, @pdf_filename_6, @pdf_filename_6, 14, 'system_import'),
    (85, @att_type_photo, 'people/TCH-000085/photo.png', 'Intake_6_p14.png', NULL, NULL, 'system_import');

COMMIT;

-- ============================================================
-- TRANCHE 7 (PDF: Intake 7.pdf, 13 records, DB ids 86-98)
-- ============================================================
START TRANSACTION;

SET @pdf_filename_7 = 'Intake 7.pdf';
SET @pdf_relative_7 = 'intake/Tranche 7 - Intake 7.pdf';

-- ── id 86: Sandra Mhaka (PDF: 202601-44) ──
UPDATE caregivers SET
    student_id = '202601-44', known_as = 'Sandra', title = 'Mrs.', initials = 'S',
    id_passport = '29-205553Q54CITF', dob = '1981-06-29', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0718552995', email = 'sandramhaka39@gmail.com',
    street_address = '128 Sicelo Street', suburb = 'Oiivienhoutbosch', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Simbarashe Mapetese', nok_relationship = 'Husband', nok_contact = '0749361532',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: ''Oiivienhoutbosch'' likely typo for ''Olievenhoutbosch'' — preserved as written.')
WHERE id = 86;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (86, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 1, 'system_import'),
    (86, @att_type_photo, 'people/TCH-000086/photo.png', 'Intake_7_p01.png', NULL, NULL, 'system_import');

-- ── id 87: Bekithemba Mpofu (PDF: 202602-10, Known As: T Man) ──
UPDATE caregivers SET
    student_id = '202602-10', known_as = 'T Man', title = 'Mr.', initials = 'B',
    id_passport = '28076269H28', dob = '1974-10-06', gender = 'Male',
    nationality = 'Zimbabwen', home_language = 'Ndebele', other_language = 'Zulu',
    mobile = '0723630347', email = 'thembampofu03@gmail.com',
    street_address = '17 Hendon & Granfton', suburb = 'Yeoville', city = 'Johannesburg', province = 'Gauteng',
    nok_name = 'Sthenjisiwe Gumbi', nok_relationship = 'Cousin', nok_contact = '0652458862',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Bekithemba'', PDF: ''T Man''.',
        'PDF data flags: Nationality ''Zimbabwen'' is a typo for ''Zimbabwean'' — preserved as written.')
WHERE id = 87;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (87, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 2, 'system_import'),
    (87, @att_type_photo, 'people/TCH-000087/photo.png', 'Intake_7_p02.png', NULL, NULL, 'system_import');

-- ── id 88: Blessing Rofhiwa Ndou (PDF: 202602-2, Known As: Rofhiwa) ──
UPDATE caregivers SET
    student_id = '202602-2', known_as = 'Rofhiwa', title = 'Miss', initials = 'BR',
    id_passport = '0604220174089', dob = '2006-04-22', gender = 'Female',
    nationality = 'South African', home_language = 'Isizulu', other_language = 'English, Zulu',
    mobile = '0602060957', email = 'blessingrofhiwa45@gmail.com',
    street_address = '51 Klipriver - 148 Eikenhof', suburb = 'Thembisa', province = 'Gauteng',
    nok_name = 'Beatrice Ndou', nok_relationship = 'Mother', nok_contact = '0630903785',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Blessing'', PDF: ''Rofhiwa''.',
        'PDF data flags: City field is blank.')
WHERE id = 88;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (88, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 3, 'system_import'),
    (88, @att_type_photo, 'people/TCH-000088/photo.png', 'Intake_7_p03.png', NULL, NULL, 'system_import');

-- ── id 89: Immaculate Tshegofatso Mataboge (PDF: 202602-8, Known As: Imma) ──
UPDATE caregivers SET
    student_id = '202602-8', known_as = 'Imma', title = 'Miss', initials = 'IT',
    id_passport = '9412010449088', dob = '1994-12-01', gender = 'Female',
    nationality = 'South African', home_language = 'Setswana', other_language = 'English, Afrikaans a little',
    mobile = '0632157574', email = 'immaculatemoteane12@gmail.com',
    street_address = '21819 Ext 07', suburb = 'Soshanguve', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Margaret Moteane', nok_relationship = 'Mother', nok_contact = '0733670407',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Tshego'', PDF: ''Imma''.')
WHERE id = 89;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (89, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 4, 'system_import'),
    (89, @att_type_photo, 'people/TCH-000089/photo.png', 'Intake_7_p04.png', NULL, NULL, 'system_import');

-- ── id 90: Josephine Olaide Olaleye (PDF: 202602-11) ──
UPDATE caregivers SET
    student_id = '202602-11', known_as = 'Josephine', title = 'Miss', initials = 'JO',
    id_passport = '96223818308', dob = '1992-05-26', gender = 'Female',
    nationality = 'Nigeria', home_language = 'English',
    mobile = '0616269378', email = 'josephinetoolz@outlool.com',
    street_address = '417 Jorissen Street', suburb = 'Sunnyside', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Johnny', nok_relationship = 'Uncle', nok_contact = '0846294378',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Email ''@outlool.com'' is a typo for ''@outlook.com'' — preserved as written. Nationality ''Nigeria'' (country) preserved.')
WHERE id = 90;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (90, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 5, 'system_import'),
    (90, @att_type_photo, 'people/TCH-000090/photo.png', 'Intake_7_p05.png', NULL, NULL, 'system_import');

-- ── id 91: Lydia James Banda (PDF: 202602-7) ──
UPDATE caregivers SET
    student_id = '202602-7', known_as = 'Lydia', title = 'Miss', initials = 'LJ',
    id_passport = '1836398/9', dob = '1978-04-08', gender = 'Female',
    nationality = 'Malawian', home_language = 'Chichewa', other_language = 'Bemba, Zulu, English',
    mobile = '0768506793', secondary_number = '0634393711', email = 'bandalydia550@gmail.com',
    street_address = '112 Ecalen', suburb = 'Tembisa', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Neels Marais', nok_relationship = 'Boss', nok_contact = '0833205950',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: NoK relationship is ''Boss'' (not a family member) — confirm whether this is intentional. Suburb ''Tembisa'' with city ''Pretoria'' — Tembisa is in Ekurhuleni. Address inconsistency.')
WHERE id = 91;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (91, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 6, 'system_import'),
    (91, @att_type_photo, 'people/TCH-000091/photo.png', 'Intake_7_p06.png', NULL, NULL, 'system_import');

-- ── id 92: Margaret Mamatime Dimakatso Aphane (PDF: 202602-9, Known As: Katso) ──
UPDATE caregivers SET
    student_id = '202602-9', known_as = 'Katso', title = 'Miss', initials = 'MMD',
    id_passport = '8902100839082', dob = '1989-02-10', gender = 'Female',
    nationality = 'South African', home_language = 'Tswana', other_language = 'English',
    mobile = '0607708305', secondary_number = '0767359351', email = 'katsoaphane5@gmail.com',
    street_address = '71 Paxite Street', suburb = 'Suiderberg', city = 'Pretoria', province = 'Gauteng',
    postal_code = '0055',
    nok_name = 'Hellen Aphane', nok_relationship = 'Mother', nok_contact = '0722749105',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Margaret'', PDF: ''Katso''.')
WHERE id = 92;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (92, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 7, 'system_import'),
    (92, @att_type_photo, 'people/TCH-000092/photo.png', 'Intake_7_p07.png', NULL, NULL, 'system_import');

-- ── id 93: Mary Komwedzai (PDF: 202602-12) ──
UPDATE caregivers SET
    student_id = '202602-12', known_as = 'Mary', title = 'Miss', initials = 'M',
    id_passport = '12-147169T-12', dob = '1994-10-22', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0785156595', secondary_number = '0602608308', email = 'komwedzaimary@gmail.com',
    street_address = '24 Silver Tree Street', suburb = 'Noordwyk', city = 'Midrand', province = 'Gauteng',
    nok_name = 'Memory', nok_relationship = 'Sister', nok_contact = '0848263507',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 93;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (93, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 8, 'system_import'),
    (93, @att_type_photo, 'people/TCH-000093/photo.png', 'Intake_7_p08.png', NULL, NULL, 'system_import');

-- ── id 94: Octovia Xitsundzuxo Marhanele (PDF: 202602-4, Known As: Tsundzu) ──
UPDATE caregivers SET
    student_id = '202602-4', known_as = 'Tsundzu', title = 'Mrs.', initials = 'OX',
    id_passport = '9408140371085', dob = '1994-08-14', gender = 'Female',
    nationality = 'South African', home_language = 'Xitsonga', other_language = 'English',
    mobile = '0788587129', email = 'marhaneleoctovia86@gmail.com',
    street_address = 'Winnie Mandela', suburb = 'Zone 10', city = 'Thembisa', province = 'Gauteng',
    nok_name = 'Maxwell', nok_relationship = 'Husband', nok_contact = '0785613857',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Octovia'', PDF: ''Tsundzu''.')
WHERE id = 94;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (94, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 9, 'system_import'),
    (94, @att_type_photo, 'people/TCH-000094/photo.png', 'Intake_7_p09.png', NULL, NULL, 'system_import');

-- ── id 95: Patience Sesi Poto (PDF: 202602-6) ──
UPDATE caregivers SET
    student_id = '202602-6', known_as = 'Patience', title = 'Miss', initials = 'PS',
    id_passport = '0305310917086', dob = '2003-05-31', gender = 'Female',
    nationality = 'South African', home_language = 'Setswana', other_language = 'English',
    mobile = '0609554986', email = 'patiencepoto20@gmail.com',
    street_address = '84 Matebeleng', suburb = 'Pankop', city = 'Mpumalanga', province = 'Gauteng',
    nok_name = 'Rebecca Poto', nok_relationship = 'Aunt', nok_contact = '0795689497',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City ''Mpumalanga'' is a province, not a city. Province says Gauteng. Address inconsistency.')
WHERE id = 95;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (95, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 10, 'system_import'),
    (95, @att_type_photo, 'people/TCH-000095/photo.png', 'Intake_7_p10.png', NULL, NULL, 'system_import');

-- ── id 96: Sibonokuhle Mpofu (PDF: 202602-5, Known As: Bongi) ──
UPDATE caregivers SET
    student_id = '202602-5', known_as = 'Bongi', title = 'Miss', initials = 'S',
    id_passport = '84089689K35', dob = '1980-11-08', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Zulu', other_language = 'English',
    mobile = '0738537080',
    street_address = '308 Hilda Street', suburb = 'Parkmore', city = 'Johannesburg', province = 'Gauteng',
    nok_name = 'Pamela', nok_relationship = 'Sister', nok_contact = '0655570815',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Sibonokuhle'', PDF: ''Bongi''.',
        'PDF data flags: Email blank.')
WHERE id = 96;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (96, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 11, 'system_import'),
    (96, @att_type_photo, 'people/TCH-000096/photo.png', 'Intake_7_p11.png', NULL, NULL, 'system_import');

-- ── id 97: Thandiwe Dhlodhlo (PDF: 202602-3, Known As: Thandi) ──
UPDATE caregivers SET
    student_id = '202602-3', known_as = 'Thandi', title = 'Miss', initials = 'T',
    id_passport = '28091930F14', dob = '1981-04-15', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Isindebele', other_language = 'English',
    mobile = '0745760880', email = 'thandlodlo@gmail.com',
    street_address = '9591 Marksman Ex 36', suburb = 'Olievehoutbosch', province = 'Gauteng',
    nok_name = 'Vusa Mpopu', nok_relationship = 'Brother', nok_contact = '0731244478',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Thandiwe'', PDF: ''Thandi''.',
        'PDF data flags: City field is blank.',
        'NOTE: Two records with known_as ''Thandi'' in the system: id 30 (Thandi Ngobeni, Tranche 2) and id 97 (this record, Thandiwe Dhlodhlo, Tranche 7). Different people.')
WHERE id = 97;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (97, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 12, 'system_import'),
    (97, @att_type_photo, 'people/TCH-000097/photo.png', 'Intake_7_p12.png', NULL, NULL, 'system_import');

-- ── id 98: Thembi Emily Mpete (PDF: 202602-1) ──
UPDATE caregivers SET
    student_id = '202602-1', known_as = 'Thembi', title = 'Miss', initials = 'TE',
    id_passport = '8912101209086', dob = '1989-12-10', gender = 'Female',
    nationality = 'South African', home_language = 'Setswana', other_language = 'English',
    mobile = '0729292867', email = 'thembimpete96@gmail.com',
    street_address = '440 Section 1', suburb = 'Suurman', city = 'Hammenskraal', province = 'Gauteng',
    postal_code = '0407',
    nok_name = 'Pogiso Mpete', nok_relationship = 'Uncle', nok_contact = '0714678191',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 7 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: City ''Hammenskraal'' is a typo for ''Hammanskraal'' — preserved as written.')
WHERE id = 98;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (98, @att_type_sheet, @pdf_relative_7, @pdf_filename_7, @pdf_filename_7, 13, 'system_import'),
    (98, @att_type_photo, 'people/TCH-000098/photo.png', 'Intake_7_p13.png', NULL, NULL, 'system_import');

COMMIT;

-- ============================================================
-- TRANCHE 8 (PDF: Intake 8.pdf, 12 records, DB ids 99-110)
-- ============================================================
START TRANSACTION;

SET @pdf_filename_8 = 'Intake 8.pdf';
SET @pdf_relative_8 = 'intake/Tranche 8 - Intake 8.pdf';

-- ── id 99: Maphefo Dinah Mogola (PDF: 202601-49) ──
UPDATE caregivers SET
    student_id = '202601-49', known_as = 'Dinah', title = 'Miss', initials = 'MD',
    id_passport = '8305100424089', dob = '1983-05-10', gender = 'Female',
    nationality = 'South African', home_language = 'Spedi', other_language = 'English, Afrikaans',
    mobile = '0625087178', secondary_number = '0846473643',
    suburb = 'Eersturust', city = 'Mamelodi', province = 'Gauteng',
    nok_name = 'Aaron', nok_relationship = 'Partner', nok_contact = '0846473643',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Street Address blank. ''Spedi'' typo for ''Sepedi''. ''Eersturust'' typo for ''Eersterust''. NoK contact identical to candidate secondary number — verify.')
WHERE id = 99;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (99, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 1, 'system_import'),
    (99, @att_type_photo, 'people/TCH-000099/photo.png', 'Intake_8_p01.png', NULL, NULL, 'system_import');

-- ── id 100: Martha Kedibone Mashigo (PDF: 202601-36, Known As: Martha) ──
UPDATE caregivers SET
    student_id = '202601-36', known_as = 'Martha', title = 'Miss', initials = 'MK',
    id_passport = '6912210835088', dob = '1969-12-21', gender = 'Female',
    nationality = 'South African', home_language = 'Tswana',
    mobile = '0791470890', email = 'marthamashigo21@gmail.com',
    street_address = '14630 Kgagudi Street', suburb = 'Mamelodi East', province = 'Gauteng',
    nok_name = 'Tshepong', nok_relationship = 'Daughter', nok_contact = '07258455122',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Kedibone'', PDF: ''Martha''.',
        'PDF data flags: NoK contact ''07258455122'' is 11 digits (one too many) — typo. City field is blank. Lead source blank.')
WHERE id = 100;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (100, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 2, 'system_import'),
    (100, @att_type_photo, 'people/TCH-000100/photo.png', 'Intake_8_p02.png', NULL, NULL, 'system_import');

-- ── id 101: Martha Uke Sona (PDF: 202601-34, Known As: Uke) ──
UPDATE caregivers SET
    student_id = '202601-34', known_as = 'Uke', title = 'Miss', initials = 'MU',
    id_passport = 'AA652194', dob = '1974-06-13', gender = 'Female',
    nationality = 'South African', home_language = 'French', other_language = 'English, Zulu',
    mobile = '0738899289', email = 'sonamartha@gmail.com',
    street_address = 'Ext 27', suburb = 'Olievenhoutbosch', city = 'Centurion', province = 'Gauteng',
    nok_name = 'Balemba Ann', nok_relationship = 'Sister', nok_contact = '0710525583',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Martha'', PDF: ''Uke''.',
        'PDF data flags: Nationality ''South African'' but home language ''French'' — likely Congolese descent. Verify.')
WHERE id = 101;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (101, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 3, 'system_import'),
    (101, @att_type_photo, 'people/TCH-000101/photo.png', 'Intake_8_p03.png', NULL, NULL, 'system_import');

-- ── id 102: Nolukhanyo Nondabula (PDF: 202601-40) ──
UPDATE caregivers SET
    student_id = '202601-40', known_as = 'Nolukhanyo', title = 'Mrs.', initials = 'N',
    id_passport = '8302090464084', dob = '1983-02-09', gender = 'Female',
    nationality = 'South African', home_language = 'Isixhosa',
    mobile = '0836331359',
    street_address = 'E895 Nancefield Hostel', city = 'Soweto', province = 'Gauteng',
    nok_name = 'Zoyisile Henry Maseti', nok_relationship = 'Husband', nok_contact = '0837715616',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: Suburb blank. Email blank.')
WHERE id = 102;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (102, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 4, 'system_import'),
    (102, @att_type_photo, 'people/TCH-000102/photo.png', 'Intake_8_p04.png', NULL, NULL, 'system_import');

-- ── id 103: Ntombifikile Octavia Mhlongo (PDF: 202601-41) ──
-- DOB on PDF is "0005-08-03" — clearly invalid. Stored as NULL with note.
UPDATE caregivers SET
    student_id = '202601-41', known_as = 'Octavia', title = 'Miss', initials = 'NO',
    id_passport = '7508030807080', gender = 'Female',
    nationality = 'South African', home_language = 'Zulu', other_language = 'English, Little bit of Afrikaans',
    mobile = '0659087063', email = 'fixnjomane@gmail.com',
    street_address = '129B Kingsway Avenue', city = 'Brakpan', province = 'Gauteng',
    nok_name = 'Thembeka', nok_relationship = 'Daughter', nok_contact = '0691859937',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: DOB on the PDF is ''0005-08-03'' which is clearly invalid (year 0005). Most likely the candidate meant 1975-08-03 (matching the ID number 7508030807080). DOB left as the existing DB value, NOT overwritten.',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: Suburb blank.')
WHERE id = 103;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (103, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 5, 'system_import'),
    (103, @att_type_photo, 'people/TCH-000103/photo.png', 'Intake_8_p05.png', NULL, NULL, 'system_import');

-- ── id 104: Sibongile Sophia Watanzi Tshibala (PDF: 202601-35) ──
UPDATE caregivers SET
    student_id = '202601-35', known_as = 'Sophia', title = 'Mrs.', initials = 'SS',
    id_passport = '6709110556081', dob = '1967-09-11', gender = 'Female',
    nationality = 'South African', home_language = 'English', other_language = 'Zulu',
    mobile = '0738673112', email = 'sophiathsiba@gmail.com',
    street_address = 'Unit 402 Cnr Mitchell & York Street', suburb = 'Berea', city = 'Johannesburg', province = 'Gauteng',
    postal_code = '2198',
    nok_name = 'Gerald', nok_relationship = 'Husband', nok_contact = '0742132916',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 104;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (104, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 6, 'system_import'),
    (104, @att_type_photo, 'people/TCH-000104/photo.png', 'Intake_8_p06.png', NULL, NULL, 'system_import');

-- ── id 105: Sithenjisiwe Gumbi (PDF: 202511-11) ──
UPDATE caregivers SET
    student_id = '202511-11', title = 'Miss', initials = 'S',
    id_passport = '08614054B19', dob = '1970-09-26', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Zulu',
    mobile = '0652458862', email = 'sitheujisiweg@gmail.com',
    street_address = '293 Fontana Inn', suburb = 'Smit Street', city = 'Johannesburg', province = 'Gauteng',
    nok_name = 'Nosizwe Albertina Kosana', nok_relationship = 'Sister', nok_contact = '0677718807',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Known As field is blank on the PDF — not updated. Lead source blank.',
        'NOTE: Mobile number 0652458862 is identical to id 87 (Bekithemba Mpofu) NoK contact. May indicate the cousin relationship is Sthenjisiwe/Sithenjisiwe — verify spelling and family link.')
WHERE id = 105;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (105, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 7, 'system_import'),
    (105, @att_type_photo, 'people/TCH-000105/photo.png', 'Intake_8_p07.png', NULL, NULL, 'system_import');

-- ── id 106: Smangele Linah Chauke (PDF: 202601-37) ──
UPDATE caregivers SET
    student_id = '202601-37', known_as = 'Linah', title = 'Miss', initials = 'SL',
    id_passport = '7903070649080', dob = '1979-03-07', gender = 'Female',
    nationality = 'South African', home_language = 'English',
    mobile = '0724090165', email = 'smagele79chauke@gmail.com',
    street_address = '75/210 Block IA', suburb = 'Ext 1', city = 'Soshanguve', province = 'Gauteng',
    postal_code = '0152',
    nok_name = 'Leonarel Makgwelhana', nok_relationship = 'Brother', nok_contact = '0814079612',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Lead source blank.')
WHERE id = 106;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (106, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 8, 'system_import'),
    (106, @att_type_photo, 'people/TCH-000106/photo.png', 'Intake_8_p08.png', NULL, NULL, 'system_import');

-- ── id 107: Xolile Fortunate Mabizela (PDF: 202601-33) ──
UPDATE caregivers SET
    student_id = '202601-33', known_as = 'Fortunate', title = 'Mrs.', initials = 'XF',
    id_passport = '8505250777083', dob = '1985-05-25', gender = 'Female',
    nationality = 'South African', home_language = 'Isizulu', other_language = 'English, Afrikaans little',
    mobile = '0725140136', email = 'xolilefortunate52@gmail.com',
    complex_estate = 'Stand 21694', street_address = '6713 Spotted Bass Street',
    suburb = 'Soshanguve South', province = 'Gauteng', postal_code = '0152',
    nok_name = 'Thulisile Mabasa', nok_relationship = 'Sister', nok_contact = '0794345281',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City field is blank.')
WHERE id = 107;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (107, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 9, 'system_import'),
    (107, @att_type_photo, 'people/TCH-000107/photo.png', 'Intake_8_p09.png', NULL, NULL, 'system_import');

-- ── id 108: Chipo Mujere (PDF: 202510-22) ──
UPDATE caregivers SET
    student_id = '202510-22', known_as = 'Chipo', title = 'Mrs.', initials = 'C',
    id_passport = '04-155947H-04', dob = '1989-07-13', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0718097605', email = 'chipomarange23@gmail.com',
    street_address = '158 Lanham Street', suburb = 'East Lynne', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Stewart Marange', nok_relationship = 'Husband', nok_contact = '0718097605',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: NoK contact identical to candidate own mobile — verify.')
WHERE id = 108;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (108, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 10, 'system_import'),
    (108, @att_type_photo, 'people/TCH-000108/photo.png', 'Intake_8_p10.png', NULL, NULL, 'system_import');

-- ── id 109: Janine Louise Jones (PDF: 202509-14, Known As: Louise) ──
UPDATE caregivers SET
    student_id = '202509-14', known_as = 'Louise', title = 'Miss', initials = 'JL',
    id_passport = '7511220042088', dob = '1975-11-22', gender = 'Female',
    nationality = 'South African', home_language = 'English', other_language = 'Afrikaans',
    mobile = '0661097752', email = 'louisejones3255@gmail.com',
    street_address = '147B Tammy Street', suburb = 'Grootfontein', city = 'Pretroia', province = 'Gauteng',
    nok_name = 'Lisa Basson', nok_relationship = 'Sister', nok_contact = '0721973357',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Janine'', PDF: ''Louise''.',
        'PDF data flags: City ''Pretroia'' is a typo for ''Pretoria'' — preserved as written.')
WHERE id = 109;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (109, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 11, 'system_import'),
    (109, @att_type_photo, 'people/TCH-000109/photo.png', 'Intake_8_p11.png', NULL, NULL, 'system_import');

-- ── id 110: Jeanine Judith Corringham (PDF: 202510-23) ──
UPDATE caregivers SET
    student_id = '202510-23', known_as = 'Jeanine', title = 'Miss', initials = 'JJ',
    id_passport = '8011120018089', dob = '1980-11-12', gender = 'Female',
    nationality = 'South African', home_language = 'English',
    mobile = '0671238947', email = 'jeaninecorringham@yahoo.com',
    street_address = '4 Falun Road', suburb = 'Valhalla', city = 'Pretoira', province = 'Gauteng',
    postal_code = '0185',
    nok_name = 'Judith McGinn', nok_relationship = 'Mother', nok_contact = '0835443244',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 8 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City ''Pretoira'' is a typo for ''Pretoria'' — preserved as written.')
WHERE id = 110;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (110, @att_type_sheet, @pdf_relative_8, @pdf_filename_8, @pdf_filename_8, 12, 'system_import'),
    (110, @att_type_photo, 'people/TCH-000110/photo.png', 'Intake_8_p12.png', NULL, NULL, 'system_import');

COMMIT;

-- ============================================================
-- TRANCHE 9 (PDF: Intake 9.pdf, 13 records, DB ids 111-123)
-- ============================================================
START TRANSACTION;

SET @pdf_filename_9 = 'Intake 9.pdf';
SET @pdf_relative_9 = 'intake/Tranche 9 - Intake 9.pdf';

-- ── id 111: Karabo Mabye (PDF: 202510-27) ──
UPDATE caregivers SET
    student_id = '202510-27', known_as = 'Karabo', title = 'Miss', initials = 'K',
    id_passport = '9901160490082', dob = '1999-01-16', gender = 'Female',
    nationality = 'South African', home_language = 'Setswane', other_language = 'English',
    mobile = '0637421259',
    street_address = '7586 Ikhukhyzi Street', suburb = 'Soshonguwe', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Maria Mabye', nok_relationship = 'Sister', nok_contact = '0792987502',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: Email blank. ''Setswane'' typo for ''Setswana'' — preserved.')
WHERE id = 111;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (111, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 1, 'system_import'),
    (111, @att_type_photo, 'people/TCH-000111/photo.png', 'Intake_9_p01.png', NULL, NULL, 'system_import');

-- ── id 112: Lisa Thembi Phasha (PDF: 202510-25) ──
UPDATE caregivers SET
    student_id = '202510-25', known_as = 'Lisa', title = 'Mrs.', initials = 'LT',
    id_passport = '8612261681088', dob = '1986-12-26', gender = 'Female',
    nationality = 'South African', home_language = 'Setswana', other_language = 'English',
    mobile = '0834414415', email = 'lisap8641@gmail.com',
    street_address = '201 Waterbok Street', suburb = 'Kwaggasrand', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Maduba Frank Phasha', nok_relationship = 'Husband', nok_contact = '0717871786',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).')
WHERE id = 112;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (112, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 2, 'system_import'),
    (112, @att_type_photo, 'people/TCH-000112/photo.png', 'Intake_9_p02.png', NULL, NULL, 'system_import');

-- ── id 113: Mamohlolo Nkalai (PDF: 202510-26, Known As: Nompi) ──
UPDATE caregivers SET
    student_id = '202510-26', known_as = 'Nompi', title = 'Mrs.', initials = 'M',
    id_passport = '029189266427', dob = '1992-09-07', gender = 'Female',
    nationality = 'Lesotho', home_language = 'Sesotho', other_language = 'English/ Little bit Afr',
    mobile = '0614094652', email = 'nompi1388@gmail.com',
    street_address = 'A550 Duduza', suburb = 'Tembisa', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Lufhando', nok_relationship = 'Husband', nok_contact = '0710070700',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Mamohlolo'', PDF: ''Nompi''.',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: Nationality ''Lesotho'' (country) preserved. Suburb ''Tembisa'' but city ''Pretoria'' — Tembisa is in Ekurhuleni.')
WHERE id = 113;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (113, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 3, 'system_import'),
    (113, @att_type_photo, 'people/TCH-000113/photo.png', 'Intake_9_p03.png', NULL, NULL, 'system_import');

-- ── id 114: Marlise Louise Els (PDF: 202510-20) ──
UPDATE caregivers SET
    student_id = '202510-20', known_as = 'Marlise', title = 'Miss', initials = 'ML',
    id_passport = '0104030114086', dob = '2001-04-03', gender = 'Female',
    nationality = 'South African', home_language = 'English', other_language = 'Afrikaans',
    mobile = '0716743803', email = 'marliseels5@gmail.com',
    street_address = '147B Tammy Street', suburb = 'Grootfontein', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Jaco Els', nok_relationship = 'Brother', nok_contact = '0622863169',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'NOTE: Same address (147B Tammy Street, Grootfontein) as id 109 (Janine Louise Jones, Tranche 8). Same surname pattern (Els, Jones) — possibly related households or shared accommodation. Verify.')
WHERE id = 114;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (114, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 4, 'system_import'),
    (114, @att_type_photo, 'people/TCH-000114/photo.png', 'Intake_9_p04.png', NULL, NULL, 'system_import');

-- ── id 115: Prisca Ndlovu (PDF: 202510-24) ──
UPDATE caregivers SET
    student_id = '202510-24', known_as = 'Prisca', title = 'Mrs.', initials = 'PK',
    id_passport = 'AE258737', dob = '1982-05-31', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0730470861', email = 'priscandlovu@gmail.com',
    street_address = '43 Railway Avenue', suburb = 'Benoni', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Sandile Sibanda', nok_relationship = 'Brother', nok_contact = '0781916606',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: Suburb ''Benoni'' but city ''Pretoria'' — Benoni is in Ekurhuleni. Address inconsistency.')
WHERE id = 115;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (115, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 5, 'system_import'),
    (115, @att_type_photo, 'people/TCH-000115/photo.png', 'Intake_9_p05.png', NULL, NULL, 'system_import');

-- ── id 116: Reshoketswe Amber Makgahlela (PDF: 202510-21) ──
UPDATE caregivers SET
    student_id = '202510-21', known_as = 'Amber', title = 'Miss', initials = 'RA',
    id_passport = '0401150563088', dob = '2004-01-15', gender = 'Female',
    nationality = 'South African', home_language = 'Sepedi', other_language = 'English',
    mobile = '0727382808', email = 'showkey989@gmail.com',
    street_address = 'Alze Vusimuzi Section', suburb = 'Tembisa', city = 'Pretoira', province = 'Gauteng',
    postal_code = '1632',
    nok_name = 'Tiny Makgahlela', nok_relationship = 'Mother', nok_contact = '0722232650',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: City ''Pretoira'' typo for ''Pretoria''. Suburb ''Tembisa'' but city ''Pretoria'' — Tembisa is in Ekurhuleni.')
WHERE id = 116;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (116, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 6, 'system_import'),
    (116, @att_type_photo, 'people/TCH-000116/photo.png', 'Intake_9_p06.png', NULL, NULL, 'system_import');

-- ── id 117: Sinqobile Chiropa (PDF: 202510-19) ──
UPDATE caregivers SET
    student_id = '202510-19', known_as = 'Sinqobile', title = 'Miss', initials = 'S',
    id_passport = '23-057554H-23', dob = '1987-05-09', gender = 'Female',
    nationality = 'Zimbabwean', home_language = 'Shona', other_language = 'English',
    mobile = '0742012185', email = 'sinqobilechiropa06@gmail.com',
    street_address = '27485 Unknown Street', suburb = 'Olivenhoutbosch', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'June Mucheto', nok_relationship = 'Partner', nok_contact = '0842245700',
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'Lead source ''Social_media'' — generic; not mapped; left blank for review.',
        'PDF data flags: Street address literally ''27485 Unknown Street'' — placeholder, candidate did not know street name. Verify.')
WHERE id = 117;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (117, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 7, 'system_import'),
    (117, @att_type_photo, 'people/TCH-000117/photo.png', 'Intake_9_p07.png', NULL, NULL, 'system_import');

-- ── id 118: Siphesihle Rabotho (PDF: 202510-28, Known As: Sihle) ──
UPDATE caregivers SET
    student_id = '202510-28', known_as = 'Sihle', title = 'Miss', initials = 'S',
    id_passport = '9907090402089', dob = '1999-07-09', gender = 'Female',
    nationality = 'South African', home_language = 'English', other_language = 'Afrikaans',
    mobile = '0718824587', email = 'siphesihlerabotho0@gmail.com',
    street_address = '281 Jeff Masemule Street', suburb = 'Pretoria Central', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Lucky Ndlovu', nok_relationship = 'Father', nok_contact = '0828882454',
    lead_source_id = @ls_word_of_mouth,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Siphesihle'', PDF: ''Sihle''.')
WHERE id = 118;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (118, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 8, 'system_import'),
    (118, @att_type_photo, 'people/TCH-000118/photo.png', 'Intake_9_p08.png', NULL, NULL, 'system_import');

-- ── id 119: Juliet Tshakane Lekgothoane (PDF: 202603-5) ──
UPDATE caregivers SET
    student_id = '202603-5', known_as = 'Juliet', title = 'Mrs.', initials = 'JT',
    id_passport = '8312010464080', dob = '1983-12-01', gender = 'Female',
    nationality = 'South African', home_language = 'Speed', other_language = 'English, Basic Afrikaans, Zulu',
    mobile = '0765283954', email = 'soafojuliet@gmail.com',
    street_address = '2059 Ivusino Str', suburb = 'Reigerpark', city = 'Boksburg', province = 'Gauteng',
    nok_name = 'Albert Soafo', nok_relationship = 'Husband', nok_contact = '0765283954',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: ''Speed'' typo for ''Sepedi''. NoK contact identical to candidate own mobile — verify.')
WHERE id = 119;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (119, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 9, 'system_import'),
    (119, @att_type_photo, 'people/TCH-000119/photo.png', 'Intake_9_p09.png', NULL, NULL, 'system_import');

-- ── id 120: Leandra Sharlen Emalize Williams (PDF: 202603-2) ──
UPDATE caregivers SET
    student_id = '202603-2', known_as = 'Leandra', title = 'Miss', initials = 'LSE',
    id_passport = '8711110194085', dob = '1987-11-11', gender = 'Female',
    nationality = 'South African', home_language = 'Afrikaans', other_language = 'English',
    mobile = '0729572182', email = 'williamsleandra9@gmail.com',
    street_address = 'Soutrivier Ave 409', suburb = 'Eesterus', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Bennie Damingo', nok_relationship = 'Uncle', nok_contact = '0658955673',
    lead_source_id = @ls_referral,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: ''Eesterus'' likely typo for ''Eersterust'' — preserved as written.')
WHERE id = 120;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (120, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 10, 'system_import'),
    (120, @att_type_photo, 'people/TCH-000120/photo.png', 'Intake_9_p10.png', NULL, NULL, 'system_import');

-- ── id 121: Mashudu Thalitha Managa (PDF: 202603-6, Known As: Thalitha) ──
UPDATE caregivers SET
    student_id = '202603-6', known_as = 'Thalitha', title = 'Miss', initials = 'MT',
    id_passport = '7603180704086', dob = '1976-03-18', gender = 'Female',
    nationality = 'South African', home_language = 'Venda', other_language = 'English, Afrikaans, Sotho, Zulu',
    mobile = '0766590154', email = 'Surprisemanaga77@gmail.com',
    street_address = '33743 Motloma St', suburb = 'Mamalodi Ext 6', city = 'Pretoria', province = 'Gauteng',
    nok_name = 'Managa Suprise', nok_relationship = 'Daughter', nok_contact = '0797733435',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Mashudu'', PDF: ''Thalitha''.',
        'PDF data flags: ''Mamalodi'' typo for ''Mamelodi''. ''Suprise'' typo for ''Surprise''.')
WHERE id = 121;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (121, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 11, 'system_import'),
    (121, @att_type_photo, 'people/TCH-000121/photo.png', 'Intake_9_p11.png', NULL, NULL, 'system_import');

-- ── id 122: Philadelpia Tsakani Mashaba (PDF: 202603-3, Known As: Philadelphia) ──
UPDATE caregivers SET
    student_id = '202603-3', known_as = 'Philadelphia', title = 'Miss', initials = 'TP',
    id_passport = '9012150367080', dob = '1990-12-15', gender = 'Female',
    nationality = 'South African', home_language = 'Xitsomga', other_language = 'English, Little Afrikaans, Sotho and Zulu',
    mobile = '0817380793', email = 'philamashmash@gmail.com',
    street_address = '278 Block 95', suburb = 'Riverside View, Fourways', city = 'JHB', province = 'Gauteng',
    nok_name = 'Norah Mashaba', nok_relationship = 'Sister', nok_contact = '0606657627',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'known_as changed: prior DB value was ''Tsakani'', PDF: ''Philadelphia''.',
        'PDF data flags: Title shows ''Philadelpia'' (missing h) but Known As shows ''Philadelphia''. ''Xitsomga'' typo for ''Xitsonga''.')
WHERE id = 122;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (122, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 12, 'system_import'),
    (122, @att_type_photo, 'people/TCH-000122/photo.png', 'Intake_9_p12.png', NULL, NULL, 'system_import');

-- ── id 123: Victoria Mandlovu Mkhonza (PDF: 202603-7) ──
UPDATE caregivers SET
    student_id = '202603-7', known_as = 'Victoria', title = 'Miss', initials = 'VM',
    id_passport = '8001140526088', dob = '1980-01-14', gender = 'Female',
    nationality = 'South African', home_language = 'Zulu', other_language = 'English, Zulu, Xhosa, Little Afrikaans',
    mobile = '0785855915', email = 'nomthimkhonza5@gmail.com',
    street_address = '1626 Nyushman St', suburb = 'Soweto', province = 'Gauteng',
    nok_name = 'Thembile Manengela', nok_relationship = 'Brother', nok_contact = '0826300369',
    lead_source_id = @ls_website,
    import_review_state = 'pending',
    import_notes = CONCAT_WS('\n\n', NULLIF(import_notes, ''),
        'Enriched from Tuniti Tranche 9 PDF on 2026-04-10. PDF adopted as canonical (Ross decision).',
        'PDF data flags: City field is blank.')
WHERE id = 123;
INSERT INTO attachments (person_id, attachment_type_id, file_path, original_filename, source_pdf, source_page, uploaded_by) VALUES
    (123, @att_type_sheet, @pdf_relative_9, @pdf_filename_9, @pdf_filename_9, 13, 'system_import'),
    (123, @att_type_photo, 'people/TCH-000123/photo.png', 'Intake_9_p13.png', NULL, NULL, 'system_import');

COMMIT;

-- ============================================================
-- Post-load verification queries (run separately after applying)
-- ============================================================
-- SELECT COUNT(*) AS pending_review FROM caregivers WHERE import_review_state = 'pending';
-- Expected: 123 (14 from Tranche 1 already done + 109 added now)
--
-- SELECT COUNT(*) AS attachments FROM attachments;
-- Expected: 246 (28 from Tranche 1 + 218 from tranches 2-9)
--
-- SELECT tranche, COUNT(*) FROM caregivers GROUP BY tranche ORDER BY tranche;
-- Expected: Tranche 1..9 + N/K, NO 'Nth Intake' values remain
