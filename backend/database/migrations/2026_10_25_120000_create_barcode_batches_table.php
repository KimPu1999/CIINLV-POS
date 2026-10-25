<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('barcode_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('generation_mode', 30);
            $table->string('barcode_type', 30)->default('internal');
            $table->string('label_template', 40)->default('50x30');
            $table->unsignedBigInteger('requested_label_count')->default(0);
            $table->unsignedBigInteger('completed_label_count')->default(0);
            $table->unsignedBigInteger('failed_label_count')->default(0);
            $table->string('status', 24)->default('pending');
            $table->jsonb('options')->nullable();
            $table->string('source_path')->nullable();
            $table->string('manifest_path')->nullable();
            $table->jsonb('error_summary')->nullable();
            $table->string('idempotency_key', 150)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'created_by', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcode_batches');
    }
};
