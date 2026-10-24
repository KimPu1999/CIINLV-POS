<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_contact_inquiries', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('email');
            $table->string('phone', 50)->nullable();
            $table->string('subject', 80);
            $table->text('message');
            $table->string('status', 20)->default('pending')->index();
            $table->timestampsTz();
            $table->index(['email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_contact_inquiries');
    }
};
