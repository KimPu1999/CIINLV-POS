<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_staff_profiles', function (Blueprint $table): void {
            $table->string('delivery_role', 50)->default('delivery_staff')->after('vehicle_plate');
            $table->boolean('assignment_eligibility')->default(true)->after('delivery_role');
            $table->string('approval_status', 20)->default('pending')->after('assignment_eligibility');
            $table->index(['tenant_id', 'approval_status', 'assignment_eligibility']);
        });
        // Existing delivery staff were already manager-created; preserve their operational state.
        DB::table('delivery_staff_profiles')->update(['approval_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('delivery_staff_profiles', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'approval_status', 'assignment_eligibility']);
            $table->dropColumn(['delivery_role', 'assignment_eligibility', 'approval_status']);
        });
    }
};
