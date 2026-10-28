<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE employee_profiles ALTER COLUMN user_id DROP NOT NULL');
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->string('name', 160)->nullable()->after('user_id');
            $table->index(['tenant_id', 'name']);
        });

        Schema::create('employee_branch_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employee_profiles')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50)->default('sales_staff');
            $table->string('status', 20)->default('active');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['employee_profile_id', 'store_id']);
            $table->index(['tenant_id', 'store_id', 'status']);
        });

        DB::statement("UPDATE employee_profiles SET name = users.name FROM users WHERE employee_profiles.user_id = users.id AND employee_profiles.name IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_branch_assignments');
        Schema::table('employee_profiles', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'name']);
            $table->dropColumn('name');
        });
        DB::statement('ALTER TABLE employee_profiles ALTER COLUMN user_id SET NOT NULL');
    }
};
