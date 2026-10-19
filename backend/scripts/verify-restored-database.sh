#!/usr/bin/env bash
set -Eeuo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${RESTORE_TARGET_DATABASE:?RESTORE_TARGET_DATABASE is required}"
case "$RESTORE_TARGET_DATABASE" in
  *_restore|*_staging|*_test) ;;
  *) echo 'Refusing verification: target database must be isolated.' >&2; exit 2 ;;
esac

export PGPASSWORD="$DB_PASSWORD"
psql --host="$DB_HOST" --port="$DB_PORT" --username="$DB_USERNAME" --dbname="$RESTORE_TARGET_DATABASE" <<'SQL'
\set ON_ERROR_STOP on
SELECT current_database(), current_user;
SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name IN ('tenants', 'stores', 'inventory_balances', 'stock_movements', 'sales', 'payments') ORDER BY table_name;
SELECT 'inventory_ledger_reconciliation' AS check_name,
       count(*) FILTER (WHERE COALESCE(b.on_hand_quantity, 0) <> COALESCE(m.ledger_quantity, 0)) AS mismatches
FROM inventory_balances b
LEFT JOIN (
  SELECT tenant_id, inventory_location_id, product_variant_id, SUM(quantity_delta) AS ledger_quantity
  FROM stock_movements
  GROUP BY tenant_id, inventory_location_id, product_variant_id
) m ON m.tenant_id = b.tenant_id AND m.inventory_location_id = b.inventory_location_id AND m.product_variant_id = b.product_variant_id;
SQL
