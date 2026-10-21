<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_health_heartbeats', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('status', 20)->default('unknown')->index();
            $table->timestampTz('last_success_at')->nullable();
            $table->timestampTz('last_failure_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'last_success_at']);
        });

        DB::statement("ALTER TABLE platform_health_heartbeats ADD CONSTRAINT platform_health_heartbeats_status_check CHECK (status IN ('unknown', 'healthy', 'unhealthy'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE platform_health_heartbeats DROP CONSTRAINT IF EXISTS platform_health_heartbeats_status_check');
        Schema::dropIfExists('platform_health_heartbeats');
    }
};
