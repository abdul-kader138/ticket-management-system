<?php

namespace Tests\Feature\Flights;

use App\Models\FlightProvider;
use App\Services\Flights\Contracts\FlightProviderContract;
use App\Services\Flights\DTO\CancellationResult;
use App\Services\Flights\DTO\Offer;
use App\Services\Flights\DTO\OfferCollection;
use App\Services\Flights\DTO\ProviderOrder;
use App\Services\Flights\DTO\SearchCriteria;

/**
 * A second, distinct driver class that always throws on search() — needed
 * alongside FakeFlightProvider (whose behavior is static/shared across all
 * instances) to exercise a *partial* provider failure: one provider down,
 * another still answering, both enabled at once. See
 * FlightProviderManagerTest.
 */
class AlwaysFailingFlightProvider implements FlightProviderContract
{
    public function __construct(private readonly FlightProvider $provider) {}

    public function configured(): bool
    {
        return true;
    }

    public function search(SearchCriteria $criteria): OfferCollection
    {
        throw new \RuntimeException('Simulated provider failure.');
    }

    public function getOffer(string $offerId): Offer
    {
        throw new \RuntimeException('Not implemented.');
    }

    public function createOrder(string $offerId, array $passengers): ProviderOrder
    {
        throw new \RuntimeException('Not implemented.');
    }

    public function cancelOrder(string $providerOrderId): CancellationResult
    {
        throw new \RuntimeException('Not implemented.');
    }

    public function changeOrder(string $providerOrderId, SearchCriteria $newCriteria): OfferCollection
    {
        throw new \RuntimeException('Not implemented.');
    }

    public function confirmChangeOffer(string $changeOfferId): array
    {
        throw new \RuntimeException('Not implemented.');
    }

    public function suggestPlaces(string $query): array
    {
        return [];
    }

    public function listAirlines(): array
    {
        return [];
    }
}
