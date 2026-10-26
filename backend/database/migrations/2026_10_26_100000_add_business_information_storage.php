<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_settings', function (Blueprint $table): void {
            // Encrypted Laravel casts are stored as text, never as public JSON.
            $table->text('settlement_configuration')->nullable()->after('data_configuration');
        });

        Schema::create('business_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 60);
            $table->string('original_name', 255);
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['tenant_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_documents');
        Schema::table('business_settings', function (Blueprint $table): void {
            $table->dropColumn('settlement_configuration');
        });
    }
};
