<?php

namespace Tests\Feature\Subscriptions;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Payments\FakePaymentGateway;
use Tests\Feature\Payments\FakePaymentGatewayManager;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', config('app.frontend_url'));
        FakePaymentGateway::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);
    }

    private function plan(): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'name' => 'Plus', 'code' => 'plus', 'price_cents' => 999, 'currency' => 'USD',
            'billing_interval' => 'month', 'daily_search_limit' => 50, 'is_active' => true,
        ]);
    }

    public function test_the_public_plans_endpoint_lists_active_plans_without_auth(): void
    {
        $this->plan();
        SubscriptionPlan::create([
            'name' => 'Hidden', 'code' => 'hidden', 'price_cents' => 500, 'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/subscription-plans')->assertOk();

        $this->assertCount(1, $response->json());
        $this->assertSame('Plus', $response->json()[0]['name']);
    }

    public function test_a_user_can_subscribe_and_the_plan_activates_on_payment_success(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/subscriptions', ['plan_id' => $plan->id, 'gateway' => 'stripe'])
            ->assertCreated();

        $subscriptionId = $response->json('subscription_id');
        $subscription = UserSubscription::findOrFail($subscriptionId);
        $this->assertSame(UserSubscription::STATUS_PENDING_PAYMENT, $subscription->status);

        $payment = $subscription->payments()->first();

        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'stripe',
        );

        $this->assertSame(UserSubscription::STATUS_ACTIVE, $subscription->fresh()->status);

        $current = $this->actingAs($user, 'web')->getJson('/api/v1/account/subscription')->assertOk();
        $this->assertSame('Plus', $current->json('plan.name'));
    }

    public function test_a_failed_subscription_payment_leaves_no_active_plan(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/subscriptions', ['plan_id' => $plan->id, 'gateway' => 'stripe'])
            ->assertCreated();

        $subscription = UserSubscription::findOrFail($response->json('subscription_id'));
        $payment = $subscription->payments()->first();

        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_FAILED, $payment->gateway_reference),
            'stripe',
        );

        $this->assertSame(UserSubscription::STATUS_FAILED, $subscription->fresh()->status);

        $current = $this->actingAs($user, 'web')->getJson('/api/v1/account/subscription')->assertOk();
        $this->assertNull($current->json('plan'));
    }

    public function test_subscribing_to_an_inactive_plan_returns_a_validation_error_not_a_404(): void
    {
        $user = User::factory()->create();
        $hidden = SubscriptionPlan::create([
            'name' => 'Hidden', 'code' => 'hidden', 'price_cents' => 500, 'is_active' => false,
        ]);

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/subscriptions', ['plan_id' => $hidden->id, 'gateway' => 'stripe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('plan_id');
    }

    public function test_a_user_cannot_start_a_second_subscription_while_one_is_already_pending(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/subscriptions', ['plan_id' => $plan->id, 'gateway' => 'stripe'])
            ->assertCreated();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/subscriptions', ['plan_id' => $plan->id, 'gateway' => 'stripe'])
            ->assertStatus(422)
            ->assertJson(['message' => 'You already have a subscription in progress or active.']);

        $this->assertSame(1, UserSubscription::where('user_id', $user->id)->count());
    }

    public function test_a_customer_can_cancel_their_active_subscription(): void
    {
        $user = User::factory()->create();
        $plan = $this->plan();

        $response = $this->actingAs($user, 'web')
            ->postJson('/api/v1/subscriptions', ['plan_id' => $plan->id, 'gateway' => 'stripe'])
            ->assertCreated();

        $subscription = UserSubscription::findOrFail($response->json('subscription_id'));
        $payment = $subscription->payments()->first();

        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'stripe',
        );

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/account/subscription/cancel')
            ->assertOk()
            ->assertJson(['message' => 'Subscription cancelled.']);

        $this->assertSame(UserSubscription::STATUS_CANCELLED, $subscription->fresh()->status);

        $current = $this->actingAs($user, 'web')->getJson('/api/v1/account/subscription')->assertOk();
        $this->assertNull($current->json('plan'));
    }

    public function test_cancelling_with_no_active_subscription_returns_a_clear_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/account/subscription/cancel')
            ->assertStatus(422)
            ->assertJson(['message' => 'You have no active subscription to cancel.']);
    }
}
