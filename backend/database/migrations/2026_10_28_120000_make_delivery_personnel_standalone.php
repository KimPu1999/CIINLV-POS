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
            $table->string('full_name', 160)->nullable()->after('staff_code');
            $table->string('phone', 50)->nullable()->after('full_name');
            $table->string('email')->nullable()->after('phone');
            $table->string('emergency_contact', 160)->nullable()->after('email');
            $table->string('profile_photo_path')->nullable()->after('emergency_contact');
            $table->string('employment_type', 30)->default('employee')->after('vehicle_plate');
            $table->text('notes')->nullable()->after('employment_type');
            $table->unsignedSmallInteger('max_active_assignments')->default(1)->after('notes');
            $table->index(['tenant_id', 'status', 'full_name']);
        });
        DB::statement('ALTER TABLE delivery_staff_profiles ALTER COLUMN employee_profile_id DROP NOT NULL');
        DB::statement("UPDATE delivery_staff_profiles d SET full_name = COALESCE(e.name, u.name, d.staff_code), phone = COALESCE(e.phone, d.phone), email = u.email FROM employee_profiles e LEFT JOIN users u ON u.id = e.user_id WHERE d.employee_profile_id = e.id AND d.full_name IS NULL");

        Schema::create('delivery_staff_branch_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('delivery_staff_profile_id')->constrained('delivery_staff_profiles')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['delivery_staff_profile_id', 'store_id']);
            $table->index(['tenant_id', 'store_id', 'status']);
        });

        DB::statement("INSERT INTO delivery_staff_branch_assignments (tenant_id, delivery_staff_profile_id, store_id, status, assigned_at, created_at, updated_at) SELECT tenant_id, id, store_id, 'active', created_at, now(), now() FROM delivery_staff_profiles WHERE store_id IS NOT NULL ON CONFLICT (delivery_staff_profile_id, store_id) DO NOTHING");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_staff_branch_assignments');
        DB::statement('ALTER TABLE delivery_staff_profiles ALTER COLUMN employee_profile_id SET NOT NULL');
        Schema::table('delivery_staff_profiles', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status', 'full_name']);
            $table->dropColumn(['full_name', 'phone', 'email', 'emergency_contact', 'profile_photo_path', 'employment_type', 'notes', 'max_active_assignments']);
        });
    }
};
