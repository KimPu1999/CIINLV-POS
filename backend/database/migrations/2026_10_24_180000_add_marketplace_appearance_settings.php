<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_settings', function (Blueprint $table): void {
            $table->jsonb('appearance_draft')->nullable();
            $table->jsonb('appearance_published')->nullable();
            $table->unsignedInteger('appearance_revision')->default(1);
            $table->timestampTz('appearance_published_at')->nullable();
            $table->foreignId('appearance_published_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_settings', function (Blueprint $table): void {
            $table->dropForeign(['appearance_published_by']);
            $table->dropColumn(['appearance_draft', 'appearance_published', 'appearance_revision', 'appearance_published_at', 'appearance_published_by']);
        });
    }
};
