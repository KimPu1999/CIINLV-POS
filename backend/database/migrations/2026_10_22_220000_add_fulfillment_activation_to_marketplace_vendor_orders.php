<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_vendor_orders', function (Blueprint $table): void {
            $table->string('fulfillment_status', 30)->default('pending')->index();
            $table->timestampTz('fulfillment_activated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_vendor_orders', function (Blueprint $table): void {
            $table->dropColumn(['fulfillment_status', 'fulfillment_activated_at']);
        });
    }
};
