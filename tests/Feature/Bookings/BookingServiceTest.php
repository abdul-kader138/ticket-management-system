<?php

namespace Tests\Feature\Bookings;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingException;
use App\Services\Bookings\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FlightProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        FakeFlightProvider::reset();

        $this->user = User::factory()->create();
        $this->provider = FlightProvider::create([
            'code' => 'fake',
            'name' => 'Fake',
            'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'fake-token'],
            'is_enabled' => true,
            'priority' => 0,
            'timeout' => 30,
        ]);

        FakeFlightProvider::$offerDetail = [
            'id' => 'off_1',
            'total_amount' => '245.50',
            'total_currency' => 'USD',
            'cabin_class' => 'economy',
            'expires_at' => now()->addMinutes(20)->toIso8601String(),
            'slices' => [[
                'origin' => ['iata_code' => 'LHR'],
                'destination' => ['iata_code' => 'JFK'],
                'segments' => [[
                    'operating_carrier' => ['iata_code' => 'BA', 'name' => 'British Airways'],
                    'operating_carrier_flight_number' => '117',
                    'origin' => ['iata_code' => 'LHR'],
                    'destination' => ['iata_code' => 'JFK'],
                    'departing_at' => '2027-01-01T10:00:00',
                    'arriving_at' => '2027-01-01T13:00:00',
                ]],
            ]],
        ];
    }

    private function traveler(): TravelerProfile
    {
        return TravelerProfile::create([
            'user_id' => $this->user->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
        ]);
    }

    public function test_creates_a_held_booking_with_segments_and_passengers(): void
    {
        $traveler = $this->traveler();

        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        $this->assertSame(Booking::STATUS_HELD, $booking->status);
        $this->assertSame(24550, $booking->total_price_cents);
        $this->assertSame('USD', $booking->currency);
        $this->assertCount(1, $booking->segments);
        $this->assertSame('BA', $booking->segments->first()->carrier_iata);
        $this->assertCount(1, $booking->passengers);
        $this->assertSame('Ada', $booking->passengers->first()->first_name);
        $this->assertNotNull($booking->expires_at);

        $this->assertDatabaseHas('booking_events', [
            'booking_id' => $booking->id,
            'event_type' => Booking::STATUS_HELD,
        ]);
    }

    public function test_rejects_a_traveler_profile_that_belongs_to_someone_else(): void
    {
        $otherUsersTraveler = TravelerProfile::create([
            'user_id' => User::factory()->create()->id,
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'date_of_birth' => '1985-05-05',
        ]);

        $this->expectException(BookingException::class);

        app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $otherUsersTraveler->id, 'type' => 'adult']]
        );
    }

    public function test_rejects_an_unknown_or_disabled_provider(): void
    {
        $traveler = $this->traveler();
        $this->provider->update(['is_enabled' => false]);

        $this->expectException(BookingException::class);

        app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
    }

    public function test_expire_stale_holds_transitions_past_due_bookings(): void
    {
        $traveler = $this->traveler();
        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        $booking->update(['expires_at' => now()->subMinute()]);

        $expiredCount = app(BookingService::class)->expireStaleHolds();

        $this->assertSame(1, $expiredCount);
        $this->assertSame(Booking::STATUS_EXPIRED, $booking->fresh()->status);
    }

    public function test_expire_stale_holds_leaves_still_valid_holds_alone(): void
    {
        $traveler = $this->traveler();
        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        $this->assertSame(0, app(BookingService::class)->expireStaleHolds());
        $this->assertSame(Booking::STATUS_HELD, $booking->fresh()->status);
    }

    public function test_confirm_with_provider_sends_the_travelers_own_title_gender_and_contact_details(): void
    {
        $traveler = $this->traveler();
        $traveler->update(['title' => 'ms', 'gender' => 'f', 'email' => 'ada@travelers.example', 'phone' => '+15559876']);

        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        $booking->transitionTo(Booking::STATUS_PENDING_PAYMENT);

        app(BookingService::class)->confirmWithProvider($booking);

        $sent = FakeFlightProvider::$lastCreateOrderPassengers[0];
        $this->assertSame('ms', $sent['title']);
        $this->assertSame('f', $sent['gender']);
        $this->assertSame('ada@travelers.example', $sent['email']);
        $this->assertSame('+15559876', $sent['phone_number']);
    }

    public function test_confirm_with_provider_falls_back_to_the_account_holders_contact_details(): void
    {
        $this->user->update(['email' => 'account-holder@example.com', 'phone' => '+15551112222']);
        $traveler = $this->traveler(); // no email/phone set on the profile itself

        $booking = app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
        $booking->transitionTo(Booking::STATUS_PENDING_PAYMENT);

        app(BookingService::class)->confirmWithProvider($booking);

        $sent = FakeFlightProvider::$lastCreateOrderPassengers[0];
        $this->assertSame('account-holder@example.com', $sent['email']);
        $this->assertSame('+15551112222', $sent['phone_number']);
        $this->assertNull($sent['title']);
        $this->assertNull($sent['gender']);
    }
}
