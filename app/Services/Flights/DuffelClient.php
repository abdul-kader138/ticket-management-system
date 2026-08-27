<?php

namespace App\Services\Flights;

use App\Models\FlightProvider;
use App\Services\Flights\Contracts\FlightProviderContract;
use App\Services\Flights\DTO\CancellationResult;
use App\Services\Flights\DTO\Offer;
use App\Services\Flights\DTO\OfferCollection;
use App\Services\Flights\DTO\ProviderOrder;
use App\Services\Flights\DTO\SearchCriteria;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DuffelClient implements FlightProviderContract
{
    protected string $baseUrl;

    protected ?string $token;

    protected int $timeout;

    public function __construct(protected readonly FlightProvider $provider)
    {
        $this->baseUrl = rtrim((string) ($provider->base_url ?: 'https://api.duffel.com'), '/');
        $this->token = $provider->credential('token');
        $this->timeout = $provider->timeout ?: 30;
    }

    public function configured(): bool
    {
        return $this->provider->is_enabled && filled($this->token);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->withHeaders([
                'Duffel-Version' => 'v2',
                'Accept' => 'application/json',
            ])
            ->timeout($this->timeout);
    }

    public function search(SearchCriteria $criteria): OfferCollection
    {
        $payload = [
            'data' => [
                'slices' => $criteria->slices,
                // Duffel requires an "age" for non-adult passenger types; since
                // the search form doesn't collect ages, only adults are sent
                // for now — children/infants are accepted by the UI but not
                // yet forwarded to the provider.
                'passengers' => array_fill(0, max(1, $criteria->adults), ['type' => 'adult']),
                'cabin_class' => $criteria->cabinClass,
            ],
        ];

        try {
            $response = $this->client()
                ->post('/air/offer_requests?return_offers=true', $payload)
                ->throw();
        } catch (RequestException $e) {
            throw DuffelApiException::fromResponse($e->response);
        }

        $offers = array_map(
            fn (array $offer) => new Offer((string) $offer['id'], $this->provider->code, $offer),
            $response->json('data.offers', []) ?? []
        );

        return new OfferCollection($offers);
    }

    public function getOffer(string $offerId): Offer
    {
        try {
            $response = $this->client()->get("/air/offers/{$offerId}")->throw();
        } catch (RequestException $e) {
            throw DuffelApiException::fromResponse($e->response);
        }

        $offer = $response->json('data', []);

        return new Offer((string) $offer['id'], $this->provider->code, $offer);
    }

    public function createOrder(string $offerId, array $passengers): ProviderOrder
    {
        $offer = $this->getOffer($offerId)->raw;

        $payload = [
            'data' => [
                'type' => 'instant',
                'selected_offers' => [$offerId],
                'payments' => [[
                    'type' => 'balance',
                    'currency' => $offer['total_currency'] ?? null,
                    'amount' => $offer['total_amount'] ?? null,
                ]],
                'passengers' => $passengers,
            ],
        ];

        try {
            $response = $this->client()->post('/air/orders', $payload)->throw();
        } catch (RequestException $e) {
            throw DuffelApiException::fromResponse($e->response);
        }

        $order = $response->json('data', []);

        return new ProviderOrder(
            (string) $order['id'],
            $order['booking_reference'] ?? null,
            'confirmed',
            $order,
        );
    }

    public function cancelOrder(string $providerOrderId): CancellationResult
    {
        try {
            $created = $this->client()
                ->post('/air/order_cancellations', ['data' => ['order_id' => $providerOrderId]])
                ->throw();

            $cancellationId = $created->json('data.id');

            $confirmed = $this->client()
                ->post("/air/order_cancellations/{$cancellationId}/actions/confirm")
                ->throw();
        } catch (RequestException $e) {
            throw DuffelApiException::fromResponse($e->response);
        }

        $data = $confirmed->json('data', []);

        return new CancellationResult(
            confirmed: true,
            refundAmount: $data['refund_amount'] ?? null,
            refundCurrency: $data['refund_currency'] ?? null,
            raw: $data,
        );
    }

    public function changeOrder(string $providerOrderId, SearchCriteria $newCriteria): OfferCollection
    {
        $payload = [
            'data' => [
                'order_id' => $providerOrderId,
                'slices' => [
                    'add' => $newCriteria->slices,
                ],
            ],
        ];

        try {
            $response = $this->client()->post('/air/order_change_requests', $payload)->throw();
        } catch (RequestException $e) {
            throw DuffelApiException::fromResponse($e->response);
        }

        $changeRequestId = $response->json('data.id');

        try {
            $offers = $this->client()
                ->get('/air/order_change_offers', ['order_change_request_id' => $changeRequestId])
                ->throw();
        } catch (RequestException $e) {
            throw DuffelApiException::fromResponse($e->response);
        }

        $collection = array_map(
            fn (array $offer) => new Offer((string) $offer['id'], $this->provider->code, $offer),
            $offers->json('data', []) ?? []
        );

        return new OfferCollection($collection);
    }

    /**
     * KNOWN GAP: Duffel's real order-change confirmation flow is not
     * verified against their live API from this codebase — the endpoint
     * and payload below are a best-effort guess (mirroring the shape of
     * order_change_requests above), not something that's actually been
     * exercised against Duffel's sandbox. Confirm against their current
     * docs before relying on this for a real change.
     *
     * @return array{new_total_amount: ?string, currency: ?string, raw: array<string, mixed>}
     */
    public function confirmChangeOffer(string $changeOfferId): array
    {
        if (! config('flights.duffel.order_change_confirmation_verified')) {
            throw new DuffelApiException(
                'Duffel order-change confirmation is disabled: the /air/order_changes call in DuffelClient '
                .'has not been verified against Duffel\'s API. Set DUFFEL_ORDER_CHANGE_CONFIRMATION_VERIFIED=true '
                .'once it has. See config/flights.php.'
            );
        }

        try {
            $response = $this->client()
                ->post('/air/order_changes', ['data' => ['order_change_offer_id' => $changeOfferId]])
                ->throw();
        } catch (RequestException $e) {
            throw DuffelApiException::fromResponse($e->response);
        }

        $data = $response->json('data', []);

        return [
            'new_total_amount' => $data['new_total_amount'] ?? $data['total_amount'] ?? null,
            'currency' => $data['new_total_currency'] ?? $data['total_currency'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * @return array<int, array{iata_code: ?string, name: ?string, city_name: ?string}>
     */
    public function suggestPlaces(string $query): array
    {
        if (mb_strlen($query) < 2) {
            return [];
        }

        try {
            $response = $this->client()
                ->get('/places/suggestions', ['query' => $query])
                ->throw();
        } catch (RequestException) {
            return [];
        }

        return $response->json('data', []) ?? [];
    }

    /**
     * The full airline list (~850+ carriers), cached for a day since it
     * barely changes and fetching it is several paginated requests.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function listAirlines(): array
    {
        if (! $this->configured()) {
            return [];
        }

        return Cache::remember("duffel:airlines:{$this->provider->id}", now()->addDay(), function () {
            $airlines = [];
            $after = null;
            $page = 0;

            // Duffel paginates ~850 airlines at up to 200/page; cap at 10
            // pages as a sane ceiling in case that list keeps growing.
            do {
                try {
                    $response = $this->client()
                        ->get('/air/airlines', array_filter(['limit' => 200, 'after' => $after]))
                        ->throw();
                } catch (RequestException) {
                    break;
                }

                foreach ($response->json('data', []) as $airline) {
                    if (filled($airline['iata_code'] ?? null) && filled($airline['name'] ?? null)) {
                        $airlines[$airline['iata_code']] = $airline['name'];
                    }
                }

                $after = $response->json('meta.after');
                $page++;
            } while ($after && $page < 10);

            asort($airlines);

            return collect($airlines)
                ->map(fn ($name, $code) => ['value' => $code, 'label' => "{$name} ({$code})"])
                ->values()
                ->all();
        });
    }
}
