<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_alerts', function (Blueprint $table): void {
            $table->timestampTz('snoozed_until')->nullable()->after('acknowledged_by');
            $table->foreignId('snoozed_by')->nullable()->after('snoozed_until')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'status', 'snoozed_until']);
        });

        DB::statement('ALTER TABLE stock_alerts DROP CONSTRAINT IF EXISTS stock_alerts_status_check');
        DB::statement("ALTER TABLE stock_alerts ADD CONSTRAINT stock_alerts_status_check CHECK (status IN ('open', 'acknowledged', 'snoozed', 'resolved'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_alerts DROP CONSTRAINT IF EXISTS stock_alerts_status_check');
        DB::statement("ALTER TABLE stock_alerts ADD CONSTRAINT stock_alerts_status_check CHECK (status IN ('open', 'acknowledged', 'resolved'))");
        Schema::table('stock_alerts', function (Blueprint $table): void {
            $table->dropForeign(['snoozed_by']);
            $table->dropIndex(['tenant_id', 'status', 'snoozed_until']);
            $table->dropColumn(['snoozed_until', 'snoozed_by']);
        });
    }
};
