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
 * A minimal FlightProviderContract implementation for exercising
 * FlightProviderManager without hitting a real API — see
 * FlightProviderManagerTest.
 */
class FakeFlightProvider implements FlightProviderContract
{
    public static int $searchCalls = 0;

    public static bool $shouldThrow = false;

    /** @var array<int, array<string, mixed>> */
    public static array $offersToReturn = [];

    /** @var array<string, mixed>|null */
    public static ?array $offerDetail = null;

    /** @var array{confirmed: bool, refund_amount: ?string, refund_currency: ?string} */
    public static array $cancelResult = ['confirmed' => true, 'refund_amount' => null, 'refund_currency' => null];

    /** @var array<int, array<string, mixed>> */
    public static array $changeOffersToReturn = [];

    /** @var array{new_total_amount: ?string, currency: ?string, raw: array<string, mixed>} */
    public static array $confirmChangeOfferResult = ['new_total_amount' => null, 'currency' => null, 'raw' => []];

    public function __construct(private readonly FlightProvider $provider) {}

    public static function reset(): void
    {
        self::$searchCalls = 0;
        self::$shouldThrow = false;
        self::$offersToReturn = [];
        self::$offerDetail = null;
        self::$cancelResult = ['confirmed' => true, 'refund_amount' => null, 'refund_currency' => null];
        self::$changeOffersToReturn = [];
        self::$confirmChangeOfferResult = ['new_total_amount' => null, 'currency' => null, 'raw' => []];
        self::$lastCreateOrderPassengers = null;
    }

    public function configured(): bool
    {
        return true;
    }

    public function search(SearchCriteria $criteria): OfferCollection
    {
        static::$searchCalls++;

        if (static::$shouldThrow) {
            throw new \RuntimeException('Simulated provider failure.');
        }

        $offers = array_map(
            fn (array $offer) => new Offer($offer['id'], $this->provider->code, $offer),
            static::$offersToReturn
        );

        return new OfferCollection($offers);
    }

    public function getOffer(string $offerId): Offer
    {
        return new Offer($offerId, $this->provider->code, static::$offerDetail ?? ['id' => $offerId]);
    }

    /** @var array<int, array<string, mixed>>|null */
    public static ?array $lastCreateOrderPassengers = null;

    public function createOrder(string $offerId, array $passengers): ProviderOrder
    {
        static::$lastCreateOrderPassengers = $passengers;

        return new ProviderOrder($offerId, null, 'confirmed', []);
    }

    public function cancelOrder(string $providerOrderId): CancellationResult
    {
        return new CancellationResult(
            static::$cancelResult['confirmed'],
            static::$cancelResult['refund_amount'],
            static::$cancelResult['refund_currency'],
            [],
        );
    }

    public function changeOrder(string $providerOrderId, SearchCriteria $newCriteria): OfferCollection
    {
        $offers = array_map(
            fn (array $offer) => new Offer($offer['id'], $this->provider->code, $offer),
            static::$changeOffersToReturn
        );

        return new OfferCollection($offers);
    }

    public function confirmChangeOffer(string $changeOfferId): array
    {
        return static::$confirmChangeOfferResult;
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
