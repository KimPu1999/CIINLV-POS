<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->string('status')->default('active')->index();
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        // Future tenant isolation: enable RLS and add a tenant_id policy here
        // once the request-scoped tenant context and DB role strategy are defined.
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
