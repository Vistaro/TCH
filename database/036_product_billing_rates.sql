-- Migration 036 — product_billing_rates table (FR-A: multi-unit product pricing).
--
-- Each product can be sold in multiple billing units (hourly, daily, weekly,
-- monthly, per-visit, upfront) with a separate standard rate per unit.
-- Exactly one row per product is marked is_default=1, driving the prefill
-- for new quote/contract lines. Quoting UI (FR-C) narrows the unit dropdown
-- to the rows in this table with is_active=1 for the chosen product.
--
-- Backfill: one row per active product mirroring its current
-- products.default_billing_freq + products.default_price, as is_default=1.
-- Products with a NULL default_price are backfilled at rate=0 so the admin
-- sees an obvious "0.00" prompting them to set a real rate before quoting.
--
-- currency_code CHAR(3) DEFAULT 'ZAR' — forward-looking hook for
-- multi-currency. No UI reads this column in v1; every insert is 'ZAR'.
--
-- products.default_billing_freq + products.default_price STAY in place
-- for now as backwards-compat; a follow-up migration retires them once
-- every read site has cut over to product_billing_rates.

START TRANSACTION;

CREATE TABLE IF NOT EXISTS product_billing_rates (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id     INT UNSIGNED NOT NULL,
    billing_freq   ENUM('hourly','daily','weekly','monthly','per_visit','upfront_only') NOT NULL,
    rate           DECIMAL(10,2) NOT NULL,
    currency_code  CHAR(3) NOT NULL DEFAULT 'ZAR'
                   COMMENT 'ISO-4217. v1 is ZAR-only; reserved for multi-currency.',
    is_default     TINYINT(1) NOT NULL DEFAULT 0
                   COMMENT 'Exactly one row per product should carry is_default=1.',
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_product_freq (product_id, billing_freq),
    INDEX idx_product        (product_id),
    INDEX idx_active_default (product_id, is_active, is_default),
    CONSTRAINT fk_pbr_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill: one default row per active product from the legacy columns.
INSERT IGNORE INTO product_billing_rates
       (product_id, billing_freq, rate, currency_code, is_default, is_active)
SELECT id,
       default_billing_freq,
       COALESCE(default_price, 0.00),
       'ZAR',
       1,
       1
  FROM products
 WHERE is_active = 1;

COMMIT;
