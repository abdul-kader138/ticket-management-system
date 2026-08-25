<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The existing (user_id, status) index only serves queries that filter
     * by user_id (a customer's own payment history). App\Filament\Resources\
     * PaymentResource's admin table filters by status or gateway with no
     * user_id in the WHERE clause at all — a leftmost-prefix index can't
     * serve either of those, so at 50k users' worth of payment rows they'd
     * fall back to a full table scan.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index('status');
            $table->index('gateway');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['gateway']);
        });
    }
};
