<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 80);
            $table->string('question', 255);
            $table->text('answer');
            $table->string('locale', 5);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('display_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->index(['locale', 'status', 'category', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_entries');
    }
};
