<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Flights\DuffelApiException;
use App\Services\Flights\DuffelClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FlightSearchController extends Controller
{
    public function index(DuffelClient $duffel): View
    {
        return view('flights.search', [
            'flightApiEnabled' => (bool) Setting::get('flight_api_enabled', false),
            'airlines' => $duffel->listAirlines(),
        ]);
    }

    public function airports(Request $request, DuffelClient $duffel): JsonResponse
    {
        if (! $duffel->configured()) {
            return response()->json(['data' => []]);
        }

        $query = (string) $request->query('query', '');

        return response()->json([
            'data' => $duffel->suggestPlaces($query),
        ]);
    }

    public function search(Request $request, DuffelClient $duffel): View|RedirectResponse
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

        if (! $duffel->configured()) {
            return back()
                ->withInput()
                ->with('error', 'Flight search is not available yet. An administrator needs to configure the flight API in System Settings.');
        }

        $slices = [];

        foreach ($data['legs'] as $index => $leg) {
            $origin = $this->extractIataCode($leg['from']);
            $destination = $this->extractIataCode($leg['to']);

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

        try {
            $result = $duffel->searchOffers($slices, (int) $data['adults'], $data['cabin_class']);
        } catch (DuffelApiException $e) {
            return back()
                ->withInput()
                ->with('error', $e->getMessage());
        }

        return view('flights.results', [
            'offers' => $result['offers'],
            'raw' => $result['raw'],
            'search' => $data,
        ]);
    }

    /**
     * Pull a 3-letter IATA code out of an airport field. Accepts either a
     * bare code ("LHR") or the "City Name (LHR)" format the autocomplete
     * dropdown fills in.
     */
    private function extractIataCode(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/\(([A-Za-z]{3})\)\s*$/', $value, $matches)) {
            return strtoupper($matches[1]);
        }

        if (preg_match('/^[A-Za-z]{3}$/', $value)) {
            return strtoupper($value);
        }

        return null;
    }
}
