<?php

namespace App\Services\Flights;

use App\Models\FlightProvider;
use App\Models\User;
use App\Services\Flights\Contracts\FlightProviderContract;
use App\Services\Flights\DTO\OfferCollection;
use App\Services\Flights\DTO\SearchCriteria;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The only thing App\Http\Controllers\FlightSearchController talks to for
 * search — it never sees DuffelClient or any other driver directly. Adding
 * a second provider is registering another `flight_providers` row; nothing
 * here or in the controller/view needs to change (see docs/ROADMAP.md,
 * Phase 2).
 */
class FlightProviderManager
{
    public function __construct(private readonly SearchQuotaService $quota) {}

    /**
     * @return array<int, FlightProviderContract>
     */
    public function enabledProviders(): array
    {
        return FlightProvider::query()
            ->enabled()
            ->orderBy('priority')
            ->get()
            ->map(fn (FlightProvider $provider) => $this->driver($provider))
            ->filter(fn (FlightProviderContract $driver) => $driver->configured())
            ->values()
            ->all();
    }

    public function driver(FlightProvider $provider): FlightProviderContract
    {
        $class = $provider->driver_class;

        return new $class($provider);
    }

    public function hasConfiguredProvider(): bool
    {
        return $this->enabledProviders() !== [];
    }

    /**
     * Fans a search out to every enabled provider and merges the results.
     * One provider erroring doesn't fail the whole search — it's logged and
     * skipped, so a customer still sees offers from whichever providers did
     * respond (see docs/ROADMAP.md, Phase 2: "graceful degradation").
     *
     * Short-TTL cached by exact search signature so a repeat search (a
     * refresh, a second passenger in the same session, back-button) doesn't
     * re-bill the provider API. A cache hit is free and skips the quota
     * check entirely; only a genuine cache-miss search — the one that's
     * actually about to cost money — is gated by SearchQuotaService and
     * counted against the user's plan (see docs/ROADMAP.md, Phase 3).
     *
     * @throws SearchQuotaExceededException
     */
    public function search(SearchCriteria $criteria, User $user): OfferCollection
    {
        $cacheKey = "flights:search:{$criteria->signature()}";

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $this->quota->ensureNotExceeded($user);

        $results = [];

        foreach ($this->enabledProviders() as $provider) {
            try {
                $results[] = $provider->search($criteria);
            } catch (\Throwable $e) {
                Log::warning('Flight provider search failed', [
                    'provider' => $provider::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $merged = OfferCollection::merge(...$results);

        $this->quota->consume($user);

        Cache::put($cacheKey, $merged, now()->addMinutes(5));

        return $merged;
    }

    /**
     * @return array<int, array{iata_code: ?string, name: ?string, city_name: ?string}>
     */
    public function suggestPlaces(string $query): array
    {
        foreach ($this->enabledProviders() as $provider) {
            $results = $provider->suggestPlaces($query);

            if ($results !== []) {
                return $results;
            }
        }

        return [];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function listAirlines(): array
    {
        $airlines = [];

        foreach ($this->enabledProviders() as $provider) {
            $airlines += collect($provider->listAirlines())->keyBy('value')->all();
        }

        return array_values($airlines);
    }
}
