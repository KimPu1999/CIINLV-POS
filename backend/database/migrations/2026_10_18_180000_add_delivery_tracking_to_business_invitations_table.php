<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_invitations', function (Blueprint $table): void {
            $table->timestampTz('sent_at')->nullable()->after('expires_at');
            $table->timestampTz('failed_at')->nullable()->after('sent_at');
            $table->text('failure_reason')->nullable()->after('failed_at');
            $table->index(['tenant_id', 'sent_at']);
            $table->index(['tenant_id', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('business_invitations', function (Blueprint $table): void {
            $table->dropIndex('business_invitations_tenant_id_sent_at_index');
            $table->dropIndex('business_invitations_tenant_id_failed_at_index');
            $table->dropColumn(['sent_at', 'failed_at', 'failure_reason']);
        });
    }
};
