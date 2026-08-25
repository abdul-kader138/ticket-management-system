<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Lifetime confirmed-booking spend — maintained by
            // App\Observers\BookingObserver, not editable directly. Drives
            // automatic tier assignment (see subscription_tier_rules and
            // App\Services\Subscriptions\SubscriptionService). Never
            // decremented on a refund — see docs/ROADMAP.md, Phase 7.
            $table->unsignedBigInteger('total_spend_cents')->default(0)->after('marketing_opt_in');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('total_spend_cents');
        });
    }
};
