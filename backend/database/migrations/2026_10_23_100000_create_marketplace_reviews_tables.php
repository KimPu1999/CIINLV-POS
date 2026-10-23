<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_order_items', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'id'], 'marketplace_order_items_tenant_id_id_unique');
        });

        Schema::create('marketplace_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('marketplace_listing_id')->constrained('marketplace_listings')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('marketplace_order_item_id')->constrained('marketplace_order_items')->restrictOnDelete();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->string('title', 160)->nullable();
            $table->text('comment');
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('verified_purchase')->default(true);
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('moderation_reason', 500)->nullable();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['marketplace_order_item_id', 'customer_user_id'], 'marketplace_reviews_item_customer_unique');
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['marketplace_listing_id', 'status', 'rating']);
            $table->foreign(['tenant_id', 'marketplace_listing_id'], 'marketplace_reviews_tenant_listing_fk')
                ->references(['tenant_id', 'id'])->on('marketplace_listings')->restrictOnDelete();
            $table->foreign(['tenant_id', 'product_id'], 'marketplace_reviews_tenant_product_fk')
                ->references(['tenant_id', 'id'])->on('products')->restrictOnDelete();
            $table->foreign(['tenant_id', 'marketplace_order_item_id'], 'marketplace_reviews_tenant_order_item_fk')
                ->references(['tenant_id', 'id'])->on('marketplace_order_items')->restrictOnDelete();
        });

        Schema::create('marketplace_review_moderation_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_review_id')->constrained('marketplace_reviews')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('moderator_user_id')->constrained('users')->restrictOnDelete();
            $table->string('previous_status', 20);
            $table->string('new_status', 20);
            $table->string('reason', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('marketplace_review_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_review_id')->constrained('marketplace_reviews')->cascadeOnDelete();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason', 500);
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['marketplace_review_id', 'reporter_user_id'], 'marketplace_review_reports_review_reporter_unique');
        });

        DB::statement('ALTER TABLE marketplace_reviews ADD CONSTRAINT marketplace_reviews_rating_check CHECK (rating BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE marketplace_reviews ADD CONSTRAINT marketplace_reviews_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'hidden'))");
        DB::statement("ALTER TABLE marketplace_review_moderation_histories ADD CONSTRAINT marketplace_review_history_status_check CHECK (previous_status IN ('pending', 'approved', 'rejected', 'hidden') AND new_status IN ('pending', 'approved', 'rejected', 'hidden'))");
        DB::statement("ALTER TABLE marketplace_review_reports ADD CONSTRAINT marketplace_review_reports_status_check CHECK (status IN ('open', 'reviewed', 'dismissed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE marketplace_review_reports DROP CONSTRAINT IF EXISTS marketplace_review_reports_status_check');
        DB::statement('ALTER TABLE marketplace_review_moderation_histories DROP CONSTRAINT IF EXISTS marketplace_review_history_status_check');
        DB::statement('ALTER TABLE marketplace_reviews DROP CONSTRAINT IF EXISTS marketplace_reviews_status_check');
        DB::statement('ALTER TABLE marketplace_reviews DROP CONSTRAINT IF EXISTS marketplace_reviews_rating_check');
        Schema::dropIfExists('marketplace_review_reports');
        Schema::dropIfExists('marketplace_review_moderation_histories');
        Schema::dropIfExists('marketplace_reviews');
        Schema::table('marketplace_order_items', function (Blueprint $table): void {
            $table->dropUnique('marketplace_order_items_tenant_id_id_unique');
        });
    }
};
