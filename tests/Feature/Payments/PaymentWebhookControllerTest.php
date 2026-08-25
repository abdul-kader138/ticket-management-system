<?php

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class PaymentWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeFlightProvider::reset();
        FakePaymentGateway::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);
    }

    private function pendingPayment(): Payment
    {
        $user = User::factory()->create();

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1', 'total_amount' => '99.00', 'total_currency' => 'USD',
            'slices' => [], 'passengers' => [['id' => 'pas_0']],
        ];

        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        $booking = app(BookingService::class)->createHold(
            $user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        return app(PaymentService::class)->initiate($booking, 'fake')['payment'];
    }

    public function test_a_valid_webhook_confirms_the_booking(): void
    {
        $payment = $this->pendingPayment();

        $this->postJson('/api/v1/webhooks/payments/fake', [
            'id' => 'evt_1',
            'outcome_type' => WebhookOutcome::PAYMENT_SUCCEEDED,
            'gateway_reference' => $payment->gateway_reference,
            'amount_cents' => $payment->amount_cents,
        ])->assertOk();

        $this->assertSame(Payment::STATUS_SUCCEEDED, $payment->fresh()->status);
        $this->assertSame(Booking::STATUS_CONFIRMED, $payment->fresh()->payable->status);
        $this->assertNotNull(PaymentWebhookEvent::where('event_id', 'evt_1')->first()->processed_at);
    }

    public function test_an_invalid_signature_is_rejected_before_any_processing(): void
    {
        $payment = $this->pendingPayment();
        FakePaymentGateway::$signatureValid = false;

        $this->postJson('/api/v1/webhooks/payments/fake', [
            'id' => 'evt_2',
            'outcome_type' => WebhookOutcome::PAYMENT_SUCCEEDED,
            'gateway_reference' => $payment->gateway_reference,
        ])->assertStatus(400);

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertDatabaseMissing('payment_webhook_events', ['event_id' => 'evt_2']);
    }

    public function test_the_same_event_delivered_twice_is_only_processed_once(): void
    {
        $payment = $this->pendingPayment();
        $body = [
            'id' => 'evt_3',
            'outcome_type' => WebhookOutcome::PAYMENT_SUCCEEDED,
            'gateway_reference' => $payment->gateway_reference,
            'amount_cents' => $payment->amount_cents,
        ];

        $this->postJson('/api/v1/webhooks/payments/fake', $body)->assertOk();
        $this->postJson('/api/v1/webhooks/payments/fake', $body)->assertOk();

        $this->assertSame(1, PaymentWebhookEvent::where('event_id', 'evt_3')->count());
        $this->assertCount(1, $payment->fresh()->payable->events()->where('event_type', Booking::STATUS_CONFIRMED)->get());
    }
}
