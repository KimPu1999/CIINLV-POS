<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_widgets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('widget_key', 80);
            $table->string('title');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedSmallInteger('width')->default(1);
            $table->unsignedSmallInteger('height')->default(1);
            $table->boolean('is_enabled')->default(true);
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
            $table->unique(['store_id', 'widget_key']);
            $table->index(['tenant_id', 'store_id']);
        });

        Schema::create('dashboard_layouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->jsonb('layout');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->unique(['store_id', 'user_id', 'name']);
            $table->index(['tenant_id', 'store_id']);
        });

        $stores = DB::table('stores')->select('id', 'tenant_id')->get();
        $now = now();
        $widgetKeys = [
            ['sales_overview', 'Sales Overview'],
            ['total_products', 'Total Products'],
            ['stock_summary', 'Stock Summary'],
            ['low_stock', 'Low Stock'],
            ['recent_transactions', 'Recent Transactions'],
            ['staff_overview', 'Staff Overview'],
            ['daily_revenue', 'Daily Revenue'],
            ['weekly_revenue', 'Weekly Revenue'],
            ['monthly_revenue', 'Monthly Revenue'],
            ['branch_performance', 'Branch Performance'],
            ['payment_summary', 'Payment Summary'],
            ['supplier_overview', 'Supplier Overview'],
            ['expenses', 'Expenses'],
        ];

        foreach ($stores as $store) {
            DB::table('dashboard_layouts')->insert([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $store->tenant_id,
                'store_id' => $store->id,
                'name' => 'Default layout',
                'layout' => json_encode(['version' => 1, 'widgets' => array_map(fn ($widget, $position) => ['widget_key' => $widget[0], 'position' => $position, 'width' => 1, 'height' => 1], $widgetKeys, array_keys($widgetKeys))], JSON_THROW_ON_ERROR),
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($widgetKeys as $position => [$key, $title]) {
                DB::table('dashboard_widgets')->insert([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $store->tenant_id,
                    'store_id' => $store->id,
                    'widget_key' => $key,
                    'title' => $title,
                    'position' => $position,
                    'width' => 1,
                    'height' => 1,
                    'is_enabled' => true,
                    'settings' => json_encode([], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::statement('ALTER TABLE dashboard_widgets ADD CONSTRAINT dashboard_widgets_dimensions_check CHECK (width BETWEEN 1 AND 12 AND height BETWEEN 1 AND 12)');
        DB::statement("ALTER TABLE dashboard_widgets ADD CONSTRAINT dashboard_widgets_key_check CHECK (widget_key IN ('sales_overview', 'total_products', 'stock_summary', 'low_stock', 'recent_transactions', 'staff_overview', 'daily_revenue', 'weekly_revenue', 'monthly_revenue', 'branch_performance', 'payment_summary', 'supplier_overview', 'expenses'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE dashboard_widgets DROP CONSTRAINT IF EXISTS dashboard_widgets_key_check');
        DB::statement('ALTER TABLE dashboard_widgets DROP CONSTRAINT IF EXISTS dashboard_widgets_dimensions_check');
        Schema::dropIfExists('dashboard_layouts');
        Schema::dropIfExists('dashboard_widgets');
    }
};
