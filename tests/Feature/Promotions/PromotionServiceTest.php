<?php

namespace Tests\Feature\Promotions;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Promotion;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Bookings\BookingService;
use App\Services\Flights\SearchQuotaService;
use App\Services\Promotions\PromotionException;
use App\Services\Promotions\PromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Flights\FakeFlightProvider;
use Tests\TestCase;

class PromotionServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        FakeFlightProvider::reset();

        $this->user = User::factory()->create();

        FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
        FakeFlightProvider::$offerDetail = ['id' => 'off_1', 'total_amount' => '200.00', 'total_currency' => 'USD', 'slices' => []];
    }

    private function heldBooking(): Booking
    {
        $traveler = TravelerProfile::create([
            'user_id' => $this->user->id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'date_of_birth' => '1990-01-01',
        ]);

        return app(BookingService::class)->createHold(
            $this->user, 'fake', 'off_1', [['traveler_profile_id' => $traveler->id, 'type' => 'adult']]
        );
    }

    private function promotion(array $overrides = []): Promotion
    {
        return Promotion::create(array_merge([
            'code' => 'SAVE10', 'name' => '10% off', 'type' => Promotion::TYPE_PERCENT,
            'value' => 10, 'per_user_limit' => 1, 'is_active' => true,
        ], $overrides));
    }

    public function test_a_percent_code_discounts_the_booking_total(): void
    {
        $this->promotion();
        $booking = $this->heldBooking();

        app(PromotionService::class)->redeemForBooking($booking, 'SAVE10', $this->user);

        $this->assertSame(18000, $booking->fresh()->total_price_cents);
    }

    public function test_a_fixed_code_discounts_by_a_flat_amount(): void
    {
        $this->promotion(['code' => 'FLAT20', 'type' => Promotion::TYPE_FIXED, 'value' => 2000]);
        $booking = $this->heldBooking();

        app(PromotionService::class)->redeemForBooking($booking, 'FLAT20', $this->user);

        $this->assertSame(18000, $booking->fresh()->total_price_cents);
    }

    public function test_a_fixed_discount_never_takes_the_total_below_zero(): void
    {
        $this->promotion(['code' => 'HUGE', 'type' => Promotion::TYPE_FIXED, 'value' => 100000]);
        $booking = $this->heldBooking();

        app(PromotionService::class)->redeemForBooking($booking, 'HUGE', $this->user);

        $this->assertSame(0, $booking->fresh()->total_price_cents);
    }

    public function test_cannot_apply_a_code_after_the_booking_has_moved_past_held(): void
    {
        $this->promotion();
        $booking = $this->heldBooking();
        $booking->transitionTo(Booking::STATUS_CANCELLED);

        $this->expectException(PromotionException::class);
        app(PromotionService::class)->redeemForBooking($booking, 'SAVE10', $this->user);
    }

    public function test_an_unknown_code_is_rejected(): void
    {
        $booking = $this->heldBooking();

        $this->expectException(PromotionException::class);
        app(PromotionService::class)->redeemForBooking($booking, 'NOPE', $this->user);
    }

    public function test_an_expired_code_is_rejected(): void
    {
        $this->promotion(['ends_at' => now()->subDay()]);
        $booking = $this->heldBooking();

        $this->expectException(PromotionException::class);
        app(PromotionService::class)->redeemForBooking($booking, 'SAVE10', $this->user);
    }

    public function test_a_code_cannot_be_used_twice_by_the_same_user(): void
    {
        $this->promotion();
        $first = $this->heldBooking();
        app(PromotionService::class)->redeemForBooking($first, 'SAVE10', $this->user);

        $second = $this->heldBooking();
        $this->expectException(PromotionException::class);
        app(PromotionService::class)->redeemForBooking($second, 'SAVE10', $this->user);
    }

    public function test_a_code_stops_working_once_its_total_usage_limit_is_reached(): void
    {
        $this->promotion(['usage_limit' => 1, 'per_user_limit' => 5]);
        $otherUser = User::factory()->create();

        $firstBooking = $this->heldBooking();
        app(PromotionService::class)->redeemForBooking($firstBooking, 'SAVE10', $this->user);

        $secondBooking = app(BookingService::class)->createHold(
            $otherUser, 'fake', 'off_1',
            [['traveler_profile_id' => TravelerProfile::create([
                'user_id' => $otherUser->id, 'first_name' => 'Bob', 'last_name' => 'Smith', 'date_of_birth' => '1985-05-05',
            ])->id, 'type' => 'adult']]
        );

        $this->expectException(PromotionException::class);
        app(PromotionService::class)->redeemForBooking($secondBooking, 'SAVE10', $otherUser);
    }

    public function test_a_percent_code_cannot_be_redeemed_standalone(): void
    {
        $this->promotion();

        $this->expectException(PromotionException::class);
        app(PromotionService::class)->redeemStandalone($this->user, 'SAVE10');
    }

    public function test_a_free_search_bonus_code_grants_extra_searches_today(): void
    {
        $this->promotion(['code' => 'BONUS20', 'type' => Promotion::TYPE_FREE_SEARCH_BONUS, 'value' => 20]);

        $quota = app(SearchQuotaService::class);
        $quota->consume($this->user); // 1 used

        app(PromotionService::class)->redeemStandalone($this->user, 'BONUS20');

        // 20 bonus - 1 already used = 19 net headroom beyond the base limit.
        $this->assertSame(-19, $quota->used($this->user, 'day'));
    }

    public function test_a_free_search_bonus_code_cannot_be_applied_at_checkout(): void
    {
        $this->promotion(['code' => 'BONUS20', 'type' => Promotion::TYPE_FREE_SEARCH_BONUS, 'value' => 20]);
        $booking = $this->heldBooking();

        $this->expectException(PromotionException::class);
        app(PromotionService::class)->redeemForBooking($booking, 'BONUS20', $this->user);
    }
}
