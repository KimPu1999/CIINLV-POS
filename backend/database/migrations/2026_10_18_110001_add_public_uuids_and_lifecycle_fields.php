<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
            $table->string('subscription_status')->default('trial')->after('status')->index();
            $table->timestampTz('suspended_at')->nullable();
            $table->text('suspension_reason')->nullable();
        });

        foreach (DB::table('tenants')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('tenants')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->unique('uuid');
        });

        Schema::table('stores', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');
        });

        foreach (DB::table('stores')->whereNull('uuid')->pluck('id') as $id) {
            DB::table('stores')->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
        }

        Schema::table('stores', function (Blueprint $table): void {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['uuid']);
            $table->dropColumn(['uuid', 'subscription_status', 'suspended_at', 'suspension_reason']);
        });
    }
};
