<?php

namespace Tests\Feature\Bookings;

use App\Filament\Pages\MyPayments;
use App\Models\FlightProvider;
use App\Models\Payment;
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

class MyPaymentsPageTest extends TestCase
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

    private function paymentFor(User $user): Payment
    {
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
        $booking = app(BookingService::class)->createHold(
            $user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        return app(PaymentService::class)->initiate($booking, 'fake')['payment'];
    }

    public function test_a_customer_sees_only_their_own_payments(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();

        $mine = $this->paymentFor($me);
        $theirs = $this->paymentFor($someoneElse);

        Livewire::actingAs($me)
            ->test(MyPayments::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_page_is_hidden_from_the_nav_for_staff_with_the_payments_permission(): void
    {
        $staff = User::factory()->create();
        Permission::findOrCreate('view_any_payment', 'web');
        $staff->givePermissionTo('view_any_payment');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($staff);
        $this->assertFalse(MyPayments::shouldRegisterNavigation());

        $this->actingAs(User::factory()->create());
        $this->assertTrue(MyPayments::shouldRegisterNavigation());
    }
}
