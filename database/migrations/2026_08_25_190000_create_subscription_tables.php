<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->unsignedInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            $table->string('billing_interval', 10)->default('month');
            // -1 means unlimited (see App\Services\Flights\SearchQuotaService);
            // null means "this plan doesn't override the default at all."
            $table->integer('daily_search_limit')->nullable();
            $table->integer('monthly_search_limit')->nullable();
            // Flat boolean perks (e.g. {"fee_free_changes": true,
            // "priority_support": true}) — see SubscriptionService::hasBenefit().
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscription_tier_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The tier grants whatever this plan defines — a tier is just
            // "earn this plan's benefits for free once you qualify,"
            // rather than duplicating limit/benefit fields.
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('min_total_spend_cents')->default(0);
            $table->unsignedInteger('min_account_age_days')->default(0);
            // Higher runs first when more than one rule's thresholds are
            // met — see SubscriptionService::matchedTierRule().
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('source', 10)->default('purchased');
            // pending_payment -> active -> expired, or -> failed/cancelled.
            $table->string('status', 20)->default('pending_payment');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            // Always false today — there's no recurring-billing integration
            // yet (see docs/ROADMAP.md, Phase 7's known gap: this reuses
            // the one-time payment rails from Phase 5, not Stripe/PayPal's
            // native Subscription objects, so renewal is not automatic).
            $table->boolean('auto_renew')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
        Schema::dropIfExists('subscription_tier_rules');
        Schema::dropIfExists('subscription_plans');
    }
};
