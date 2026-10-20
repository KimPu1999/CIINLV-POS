<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_stores', function (Blueprint $table): void {
            $table->string('public_contact_email')->nullable()->after('description');
            $table->string('public_contact_phone', 50)->nullable()->after('public_contact_email');
            $table->string('business_category', 120)->nullable()->after('public_contact_phone');
            $table->jsonb('notification_preferences')->nullable()->after('is_active');
            $table->jsonb('fulfillment_preferences')->nullable()->after('notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_stores', function (Blueprint $table): void {
            $table->dropColumn([
                'public_contact_email',
                'public_contact_phone',
                'business_category',
                'notification_preferences',
                'fulfillment_preferences',
            ]);
        });
    }
};
