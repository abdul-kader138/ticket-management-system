<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class ExpireStaleBookingHoldsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_expires_past_due_holds(): void
    {
        FakeFlightProvider::reset();
        FakeFlightProvider::$offerDetail = ['id' => 'off_1', 'total_amount' => '10.00', 'total_currency' => 'USD', 'slices' => []];

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);

        $user = User::factory()->create();
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        $booking = app(BookingService::class)->createHold(
            $user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        $booking->update(['expires_at' => now()->subMinute()]);

        $this->artisan('bookings:expire-holds')->assertSuccessful();

        $this->assertSame(Booking::STATUS_EXPIRED, $booking->fresh()->status);
    }
}
