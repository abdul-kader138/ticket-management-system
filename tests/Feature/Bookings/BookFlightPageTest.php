<?php

namespace Tests\Feature\Bookings;

use App\Filament\Pages\BookFlight;
use App\Filament\Pages\ChangeBooking;
use App\Filament\Resources\BookingResource\Pages\ListBookings;
use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Payment;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Payments\DTO\WebhookOutcome;
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

class BookFlightPageTest extends TestCase
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
        $this->actingAs($this->user);

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);

        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1', 'total_amount' => '150.00', 'total_currency' => 'USD',
            'slices' => [], 'passengers' => [['id' => 'pas_0']],
        ];
    }

    private function traveler(): TravelerProfile
    {
        return TravelerProfile::create([
            'user_id' => $this->user->id,
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);
    }

    public function test_book_flight_walks_from_selected_offer_to_a_paid_confirmed_booking(): void
    {
        $traveler = $this->traveler();

        $component = Livewire::withQueryParams(['provider' => 'fake', 'offer' => 'off_1'])
            ->test(BookFlight::class)
            ->assertSet('step', 1)
            ->call('goToPassengers')
            ->assertSet('step', 2)
            ->fillForm([
                'customer_id' => $this->user->id,
                'passengers' => [['traveler_profile_id' => $traveler->id, 'type' => 'adult']],
            ])
            ->call('createHold')
            ->assertSet('step', 3);

        $bookingId = $component->get('bookingId');
        $this->assertNotNull($bookingId);
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => Booking::STATUS_HELD]);

        FakePaymentGateway::$retrieveStatusResult = 'succeeded';

        $component->call('startPayment', 'stripe')
            ->assertSet('paymentGateway', 'stripe')
            ->call('refreshPaymentStatus')
            ->assertSet('step', 4);

        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => Booking::STATUS_CONFIRMED]);
        $this->assertDatabaseHas('payments', [
            'payable_id' => $bookingId,
            'payable_type' => Booking::class,
            'status' => Payment::STATUS_SUCCEEDED,
        ]);
    }

    public function test_book_flight_rejects_a_passenger_mix_that_does_not_match_the_fare(): void
    {
        // FakeFlightProvider::$offerDetail is priced for a single adult.
        $t1 = $this->traveler();
        $t2 = TravelerProfile::create([
            'user_id' => $this->user->id,
            'first_name' => 'Grace', 'last_name' => 'Hopper', 'date_of_birth' => '1980-01-01',
        ]);

        Livewire::withQueryParams(['provider' => 'fake', 'offer' => 'off_1'])
            ->test(BookFlight::class)
            ->call('goToPassengers')
            ->fillForm([
                'customer_id' => $this->user->id,
                'passengers' => [
                    ['traveler_profile_id' => $t1->id, 'type' => 'adult'],
                    ['traveler_profile_id' => $t2->id, 'type' => 'adult'],
                ],
            ])
            ->call('createHold')
            ->assertSet('step', 2);

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_check_payment_action_reconciles_a_stranded_pending_payment_booking(): void
    {
        Permission::findOrCreate('view_any_booking', 'web');
        $this->user->givePermissionTo('view_any_booking');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $traveler = $this->traveler();
        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        app(PaymentService::class)->initiate($booking, 'fake');
        $this->assertSame(Booking::STATUS_PENDING_PAYMENT, $booking->fresh()->status);

        FakePaymentGateway::$retrieveStatusResult = 'succeeded';

        Livewire::test(ListBookings::class)
            ->callTableAction('checkPayment', $booking);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->fresh()->status);
    }

    public function test_book_flight_can_resume_an_existing_held_booking_for_payment(): void
    {
        $traveler = $this->traveler();
        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        Livewire::withQueryParams(['booking' => $booking->id])
            ->test(BookFlight::class)
            ->assertSet('step', 3)
            ->assertSet('bookingId', $booking->id);
    }

    public function test_change_booking_applies_a_zero_difference_change_without_payment(): void
    {
        $traveler = $this->traveler();
        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        $payment = app(PaymentService::class)->initiate($booking, 'fake')['payment'];
        app(PaymentService::class)->applyWebhookOutcome(
            new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $payment->gateway_reference, $payment->amount_cents),
            'fake',
        );
        $booking->refresh();
        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);

        FakeFlightProvider::$changeOffersToReturn = [['id' => 'chg_1', 'new_total_amount' => '150.00', 'new_total_currency' => 'USD']];
        FakeFlightProvider::$confirmChangeOfferResult = ['new_total_amount' => '150.00', 'currency' => 'USD', 'raw' => ['slices' => []]];

        Livewire::withQueryParams(['booking' => $booking->id])
            ->test(ChangeBooking::class)
            ->assertSet('step', 1)
            ->fillForm([
                'adults' => 1,
                'legs' => [['from' => 'LHR', 'to' => 'CDG', 'date' => now()->addMonth()->toDateString()]],
            ])
            ->call('searchChangeOffers')
            ->assertSet('step', 2)
            ->call('applyChange', 'chg_1')
            ->assertSet('step', 4);

        $this->assertDatabaseHas('bookings', ['id' => $booking->id, 'status' => Booking::STATUS_CHANGED]);
    }
}
