<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('translatable_type', 160);
            $table->unsignedBigInteger('translatable_id');
            $table->string('locale', 10);
            $table->string('source_locale', 10);
            $table->string('field', 80);
            $table->text('value')->nullable();
            $table->string('source_value_hash', 64)->nullable();
            $table->string('state', 20)->default('pending');
            $table->foreignId('translated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'translatable_type', 'translatable_id', 'locale', 'field'],
                'translations_unique_record_locale_field'
            );
            $table->index(['tenant_id', 'locale', 'state'], 'translations_tenant_locale_state_index');
            $table->index(['translatable_type', 'translatable_id'], 'translations_translatable_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
