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

class BookingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', config('app.frontend_url'));
        FakeFlightProvider::reset();

        FlightProvider::create([
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
            'total_amount' => '100.00',
            'total_currency' => 'USD',
            'slices' => [],
        ];
    }

    public function test_a_user_can_create_a_booking_hold_via_the_api(): void
    {
        $user = User::factory()->create();
        $traveler = TravelerProfile::create([
            'user_id' => $user->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
        ]);

        $response = $this->actingAs($user, 'web')->postJson('/api/v1/bookings', [
            'provider_code' => 'fake',
            'offer_id' => 'off_1',
            'passengers' => [['traveler_profile_id' => $traveler->id, 'type' => 'adult']],
        ]);

        $response->assertCreated()->assertJsonPath('status', Booking::STATUS_HELD);
        $this->assertDatabaseHas('bookings', ['user_id' => $user->id, 'status' => Booking::STATUS_HELD]);
    }

    public function test_a_user_cannot_view_someone_elses_booking(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $traveler = TravelerProfile::create([
            'user_id' => $owner->id,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
        ]);

        $booking = app(BookingService::class)->createHold(
            $owner, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );

        $this->actingAs($intruder, 'web')
            ->getJson("/api/v1/bookings/{$booking->id}")
            ->assertForbidden();
    }

    public function test_booking_with_someone_elses_traveler_is_rejected(): void
    {
        $user = User::factory()->create();
        $otherUsersTraveler = TravelerProfile::create([
            'user_id' => User::factory()->create()->id,
            'first_name' => 'Bob',
            'last_name' => 'Smith',
            'date_of_birth' => '1985-05-05',
        ]);

        $this->actingAs($user, 'web')->postJson('/api/v1/bookings', [
            'provider_code' => 'fake',
            'offer_id' => 'off_1',
            'passengers' => [['traveler_profile_id' => $otherUsersTraveler->id, 'type' => 'adult']],
        ])->assertStatus(422);
    }
}
