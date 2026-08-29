<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The bookings table already indexes (user_id, status) and
     * (status, expires_at) — neither helps App\Filament\Resources\
     * BookingResource's list, which sorts by created_at desc (optionally
     * filtered by status) with no user_id in the WHERE. Same reasoning as
     * 2026_08_25_240000 did for payments: at 50k users' worth of bookings
     * that ordering is a filesort over the whole table.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('created_at');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['status', 'created_at']);
        });
    }
};
