<?php

namespace Tests\Feature\Flights;

use App\Models\Setting;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Flights\SearchQuotaExceededException;
use App\Services\Flights\SearchQuotaService;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchQuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_apply_when_no_setting_is_configured(): void
    {
        $user = User::factory()->create();
        $quota = app(SearchQuotaService::class);

        $this->assertSame(10, $quota->limitFor($user, 'day'));
        $this->assertSame(300, $quota->limitFor($user, 'month'));
    }

    public function test_admin_configured_limits_are_respected(): void
    {
        Setting::set('default_daily_search_limit', 5);
        Setting::set('default_monthly_search_limit', 50);

        $user = User::factory()->create();
        $quota = app(SearchQuotaService::class);

        $this->assertSame(5, $quota->limitFor($user, 'day'));
        $this->assertSame(50, $quota->limitFor($user, 'month'));
    }

    public function test_consume_increments_usage_and_writes_an_audit_row(): void
    {
        $user = User::factory()->create();
        $quota = app(SearchQuotaService::class);

        $quota->consume($user);
        $quota->consume($user);

        $this->assertSame(2, $quota->used($user, 'day'));
        $this->assertSame(2, $quota->used($user, 'month'));

        $this->assertDatabaseHas('search_quota_usage', [
            'user_id' => $user->id,
            'period_type' => 'day',
            'used_count' => 2,
        ]);
    }

    public function test_ensure_not_exceeded_throws_once_the_limit_is_reached(): void
    {
        Setting::set('default_daily_search_limit', 1);
        $user = User::factory()->create();
        $quota = app(SearchQuotaService::class);

        $quota->ensureNotExceeded($user); // 0 used, limit 1 — fine
        $quota->consume($user);

        $this->expectException(SearchQuotaExceededException::class);
        $quota->ensureNotExceeded($user);
    }

    public function test_remaining_reflects_usage_against_the_limit(): void
    {
        Setting::set('default_daily_search_limit', 3);
        $user = User::factory()->create();
        $quota = app(SearchQuotaService::class);

        $quota->consume($user);

        $this->assertSame(2, $quota->remaining($user)['day']);
    }

    public function test_a_purchased_plan_raises_the_limit_above_the_default(): void
    {
        Setting::set('default_daily_search_limit', 10);

        $plan = SubscriptionPlan::create([
            'name' => 'Plus', 'code' => 'plus', 'price_cents' => 999, 'currency' => 'USD',
            'billing_interval' => 'month', 'daily_search_limit' => 100, 'is_active' => true,
        ]);
        $user = User::factory()->create();
        $subscriptions = app(SubscriptionService::class);
        $subscription = $subscriptions->createPendingSubscription($user, $plan);
        $subscriptions->activate($subscription->fresh());

        $this->assertSame(100, app(SearchQuotaService::class)->limitFor($user, 'day'));
    }

    public function test_an_unlimited_plan_disables_enforcement_for_that_period(): void
    {
        Setting::set('default_daily_search_limit', 1);

        $plan = SubscriptionPlan::create([
            'name' => 'Elite', 'code' => 'elite', 'price_cents' => 4999, 'currency' => 'USD',
            'billing_interval' => 'month', 'daily_search_limit' => SubscriptionPlan::UNLIMITED, 'is_active' => true,
        ]);
        $user = User::factory()->create();
        $subscriptions = app(SubscriptionService::class);
        $subscription = $subscriptions->createPendingSubscription($user, $plan);
        $subscriptions->activate($subscription->fresh());

        $quota = app(SearchQuotaService::class);
        $quota->consume($user);
        $quota->consume($user);

        // Would already have exceeded the default limit of 1 — unlimited
        // should mean ensureNotExceeded() never throws for this period.
        $quota->ensureNotExceeded($user);
        $this->assertTrue($quota->isUnlimited($user, 'day'));
        $this->assertSame(SubscriptionPlan::UNLIMITED, $quota->remaining($user)['day']);
    }
}
