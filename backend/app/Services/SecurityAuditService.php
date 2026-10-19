<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class SecurityAuditService
{
    /**
     * Read-only application and PostgreSQL posture audit. It intentionally never
     * enables RLS or changes roles; those changes require staged verification.
     */
    public function run(): array
    {
        $result = [
            'application' => [
                'debug' => (bool) config('app.debug'),
                'force_https' => (bool) config('app.force_https'),
                'session_secure_cookie' => (bool) config('session.secure'),
                'cors_wildcard_methods' => config('cors.allowed_methods') === ['*'],
                'cors_wildcard_headers' => config('cors.allowed_headers') === ['*'],
                'sanctum_token_expiration_minutes' => config('sanctum.expiration'),
            ],
            'database' => [],
        ];

        try {
            $role = DB::selectOne('SELECT current_database() AS database_name, current_user AS role_name, rolsuper, rolbypassrls, rolcreaterole FROM pg_roles WHERE rolname = current_user');
            $tables = DB::select("SELECT c.relname AS table_name, c.relrowsecurity AS rls_enabled, c.relforcerowsecurity AS force_rls FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND c.relname IN ('tenants','tenant_memberships','stores','store_memberships','products','product_variants','inventory_locations','inventory_balances','stock_movements','sales','payments','report_exports') ORDER BY c.relname");
            $policies = (int) DB::scalar("SELECT count(*) FROM pg_policies WHERE schemaname = 'public'");

            $result['database'] = [
                'database_name' => $role?->database_name,
                'role_name' => $role?->role_name,
                'is_superuser' => (bool) ($role?->rolsuper ?? false),
                'bypass_rls' => (bool) ($role?->rolbypassrls ?? false),
                'can_create_roles' => (bool) ($role?->rolcreaterole ?? false),
                'policy_count' => $policies,
                'tables' => array_map(static fn (object $table): array => [
                    'table' => $table->table_name,
                    'rls_enabled' => (bool) $table->rls_enabled,
                    'force_rls' => (bool) $table->force_rls,
                ], $tables),
            ];
        } catch (Throwable $exception) {
            report($exception);
            $result['database'] = ['status' => 'unavailable'];
        }

        return $result;
    }
}
