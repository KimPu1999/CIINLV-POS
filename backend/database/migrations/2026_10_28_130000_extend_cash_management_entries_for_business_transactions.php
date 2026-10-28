<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_management_entries', function (Blueprint $table): void {
            $table->string('paid_to', 255)->nullable()->after('category');
            $table->string('received_from', 255)->nullable()->after('paid_to');
            $table->date('transaction_date')->nullable()->after('amount');
            $table->text('notes')->nullable()->after('reason');
            $table->foreignId('purchase_order_id')->nullable()->after('cashier_shift_id')->constrained('purchase_orders')->nullOnDelete();
            $table->index(['tenant_id', 'category', 'transaction_date']);
            $table->index(['tenant_id', 'purchase_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_management_entries', function (Blueprint $table): void {
            $table->dropForeign(['purchase_order_id']);
            $table->dropIndex(['cash_management_entries_tenant_id_category_transaction_date_index']);
            $table->dropIndex(['cash_management_entries_tenant_id_purchase_order_id_index']);
            $table->dropColumn(['paid_to', 'received_from', 'transaction_date', 'notes', 'purchase_order_id']);
        });
    }
};
