<?php

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Payment;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class PaymentServiceTest extends TestCase
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
    }

    private function heldBooking(): Booking
    {
        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);

        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1', 'total_amount' => '150.00', 'total_currency' => 'USD',
            'slices' => [], 'passengers' => [['id' => 'pas_0']],
        ];

        $traveler = TravelerProfile::create([
            'user_id' => $this->user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        return app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
    }

    public function test_initiate_creates_a_pending_payment_and_moves_the_booking_to_pending_payment(): void
    {
        $booking = $this->heldBooking();

        $result = app(PaymentService::class)->initiate($booking, 'fake');

        $this->assertSame(Payment::STATUS_PENDING, $result['payment']->status);
        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->fresh()->status);
        $this->assertNotNull($result['payment']->gateway_reference);
    }

    public function test_initiate_rejects_a_booking_that_is_not_held(): void
    {
        $booking = $this->heldBooking();
        $booking->transitionTo(Booking::STATUS_CANCELLED);

        $this->expectException(PaymentException::class);
        app(PaymentService::class)->initiate($booking, 'fake');
    }

    public function test_a_gateway_failure_during_initiate_reverts_the_booking_to_held(): void
    {
        $booking = $this->heldBooking();
        FakePaymentGateway::$shouldThrowOnCreateIntent = true;

        try {
            app(PaymentService::class)->initiate($booking, 'fake');
            $this->fail('Expected a PaymentException.');
        } catch (PaymentException) {
            // expected
        }

        $this->assertSame(Booking::STATUS_HELD, $booking->fresh()->status);
        $this->assertSame(Payment::STATUS_FAILED, Payment::first()->status);
    }

    public function test_a_succeeded_webhook_outcome_confirms_the_booking_and_issues_a_provider_order(): void
    {
        $booking = $this->heldBooking();
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];

        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->provider_order_id);
    }

    public function test_a_duplicate_succeeded_webhook_is_a_no_op(): void
    {
        $booking = $this->heldBooking();
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];

        $outcome = new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents);
        app(PaymentService::class)->applyWebhookOutcome($outcome, 'fake');
        app(PaymentService::class)->applyWebhookOutcome($outcome, 'fake'); // delivered twice

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
        $this->assertCount(1, $booking->fresh()->events()->where('event_type', Booking::STATUS_CONFIRMED)->get());
    }

    public function test_a_failed_webhook_outcome_returns_the_booking_to_held(): void
    {
        $booking = $this->heldBooking();
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];

        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_FAILED, $payment->gateway_reference),
            'fake',
        );

        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
        $this->assertSame(Booking::STATUS_HELD, $booking->fresh()->status);
    }

    public function test_refund_records_a_refund_and_marks_the_payment_refunded(): void
    {
        $booking = $this->heldBooking();
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );

        $refund = app(PaymentService::class)->refund($payment->fresh(), $payment->amount_cents, 'customer request');

        $this->assertSame('succeeded', $refund->status);
        $this->assertSame(Payment::STATUS_REFUNDED, $payment->fresh()->status);
        $this->assertSame(Booking::STATUS_REFUNDED, $booking->fresh()->status);
    }

    public function test_cannot_refund_a_payment_that_never_succeeded(): void
    {
        $booking = $this->heldBooking();
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];

        $this->expectException(PaymentException::class);
        app(PaymentService::class)->refund($payment, $payment->amount_cents);
    }

    public function test_reconcile_confirms_a_booking_whose_webhook_never_arrived(): void
    {
        $booking = $this->heldBooking();
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
        FakePaymentGateway::$retrieveStatusResult = 'succeeded';

        app(PaymentService::class)->reconcile($payment->fresh());

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    public function test_reconcile_leaves_a_still_pending_payment_alone(): void
    {
        $booking = $this->heldBooking();
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
        FakePaymentGateway::$retrieveStatusResult = 'pending';

        app(PaymentService::class)->reconcile($payment->fresh());

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }
}
