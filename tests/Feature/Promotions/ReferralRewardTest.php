<?php

namespace Tests\Feature\Promotions;

use App\Models\FlightProvider;
use App\Models\Setting;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Flights\SearchQuotaService;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\Feature\Payments\FakePaymentGateway;
use Tests\Feature\Payments\FakePaymentGatewayManager;
use Tests\TestCase;

class ReferralRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', config('app.frontend_url'));
        FakeFlightProvider::reset();
        FakePaymentGateway::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1', 'total_amount' => '200.00', 'total_currency' => 'USD',
            'slices' => [], 'passengers' => [['id' => 'pas_0']],
        ];
    }

    public function test_registering_with_a_referral_code_links_the_referrer(): void
    {
        $referrer = User::factory()->create();

        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'New', 'last_name' => 'Customer', 'email' => 'newcustomer@example.com',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
            'referral_code' => $referrer->referralCode(),
        ])->assertCreated();

        $newUser = User::find($response->json('id'));
        $this->assertSame($referrer->id, $newUser->referrer_id);
    }

    public function test_an_invalid_referral_code_is_silently_ignored(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'first_name' => 'New', 'last_name' => 'Customer', 'email' => 'newcustomer2@example.com',
            'password' => 'Password123', 'password_confirmation' => 'Password123',
            'referral_code' => 'NOTREAL',
        ])->assertCreated();

        $this->assertNull(User::find($response->json('id'))->referrer_id);
    }

    public function test_the_referrer_is_rewarded_when_the_referred_users_first_booking_confirms(): void
    {
        Setting::set('referral_reward_bonus_searches', 15);

        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referrer_id' => $referrer->id]);

        $traveler = TravelerProfile::create([
            'user_id' => $referred->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        $booking = app(BookingService::class)->createHold(
            $referred, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];

        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );

        $quota = app(SearchQuotaService::class);
        $this->assertSame(-15, $quota->used($referrer, 'month'));
    }

    public function test_a_second_confirmed_booking_does_not_reward_the_referrer_again(): void
    {
        Setting::set('referral_reward_bonus_searches', 15);

        $referrer = User::factory()->create();
        $referred = User::factory()->create(['referrer_id' => $referrer->id]);

        for ($i = 0; $i < 2; $i++) {
            $traveler = TravelerProfile::create([
                'user_id' => $referred->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
            ]);
            $booking = app(BookingService::class)->createHold(
                $referred, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
            );
            $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
            app(PaymentService::class)->applyWebhookOutcome(
                new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
                'fake',
            );
        }

        // Still -15, not -30 — only the first confirmed booking rewards.
        $this->assertSame(-15, app(SearchQuotaService::class)->used($referrer, 'month'));
    }
}
