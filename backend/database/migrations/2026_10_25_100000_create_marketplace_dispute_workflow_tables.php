<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_disputes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('reference', 40)->unique();
            $table->foreignId('marketplace_order_id')->constrained('marketplace_orders')->restrictOnDelete();
            $table->foreignId('marketplace_vendor_order_id')->nullable()->constrained('marketplace_vendor_orders')->restrictOnDelete();
            $table->foreignId('marketplace_order_item_id')->nullable()->constrained('marketplace_order_items')->restrictOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 50);
            $table->string('subject', 180);
            $table->text('description');
            $table->string('status', 40)->default('open')->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->string('requested_resolution', 40)->default('other');
            $table->text('resolution_note')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['customer_user_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['assigned_reviewer_id', 'status']);
        });

        Schema::create('marketplace_dispute_evidence', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_dispute_id')->constrained('marketplace_disputes')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk', 40)->default('private');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->timestampsTz();
            $table->index(['marketplace_dispute_id', 'created_at']);
        });

        Schema::create('marketplace_dispute_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_dispute_id')->constrained('marketplace_disputes')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->string('visibility', 20)->default('participants');
            $table->text('message');
            $table->timestampsTz();
            $table->index(['marketplace_dispute_id', 'visibility', 'created_at']);
        });

        Schema::create('marketplace_dispute_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketplace_dispute_id')->constrained('marketplace_disputes')->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->index(['marketplace_dispute_id', 'created_at']);
        });

        Schema::create('marketplace_dispute_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('marketplace_dispute_id')->unique()->constrained('marketplace_disputes')->cascadeOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('currency_code', 3)->nullable();
            $table->text('note');
            $table->string('refund_status', 30)->default('not_applicable');
            $table->string('refund_idempotency_key', 180)->nullable()->unique();
            $table->string('provider_reference', 180)->nullable();
            $table->timestampTz('approved_at');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE marketplace_disputes ADD CONSTRAINT marketplace_disputes_status_check CHECK (status IN ('open', 'awaiting_seller_response', 'under_review', 'awaiting_customer_information', 'resolved', 'rejected', 'closed'))");
        DB::statement("ALTER TABLE marketplace_disputes ADD CONSTRAINT marketplace_disputes_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
        DB::statement("ALTER TABLE marketplace_dispute_messages ADD CONSTRAINT marketplace_dispute_messages_visibility_check CHECK (visibility IN ('participants', 'internal'))");
        DB::statement("ALTER TABLE marketplace_dispute_resolutions ADD CONSTRAINT marketplace_dispute_resolutions_type_check CHECK (type IN ('full_refund', 'partial_refund', 'replacement', 'return_requested', 'no_refund', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_dispute_resolutions');
        Schema::dropIfExists('marketplace_dispute_status_history');
        Schema::dropIfExists('marketplace_dispute_messages');
        Schema::dropIfExists('marketplace_dispute_evidence');
        Schema::dropIfExists('marketplace_disputes');
    }
};
