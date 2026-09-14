<?php

namespace Tests\Feature;

use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Models\FlightProvider;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\Feature\Payments\FakePaymentGatewayManager;
use Tests\TestCase;

class PaymentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
    }

    public function test_an_admin_can_search_payments_by_gateway_reference_and_see_the_booking_pnr(): void
    {
        FakeFlightProvider::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1', 'total_amount' => '100.00', 'total_currency' => 'USD',
            'slices' => [], 'passengers' => [['id' => 'pas_0']],
        ];

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $customer = User::factory()->create();
        $traveler = TravelerProfile::create([
            'user_id' => $customer->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        $booking = app(BookingService::class)->createHold(
            $customer, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );

        $this->actingAs($admin);

        Livewire::test(ListPayments::class)
            ->searchTable($payment->gateway_reference)
            ->assertCanSeeTableRecords([$payment])
            ->assertSee($booking->fresh()->pnr);
    }
}
