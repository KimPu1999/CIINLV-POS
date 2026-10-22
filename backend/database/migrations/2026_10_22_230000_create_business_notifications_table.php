<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // This is intentionally un-constrained because marketplace and inventory
            // records use different store tables. The canonical reference is also
            // retained in data as a UUID.
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('type', 80);
            $table->string('title', 160);
            $table->text('message');
            $table->jsonb('data')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('dedupe_key', 255);
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'user_id', 'dedupe_key'], 'business_notifications_tenant_user_dedupe_unique');
            $table->index(['tenant_id', 'user_id', 'read_at']);
            $table->index(['tenant_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_notifications');
    }
};
