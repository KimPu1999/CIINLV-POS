<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            $table->string('approval_status', 20)->default('pending')->index();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique('tenant_id');
            $table->unique(['tenant_id', 'id'], 'marketplace_stores_tenant_id_id_unique');
            $table->index(['tenant_id', 'approval_status', 'is_active']);
        });

        Schema::create('marketplace_listings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_store_id')->constrained('marketplace_stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'product_id']);
            $table->unique(['tenant_id', 'id'], 'marketplace_listings_tenant_id_id_unique');
            $table->index(['tenant_id', 'status', 'published_at']);
            $table->foreign(['tenant_id', 'marketplace_store_id'], 'marketplace_listings_tenant_store_fk')
                ->references(['tenant_id', 'id'])
                ->on('marketplace_stores')
                ->cascadeOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'marketplace_listings_tenant_product_fk')
                ->references(['tenant_id', 'id'])
                ->on('products')
                ->cascadeOnDelete();
        });

        Schema::create('marketplace_listing_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_listing_id')->constrained('marketplace_listings')->cascadeOnDelete();
            $table->string('image_path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'marketplace_listing_id']);
            $table->foreign(['tenant_id', 'marketplace_listing_id'], 'marketplace_listing_images_tenant_listing_fk')
                ->references(['tenant_id', 'id'])
                ->on('marketplace_listings')
                ->cascadeOnDelete();
        });

        Schema::create('customer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 50)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestampsTz();
        });

        Schema::create('customer_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_profile_id')->constrained('customer_profiles')->cascadeOnDelete();
            $table->string('recipient_name');
            $table->string('phone', 50)->nullable();
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city');
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country', 2)->default('MM');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->index(['customer_profile_id', 'is_default']);
        });

        Schema::create('marketplace_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['action', 'created_at']);
        });

        DB::statement("ALTER TABLE marketplace_stores ADD CONSTRAINT marketplace_stores_approval_status_check CHECK (approval_status IN ('pending', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE marketplace_listings ADD CONSTRAINT marketplace_listings_status_check CHECK (status IN ('draft', 'published', 'archived'))");
        DB::statement("ALTER TABLE customer_profiles ADD CONSTRAINT customer_profiles_status_check CHECK (status IN ('active', 'suspended'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customer_profiles DROP CONSTRAINT IF EXISTS customer_profiles_status_check');
        DB::statement('ALTER TABLE marketplace_listings DROP CONSTRAINT IF EXISTS marketplace_listings_status_check');
        DB::statement('ALTER TABLE marketplace_stores DROP CONSTRAINT IF EXISTS marketplace_stores_approval_status_check');
        Schema::dropIfExists('marketplace_audit_logs');
        Schema::dropIfExists('customer_addresses');
        Schema::dropIfExists('customer_profiles');
        Schema::dropIfExists('marketplace_listing_images');
        Schema::dropIfExists('marketplace_listings');
        Schema::dropIfExists('marketplace_stores');
    }
};
