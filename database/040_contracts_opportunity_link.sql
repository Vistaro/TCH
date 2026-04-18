-- ─────────────────────────────────────────────────────────────
--  040_contracts_opportunity_link.sql
--  Link contracts back to their originating opportunity.
--
--  Why: FR-C (quote builder) creates a draft-status contract from
--  a Quoted-stage opportunity. contracts.opportunity_id is the
--  back-pointer so pipeline reporting (FR-M Acquire) can trace a
--  won deal to the opp it came from, and the opportunity detail
--  page can render a "Current quote →" link to the contract.
--
--  opportunities.contract_id (added in 039) + contracts.opportunity_id
--  are a bi-directional link. v1: one opportunity → one contract.
--  If we ever need multiple quote iterations per opp, that's a
--  later migration that relaxes the uniqueness (currently no unique
--  constraint — just nullable FK both ways).
--
--  Existing contracts: opportunity_id stays NULL. No backfill.
--
--  Rollback:
--    ALTER TABLE contracts DROP FOREIGN KEY fk_contract_opportunity;
--    ALTER TABLE contracts DROP COLUMN opportunity_id;
-- ─────────────────────────────────────────────────────────────

SET NAMES utf8mb4;

ALTER TABLE contracts
    ADD COLUMN opportunity_id INT UNSIGNED DEFAULT NULL
        COMMENT 'FK to opportunities — the pipeline record this contract (as quote or active) was built from. NULL for contracts pre-dating the pipeline or entered direct.'
        AFTER patient_person_id,
    ADD INDEX idx_contract_opportunity (opportunity_id),
    ADD CONSTRAINT fk_contract_opportunity
        FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE SET NULL;
