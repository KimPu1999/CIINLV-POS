<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('currency_code', 3)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestampsTz();
        });

        Schema::create('marketplace_cart_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_cart_id')->constrained('marketplace_carts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->timestampsTz();
            $table->unique(['marketplace_cart_id', 'marketplace_listing_id', 'product_variant_id'], 'marketplace_cart_listing_variant_unique');
            $table->index(['tenant_id', 'marketplace_listing_id']);
            $table->foreign(['tenant_id', 'marketplace_listing_id'], 'marketplace_cart_items_tenant_listing_fk')
                ->references(['tenant_id', 'id'])->on('marketplace_listings')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'marketplace_cart_items_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'], 'marketplace_cart_items_tenant_variant_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('order_number', 80)->unique();
            $table->string('currency_code', 3);
            $table->string('status', 30)->default('confirmed')->index();
            $table->string('payment_status', 20)->default('pending')->index();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('shipping_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('grand_total', 18, 2)->default(0);
            $table->jsonb('delivery_address');
            $table->string('idempotency_key', 150);
            $table->string('request_hash', 64);
            $table->timestampsTz();
            $table->unique(['customer_user_id', 'idempotency_key'], 'marketplace_orders_customer_idempotency_unique');
            $table->index(['customer_user_id', 'created_at']);
        });

        Schema::create('marketplace_vendor_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('marketplace_store_id')->constrained('marketplace_stores')->restrictOnDelete();
            $table->string('vendor_order_number', 90)->unique();
            $table->string('status', 30)->default('pending')->index();
            $table->decimal('subtotal', 18, 2)->default(0);
            $table->decimal('discount_total', 18, 2)->default(0);
            $table->decimal('shipping_total', 18, 2)->default(0);
            $table->decimal('tax_total', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);
            $table->string('currency_code', 3);
            $table->jsonb('delivery_options')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->foreign(['tenant_id', 'marketplace_store_id'], 'marketplace_vendor_orders_tenant_store_fk')
                ->references(['tenant_id', 'id'])->on('marketplace_stores')->restrictOnDelete();
        });

        Schema::create('marketplace_order_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('marketplace_vendor_order_id')->constrained('marketplace_vendor_orders')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('marketplace_listing_id')->nullable()->constrained('marketplace_listings')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name_snapshot');
            $table->string('sku_snapshot', 100);
            $table->jsonb('variant_snapshot')->nullable();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('discount_amount', 18, 2)->default(0);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->jsonb('tax_snapshot')->nullable();
            $table->string('currency_code', 3);
            $table->timestampsTz();
            $table->index(['tenant_id', 'marketplace_vendor_order_id']);
            $table->foreign(['tenant_id', 'marketplace_listing_id'], 'marketplace_order_items_tenant_listing_fk')
                ->references(['tenant_id', 'id'])->on('marketplace_listings')->nullOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'marketplace_order_items_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->nullOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'], 'marketplace_order_items_tenant_variant_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')->nullOnDelete();
        });

        Schema::create('marketplace_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->string('method', 30);
            $table->string('provider', 80)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 3);
            $table->string('provider_reference', 150)->nullable();
            $table->string('idempotency_key', 150);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();
            $table->unique(['marketplace_order_id', 'method'], 'marketplace_payments_order_method_unique');
            $table->unique(['method', 'idempotency_key'], 'marketplace_payments_method_idempotency_unique');
            $table->unique(['provider', 'provider_reference'], 'marketplace_payments_provider_reference_unique');
        });

        Schema::create('marketplace_inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('marketplace_order_item_id')->constrained('marketplace_order_items')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('inventory_balance_id')->constrained('inventory_balances')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->decimal('quantity', 18, 3);
            $table->string('status', 20)->default('reserved')->index();
            $table->string('idempotency_key', 180)->unique();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'product_variant_id', 'status']);
            $table->foreign(['tenant_id', 'inventory_location_id'], 'marketplace_reservations_tenant_location_fk')
                ->references(['tenant_id', 'id'])->on('inventory_locations')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_variant_id'], 'marketplace_reservations_tenant_variant_fk')
                ->references(['tenant_id', 'id'])->on('product_variants')->restrictOnDelete();
        });

        Schema::create('marketplace_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->cascadeOnDelete();
            $table->foreignId('marketplace_vendor_order_id')->nullable()->constrained('marketplace_vendor_orders')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 80);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['marketplace_order_id', 'created_at']);
            $table->index(['marketplace_vendor_order_id', 'created_at']);
        });

        DB::statement("ALTER TABLE marketplace_carts ADD CONSTRAINT marketplace_carts_status_check CHECK (status IN ('active', 'converted', 'abandoned'))");
        DB::statement("ALTER TABLE marketplace_orders ADD CONSTRAINT marketplace_orders_status_check CHECK (status IN ('pending_payment', 'confirmed', 'processing', 'partially_shipped', 'shipped', 'delivered', 'cancelled', 'partially_refunded', 'refunded'))");
        DB::statement("ALTER TABLE marketplace_orders ADD CONSTRAINT marketplace_orders_payment_status_check CHECK (payment_status IN ('pending', 'authorized', 'paid', 'failed', 'cancelled', 'refunded'))");
        DB::statement("ALTER TABLE marketplace_vendor_orders ADD CONSTRAINT marketplace_vendor_orders_status_check CHECK (status IN ('pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'))");
        DB::statement("ALTER TABLE marketplace_payments ADD CONSTRAINT marketplace_payments_method_check CHECK (method IN ('cod', 'online'))");
        DB::statement("ALTER TABLE marketplace_payments ADD CONSTRAINT marketplace_payments_status_check CHECK (status IN ('pending', 'authorized', 'paid', 'failed', 'cancelled', 'refunded'))");
        DB::statement("ALTER TABLE marketplace_inventory_reservations ADD CONSTRAINT marketplace_reservations_status_check CHECK (status IN ('reserved', 'confirmed', 'released', 'expired'))");
    }

    public function down(): void
    {
        foreach (['marketplace_order_events', 'marketplace_inventory_reservations', 'marketplace_payments', 'marketplace_order_items', 'marketplace_vendor_orders', 'marketplace_orders', 'marketplace_cart_items', 'marketplace_carts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
