<?php

namespace Tests\Feature\Promotions;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Promotion;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class PromotionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withHeader('Referer', config('app.frontend_url'));
        FakeFlightProvider::reset();

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = ['id' => 'off_1', 'total_amount' => '200.00', 'total_currency' => 'USD', 'slices' => []];
    }

    private function heldBookingFor(User $user): Booking
    {
        $traveler = TravelerProfile::create([
            'user_id' => $user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        return app(BookingService::class)->createHold($user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]);
    }

    public function test_a_user_can_apply_a_code_to_their_own_booking(): void
    {
        Promotion::create(['code' => 'SAVE10', 'name' => '10% off', 'type' => Promotion::TYPE_PERCENT, 'value' => 10, 'per_user_limit' => 1, 'is_active' => true]);
        $user = User::factory()->create();
        $booking = $this->heldBookingFor($user);

        $this->actingAs($user, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/promotions", ['code' => 'SAVE10'])
            ->assertOk()
            ->assertJsonPath('total_price', '180.00');
    }

    public function test_a_user_cannot_apply_a_code_to_someone_elses_booking(): void
    {
        Promotion::create(['code' => 'SAVE10', 'name' => '10% off', 'type' => Promotion::TYPE_PERCENT, 'value' => 10, 'per_user_limit' => 1, 'is_active' => true]);
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $booking = $this->heldBookingFor($owner);

        $this->actingAs($intruder, 'web')
            ->postJson("/api/v1/bookings/{$booking->id}/promotions", ['code' => 'SAVE10'])
            ->assertForbidden();
    }

    public function test_a_user_can_redeem_a_standalone_bonus_code(): void
    {
        Promotion::create(['code' => 'BONUS20', 'name' => 'Bonus', 'type' => Promotion::TYPE_FREE_SEARCH_BONUS, 'value' => 20, 'per_user_limit' => 1, 'is_active' => true]);
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/promotions/redeem', ['code' => 'BONUS20'])
            ->assertOk();
    }

    public function test_an_invalid_standalone_code_returns_an_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/v1/promotions/redeem', ['code' => 'NOPE'])
            ->assertStatus(422);
    }
}
