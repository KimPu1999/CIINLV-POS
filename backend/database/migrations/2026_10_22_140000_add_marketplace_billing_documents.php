<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->string('invoice_number', 80)->nullable()->unique()->after('order_number');
        });

        Schema::table('marketplace_payments', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('marketplace_order_id')->constrained()->nullOnDelete();
            $table->foreignId('marketplace_vendor_order_id')->nullable()->after('tenant_id')->constrained('marketplace_vendor_orders')->nullOnDelete();
            $table->foreignId('marketplace_store_id')->nullable()->after('marketplace_vendor_order_id')->constrained('marketplace_stores')->nullOnDelete();
            $table->decimal('amount_received', 18, 2)->default(0)->after('amount');
            $table->decimal('change_amount', 18, 2)->default(0)->after('amount_received');
            $table->string('transaction_reference', 150)->nullable()->after('provider_reference');
            $table->foreignId('received_by')->nullable()->after('transaction_reference')->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at')->nullable()->after('paid_at');
            $table->string('receipt_number', 80)->nullable()->unique()->after('received_at');
            $table->index(['tenant_id', 'marketplace_vendor_order_id']);
        });

        DB::table('marketplace_orders')->whereNull('invoice_number')->orderBy('id')->eachById(function (object $order): void {
            DB::table('marketplace_orders')->where('id', $order->id)->update([
                'invoice_number' => 'INV-MP-'.date('Y').'-'.str_pad((string) $order->id, 6, '0', STR_PAD_LEFT),
            ]);
        });

        DB::table('marketplace_payments')->where('status', 'paid')->where('amount_received', 0)->update(['amount_received' => DB::raw('amount')]);
        DB::table('marketplace_payments')->where('status', 'paid')->whereNull('receipt_number')->orderBy('id')->eachById(function (object $payment): void {
            DB::table('marketplace_payments')->where('id', $payment->id)->update([
                'receipt_number' => 'RCT-MP-'.date('Y').'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
                'received_at' => $payment->paid_at ?? now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_payments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'marketplace_vendor_order_id']);
            $table->dropForeign(['received_by']);
            $table->dropForeign(['marketplace_store_id']);
            $table->dropForeign(['marketplace_vendor_order_id']);
            $table->dropForeign(['tenant_id']);
            $table->dropUnique(['receipt_number']);
            $table->dropColumn(['tenant_id', 'marketplace_vendor_order_id', 'marketplace_store_id', 'amount_received', 'change_amount', 'transaction_reference', 'received_by', 'received_at', 'receipt_number']);
        });

        Schema::table('marketplace_orders', function (Blueprint $table): void {
            $table->dropUnique(['invoice_number']);
            $table->dropColumn('invoice_number');
        });
    }
};
