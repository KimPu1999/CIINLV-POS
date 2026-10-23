<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_scheduler_telemetry', function (Blueprint $table): void {
            $table->id();
            $table->string('execution_reference', 64)->unique();
            $table->string('task_key', 64)->index();
            $table->string('task_name', 160);
            $table->string('command_identifier', 160);
            $table->string('schedule_frequency', 120);
            $table->string('schedule_expression', 100);
            $table->string('timezone', 80)->nullable();
            $table->string('status', 20)->index();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestampsTz();
            $table->index(['task_key', 'status', 'finished_at']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_scheduler_telemetry');
    }
};
