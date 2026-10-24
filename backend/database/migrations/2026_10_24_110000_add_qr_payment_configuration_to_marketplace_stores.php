<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_stores', function (Blueprint $table): void {
            $table->string('qr_payment_provider', 80)->nullable()->after('cod_available');
            $table->string('qr_payment_account_name', 255)->nullable()->after('qr_payment_provider');
            $table->string('qr_payment_phone', 50)->nullable()->after('qr_payment_account_name');
            $table->text('qr_payment_instructions')->nullable()->after('qr_payment_phone');
            $table->string('qr_payment_qr_path')->nullable()->after('qr_payment_instructions');
            $table->boolean('qr_payment_enabled')->default(false)->after('qr_payment_qr_path');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_stores', function (Blueprint $table): void {
            $table->dropColumn([
                'qr_payment_provider',
                'qr_payment_account_name',
                'qr_payment_phone',
                'qr_payment_instructions',
                'qr_payment_qr_path',
                'qr_payment_enabled',
            ]);
        });
    }
};
