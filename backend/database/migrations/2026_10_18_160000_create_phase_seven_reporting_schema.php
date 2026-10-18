<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('alert_type', 30);
            $table->decimal('threshold_quantity', 18, 3)->default(0);
            $table->decimal('available_quantity', 18, 3)->default(0);
            $table->string('status', 20)->default('open');
            $table->timestampTz('first_triggered_at')->useCurrent();
            $table->timestampTz('last_triggered_at')->useCurrent();
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'inventory_location_id', 'status']);
            $table->index(['tenant_id', 'last_triggered_at']);
            $table->foreign(['tenant_id', 'inventory_location_id'])
                ->references(['tenant_id', 'id'])->on('inventory_locations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'])
                ->references(['tenant_id', 'id'])->on('product_variants')->restrictOnDelete();
        });
        DB::statement("CREATE UNIQUE INDEX stock_alerts_open_unique ON stock_alerts (tenant_id, inventory_location_id, product_variant_id, alert_type) WHERE status IN ('open', 'acknowledged')");
        DB::statement("ALTER TABLE stock_alerts ADD CONSTRAINT stock_alerts_type_check CHECK (alert_type IN ('low_stock', 'out_of_stock'))");
        DB::statement("ALTER TABLE stock_alerts ADD CONSTRAINT stock_alerts_status_check CHECK (status IN ('open', 'acknowledged', 'resolved'))");
        DB::statement('ALTER TABLE stock_alerts ADD CONSTRAINT stock_alerts_quantities_check CHECK (threshold_quantity >= 0 AND available_quantity >= 0)');

        Schema::create('alert_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('email_enabled')->default(false);
            $table->boolean('database_enabled')->default(true);
            $table->boolean('low_stock_enabled')->default(true);
            $table->boolean('out_of_stock_enabled')->default(true);
            $table->jsonb('channels')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('report_type', 80);
            $table->string('format', 10);
            $table->jsonb('filters')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'requested_by', 'status']);
            $table->index(['tenant_id', 'expires_at']);
        });
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_format_check CHECK (format IN ('csv', 'pdf'))");
        DB::statement("ALTER TABLE report_exports ADD CONSTRAINT report_exports_status_check CHECK (status IN ('queued', 'processing', 'completed', 'failed', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('alert_preferences');
        Schema::dropIfExists('stock_alerts');
    }
};
