<?php

namespace Tests\Feature;

use App\Services\SecurityAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostgreSQLRlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_role_is_not_allowed_to_bypass_rls(): void
    {
        $database = app(SecurityAuditService::class)->run()['database'];

        $this->assertFalse($database['is_superuser']);
        $this->assertFalse($database['bypass_rls']);
        $this->assertFalse($database['can_create_roles']);
    }

    public function test_current_schema_reports_rls_gap_explicitly_instead_of_claiming_protection(): void
    {
        $database = app(SecurityAuditService::class)->run()['database'];

        $this->assertSame(0, $database['policy_count']);
        $this->assertNotEmpty($database['tables']);
        $this->assertTrue(collect($database['tables'])->every(fn (array $table): bool => $table['rls_enabled'] === false));
    }
}
