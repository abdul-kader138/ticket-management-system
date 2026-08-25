<?php

namespace Tests\Feature\Subscriptions;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTierRule;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private function plan(array $overrides = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'name' => 'Plus', 'code' => 'plus-'.uniqid(), 'price_cents' => 999, 'currency' => 'USD',
            'billing_interval' => 'month', 'daily_search_limit' => 50, 'monthly_search_limit' => 1000,
            'benefits' => ['fee_free_changes' => true], 'is_active' => true,
        ], $overrides));
    }

    public function test_a_user_with_no_subscription_has_no_override(): void
    {
        $user = User::factory()->create();
        $service = app(SubscriptionService::class);

        $this->assertNull($service->activePlan($user));
        $this->assertNull($service->searchLimit($user, 'day'));
        $this->assertFalse($service->hasBenefit($user, 'fee_free_changes'));
    }

    public function test_activate_makes_a_pending_subscription_active_with_an_end_date(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $service = app(SubscriptionService::class);

        $subscription = $service->createPendingSubscription($user, $plan);
        $this->assertSame(UserSubscription::STATUS_PENDING_PAYMENT, $subscription->status);
        $this->assertNull($service->activePlan($user));

        $service->activate($subscription->fresh());

        $this->assertSame($plan->id, $service->activePlan($user)->id);
        $this->assertTrue($subscription->fresh()->ends_at->isFuture());
    }

    public function test_a_yearly_plan_activates_with_a_one_year_end_date(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan(['billing_interval' => 'year']);
        $service = app(SubscriptionService::class);

        $subscription = $service->createPendingSubscription($user, $plan);
        $service->activate($subscription->fresh());

        $this->assertTrue($subscription->fresh()->ends_at->greaterThan(now()->addMonths(11)));
    }

    public function test_mark_failed_leaves_the_user_with_no_active_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $service = app(SubscriptionService::class);

        $subscription = $service->createPendingSubscription($user, $plan);
        $service->markFailed($subscription->fresh());

        $this->assertSame(UserSubscription::STATUS_FAILED, $subscription->fresh()->status);
        $this->assertNull($service->activePlan($user));
    }

    public function test_an_expired_subscription_no_longer_counts_as_active(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();
        $service = app(SubscriptionService::class);

        $subscription = $service->createPendingSubscription($user, $plan);
        $service->activate($subscription->fresh());
        $subscription->fresh()->forceFill(['ends_at' => now()->subDay()])->save();

        $this->assertNull($service->activePlan($user));
    }

    public function test_a_tier_rule_grants_benefits_based_on_spend_without_any_purchase(): void
    {
        $goldPlan = $this->plan(['name' => 'Gold', 'daily_search_limit' => 100]);
        SubscriptionTierRule::create([
            'name' => 'Gold Tier', 'subscription_plan_id' => $goldPlan->id,
            'min_total_spend_cents' => 100000, 'min_account_age_days' => 0, 'priority' => 10, 'is_active' => true,
        ]);

        $user = User::factory()->create(['total_spend_cents' => 150000]);
        $service = app(SubscriptionService::class);

        $this->assertSame($goldPlan->id, $service->matchedTierRule($user)->subscriptionPlan->id);
        $this->assertSame(100, $service->searchLimit($user, 'day'));
    }

    public function test_a_tier_rule_does_not_apply_below_the_spend_threshold(): void
    {
        $goldPlan = $this->plan(['name' => 'Gold']);
        SubscriptionTierRule::create([
            'name' => 'Gold Tier', 'subscription_plan_id' => $goldPlan->id,
            'min_total_spend_cents' => 100000, 'min_account_age_days' => 0, 'priority' => 10, 'is_active' => true,
        ]);

        $user = User::factory()->create(['total_spend_cents' => 5000]);
        $service = app(SubscriptionService::class);

        $this->assertNull($service->matchedTierRule($user));
    }

    public function test_highest_priority_tier_wins_when_multiple_qualify(): void
    {
        $silver = $this->plan(['name' => 'Silver', 'daily_search_limit' => 30]);
        $gold = $this->plan(['name' => 'Gold', 'daily_search_limit' => 100]);

        SubscriptionTierRule::create([
            'name' => 'Silver Tier', 'subscription_plan_id' => $silver->id,
            'min_total_spend_cents' => 10000, 'min_account_age_days' => 0, 'priority' => 5, 'is_active' => true,
        ]);
        SubscriptionTierRule::create([
            'name' => 'Gold Tier', 'subscription_plan_id' => $gold->id,
            'min_total_spend_cents' => 10000, 'min_account_age_days' => 0, 'priority' => 10, 'is_active' => true,
        ]);

        $user = User::factory()->create(['total_spend_cents' => 200000]);
        $service = app(SubscriptionService::class);

        $this->assertSame('Gold Tier', $service->matchedTierRule($user)->name);
    }

    public function test_purchased_plan_and_tier_take_the_higher_limit_per_benefit(): void
    {
        $purchasedPlan = $this->plan(['name' => 'Plus', 'daily_search_limit' => 50]);
        $tierPlan = $this->plan(['name' => 'Gold', 'daily_search_limit' => 30]);

        SubscriptionTierRule::create([
            'name' => 'Gold Tier', 'subscription_plan_id' => $tierPlan->id,
            'min_total_spend_cents' => 0, 'min_account_age_days' => 0, 'priority' => 10, 'is_active' => true,
        ]);

        $user = User::factory()->create();
        $service = app(SubscriptionService::class);
        $subscription = $service->createPendingSubscription($user, $purchasedPlan);
        $service->activate($subscription->fresh());

        // Purchased plan's 50 beats the tier's 30.
        $this->assertSame(50, $service->searchLimit($user, 'day'));
    }

    public function test_an_unlimited_plan_wins_over_any_finite_limit(): void
    {
        $purchasedPlan = $this->plan(['name' => 'Plus', 'daily_search_limit' => 50]);
        $unlimitedTierPlan = $this->plan(['name' => 'Elite', 'daily_search_limit' => SubscriptionPlan::UNLIMITED]);

        SubscriptionTierRule::create([
            'name' => 'Elite Tier', 'subscription_plan_id' => $unlimitedTierPlan->id,
            'min_total_spend_cents' => 0, 'min_account_age_days' => 0, 'priority' => 10, 'is_active' => true,
        ]);

        $user = User::factory()->create();
        $service = app(SubscriptionService::class);
        $subscription = $service->createPendingSubscription($user, $purchasedPlan);
        $service->activate($subscription->fresh());

        $this->assertSame(SubscriptionPlan::UNLIMITED, $service->searchLimit($user, 'day'));
    }
}
