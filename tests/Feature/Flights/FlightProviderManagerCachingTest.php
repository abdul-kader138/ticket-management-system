<?php

namespace Tests\Feature\Flights;

use App\Models\FlightProvider;
use App\Services\Flights\FlightProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Locks in enabledProviders()'s caching and, just as importantly, its
 * invalidation — a cache with no invalidation path is worse than no cache,
 * since it would serve a disabled provider (or a rotated API key) for up
 * to CACHE_KEY's full TTL after an admin change.
 */
class FlightProviderManagerCachingTest extends TestCase
{
    use RefreshDatabase;

    private function makeProvider(): FlightProvider
    {
        return FlightProvider::create([
            'code' => 'fake', 'name' => 'Fake', 'driver_class' => FakeFlightProvider::class,
            'credentials' => ['token' => 'x'], 'is_enabled' => true, 'priority' => 0, 'timeout' => 30,
        ]);
    }

    public function test_repeated_calls_only_query_the_database_once(): void
    {
        $this->makeProvider();
        $manager = app(FlightProviderManager::class);

        $manager->enabledProviders();

        DB::flushQueryLog();
        DB::enableQueryLog();

        for ($i = 0; $i < 5; $i++) {
            $manager->enabledProviders();
        }

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queryCount);
    }

    public function test_disabling_a_provider_is_reflected_immediately_not_after_the_cache_ttl(): void
    {
        $provider = $this->makeProvider();
        $manager = app(FlightProviderManager::class);

        $this->assertCount(1, $manager->enabledProviders());

        $provider->update(['is_enabled' => false]);

        $this->assertCount(0, $manager->enabledProviders());
    }

    public function test_a_newly_created_provider_is_visible_immediately(): void
    {
        $manager = app(FlightProviderManager::class);
        $this->assertCount(0, $manager->enabledProviders());

        $this->makeProvider();

        $this->assertCount(1, $manager->enabledProviders());
    }

    public function test_deleting_a_provider_is_reflected_immediately(): void
    {
        $provider = $this->makeProvider();
        $manager = app(FlightProviderManager::class);
        $this->assertCount(1, $manager->enabledProviders());

        $provider->delete();

        $this->assertCount(0, $manager->enabledProviders());
    }
}
