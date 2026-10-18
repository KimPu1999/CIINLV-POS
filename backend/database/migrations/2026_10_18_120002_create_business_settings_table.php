<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency_code', 3)->default('MMK');
            $table->string('timezone', 100)->default('Asia/Yangon');
            $table->string('locale', 20)->default('en');
            $table->jsonb('tax_configuration')->nullable();
            $table->jsonb('receipt_configuration')->nullable();
            $table->timestampsTz();
        });

        DB::table('tenants')->orderBy('id')->each(function (object $tenant): void {
            DB::table('business_settings')->insert([
                'tenant_id' => $tenant->id,
                'currency_code' => 'MMK',
                'timezone' => 'Asia/Yangon',
                'locale' => 'en',
                'tax_configuration' => json_encode([]),
                'receipt_configuration' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
