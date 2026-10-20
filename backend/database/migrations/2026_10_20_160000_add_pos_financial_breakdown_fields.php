<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'tax_exempt')) {
                $table->boolean('tax_exempt')->default(false)->index();
            }
        });

        Schema::table('sales', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales', 'taxable_amount')) {
                $table->decimal('taxable_amount', 18, 2)->default(0);
            }
            if (! Schema::hasColumn('sales', 'service_charge_total')) {
                $table->decimal('service_charge_total', 18, 2)->default(0);
            }
            if (! Schema::hasColumn('sales', 'fee_total')) {
                $table->decimal('fee_total', 18, 2)->default(0);
            }
            if (! Schema::hasColumn('sales', 'rounding_adjustment')) {
                $table->decimal('rounding_adjustment', 18, 2)->default(0);
            }
            if (! Schema::hasColumn('sales', 'tax_breakdown')) {
                $table->jsonb('tax_breakdown')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $columns = array_values(array_filter(['taxable_amount', 'service_charge_total', 'fee_total', 'rounding_adjustment', 'tax_breakdown'], fn (string $column): bool => Schema::hasColumn('sales', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('products', function (Blueprint $table): void {
            if (Schema::hasColumn('products', 'tax_exempt')) {
                $table->dropColumn('tax_exempt');
            }
        });
    }
};
