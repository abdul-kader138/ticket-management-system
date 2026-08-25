<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Closes the gap flagged in BookingService::confirmWithProvider() since
     * Phase 5: Duffel (and airline order APIs generally) require title,
     * gender, and contact details per passenger, none of which
     * traveler_profiles collected originally.
     */
    public function up(): void
    {
        Schema::table('traveler_profiles', function (Blueprint $table) {
            $table->string('title', 10)->nullable()->after('last_name');
            $table->string('gender', 1)->nullable()->after('title');
            $table->string('email')->nullable()->after('nationality');
            $table->string('phone')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('traveler_profiles', function (Blueprint $table) {
            $table->dropColumn(['title', 'gender', 'email', 'phone']);
        });
    }
};
