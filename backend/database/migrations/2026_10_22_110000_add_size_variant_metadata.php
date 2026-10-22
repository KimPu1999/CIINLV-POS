<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('has_sizes')->default(false)->after('track_inventory');
        });

        Schema::table('product_variants', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('attributes');
            $table->index(['product_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'is_default']);
            $table->dropColumn('is_default');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('has_sizes');
        });
    }
};
