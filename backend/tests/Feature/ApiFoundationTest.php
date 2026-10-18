<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_health_endpoint_returns_successful_json(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'application' => 'CIINLV POS',
                'status' => 'online',
                'version' => '1.0.0',
            ]);
    }

    public function test_database_connection_works(): void
    {
        $this->assertSame(1, DB::selectOne('select 1 as result')->result);
    }

    public function test_me_requires_sanctum_authentication(): void
    {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertJsonStructure(['message']);
    }

    public function test_invalid_api_routes_return_json_not_found(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
