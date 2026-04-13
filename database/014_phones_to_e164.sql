-- ================================================================
--  014_phones_to_e164.sql
--  Normalise SA local-format phone numbers to E.164 (+27...).
--  Only rows that match the SA local pattern (^0[0-9]{9}$) are
--  rewritten — anything already in international format or in a
--  non-matching shape is left alone.
--
--  Affected columns on persons: mobile, secondary_number,
--  nok_contact, nok_2_contact.
-- ================================================================

START TRANSACTION;

UPDATE persons
SET mobile = CONCAT('+27', SUBSTRING(mobile, 2))
WHERE mobile REGEXP '^0[0-9]{9}$';

UPDATE persons
SET secondary_number = CONCAT('+27', SUBSTRING(secondary_number, 2))
WHERE secondary_number REGEXP '^0[0-9]{9}$';

UPDATE persons
SET nok_contact = CONCAT('+27', SUBSTRING(nok_contact, 2))
WHERE nok_contact REGEXP '^0[0-9]{9}$';

UPDATE persons
SET nok_2_contact = CONCAT('+27', SUBSTRING(nok_2_contact, 2))
WHERE nok_2_contact REGEXP '^0[0-9]{9}$';

COMMIT;
