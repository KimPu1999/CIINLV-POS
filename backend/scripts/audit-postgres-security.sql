-- Read-only audit. This script never enables RLS or changes roles/policies.
SELECT current_database(), current_user;
SELECT rolname, rolsuper, rolbypassrls, rolcreaterole
FROM pg_roles
WHERE rolname = current_user;

SELECT c.relname AS table_name,
       c.relrowsecurity AS rls_enabled,
       c.relforcerowsecurity AS force_rls
FROM pg_class c
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relkind = 'r'
  AND c.relname IN (
    'tenants', 'tenant_memberships', 'stores', 'store_memberships',
    'products', 'product_variants', 'inventory_locations',
    'inventory_balances', 'stock_movements', 'sales', 'payments',
    'report_exports'
  )
ORDER BY c.relname;

SELECT schemaname, tablename, policyname, permissive, roles, cmd
FROM pg_policies
WHERE schemaname = 'public'
ORDER BY tablename, policyname;
