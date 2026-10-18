<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->timestampsTz();
            $table->unique(['actor_user_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_idempotency_keys');
    }
};
