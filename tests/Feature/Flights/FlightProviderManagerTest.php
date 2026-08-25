<?php

namespace Tests\Feature\Flights;

use App\Models\FlightProvider;
use App\Models\Setting;
use App\Models\User;
use App\Services\Flights\DTO\SearchCriteria;
use App\Services\Flights\FlightProviderManager;
use App\Services\Flights\SearchQuotaExceededException;
use App\Services\Flights\SearchQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightProviderManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        FakeFlightProvider::reset();
        $this->user = User::factory()->create();
    }

    private function makeProvider(array $overrides = []): FlightProvider
    {
        return FlightProvider::create(array_merge([
            'code' => 'fake',
            'name' => 'Fake',
            'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'fake-token'],
            'is_enabled' => true,
            'priority' => 0,
            'timeout' => 30,
        ], $overrides));
    }

    private function criteria(): SearchCriteria
    {
        return new SearchCriteria(
            slices: [['origin' => 'LHR', 'destination' => 'JFK', 'departure_date' => '2027-01-01']],
            adults: 1,
        );
    }

    public function test_disabled_providers_are_excluded_from_search(): void
    {
        $this->makeProvider(['is_enabled' => false]);

        $manager = app(FlightProviderManager::class);

        $this->assertFalse($manager->hasConfiguredProvider());
        $this->assertCount(0, $manager->search($this->criteria(), $this->user));
    }

    public function test_search_returns_offers_from_an_enabled_provider(): void
    {
        $this->makeProvider();
        FakeFlightProvider::$offersToReturn = [['id' => 'off_1'], ['id' => 'off_2']];

        $manager = app(FlightProviderManager::class);
        $offers = $manager->search($this->criteria(), $this->user);

        $this->assertCount(2, $offers);
        $this->assertSame('fake', $offers->toArray()[0]['provider_code']);
    }

    public function test_search_merges_offers_from_multiple_enabled_providers(): void
    {
        $this->makeProvider(['code' => 'fake-a', 'priority' => 0]);
        $this->makeProvider(['code' => 'fake-b', 'priority' => 1]);
        FakeFlightProvider::$offersToReturn = [['id' => 'off_1']];

        $manager = app(FlightProviderManager::class);
        $offers = $manager->search($this->criteria(), $this->user);

        // One offer per provider row (each drives the same fake class) — two
        // providers means the collection carries both.
        $this->assertCount(2, $offers);
    }

    public function test_a_failing_provider_does_not_break_the_search(): void
    {
        $this->makeProvider();
        FakeFlightProvider::$shouldThrow = true;

        $manager = app(FlightProviderManager::class);
        $offers = $manager->search($this->criteria(), $this->user);

        $this->assertCount(0, $offers);
    }

    public function test_repeat_searches_are_served_from_cache_not_the_provider(): void
    {
        $this->makeProvider();
        FakeFlightProvider::$offersToReturn = [['id' => 'off_1']];

        $manager = app(FlightProviderManager::class);
        $criteria = $this->criteria();

        $manager->search($criteria, $this->user);
        $manager->search($criteria, $this->user);

        $this->assertSame(1, FakeFlightProvider::$searchCalls);
    }

    public function test_a_cached_search_does_not_consume_quota(): void
    {
        $this->makeProvider();
        FakeFlightProvider::$offersToReturn = [['id' => 'off_1']];

        $manager = app(FlightProviderManager::class);
        $criteria = $this->criteria();

        $manager->search($criteria, $this->user);
        $manager->search($criteria, $this->user); // served from cache

        $quota = app(SearchQuotaService::class);
        $this->assertSame(1, $quota->used($this->user, 'day'));
    }

    public function test_search_throws_once_the_daily_quota_is_exhausted(): void
    {
        Setting::set('default_daily_search_limit', 1);
        $this->makeProvider();
        FakeFlightProvider::$offersToReturn = [['id' => 'off_1']];

        $manager = app(FlightProviderManager::class);

        // Two distinct searches (different destination) so the second isn't
        // just served from FlightProviderManager's own result cache.
        $manager->search($this->criteria(), $this->user);

        $secondCriteria = new SearchCriteria(
            slices: [['origin' => 'LHR', 'destination' => 'CDG', 'departure_date' => '2027-01-01']],
            adults: 1,
        );

        $this->expectException(SearchQuotaExceededException::class);
        $manager->search($secondCriteria, $this->user);
    }
}
