<?php

namespace Tests\Feature;

use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Models\Booking;
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

class BookingResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShieldSeeder::class);
    }

    private function confirmedBooking(User $user, string $offerId): Booking
    {
        FakeFlightProvider::$offerDetail = [
            'id' => $offerId, 'total_amount' => '100.00', 'total_currency' => 'USD',
            'slices' => [], 'passengers' => [['id' => 'pas_0']],
        ];
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        $booking = app(BookingService::class)->createHold(
            $user, 'fake', $offerId, [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );

        return $booking->fresh();
    }

    public function test_an_admin_can_search_bookings_by_pnr(): void
    {
        FakeFlightProvider::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $customer = User::factory()->create();

        $booking = $this->confirmedBooking($customer, 'off_1');

        $this->actingAs($admin);

        Livewire::test(ListBookings::class)
            ->searchTable($booking->pnr)
            ->assertCanSeeTableRecords([$booking]);
    }

    public function test_the_booked_between_filter_narrows_results_by_date(): void
    {
        FakeFlightProvider::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        $customer = User::factory()->create();

        $old = $this->confirmedBooking($customer, 'off_old');
        $old->forceFill(['created_at' => now()->subDays(30)])->save();

        $recent = $this->confirmedBooking($customer, 'off_recent');

        $this->actingAs($admin);

        Livewire::test(ListBookings::class)
            ->filterTable('created_at', ['booked_from' => now()->subDays(2)->toDateString()])
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);
    }
}
