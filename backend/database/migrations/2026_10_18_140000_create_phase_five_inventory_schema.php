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
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('status')->default('active');
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'categories_tenant_id_id_unique');
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('status')->default('active');
            $table->boolean('track_inventory')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'products_tenant_id_id_unique');
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'category_id', 'status']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 100);
            $table->string('barcode', 100)->nullable();
            $table->string('barcode_type', 30)->nullable();
            $table->string('unit', 30)->default('piece');
            $table->jsonb('attributes')->nullable();
            $table->decimal('purchase_cost', 18, 4)->default(0);
            $table->decimal('selling_price', 18, 4)->default(0);
            $table->decimal('reorder_level', 18, 3)->default(0);
            $table->string('status')->default('active');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'product_variants_tenant_id_id_unique');
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'product_id', 'status']);
        });

        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('storage_path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
            $table->index(['tenant_id', 'product_id']);
        });

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('supplier_code', 50);
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('active');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'supplier_code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('location_type', 30);
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('name');
            $table->string('code', 50);
            $table->text('address')->nullable();
            $table->string('status')->default('active');
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id'], 'inventory_locations_tenant_id_id_unique');
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'location_type', 'status']);
            $table->foreign(['tenant_id', 'store_id'])
                ->references(['tenant_id', 'id'])->on('stores')->cascadeOnDelete();
        });

        foreach (DB::table('stores')->select('id', 'uuid', 'tenant_id', 'name', 'code')->get() as $store) {
            DB::table('inventory_locations')->insert([
                'uuid' => (string) Str::uuid(), 'tenant_id' => $store->tenant_id, 'location_type' => 'store', 'store_id' => $store->id,
                'name' => $store->name.' Stockroom', 'code' => $store->code, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->decimal('on_hand_quantity', 18, 3)->default(0);
            $table->decimal('reserved_quantity', 18, 3)->default(0);
            $table->decimal('average_cost', 18, 4)->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestampsTz();
            $table->unique(['inventory_location_id', 'product_variant_id']);
            $table->index(['tenant_id', 'inventory_location_id', 'product_variant_id']);
            $table->foreign(['tenant_id', 'inventory_location_id'])
                ->references(['tenant_id', 'id'])->on('inventory_locations')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'])
                ->references(['tenant_id', 'id'])->on('product_variants')->cascadeOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('movement_type', 40);
            $table->decimal('quantity_delta', 18, 3);
            $table->decimal('quantity_before', 18, 3);
            $table->decimal('quantity_after', 18, 3);
            $table->string('reference_type', 100)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 150)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'inventory_location_id', 'created_at']);
            $table->index(['tenant_id', 'product_variant_id', 'created_at']);
        });

        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('purchase_order_number', 60);
            $table->string('status', 30)->default('draft');
            $table->timestampTz('ordered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'purchase_order_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('ordered_quantity', 18, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('received_quantity', 18, 3)->default(0);
            $table->timestampsTz();
            $table->unique(['purchase_order_id', 'product_variant_id']);
        });

        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_at')->useCurrent();
            $table->string('status', 30)->default('posted');
            $table->string('idempotency_key', 150);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'purchase_order_id']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_received', 18, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->timestampsTz();
        });

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->string('transfer_number', 60);
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'transfer_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('requested_quantity', 18, 3);
            $table->decimal('reserved_quantity', 18, 3)->default(0);
            $table->decimal('dispatched_quantity', 18, 3)->default(0);
            $table->decimal('received_quantity', 18, 3)->default(0);
            $table->decimal('damaged_quantity', 18, 3)->default(0);
            $table->decimal('missing_quantity', 18, 3)->default(0);
            $table->timestampsTz();
            $table->unique(['stock_transfer_id', 'product_variant_id']);
        });

        Schema::create('stock_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_delta', 18, 3);
            $table->string('reason', 100);
            $table->string('status', 30)->default('approved');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 150);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'inventory_location_id', 'created_at']);
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_status_check CHECK (status IN ('active', 'inactive', 'archived'))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('active', 'inactive', 'archived'))");
        DB::statement("ALTER TABLE product_variants ADD CONSTRAINT product_variants_status_check CHECK (status IN ('active', 'inactive', 'archived'))");
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_amounts_check CHECK (purchase_cost >= 0 AND selling_price >= 0 AND reorder_level >= 0)');
        DB::statement("CREATE UNIQUE INDEX product_variants_active_barcode_unique ON product_variants (tenant_id, barcode) WHERE barcode IS NOT NULL AND status = 'active'");
        DB::statement("ALTER TABLE inventory_locations ADD CONSTRAINT inventory_locations_type_check CHECK ((location_type = 'store' AND store_id IS NOT NULL) OR (location_type = 'warehouse' AND store_id IS NULL))");
        DB::statement('ALTER TABLE inventory_balances ADD CONSTRAINT inventory_balances_quantity_check CHECK (on_hand_quantity >= 0 AND reserved_quantity >= 0 AND reserved_quantity <= on_hand_quantity AND average_cost >= 0)');
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (movement_type IN ('opening_stock', 'purchase_receipt', 'stock_transfer_out', 'stock_transfer_in', 'sale', 'sale_return', 'adjustment_in', 'adjustment_out', 'damage', 'loss', 'correction'))");
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft', 'submitted', 'approved', 'partially_received', 'received', 'cancelled'))");
        DB::statement('ALTER TABLE purchase_order_items ADD CONSTRAINT purchase_order_items_quantity_check CHECK (ordered_quantity > 0 AND unit_cost >= 0 AND received_quantity >= 0 AND received_quantity <= ordered_quantity)');
        DB::statement("ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_status_check CHECK (status IN ('draft', 'pending_approval', 'approved', 'dispatched', 'partially_received', 'received', 'cancelled'))");
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_quantity_check CHECK (requested_quantity > 0 AND reserved_quantity >= 0 AND dispatched_quantity >= 0 AND received_quantity >= 0 AND damaged_quantity >= 0 AND missing_quantity >= 0 AND received_quantity + damaged_quantity + missing_quantity <= dispatched_quantity)');
        DB::statement('ALTER TABLE stock_adjustments ADD CONSTRAINT stock_adjustments_quantity_check CHECK (quantity_delta <> 0)');
    }

    public function down(): void
    {
        foreach (['stock_adjustments', 'stock_transfer_items', 'stock_transfers', 'goods_receipt_items', 'goods_receipts', 'purchase_order_items', 'purchase_orders', 'stock_movements', 'inventory_balances', 'inventory_locations', 'suppliers', 'product_images', 'product_variants', 'products', 'categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
