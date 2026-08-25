<?php

namespace App\Http\Controllers;

use App\Services\Flights\DTO\SearchCriteria;
use App\Services\Flights\DuffelApiException;
use App\Services\Flights\FlightProviderManager;
use App\Services\Flights\SearchQuotaExceededException;
use App\Services\Flights\SearchQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FlightSearchController extends Controller
{
    public function index(Request $request, FlightProviderManager $providers, SearchQuotaService $quota): View
    {
        return view('flights.search', [
            'flightApiEnabled' => $providers->hasConfiguredProvider(),
            'airlines' => $providers->listAirlines(),
            'quotaRemaining' => $quota->remaining($request->user()),
        ]);
    }

    public function airports(Request $request, FlightProviderManager $providers): JsonResponse
    {
        $query = (string) $request->query('query', '');

        return response()->json([
            'data' => $providers->suggestPlaces($query),
        ]);
    }

    public function search(Request $request, FlightProviderManager $providers): View|RedirectResponse
    {
        $data = $request->validate([
            'trip_type' => ['required', 'in:oneway,roundtrip,multicity'],
            'legs' => ['required', 'array', 'min:1'],
            'legs.*.from' => ['required', 'string', 'max:150'],
            'legs.*.to' => ['required', 'string', 'max:150'],
            'legs.*.date' => ['required', 'date'],
            'adults' => ['required', 'integer', 'min:1', 'max:9'],
            'children' => ['nullable', 'integer', 'min:0', 'max:9'],
            'infants' => ['nullable', 'integer', 'min:0', 'max:9'],
            'cabin_class' => ['required', 'in:economy,premium_economy,business,first'],
            'flexible_dates' => ['nullable', 'boolean'],
            'fare_type' => ['nullable', 'string', 'max:50'],
        ]);

        if (! $providers->hasConfiguredProvider()) {
            return back()
                ->withInput()
                ->with('error', 'Flight search is not available yet. An administrator needs to configure a flight provider in Flight Providers.');
        }

        $slices = [];

        foreach ($data['legs'] as $index => $leg) {
            $origin = SearchCriteria::extractIataCode($leg['from']);
            $destination = SearchCriteria::extractIataCode($leg['to']);

            if (! $origin || ! $destination) {
                return back()
                    ->withInput()
                    ->withErrors([
                        "legs.{$index}.from" => 'Please pick an airport from the suggestions for both departure and arrival.',
                    ]);
            }

            $slices[] = [
                'origin' => $origin,
                'destination' => $destination,
                'departure_date' => $leg['date'],
            ];
        }

        $criteria = new SearchCriteria(
            slices: $slices,
            adults: (int) $data['adults'],
            children: (int) ($data['children'] ?? 0),
            infants: (int) ($data['infants'] ?? 0),
            cabinClass: $data['cabin_class'],
        );

        try {
            $offers = $providers->search($criteria, $request->user())->toArray();
        } catch (SearchQuotaExceededException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (DuffelApiException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return view('flights.results', [
            'offers' => $offers,
            'search' => $data,
        ]);
    }
}
