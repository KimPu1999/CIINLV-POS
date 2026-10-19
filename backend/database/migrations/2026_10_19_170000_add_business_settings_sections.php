<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->jsonb('pos_configuration')->nullable();
            $table->jsonb('inventory_configuration')->nullable();
            $table->jsonb('payment_configuration')->nullable();
            $table->jsonb('notification_configuration')->nullable();
            $table->jsonb('appearance_configuration')->nullable();
            $table->jsonb('data_configuration')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->dropColumn(['pos_configuration', 'inventory_configuration', 'payment_configuration', 'notification_configuration', 'appearance_configuration', 'data_configuration', 'revision']);
        });
    }
};
