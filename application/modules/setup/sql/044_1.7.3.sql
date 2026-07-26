ALTER TABLE `ip_products`
    MODIFY COLUMN `product_price` DECIMAL(20, 8) NULL DEFAULT NULL;

ALTER TABLE `ip_products`
    ADD COLUMN `product_price_gross` DECIMAL(20, 8) NULL DEFAULT NULL AFTER `product_price`;

UPDATE `ip_products` p
LEFT JOIN `ip_tax_rates` t ON t.`tax_rate_id` = p.`tax_rate_id`
SET p.`product_price_gross` = ROUND(
    p.`product_price` * (1 + (COALESCE(t.`tax_rate_percent`, 0) / 100)),
    8
)
WHERE p.`product_price` IS NOT NULL
  AND p.`product_price_gross` IS NULL;

ALTER TABLE `ip_invoice_items`
    MODIFY COLUMN `item_price` DECIMAL(20, 8) DEFAULT NULL,
    ADD COLUMN `item_price_gross` DECIMAL(20, 8) NULL DEFAULT NULL AFTER `item_price`;

ALTER TABLE `ip_quote_items`
    MODIFY COLUMN `item_price` DECIMAL(20, 8) DEFAULT NULL,
    ADD COLUMN `item_price_gross` DECIMAL(20, 8) NULL DEFAULT NULL AFTER `item_price`;

UPDATE `ip_invoice_items` ii
LEFT JOIN `ip_tax_rates` t ON t.`tax_rate_id` = ii.`item_tax_rate_id`
SET ii.`item_price_gross` = ROUND(
    ii.`item_price` * (1 + (COALESCE(t.`tax_rate_percent`, 0) / 100)),
    8
)
WHERE ii.`item_price` IS NOT NULL
  AND ii.`item_price_gross` IS NULL;

UPDATE `ip_quote_items` qi
LEFT JOIN `ip_tax_rates` t ON t.`tax_rate_id` = qi.`item_tax_rate_id`
SET qi.`item_price_gross` = ROUND(
    qi.`item_price` * (1 + (COALESCE(t.`tax_rate_percent`, 0) / 100)),
    8
)
WHERE qi.`item_price` IS NOT NULL
  AND qi.`item_price_gross` IS NULL;