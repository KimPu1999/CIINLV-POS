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
            $table->string('business_code', 50)->nullable()->after('slug');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('created_by')->nullable()->after('subscription_status')->constrained('users')->nullOnDelete();
        });

        foreach (DB::table('tenants')->whereNull('business_code')->get(['id', 'slug']) as $tenant) {
            $base = Str::upper(Str::of($tenant->slug)->replace('-', '_')->substr(0, 38));
            DB::table('tenants')->where('id', $tenant->id)->update([
                'business_code' => $base.'_'.$tenant->id,
            ]);
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->unique('business_code');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['business_code']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['business_code', 'email', 'phone', 'address']);
        });
    }
};
