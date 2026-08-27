<?php

namespace Tests\Feature\Payments;

use App\Jobs\IssueProviderOrderJob;
use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class IssueProviderOrderJobTest extends TestCase
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

    private function pendingPaymentBooking(): Booking
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

        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        app(PaymentService::class)->initiate($booking, 'fake');

        return $booking->fresh();
    }

    public function test_a_succeeded_payment_queues_issuance_rather_than_running_it_inline(): void
    {
        Queue::fake();

        $booking = $this->pendingPaymentBooking();
        $payment = $booking->payments()->first();

        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );

        Queue::assertPushed(IssueProviderOrderJob::class, fn (IssueProviderOrderJob $job) => $job->bookingId === $booking->id);
        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->fresh()->status);
    }

    public function test_handle_confirms_the_booking_with_the_provider(): void
    {
        $booking = $this->pendingPaymentBooking();

        (new IssueProviderOrderJob($booking->id))->handle(app(BookingService::class));

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
        $this->assertNotNull($booking->fresh()->provider_order_id);
    }

    public function test_handle_is_a_no_op_when_the_booking_is_no_longer_pending_payment(): void
    {
        $booking = $this->pendingPaymentBooking();
        $booking->transitionTo(Booking::STATUS_CANCELLED, actorType: 'system');

        (new IssueProviderOrderJob($booking->id))->handle(app(BookingService::class));

        FakeFlightProvider::$offerDetail = null;
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
        $this->assertNull(FakeFlightProvider::$lastCreateOrderPassengers);
    }

    public function test_permanent_failure_escalates_to_an_audit_event_and_notifies_super_admins(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $booking = $this->pendingPaymentBooking();

        (new IssueProviderOrderJob($booking->id))->failed(new RuntimeException('Duffel 503'));

        $this->assertDatabaseHas('booking_events', [
            'booking_id' => $booking->id,
            'event_type' => 'provider_order_failed',
        ]);
        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->fresh()->status);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }
}
