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

class ReconcilePendingPaymentsCommandTest extends TestCase
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
        FakeFlightProvider::$offerDetail = ['id' => 'off_1', 'total_amount' => '10.00', 'total_currency' => 'USD', 'slices' => []];

        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        $booking = app(BookingService::class)->createHold($user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]);

        return app(PaymentService::class)->initiate($booking, 'fake')['payment'];
    }

    public function test_only_reconciles_payments_stale_for_over_an_hour(): void
    {
        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);

        $recent = $this->pendingPayment();

        $stale = $this->pendingPayment();
        $stale->forceFill(['created_at' => now()->subHours(2)])->save();

        FakePaymentGateway::$retrieveStatusResult = 'succeeded';

        $this->artisan('payments:reconcile')->assertSuccessful();

        $this->assertSame(Payment::STATUS_PENDING, $recent->fresh()->status);
        $this->assertSame(Payment::STATUS_SUCCEEDED, $stale->fresh()->status);
        $this->assertSame(Booking::STATUS_CONFIRMED, $stale->fresh()->payable->status);
    }
}
