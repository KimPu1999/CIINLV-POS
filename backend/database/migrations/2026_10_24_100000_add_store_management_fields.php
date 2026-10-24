<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_stores', function (Blueprint $table): void {
            $table->string('tagline', 255)->nullable()->after('description');
            $table->string('secondary_contact_phone', 50)->nullable()->after('public_contact_phone');
            $table->string('website')->nullable()->after('business_category');
            $table->string('facebook_url')->nullable()->after('website');
            $table->string('messaging_contact', 100)->nullable()->after('facebook_url');
            $table->string('shipping_coverage', 255)->nullable()->after('messaging_contact');
            $table->string('support_contact', 255)->nullable()->after('shipping_coverage');
            $table->boolean('cod_available')->default(false)->after('support_contact');
            $table->jsonb('delivery_methods')->nullable()->after('cod_available');
            $table->jsonb('operating_hours')->nullable()->after('delivery_methods');
            $table->text('temporary_closure_message')->nullable()->after('operating_hours');
            $table->text('holiday_notice')->nullable()->after('temporary_closure_message');
            $table->jsonb('policies')->nullable()->after('holiday_notice');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_stores', function (Blueprint $table): void {
            $table->dropColumn([
                'tagline',
                'secondary_contact_phone',
                'website',
                'facebook_url',
                'messaging_contact',
                'shipping_coverage',
                'support_contact',
                'cod_available',
                'delivery_methods',
                'operating_hours',
                'temporary_closure_message',
                'holiday_notice',
                'policies',
            ]);
        });
    }
};
