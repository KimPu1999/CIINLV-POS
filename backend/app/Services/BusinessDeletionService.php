<?php

namespace App\Services;

use App\Jobs\CleanupDeletedBusinessFiles;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;

class BusinessDeletionService
{
    /** Tables that represent financial, tax, or legally retained operational history. */
    private const BLOCKING_TABLES = [
        'sales', 'sale_items', 'sale_adjustments', 'payments', 'sale_returns',
        'sale_return_items', 'refunds', 'receipts', 'purchase_orders',
        'purchase_order_items', 'goods_receipts', 'goods_receipt_items',
        'stock_movements', 'stock_transfers', 'stock_transfer_items',
        'stock_transfer_receipts', 'stock_adjustments', 'cashier_shifts',
        'cash_movements', 'invoices', 'tax_records', 'tax_transactions',
    ];

    private const COUNT_TABLES = [
        'tenants', 'business_settings', 'stores', 'store_settings', 'tenant_memberships',
        'store_memberships', 'pos_registers', 'pos_register_memberships', 'cashier_shifts',
        'cash_movements', 'roles', 'business_invitations', 'business_idempotency_keys',
        'categories', 'products', 'product_variants', 'product_images', 'suppliers',
        'inventory_locations', 'inventory_balances', 'stock_alerts', 'alert_preferences',
        'dashboard_widgets', 'dashboard_layouts', 'report_exports', 'sales', 'payments',
        'invoices', 'tax_records',
    ];

    public function preview(Tenant $tenant): array
    {
        $counts = [];
        foreach (self::COUNT_TABLES as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table)) { $counts[$table] = 0; continue; }
            $query = DB::table($table);
            if ($table === 'tenants') $query->where('id', $tenant->getKey());
            elseif (in_array($table, ['roles'], true)) $query->where('tenant_id', $tenant->getKey());
            elseif (in_array($table, ['store_memberships', 'pos_register_memberships'], true)) $query->where('tenant_id', $tenant->getKey());
            elseif (SchemaTenantTables::hasTenantColumn($table)) $query->where('tenant_id', $tenant->getKey());
            elseif ($table === 'store_settings') $query->where('tenant_id', $tenant->getKey());
            else { $counts[$table] = 0; continue; }
            $counts[$table] = $query->count();
        }
        $blockers = [];
        foreach (self::BLOCKING_TABLES as $table) {
            if (! DB::getSchemaBuilder()->hasTable($table) || ! SchemaTenantTables::hasTenantColumn($table)) continue;
            $count = DB::table($table)->where('tenant_id', $tenant->getKey())->count();
            if ($count > 0) $blockers[$table] = $count;
        }
        $userIds = DB::table('tenant_memberships')->where('tenant_id', $tenant->getKey())->pluck('user_id');
        $deletableUsers = User::query()->whereIn('id', $userIds)->whereNull('platform_role')
            ->whereDoesntHave('memberships', fn ($q) => $q->where('tenant_id', '!=', $tenant->getKey()))->count();
        $owner = $tenant->creator;

        return [
            'business' => ['id' => $tenant->uuid, 'name' => $tenant->name, 'business_code' => $tenant->business_code, 'owner' => $owner ? ['name' => $owner->name, 'email' => $owner->email] : null],
            'counts' => array_merge(['users' => $userIds->count()], $counts),
            'blocking_records' => $blockers,
            'deletable_user_count' => $deletableUsers,
            'can_delete' => $blockers === [],
            'preview_token' => $this->issuePreviewToken($tenant),
            'preview_expires_at' => now()->addMinutes(10)->toIso8601String(),
        ];
    }

    public function reauthenticate(User $actor, string $password): void
    {
        abort_unless(Hash::check($password, $actor->password), 422, 'Reauthentication failed.');
        request()->session()->put('platform_deletion_reauthenticated_at', now()->timestamp);
    }

    public function delete(Tenant $tenant, User $actor, string $businessCode, string $previewToken): array
    {
        abort_unless(hash_equals($tenant->business_code, strtoupper(trim($businessCode))), 422, 'Business code does not match.');
        abort_unless(($at = (int) request()->session()->get('platform_deletion_reauthenticated_at')) > now()->subMinutes(10)->timestamp, 422, 'Recent reauthentication is required.');
        $preview = $this->preview($tenant);
        abort_if($preview['blocking_records'] !== [], 409, 'Deletion is blocked because legally retained records exist.');
        abort_unless(Cache::get('business-deletion-preview:'.$previewToken) === $tenant->getKey(), 422, 'Deletion preview expired. Reload the preview.');
        $backup = $this->verifiedBackup();

        $files = [];
        $deleted = DB::transaction(function () use ($tenant, $actor, $preview, &$files): array {
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();
            // The row lock makes a second delete wait; after the first commit it cannot
            // resolve the tenant and therefore cannot delete anything a second time.
            $lockedPreview = $this->preview($locked);
            abort_if($lockedPreview['blocking_records'] !== [], 409, 'Deletion is blocked because legally retained records exist.');
            $tenantId = $locked->getKey();
            $files = array_merge(
                DB::table('product_images')->where('tenant_id', $tenantId)->pluck('storage_path')->all(),
                DB::table('categories')->where('tenant_id', $tenantId)->pluck('image_path')->filter()->all(),
                DB::table('report_exports')->where('tenant_id', $tenantId)->pluck('file_path')->filter()->all(),
            );
            $userIds = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->pluck('user_id');
            foreach (['product_images','inventory_balances','stock_alerts','alert_preferences','dashboard_widgets','dashboard_layouts','report_exports','pos_register_memberships','cash_movements','cashier_shifts','pos_registers','store_memberships','store_settings','inventory_locations','product_variants','products','categories','suppliers','business_settings','business_invitations','business_idempotency_keys','roles','tenant_memberships','stores'] as $table) {
                if (DB::getSchemaBuilder()->hasTable($table) && SchemaTenantTables::hasTenantColumn($table)) DB::table($table)->where('tenant_id', $tenantId)->delete();
            }
            DB::table('audit_logs')->where('tenant_id', $tenantId)->delete();
            foreach ($userIds as $userId) {
                $user = User::query()->find($userId);
                if ($user && $user->platform_role === null && ! $user->memberships()->exists()) $user->delete();
            }
            DB::table('audit_logs')->insert(['actor_user_id' => $actor->getKey(), 'tenant_id' => null, 'action' => 'platform.business_permanently_deleted', 'subject_type' => Tenant::class, 'subject_id' => (string) $tenantId, 'metadata' => json_encode(['record_counts' => $preview['counts'], 'deletable_user_count' => $preview['deletable_user_count']]), 'created_at' => now()]);
            $locked->delete();
            return ['deleted_counts' => $preview['counts'], 'deleted_user_count' => $preview['deletable_user_count']];
        });
        CleanupDeletedBusinessFiles::dispatch($files);
        request()->session()->forget('platform_deletion_reauthenticated_at');
        Cache::forget('business-deletion-preview:'.$previewToken);
        return $deleted + ['backup' => $backup];
    }

    private function issuePreviewToken(Tenant $tenant): string
    {
        $token = bin2hex(random_bytes(32));
        Cache::put('business-deletion-preview:'.$token, $tenant->getKey(), now()->addMinutes(10));
        return $token;
    }

    private function verifiedBackup(): string
    {
        $script = base_path('scripts/backup-postgres.sh');
        abort_unless(is_file($script), 503, 'Verified database backup is unavailable.');
        $process = new Process(['bash', $script], base_path(), null, null, 180);
        $process->run();
        abort_unless($process->isSuccessful(), 503, 'Database backup failed; deletion was not started.');
        preg_match('/written to (.+)$/m', $process->getOutput(), $match);
        $path = trim($match[1] ?? '');
        abort_unless($path !== '' && is_file($path), 503, 'Database backup could not be verified.');
        $verify = new Process(['bash', base_path('scripts/verify-postgres-backup.sh'), $path], base_path(), null, null, 180);
        $verify->run();
        abort_unless($verify->isSuccessful(), 503, 'Database backup verification failed; deletion was not started.');
        return $path;
    }
}

final class SchemaTenantTables
{
    public static function hasTenantColumn(string $table): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, 'tenant_id');
    }
}
