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
    // Enable/disable and credential edits are rare admin actions; a search
    // request is not — caching the (small) enabled-providers row set saves
    // a DB round trip on every single search/autocomplete/airline-list call
    // at 50k-user scale. Flushed immediately on any FlightProvider write
    // (see FlightProvider::booted()), so this is a performance cache, not a
    // staleness trade-off.
    public const CACHE_KEY = 'flight-providers:enabled';

    public function __construct(private readonly SearchQuotaService $quota) {}

    /**
     * @return array<int, FlightProviderContract>
     */
    public function enabledProviders(): array
    {
        // Cache the raw column values (an array of plain scalars), never
        // the Eloquent models or the Collection wrapping them — a
        // serializing cache store (redis/file) can't round-trip those and
        // hands back an incomplete object on the next read. `credentials`
        // stays in the cache exactly as it sits in the database: the
        // encrypted ciphertext, decrypted lazily by the model's cast only
        // once a driver actually reads it, so no plaintext secret ever
        // touches the cache.
        $rows = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn () => FlightProvider::query()
            ->enabled()
            ->orderBy('priority')
            ->get()
            ->map(fn (FlightProvider $provider) => $provider->getAttributes())
            ->all()
        );

        return collect($rows)
            ->map(fn (array $attributes) => $this->hydrate($attributes))
            ->map(fn (FlightProvider $provider) => $this->driver($provider))
            ->filter(fn (FlightProviderContract $driver) => $driver->configured())
            ->values()
            ->all();
    }

    /**
     * Rebuilds a FlightProvider from its cached raw attributes — marked as
     * existing so it behaves like a loaded record (casts, relations,
     * ->id), but never saved from here.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function hydrate(array $attributes): FlightProvider
    {
        $provider = new FlightProvider;
        $provider->setRawAttributes($attributes, sync: true);
        $provider->exists = true;

        return $provider;
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
