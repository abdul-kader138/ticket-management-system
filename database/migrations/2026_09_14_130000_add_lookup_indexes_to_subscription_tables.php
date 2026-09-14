<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SubscriptionService::matchedTierRule() filters subscription_tier_rules by
 * is_active and orders by priority on every quota-affecting request;
 * activePlan() filters user_subscriptions by user_id/status and range-scans
 * both starts_at and ends_at. Neither existing index matches these shapes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_tier_rules', function (Blueprint $table) {
            $table->index(['is_active', 'priority']);
        });

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->index(['user_id', 'status', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_tier_rules', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'priority']);
        });

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'starts_at', 'ends_at']);
        });
    }
};
