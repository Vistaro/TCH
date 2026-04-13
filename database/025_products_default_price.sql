-- ================================================================
--  025_products_default_price.sql
--
--  Add a suggested-price column to the products catalogue. This is a
--  default that pre-fills new roster/billing rows; users can still
--  override per customer profile or per shift.
--
--  Currency is implicitly ZAR (TCH's home currency). If multi-currency
--  product pricing is ever needed, add a currency_code column then.
-- ================================================================

START TRANSACTION;

SET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = 'products'
             AND COLUMN_NAME = 'default_price');
SET @sql := IF(@c = 0,
    'ALTER TABLE products ADD COLUMN default_price DECIMAL(10,2) NULL COMMENT ''Suggested price in ZAR; can be overridden per customer/shift'' AFTER description',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

COMMIT;
