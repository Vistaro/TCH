-- ================================================================
--  023_seed_products.sql
--  Seed the public-facing product/service list taken from
--  tch.intelligentae.co.uk (2026-04-13). All existing roster rows
--  remain attached to Day Rate (product_id 1); Tuniti will remap
--  them to the correct product as part of UAT.
-- ================================================================

START TRANSACTION;

INSERT INTO products (code, name, description, is_active, sort_order) VALUES
    ('full_time_care',      'Full-Time Care',
     'Daily, ongoing support — permanent placement or temporary cover when the usual carer is unavailable.',
     1, 20),
    ('post_operative_care', 'Post-Operative Care',
     'Short-term caregiving support during the recovery window after surgery or hospitalisation.',
     1, 30),
    ('palliative_care',     'Palliative Care',
     'Gentle, dignified support for serious illness or end-of-life care.',
     1, 40),
    ('respite_care',        'Respite Care',
     'Short-duration cover — hours, a day, a week — so a family carer can rest.',
     1, 50),
    ('errand_care',         'Errand Care',
     'Shopping, pharmacy runs, appointments, light housekeeping — practical everyday support.',
     1, 60)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description),
    is_active = 1;

COMMIT;
