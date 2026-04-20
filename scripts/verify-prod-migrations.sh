#!/usr/bin/env bash
# Read-only PROD verification: which migrations are actually applied.
# Runs server-side so DB_PASS is never in the process list or transcript.
set -euo pipefail

cd "$(dirname "$0")/.."

# Source DB_* only; never print the .env
set -a
. <(grep -E '^DB_' .env | sed 's/^/export /')
set +a

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null <<'SQL'
SELECT 'mig034_products_freq' AS check_name,
       COLUMN_TYPE AS value_on_prod
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='default_billing_freq'
UNION ALL
SELECT 'mig035_cg_pattern_width',
       CAST(CHARACTER_MAXIMUM_LENGTH AS CHAR)
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='caregivers' AND COLUMN_NAME='working_pattern'
UNION ALL
SELECT 'mig036_product_billing_rates_table',
       CAST(COUNT(*) AS CHAR)
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='product_billing_rates'
UNION ALL
SELECT 'mig037_contract_lines_start_date',
       CAST(COUNT(*) AS CHAR)
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contract_lines' AND COLUMN_NAME='start_date'
UNION ALL
SELECT 'mig038_contracts_quote_reference',
       CAST(COUNT(*) AS CHAR)
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='quote_reference'
UNION ALL
SELECT 'mig039_opportunities_table',
       CAST(COUNT(*) AS CHAR)
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='opportunities'
UNION ALL
SELECT 'mig040_contracts_opp_id',
       CAST(COUNT(*) AS CHAR)
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contracts' AND COLUMN_NAME='opportunity_id'
UNION ALL
SELECT 'mig041_rate_override_reason',
       CAST(COUNT(*) AS CHAR)
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contract_lines' AND COLUMN_NAME='rate_override_reason'
UNION ALL
SELECT 'mig044_enquiries_is_test_data',
       CAST(COUNT(*) AS CHAR)
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enquiries' AND COLUMN_NAME='is_test_data'
UNION ALL
SELECT 'mig046_persons_latitude',
       CAST(COUNT(*) AS CHAR)
  FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='persons' AND COLUMN_NAME='latitude';
SQL
