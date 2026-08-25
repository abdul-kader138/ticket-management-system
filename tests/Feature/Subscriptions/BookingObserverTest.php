<?php

namespace Tests\Feature\Subscriptions;

use App\Models\FlightProvider;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\Feature\Payments\FakePaymentGateway;
use Tests\Feature\Payments\FakePaymentGatewayManager;
use Tests\TestCase;

class BookingObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_a_booking_increments_the_users_total_spend(): void
    {
        FakeFlightProvider::reset();
        FakePaymentGateway::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1', 'total_amount' => '325.50', 'total_currency' => 'USD',
            'slices' => [], 'passengers' => [['id' => 'pas_0']],
        ];

        $user = User::factory()->create();
        $this->assertSame(0, $user->total_spend_cents);

        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        $booking = app(BookingService::class)->createHold(
            $user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];

        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );

        $this->assertSame(32550, $user->fresh()->total_spend_cents);
    }

    public function test_a_held_booking_that_never_confirms_does_not_add_spend(): void
    {
        FakeFlightProvider::reset();

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = ['id' => 'off_1', 'total_amount' => '100.00', 'total_currency' => 'USD', 'slices' => []];

        $user = User::factory()->create();
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        app(BookingService::class)->createHold($user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]);

        $this->assertSame(0, $user->fresh()->total_spend_cents);
    }
}
