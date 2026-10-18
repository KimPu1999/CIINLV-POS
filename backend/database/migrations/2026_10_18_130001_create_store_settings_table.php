<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('receipt_prefix', 20)->nullable();
            $table->boolean('allow_negative_stock')->default(false);
            $table->jsonb('tax_configuration')->nullable();
            $table->jsonb('receipt_configuration')->nullable();
            $table->jsonb('dashboard_preferences')->nullable();
            $table->timestampsTz();
            $table->unique('store_id');
            $table->index(['tenant_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
