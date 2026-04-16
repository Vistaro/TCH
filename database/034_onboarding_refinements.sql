-- Migration 034 — onboarding refinements.
-- Widens products.default_billing_freq ENUM to add 'hourly'.
-- Column was already ENUM NOT NULL DEFAULT 'monthly' from migration 031;
-- this is a pure ENUM-widening (metadata-only on InnoDB, no row data
-- coerced).
--
-- Destructive if rolled back: once a row is saved as 'hourly', reverting
-- to migration 031's ENUM (without 'hourly') will fail. Verify no PROD
-- rows use 'hourly' before attempting any rollback.

START TRANSACTION;

ALTER TABLE products
    MODIFY COLUMN default_billing_freq
    ENUM('hourly','daily','weekly','monthly','per_visit','upfront_only')
    NOT NULL DEFAULT 'monthly';

COMMIT;
