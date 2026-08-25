<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
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

class BookingCancelChangeApiTest extends TestCase
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

    private function heldBookingFor(User $user): Booking
    {
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        return app(BookingService::class)->createHold($user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]);
    }

    private function confirmedBookingFor(User $user): Booking
    {
        $booking = $this->heldBookingFor($user);
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );

        return $booking->fresh();
    }

    public function test_a_user_can_cancel_their_own_held_booking(): void
    {
        $user = User::factory()->create();
        $booking = $this->heldBookingFor($user);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel", ['reason' => 'changed plans'])
            ->assertOk()
            ->assertJsonPath('status', Booking::STATUS_CANCELLED);
    }

    public function test_a_user_cannot_cancel_someone_elses_booking(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $booking = $this->heldBookingFor($owner);

        $this->actingAs($intruder, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/cancel")
            ->assertForbidden();
    }

    public function test_a_user_can_search_and_apply_a_change_on_their_confirmed_booking(): void
    {
        $user = User::factory()->create();
        $booking = $this->confirmedBookingFor($user);
        FakeFlightProvider::$changeOffersToReturn = [['id' => 'chg_1']];

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/change/search", [
                'legs' => [['from' => 'London (LHR)', 'to' => 'Paris (CDG)', 'date' => '2027-03-01']],
                'adults' => 1,
            ])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        FakeFlightProvider::$confirmChangeOfferResult = ['new_total_amount' => '250.00', 'currency' => 'USD', 'raw' => ['slices' => []]];

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/change", ['change_offer_id' => 'chg_1', 'gateway' => 'stripe'])
            ->assertCreated();

        $this->assertSame(Booking::STATUS_CHANGED, $booking->fresh()->status);
    }

    public function test_a_user_cannot_change_someone_elses_booking(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $booking = $this->confirmedBookingFor($owner);

        $this->actingAs($intruder, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/change", ['change_offer_id' => 'chg_1', 'gateway' => 'stripe'])
            ->assertForbidden();
    }

    public function test_change_search_rejects_an_unrecognized_airport(): void
    {
        $user = User::factory()->create();
        $booking = $this->confirmedBookingFor($user);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/change/search", [
                'legs' => [['from' => 'not an airport', 'to' => 'Paris (CDG)', 'date' => '2027-03-01']],
                'adults' => 1,
            ])
            ->assertStatus(422);
    }
}
