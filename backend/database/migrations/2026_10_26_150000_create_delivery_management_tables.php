<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_staff_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employee_profiles')->restrictOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('staff_code', 50);
            $table->string('vehicle_type', 50)->nullable();
            $table->string('vehicle_plate', 50)->nullable();
            $table->string('availability_status', 20)->default('available');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'employee_profile_id']);
            $table->unique(['tenant_id', 'staff_code']);
            $table->index(['tenant_id', 'status', 'availability_status']);
        });

        Schema::create('delivery_courier_partners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('marketplace_shipments', function (Blueprint $table): void {
            $table->foreignId('delivery_staff_profile_id')->nullable()->after('tenant_id')->constrained('delivery_staff_profiles')->nullOnDelete();
            $table->foreignId('courier_partner_id')->nullable()->after('delivery_staff_profile_id')->constrained('delivery_courier_partners')->nullOnDelete();
            $table->date('delivery_date')->nullable()->after('delivery_type');
            $table->string('assignment_status', 30)->default('pending_assignment')->after('status');
            $table->timestampTz('assigned_at')->nullable()->after('delivery_date');
            $table->string('assignment_idempotency_key', 120)->nullable()->after('assigned_at');
            $table->string('proof_of_delivery_path')->nullable()->after('delivery_notes');
            $table->text('proof_of_delivery_note')->nullable()->after('proof_of_delivery_path');
            $table->unique(['tenant_id', 'assignment_idempotency_key'], 'delivery_assignment_idempotency_unique');
            $table->index(['tenant_id', 'assignment_status', 'delivery_date']);
        });

        Schema::create('delivery_assignment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_shipment_id')->constrained('marketplace_shipments')->cascadeOnDelete();
            $table->foreignId('delivery_staff_profile_id')->nullable()->constrained('delivery_staff_profiles')->nullOnDelete();
            $table->foreignId('previous_staff_profile_id')->nullable()->constrained('delivery_staff_profiles')->nullOnDelete();
            $table->string('event_type', 30);
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('event_time');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'marketplace_shipment_id', 'event_time']);
        });

        DB::statement("ALTER TABLE marketplace_shipments DROP CONSTRAINT IF EXISTS marketplace_shipments_delivery_type_check");
        DB::statement("ALTER TABLE marketplace_shipments ADD CONSTRAINT marketplace_shipments_delivery_type_check CHECK (delivery_type IN ('self_delivery', 'external_courier', 'customer_pickup'))");
        DB::statement("ALTER TABLE marketplace_shipments ADD CONSTRAINT marketplace_shipments_assignment_status_check CHECK (assignment_status IN ('pending_assignment', 'assigned', 'accepted', 'picked_up', 'in_transit', 'delivered', 'failed', 'returned', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE marketplace_shipments DROP CONSTRAINT IF EXISTS marketplace_shipments_assignment_status_check");
        DB::statement("ALTER TABLE marketplace_shipments DROP CONSTRAINT IF EXISTS marketplace_shipments_delivery_type_check");
        DB::statement("ALTER TABLE marketplace_shipments ADD CONSTRAINT marketplace_shipments_delivery_type_check CHECK (delivery_type IN ('self_delivery', 'external_courier'))");
        Schema::dropIfExists('delivery_assignment_events');
        Schema::table('marketplace_shipments', function (Blueprint $table): void {
            $table->dropUnique('delivery_assignment_idempotency_unique');
            $table->dropForeign(['delivery_staff_profile_id']);
            $table->dropForeign(['courier_partner_id']);
            $table->dropColumn(['delivery_staff_profile_id', 'courier_partner_id', 'delivery_date', 'assignment_status', 'assigned_at', 'assignment_idempotency_key', 'proof_of_delivery_path', 'proof_of_delivery_note']);
        });
        Schema::dropIfExists('delivery_courier_partners');
        Schema::dropIfExists('delivery_staff_profiles');
    }
};
