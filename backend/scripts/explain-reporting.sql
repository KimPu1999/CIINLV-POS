-- Run against an isolated staging database with representative data.
-- Never use EXPLAIN ANALYZE on a write statement in production without review.
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT tenant_id, store_id, COUNT(*) AS transaction_count, COALESCE(SUM(grand_total), 0) AS gross_sales
FROM sales
WHERE tenant_id = 1
  AND status = 'completed'
  AND completed_at >= (CURRENT_TIMESTAMP - INTERVAL '30 days')
GROUP BY tenant_id, store_id;

EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT tenant_id, inventory_location_id, product_variant_id,
       on_hand_quantity - reserved_quantity AS available_quantity
FROM inventory_balances
WHERE tenant_id = 1
ORDER BY inventory_location_id, product_variant_id;
