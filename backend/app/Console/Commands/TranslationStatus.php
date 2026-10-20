<?php

namespace App\Console\Commands;

use App\Models\Translation;
use Illuminate\Console\Command;

class TranslationStatus extends Command
{
    protected $signature = 'translations:status {--tenant= : Limit the report to a tenant ID}';

    protected $description = 'Report translation counts by tenant and state';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        if ($tenantId !== null && ! ctype_digit((string) $tenantId)) {
            $this->error('--tenant must be a numeric tenant ID.');

            return self::INVALID;
        }

        $rows = Translation::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->selectRaw('tenant_id, state, COUNT(*) AS total')
            ->groupBy('tenant_id', 'state')
            ->orderBy('tenant_id')
            ->orderBy('state')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No translation records exist yet.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', 'State', 'Records'], $rows->map(fn ($row): array => [$row->tenant_id, $row->state, $row->total])->all());

        return self::SUCCESS;
    }
}
