<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfer_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 150);
            $table->jsonb('payload');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'stock_transfer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_receipts');
    }
};
