<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_contact_inquiries', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            $table->foreignId('marketplace_store_id')->nullable()->after('tenant_id')->constrained('marketplace_stores')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestampTz('read_at')->nullable()->after('assigned_to_user_id');
            $table->foreignId('read_by_user_id')->nullable()->after('read_at')->constrained('users')->nullOnDelete();
            $table->string('admin_notification_status', 20)->default('not_configured')->after('read_by_user_id');
            $table->text('admin_notification_error')->nullable()->after('admin_notification_status');
            $table->timestampTz('admin_notified_at')->nullable()->after('admin_notification_error');
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'marketplace_store_id']);
        });

        Schema::create('marketplace_contact_inquiry_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_contact_inquiry_id')->constrained('marketplace_contact_inquiries')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('message_type', 30);
            $table->text('body');
            $table->string('delivery_status', 20)->nullable();
            $table->text('delivery_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
            $table->index(['marketplace_contact_inquiry_id', 'created_at']);
            $table->index(['message_type', 'delivery_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_contact_inquiry_messages');
        Schema::table('marketplace_contact_inquiries', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['marketplace_store_id']);
            $table->dropForeign(['assigned_to_user_id']);
            $table->dropForeign(['read_by_user_id']);
            $table->dropIndex(['tenant_id', 'status', 'created_at']);
            $table->dropIndex(['tenant_id', 'marketplace_store_id']);
            $table->dropColumn([
                'tenant_id', 'marketplace_store_id', 'assigned_to_user_id', 'read_at', 'read_by_user_id',
                'admin_notification_status', 'admin_notification_error', 'admin_notified_at',
            ]);
        });
    }
};
