<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('id_type', 40)->nullable()->after('business_type');
            $table->string('id_number', 150)->nullable()->after('id_type');
            $table->string('company_registration_number', 150)->nullable()->after('id_number');
            $table->string('personal_registration_number', 150)->nullable()->after('company_registration_number');
            $table->string('id_front_image_path')->nullable()->after('logo_path');
            $table->string('id_back_image_path')->nullable()->after('id_front_image_path');
            $table->string('company_certificate_image_path')->nullable()->after('id_back_image_path');
            $table->string('verification_status', 30)->default('pending')->after('company_certificate_image_path');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('profile_image_path')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'id_type', 'id_number', 'company_registration_number', 'personal_registration_number',
                'id_front_image_path', 'id_back_image_path', 'company_certificate_image_path', 'verification_status',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('profile_image_path');
        });
    }
};
