<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Payment;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingChangeService;
use App\Services\Bookings\BookingException;
use App\Services\Bookings\BookingService;
use App\Services\Flights\DTO\SearchCriteria;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\Feature\Payments\FakePaymentGateway;
use Tests\Feature\Payments\FakePaymentGatewayManager;
use Tests\TestCase;

class BookingChangeServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        FakeFlightProvider::reset();
        FakePaymentGateway::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);

        $this->user = User::factory()->create();

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1', 'total_amount' => '200.00', 'total_currency' => 'USD',
            'slices' => [], 'passengers' => [['id' => 'pas_0']],
        ];
    }

    private function confirmedBooking(): Booking
    {
        $traveler = TravelerProfile::create([
            'user_id' => $this->user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );

        return $booking->fresh();
    }

    public function test_search_offers_returns_the_providers_change_offers(): void
    {
        $booking = $this->confirmedBooking();
        FakeFlightProvider::$changeOffersToReturn = [['id' => 'chg_1'], ['id' => 'chg_2']];

        $offers = app(BookingChangeService::class)->searchOffers($booking, new SearchCriteria(
            slices: [['origin' => 'LHR', 'destination' => 'CDG', 'departure_date' => '2027-02-01']],
            adults: 1,
        ));

        $this->assertCount(2, $offers);
    }

    public function test_cannot_search_change_offers_for_a_non_confirmed_booking(): void
    {
        $traveler = TravelerProfile::create([
            'user_id' => $this->user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        $held = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        $this->expectException(BookingException::class);
        app(BookingChangeService::class)->searchOffers($held, new SearchCriteria(slices: [], adults: 1));
    }

    public function test_a_more_expensive_change_charges_the_fare_difference(): void
    {
        $booking = $this->confirmedBooking();
        FakeFlightProvider::$confirmChangeOfferResult = ['new_total_amount' => '250.00', 'currency' => 'USD', 'raw' => ['slices' => []]];

        $result = app(BookingChangeService::class)->applyChange($booking, 'chg_1', $this->user, 'fake');

        $this->assertSame(Booking::STATUS_CHANGED, $booking->fresh()->status);
        $this->assertSame(25000, $booking->fresh()->total_price_cents);
        $this->assertNotNull($result['payment']);
        $this->assertSame(5000, $result['payment']->amount_cents);
        $this->assertSame(Payment::STATUS_PENDING, $result['payment']->status);
    }

    public function test_a_cheaper_change_applies_with_no_charge(): void
    {
        $booking = $this->confirmedBooking();
        FakeFlightProvider::$confirmChangeOfferResult = ['new_total_amount' => '150.00', 'currency' => 'USD', 'raw' => ['slices' => []]];

        $result = app(BookingChangeService::class)->applyChange($booking, 'chg_1', $this->user, 'fake');

        $this->assertSame(Booking::STATUS_CHANGED, $booking->fresh()->status);
        $this->assertSame(15000, $booking->fresh()->total_price_cents);
        $this->assertNull($result['payment']);
    }

    public function test_cannot_apply_a_change_to_a_non_confirmed_booking(): void
    {
        $traveler = TravelerProfile::create([
            'user_id' => $this->user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        $held = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        $this->expectException(BookingException::class);
        app(BookingChangeService::class)->applyChange($held, 'chg_1', $this->user, 'fake');
    }
}
