<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('supplier_type')->nullable()->after('name');
            $table->string('alternate_phone', 50)->nullable()->after('phone');
            $table->string('website')->nullable()->after('email');
            $table->string('city')->nullable()->after('address');
            $table->string('region')->nullable()->after('city');
            $table->string('postal_code')->nullable()->after('region');
            $table->string('country')->nullable()->after('postal_code');
            $table->string('default_currency', 3)->nullable()->after('country');
            $table->string('payment_terms')->nullable()->after('default_currency');
            $table->unsignedInteger('default_lead_time_days')->nullable()->after('payment_terms');
            $table->decimal('minimum_order_amount', 18, 4)->nullable()->after('default_lead_time_days');
            $table->string('tax_registration_number')->nullable()->after('minimum_order_amount');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropColumn(['supplier_type', 'alternate_phone', 'website', 'city', 'region', 'postal_code', 'country', 'default_currency', 'payment_terms', 'default_lead_time_days', 'minimum_order_amount', 'tax_registration_number']);
        });
    }
};
