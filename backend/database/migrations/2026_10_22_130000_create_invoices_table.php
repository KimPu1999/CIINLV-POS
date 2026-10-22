<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id(); $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $table->string('invoice_number', 80); $table->string('status', 30)->default('issued'); $table->string('currency_code', 3);
            $table->decimal('subtotal', 18, 2)->default(0); $table->decimal('tax_total', 18, 2)->default(0); $table->decimal('grand_total', 18, 2)->default(0); $table->decimal('paid_total', 18, 2)->default(0); $table->decimal('balance_due', 18, 2)->default(0);
            $table->timestampTz('issued_at')->useCurrent(); $table->timestampTz('due_at')->nullable(); $table->jsonb('metadata')->nullable(); $table->timestampsTz();
            $table->unique(['tenant_id', 'invoice_number']); $table->index(['tenant_id', 'status', 'created_at']);
        });
        Schema::table('payments', function (Blueprint $table): void { $table->foreignId('invoice_id')->nullable()->after('sale_id')->constrained('invoices')->nullOnDelete(); $table->index(['tenant_id', 'invoice_id', 'status']); });
    }
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void { $table->dropForeign(['invoice_id']); $table->dropIndex(['tenant_id', 'invoice_id', 'status']); $table->dropColumn('invoice_id'); });
        Schema::dropIfExists('invoices');
    }
};
