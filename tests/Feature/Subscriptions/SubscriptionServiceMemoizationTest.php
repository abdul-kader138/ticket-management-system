<?php

namespace Tests\Feature\Subscriptions;

use App\Models\SubscriptionPlan;
use App\Models\SubscriptionTierRule;
use App\Models\User;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks in the query-count reduction from SubscriptionService's per-instance
 * memoization — a regression here (e.g. someone "simplifying" activePlan()
 * back to a plain query) would silently reintroduce a dozen-plus redundant
 * queries per search request without any functional test failing to catch it.
 */
class SubscriptionServiceMemoizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_lookups_for_the_same_user_only_query_once_each(): void
    {
        $plan = SubscriptionPlan::create([
            'name' => 'Plus', 'code' => 'plus', 'price_cents' => 999, 'currency' => 'USD',
            'billing_interval' => 'month', 'daily_search_limit' => 50, 'is_active' => true,
        ]);
        $tierPlan = SubscriptionPlan::create([
            'name' => 'Gold', 'code' => 'gold', 'price_cents' => 0, 'currency' => 'USD',
            'billing_interval' => 'month', 'daily_search_limit' => 100, 'is_active' => true,
        ]);
        SubscriptionTierRule::create([
            'name' => 'Gold Tier', 'subscription_plan_id' => $tierPlan->id,
            'min_total_spend_cents' => 0, 'min_account_age_days' => 0, 'priority' => 10, 'is_active' => true,
        ]);

        $user = User::factory()->create();
        $service = app(SubscriptionService::class);
        $subscription = $service->createPendingSubscription($user, $plan);
        $service->activate($subscription->fresh());

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Mirrors a single search request's actual call pattern —
        // SearchQuotaService::ensureNotExceeded()/consume()/remaining() each
        // call limitFor() per period, and limitFor() calls searchLimit().
        for ($i = 0; $i < 6; $i++) {
            $service->searchLimit($user, 'day');
            $service->searchLimit($user, 'month');
        }

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // 4 queries total for the *first* searchLimit() call (subscription
        // + its plan, tier rule + its plan) and zero more for the other 11
        // calls in the loop above — both this cache and Eloquent's own
        // per-model relation cache serve the rest from memory. Without
        // memoization this would scale linearly with the loop (48+ queries).
        $this->assertLessThanOrEqual(4, $queryCount);
    }
}
