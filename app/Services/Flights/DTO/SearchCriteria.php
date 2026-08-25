<?php

namespace App\Services\Flights\DTO;

/**
 * One-way/round-trip/multi-city are all just "one or more slices" to a
 * provider API — the trip-type distinction only matters to the search form
 * UI (see App\Http\Controllers\FlightSearchController), not this layer.
 */
final class SearchCriteria
{
    /**
     * @param  array<int, array{origin: string, destination: string, departure_date: string}>  $slices
     */
    public function __construct(
        public readonly array $slices,
        public readonly int $adults,
        public readonly int $children = 0,
        public readonly int $infants = 0,
        public readonly string $cabinClass = 'economy',
    ) {}

    /**
     * A stable cache/idempotency key for this exact search — used by
     * FlightProviderManager to short-circuit a repeat search against the
     * cache instead of re-hitting a paid provider API (see docs/ROADMAP.md,
     * Phase 2/3).
     */
    public function signature(): string
    {
        return hash('sha256', json_encode([
            $this->slices, $this->adults, $this->children, $this->infants, $this->cabinClass,
        ]));
    }

    /**
     * Pulls a 3-letter IATA code out of an airport field. Accepts either a
     * bare code ("LHR") or the "City Name (LHR)" format the search form's
     * and change-search's autocomplete dropdowns both fill in — see
     * App\Http\Controllers\FlightSearchController and
     * App\Http\Controllers\Api\V1\BookingChangeController.
     */
    public static function extractIataCode(string $value): ?string
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
