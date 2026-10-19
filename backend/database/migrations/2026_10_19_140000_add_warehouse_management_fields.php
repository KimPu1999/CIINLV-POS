<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table): void {
            $table->text('description')->nullable()->after('name');
            $table->string('warehouse_type', 50)->nullable()->after('location_type');
            $table->string('contact_phone', 50)->nullable()->after('address');
            $table->string('contact_email')->nullable()->after('contact_phone');
            $table->string('city', 100)->nullable()->after('contact_email');
            $table->string('region', 100)->nullable()->after('city');
            $table->string('country', 100)->nullable()->after('region');
            $table->foreignId('manager_user_id')->nullable()->after('country')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table): void {
            $table->dropForeign(['manager_user_id']);
            $table->dropColumn(['description', 'warehouse_type', 'contact_phone', 'contact_email', 'city', 'region', 'country', 'manager_user_id']);
        });
    }
};
