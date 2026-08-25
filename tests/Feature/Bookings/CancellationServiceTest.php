<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Payment;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingException;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\CancellationService;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\Feature\Payments\FakePaymentGateway;
use Tests\Feature\Payments\FakePaymentGatewayManager;
use Tests\TestCase;

class CancellationServiceTest extends TestCase
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

    private function heldBooking(): Booking
    {
        $traveler = TravelerProfile::create([
            'user_id' => $this->user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        return app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
    }

    private function confirmedBooking(): Booking
    {
        $booking = $this->heldBooking();
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];

        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(
                WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents
            ),
            'fake',
        );

        return $booking->fresh();
    }

    public function test_cancelling_a_held_booking_needs_no_refund(): void
    {
        $booking = $this->heldBooking();

        app(CancellationService::class)->cancel($booking, 'user', $this->user->id, 'changed my mind');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
    }

    public function test_cancelling_a_confirmed_booking_refunds_the_full_amount(): void
    {
        $booking = $this->confirmedBooking();
        FakeFlightProvider::$cancelResult = ['confirmed' => true, 'refund_amount' => '200.00', 'refund_currency' => 'USD'];

        app(CancellationService::class)->cancel($booking, 'user', $this->user->id, 'flight no longer needed');

        $this->assertSame(Booking::STATUS_REFUNDED, $booking->fresh()->status);
        $this->assertSame(Payment::STATUS_REFUNDED, $booking->fresh()->payments()->latest()->first()->status);
    }

    public function test_cancelling_a_non_refundable_confirmed_booking_takes_no_refund(): void
    {
        $booking = $this->confirmedBooking();
        FakeFlightProvider::$cancelResult = ['confirmed' => true, 'refund_amount' => null, 'refund_currency' => null];

        app(CancellationService::class)->cancel($booking, 'user', $this->user->id, 'non-refundable fare');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $booking->fresh()->payments()->latest()->first()->status);
    }

    public function test_cancelling_a_partially_refundable_booking_records_a_partial_refund(): void
    {
        $booking = $this->confirmedBooking();
        FakeFlightProvider::$cancelResult = ['confirmed' => true, 'refund_amount' => '50.00', 'refund_currency' => 'USD'];

        app(CancellationService::class)->cancel($booking, 'user', $this->user->id, 'change fee applies');

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertSame(Payment::STATUS_PARTIALLY_REFUNDED, $booking->fresh()->payments()->latest()->first()->status);
    }

    public function test_cannot_cancel_an_already_cancelled_booking(): void
    {
        $booking = $this->heldBooking();
        app(CancellationService::class)->cancel($booking, 'user', $this->user->id, 'first cancel');

        $this->expectException(BookingException::class);
        app(CancellationService::class)->cancel($booking->fresh(), 'user', $this->user->id, 'second cancel');
    }

    public function test_cannot_cancel_while_payment_is_pending(): void
    {
        $booking = $this->heldBooking();
        app(PaymentService::class)->initiate($booking, 'fake');

        $this->expectException(BookingException::class);
        app(CancellationService::class)->cancel($booking->fresh(), 'user', $this->user->id, 'reason');
    }
}
