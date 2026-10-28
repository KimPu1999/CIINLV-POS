<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_staff_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('delivery_staff_profiles', 'max_active_assignments')) {
                $table->unsignedSmallInteger('max_active_assignments')->default(1)->after('notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_staff_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('delivery_staff_profiles', 'max_active_assignments')) {
                $table->dropColumn('max_active_assignments');
            }
        });
    }
};
