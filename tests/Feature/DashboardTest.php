<?php

namespace Tests\Feature;

use App\Filament\Widgets\BookingsByStatusChart;
use App\Filament\Widgets\LatestBookings;
use App\Filament\Widgets\OperationalHealthStats;
use App\Filament\Widgets\PlatformOverviewStats;
use App\Filament\Widgets\RevenueChart;
use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\Feature\Payments\FakePaymentGateway;
use Tests\Feature\Payments\FakePaymentGatewayManager;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        return $user;
    }

    private function seedOneConfirmedBooking(): Booking
    {
        FakeFlightProvider::reset();
        FakePaymentGateway::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);

        $customer = User::factory()->create();

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1', 'total_amount' => '250.00', 'total_currency' => 'USD',
            'slices' => [['segments' => [['origin' => ['iata_code' => 'LHR'], 'destination' => ['iata_code' => 'JFK']]]]],
            'passengers' => [['id' => 'pas_0']],
        ];

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

        return $booking->fresh();
    }

    public function test_the_dashboard_page_renders_for_a_super_admin(): void
    {
        $this->actingAsSuperAdmin();

        $this->get('/')->assertOk();
    }

    public function test_every_dashboard_widget_renders_without_error(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedOneConfirmedBooking();

        Livewire::test(PlatformOverviewStats::class)->assertOk()->assertSee('Net revenue');
        Livewire::test(OperationalHealthStats::class)->assertOk()->assertSee('Stuck bookings');
        Livewire::test(RevenueChart::class)->assertOk();
        Livewire::test(BookingsByStatusChart::class)->assertOk();
        Livewire::test(LatestBookings::class)->assertOk()->assertSee('LHR → JFK');
    }

    public function test_the_overview_widget_caches_only_serializable_data(): void
    {
        // Regression: caching the Stat objects themselves poisons a
        // serializing store (redis/file) — they come back as
        // __PHP_Incomplete_Class and the next Livewire render 500s.
        $this->actingAsSuperAdmin();
        $this->seedOneConfirmedBooking();

        Livewire::test(PlatformOverviewStats::class)->assertOk();

        $cached = Cache::get('dashboard:platform-overview');
        $this->assertIsArray($cached);
        $this->assertEquals($cached, unserialize(serialize($cached)));
        array_walk_recursive($cached, function ($value) {
            $this->assertFalse(is_object($value), 'Cached dashboard data must not contain objects');
        });

        // Second render hits the cache branch.
        Livewire::test(PlatformOverviewStats::class)->assertOk()->assertSee('Net revenue');
    }

    public function test_operational_health_flags_a_stuck_booking(): void
    {
        $this->actingAsSuperAdmin();
        $booking = $this->seedOneConfirmedBooking();

        // Force it into the "paid but not issued" state, aged past the grace window.
        Booking::withoutEvents(fn () => $booking->forceFill([
            'status' => Booking::STATUS_PENDING_PAYMENT,
            'updated_at' => now()->subHour(),
        ])->save());

        Livewire::test(OperationalHealthStats::class)
            ->assertSee('Paid, awaiting provider order — reconcile');
    }

    public function test_widgets_are_hidden_from_a_role_without_permission(): void
    {
        Role::firstOrCreate(['name' => 'panel_user', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->assignRole('panel_user');
        $this->actingAs($user);

        $this->assertFalse(PlatformOverviewStats::canView());
        $this->assertFalse(LatestBookings::canView());
    }
}
