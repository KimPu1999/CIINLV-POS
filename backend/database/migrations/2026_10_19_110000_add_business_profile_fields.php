<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('display_name')->nullable()->after('name');
            $table->text('description')->nullable()->after('display_name');
            $table->string('business_type', 100)->nullable()->after('description');
            $table->string('website', 255)->nullable()->after('email');
            $table->string('city', 100)->nullable()->after('address');
            $table->string('state', 100)->nullable()->after('city');
            $table->string('postal_code', 30)->nullable()->after('state');
            $table->string('logo_path')->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['display_name', 'description', 'business_type', 'website', 'city', 'state', 'postal_code', 'logo_path']);
        });
    }
};
