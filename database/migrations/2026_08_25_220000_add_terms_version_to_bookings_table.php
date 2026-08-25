<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Snapshotted at hold-creation time from the
            // 'current_terms_version' Setting — see docs/ROADMAP.md, Phase
            // 10 and BookingService::createHold(). A later change to the
            // admin-configured terms text must not retroactively change
            // which policy applied to an existing booking.
            $table->string('terms_version', 30)->nullable()->after('cabin_class');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('terms_version');
        });
    }
};
