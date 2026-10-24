<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 80);
            $table->string('version', 40);
            $table->string('locale', 5);
            $table->string('title', 255);
            $table->text('content');
            $table->string('status', 20)->default('draft');
            $table->timestampTz('effective_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->unique(['type', 'version', 'locale']);
            $table->index(['type', 'locale', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_documents');
    }
};
