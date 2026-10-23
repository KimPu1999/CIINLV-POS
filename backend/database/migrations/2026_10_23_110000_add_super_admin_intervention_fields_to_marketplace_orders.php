<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->boolean('admin_hold')->default(false)->index();
            $table->string('admin_hold_reason', 500)->nullable();
            $table->foreignId('admin_hold_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('admin_hold_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropForeign(['admin_hold_by']);
            $table->dropColumn(['admin_hold', 'admin_hold_reason', 'admin_hold_by', 'admin_hold_at']);
        });
    }
};
