<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE marketplace_payments DROP CONSTRAINT IF EXISTS marketplace_payments_method_check');
        DB::statement("ALTER TABLE marketplace_payments ADD CONSTRAINT marketplace_payments_method_check CHECK (method IN ('cod', 'card', 'qr', 'bank_transfer'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE marketplace_payments DROP CONSTRAINT IF EXISTS marketplace_payments_method_check');
        DB::statement("ALTER TABLE marketplace_payments ADD CONSTRAINT marketplace_payments_method_check CHECK (method IN ('cod', 'online'))");
    }
};
