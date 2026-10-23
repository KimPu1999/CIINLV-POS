<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_job_telemetry', function (Blueprint $table): void {
            $table->id();
            $table->string('job_reference', 64)->unique();
            $table->string('job_type', 160);
            $table->string('connection', 80);
            $table->string('queue', 120);
            $table->string('status', 20)->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();
            $table->index(['queue', 'status', 'finished_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_job_telemetry');
    }
};
