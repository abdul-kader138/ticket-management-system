<?php

namespace App\Services\Flights\Contracts;

use App\Models\FlightProvider;
use App\Services\Flights\DTO\CancellationResult;
use App\Services\Flights\DTO\Offer;
use App\Services\Flights\DTO\OfferCollection;
use App\Services\Flights\DTO\ProviderOrder;
use App\Services\Flights\DTO\SearchCriteria;

/**
 * Implemented once per flight search/booking API (Duffel today; see
 * docs/ROADMAP.md, Phase 2 for the rationale — adding a second provider
 * should mean writing one new class against this interface, not touching
 * FlightProviderManager or the search controller/view).
 */
interface FlightProviderContract
{
    /**
     * @param  FlightProvider  $provider  This provider's own DB row —
     *                                    credentials, base URL, timeout — so the driver never reads
     *                                    config()/Setting directly (see App\Models\FlightProvider).
     */
    public function __construct(FlightProvider $provider);

    public function configured(): bool;

    public function search(SearchCriteria $criteria): OfferCollection;

    public function getOffer(string $offerId): Offer;

    /**
     * @param  array<int, array<string, mixed>>  $passengers  Provider-shaped
     *                                                        passenger data (Duffel's own given_name/family_name/born_on/…
     *                                                        fields today) — normalizing this awaits Phase 4's booking flow,
     *                                                        where a real local passenger model exists to map from.
     */
    public function createOrder(string $offerId, array $passengers): ProviderOrder;

    public function cancelOrder(string $providerOrderId): CancellationResult;

    /**
     * Returns the change offers available for the new criteria — accepting
     * one of them is a separate call, confirmChangeOffer() below.
     */
    public function changeOrder(string $providerOrderId, SearchCriteria $newCriteria): OfferCollection;

    /**
     * Confirms a specific change offer returned by changeOrder() and
     * actually applies it to the provider's order.
     *
     * KNOWN GAP: Duffel's real endpoint/payload for this step is
     * unverified against their live API (see
     * App\Services\Flights\DuffelClient::confirmChangeOffer() for the
     * assumption this makes) — needs confirming against Duffel's actual
     * docs before Phase 6 goes live for real.
     *
     * @return array{new_total_amount: ?string, currency: ?string, raw: array<string, mixed>}
     */
    public function confirmChangeOffer(string $changeOfferId): array;

    /**
     * Airport/city autocomplete suggestions.
     *
     * @return array<int, array{iata_code: ?string, name: ?string, city_name: ?string}>
     */
    public function suggestPlaces(string $query): array;

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function listAirlines(): array;
}
