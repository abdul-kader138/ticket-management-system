<?php

namespace Tests\Feature\Compliance;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Setting;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class TermsVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeFlightProvider::reset();
        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = ['id' => 'off_1', 'total_amount' => '100.00', 'total_currency' => 'USD', 'slices' => []];
    }

    private function createHold(User $user): Booking
    {
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        return app(BookingService::class)->createHold($user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]);
    }

    public function test_a_booking_snapshots_the_current_terms_version(): void
    {
        Setting::set('current_terms_version', '2026-08-25');
        $booking = $this->createHold(User::factory()->create());

        $this->assertSame('2026-08-25', $booking->terms_version);
    }

    public function test_an_existing_bookings_terms_version_is_unaffected_by_a_later_change(): void
    {
        Setting::set('current_terms_version', 'v1');
        $booking = $this->createHold(User::factory()->create());

        Setting::set('current_terms_version', 'v2');

        $this->assertSame('v1', $booking->fresh()->terms_version);
    }

    public function test_defaults_to_v1_when_no_terms_version_is_configured(): void
    {
        $booking = $this->createHold(User::factory()->create());

        $this->assertSame('v1', $booking->terms_version);
    }
}
