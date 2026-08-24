<?php

namespace App\Services\Flights;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class DuffelClient
{
    protected string $baseUrl;

    protected ?string $token;

    protected int $timeout;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) Setting::get('flight_api_base_url', 'https://api.duffel.com'), '/') ?: 'https://api.duffel.com';
        $this->token = Setting::get('flight_api_token') ?: null;
        $this->timeout = (int) Setting::get('flight_api_timeout', 30);
    }

    public function configured(): bool
    {
        return (bool) Setting::get('flight_api_enabled', false) && filled($this->token);
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

    /**
     * Search for flight offers.
     *
     * @param  array<int, array{origin: string, destination: string, departure_date: string}>  $slices
     * @return array{offers: array, raw: array}
     *
     * @throws DuffelApiException
     */
    public function searchOffers(array $slices, int $adults, string $cabinClass): array
    {
        $payload = [
            'data' => [
                'slices' => $slices,
                // Duffel requires an "age" for non-adult passenger types; since
                // the search form doesn't collect ages, only adults are sent
                // for now — children/infants are accepted by the UI but not
                // yet forwarded to the provider.
                'passengers' => array_fill(0, max(1, $adults), ['type' => 'adult']),
                'cabin_class' => $cabinClass,
            ],
        ];

        try {
            $response = $this->client()
                ->post('/air/offer_requests?return_offers=true', $payload)
                ->throw();
        } catch (RequestException $e) {
            throw DuffelApiException::fromResponse($e->response);
        }

        $json = $response->json();

        return [
            'offers' => $json['data']['offers'] ?? [],
            'raw' => $json,
        ];
    }

    /**
     * Airport/city autocomplete suggestions.
     *
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

        return Cache::remember('duffel:airlines', now()->addDay(), function () {
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
