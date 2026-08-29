<?php

namespace Tests\Feature\Bookings;

use App\Filament\Pages\MyBookings;
use App\Filament\Resources\BookingResource\Pages\ViewBooking;
use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\Feature\Payments\FakePaymentGateway;
use Tests\Feature\Payments\FakePaymentGatewayManager;
use Tests\TestCase;

class MyBookingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeFlightProvider::reset();
        FakePaymentGateway::reset();
        $this->app->instance(PaymentGatewayManager::class, new FakePaymentGatewayManager);

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1', 'total_amount' => '150.00', 'total_currency' => 'USD', 'slices' => [],
        ];
    }

    private function heldBookingFor(User $user): Booking
    {
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        return app(BookingService::class)->createHold(
            $user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
    }

    public function test_a_customer_sees_only_their_own_bookings(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();

        $mine = $this->heldBookingFor($me);
        $theirs = $this->heldBookingFor($someoneElse);

        Livewire::actingAs($me)
            ->test(MyBookings::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_a_customer_can_cancel_their_own_held_booking_from_the_list(): void
    {
        $me = User::factory()->create();
        $booking = $this->heldBookingFor($me);

        Livewire::actingAs($me)
            ->test(MyBookings::class)
            ->callTableAction('cancel', $booking, data: ['reason' => 'changed my mind']);

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
    }

    public function test_a_customer_can_self_check_a_stranded_pending_payment_booking(): void
    {
        $me = User::factory()->create();
        $booking = $this->heldBookingFor($me);
        app(PaymentService::class)->initiate($booking, 'fake');
        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->fresh()->status);

        FakePaymentGateway::$retrieveStatusResult = 'succeeded';

        Livewire::actingAs($me)
            ->test(MyBookings::class)
            ->callTableAction('checkPayment', $booking);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    public function test_the_staff_booking_view_page_renders_all_sections_without_lazy_loading(): void
    {
        // Model::preventLazyLoading is on outside production (AppServiceProvider),
        // so this render throws if the infolist relations aren't eager-loaded.
        Permission::findOrCreate('view_any_booking', 'web');
        Permission::findOrCreate('view_booking', 'web');
        $staff = User::factory()->create();
        $staff->givePermissionTo(['view_any_booking', 'view_booking']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $customer = User::factory()->create();
        $booking = $this->heldBookingFor($customer);
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
        app(PaymentService::class)->reconcile($payment); // pending -> nothing, but creates a payment row

        Livewire::actingAs($staff)
            ->test(ViewBooking::class, ['record' => $booking->id])
            ->assertOk()
            ->assertSee($booking->currency);
    }

    public function test_the_page_is_hidden_from_the_nav_for_staff_with_the_bookings_permission(): void
    {
        $staff = User::factory()->create();
        Permission::findOrCreate('view_any_booking', 'web');
        $staff->givePermissionTo('view_any_booking');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($staff);
        $this->assertFalse(MyBookings::shouldRegisterNavigation());

        $customer = User::factory()->create();
        $this->actingAs($customer);
        $this->assertTrue(MyBookings::shouldRegisterNavigation());
    }
}
