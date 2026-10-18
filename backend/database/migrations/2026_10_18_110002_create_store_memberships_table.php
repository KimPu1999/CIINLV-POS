<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_membership_id')->constrained('tenant_memberships')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('active')->index();
            $table->timestampsTz();
            $table->unique(['tenant_membership_id', 'store_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_memberships');
    }
};
