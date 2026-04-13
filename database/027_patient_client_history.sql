-- ================================================================
--  027_patient_client_history.sql
--
--  Time-series table recording who paid for each patient and when.
--  Built now (in Phase-1 "data cleanup" mode) so the structure is
--  ready; behaviour switches to time-stamped on every re-assign in
--  Phase 2 once historic data is locked down. See TODO #15.
--
--  Phase-1 semantics:
--    - One open row per patient (valid_to IS NULL = currently active)
--    - Re-assign = UPDATE that open row's client_id
--      (treats the change as a data-error correction, retroactive)
--
--  Phase-2 semantics (to enable later):
--    - Re-assign = SET valid_to = NOW() on the open row,
--      INSERT a new open row with valid_from = NOW() + new client_id
--    - Historic shifts continue to bill the previous client
--
--  patients.client_id stays as the denormalised "current" pointer for
--  fast lookups — both phases keep it in sync.
-- ================================================================

SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

CREATE TABLE IF NOT EXISTS patient_client_history (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    patient_person_id   INT UNSIGNED NOT NULL,
    client_id           INT UNSIGNED NOT NULL,
    valid_from          TIMESTAMP NULL DEFAULT NULL COMMENT 'NULL = "since this record began" (lifetime open)',
    valid_to            TIMESTAMP NULL DEFAULT NULL COMMENT 'NULL = currently active',
    changed_by_user_id  INT UNSIGNED NULL,
    reason              VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pch_patient (patient_person_id),
    KEY idx_pch_open    (patient_person_id, valid_to),
    CONSTRAINT fk_pch_patient FOREIGN KEY (patient_person_id) REFERENCES persons(id) ON DELETE CASCADE,
    CONSTRAINT fk_pch_client  FOREIGN KEY (client_id)         REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: one open row per existing patient — assumption "today's
-- bill-payer = lifetime bill-payer" (Ross's call, valid for now).
-- Idempotent: skip if a row already exists for this patient.
INSERT INTO patient_client_history (patient_person_id, client_id, valid_from, valid_to, reason)
SELECT pt.person_id, pt.client_id, NULL, NULL,
       'Seeded by migration 027 — assumes current pairing has been in place since record began'
FROM patients pt
WHERE NOT EXISTS (
    SELECT 1 FROM patient_client_history pch WHERE pch.patient_person_id = pt.person_id
);

COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

-- ROLLBACK:
--   DROP TABLE IF EXISTS patient_client_history;
