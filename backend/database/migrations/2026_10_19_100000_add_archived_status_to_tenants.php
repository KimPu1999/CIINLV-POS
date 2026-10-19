<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestampTz('archived_at')->nullable()->after('suspension_reason');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->string('archived_previous_status', 20)->nullable()->after('archived_by');
        });

        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_status_check');
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('pending', 'active', 'suspended', 'archived'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_status_check');
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('pending', 'active', 'suspended'))");
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['archived_at', 'archived_previous_status']);
        });
    }
};
