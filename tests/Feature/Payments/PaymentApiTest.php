<?php

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Payment;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', config('app.frontend_url'));
        FakeFlightProvider::reset();
        FakePaymentGateway::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);
    }

    private function heldBookingFor(User $user): Booking
    {
        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = ['id' => 'off_1', 'total_amount' => '75.00', 'total_currency' => 'USD', 'slices' => []];

        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        return app(BookingService::class)->createHold($user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]);
    }

    public function test_a_user_can_initiate_payment_on_their_own_booking(): void
    {
        $user = User::factory()->create();
        $booking = $this->heldBookingFor($user);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/payments", ['gateway' => 'stripe'])
            ->assertCreated()
            ->assertJsonPath('gateway', 'stripe');
    }

    public function test_a_user_cannot_initiate_payment_on_someone_elses_booking(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $booking = $this->heldBookingFor($owner);

        $this->actingAs($intruder, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/payments", ['gateway' => 'stripe'])
            ->assertForbidden();
    }

    public function test_capture_paypal_is_rejected_for_a_stripe_payment(): void
    {
        $user = User::factory()->create();
        $booking = $this->heldBookingFor($user);

        $payment = app(PaymentService::class)->initiate($booking, Payment::GATEWAY_STRIPE)['payment'];

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/payments/{$payment->id}/capture-paypal")
            ->assertStatus(422);
    }
}
