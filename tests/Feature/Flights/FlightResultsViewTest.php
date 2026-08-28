<?php

namespace Tests\Feature\Flights;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightResultsViewTest extends TestCase
{
    use RefreshDatabase;

    private function offer(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'off_123',
            'provider_code' => 'duffel',
            'total_amount' => '432.10',
            'total_currency' => 'GBP',
            'owner' => ['name' => 'British Airways', 'iata_code' => 'BA', 'logo_symbol_url' => 'https://example.test/ba.svg'],
            'conditions' => ['refund_before_departure' => ['allowed' => true]],
            'slices' => [[
                'origin' => ['iata_code' => 'LHR'],
                'destination' => ['iata_code' => 'JFK'],
                'duration' => 'PT7H55M',
                'segments' => [[
                    'origin' => ['iata_code' => 'LHR'],
                    'destination' => ['iata_code' => 'JFK'],
                    'departing_at' => '2026-09-01T08:30:00',
                    'arriving_at' => '2026-09-01T11:25:00',
                    'marketing_carrier' => ['iata_code' => 'BA'],
                    'marketing_carrier_flight_number' => '175',
                ]],
            ]],
        ], $overrides);
    }

    private function render(array $offers): string
    {
        return view('flights.results', [
            'offers' => $offers,
            'search' => [
                'legs' => [['from' => 'London (LHR)', 'to' => 'New York (JFK)']],
                'cabin_class' => 'economy',
                'adults' => 2,
                'children' => 0,
                'infants' => 0,
            ],
        ])->render();
    }

    public function test_it_renders_a_flight_card_with_formatted_times_price_and_duration(): void
    {
        $html = $this->render([$this->offer()]);

        $this->assertStringContainsString('1 flight found', $html);
        $this->assertStringContainsString('08:30', $html);          // departing_at
        $this->assertStringContainsString('11:25', $html);          // arriving_at
        $this->assertStringContainsString('7h 55m', $html);         // ISO-8601 duration parsed
        $this->assertStringContainsString('GBP 432.10', $html);     // number_format price
        $this->assertStringContainsString('Direct', $html);
        $this->assertStringContainsString('Cheapest', $html);       // only offer => cheapest badge
        $this->assertStringContainsString('Refundable', $html);
        $this->assertStringContainsString('BA175', $html);          // flight number
    }

    public function test_it_renders_a_helpful_empty_state_when_there_are_no_offers(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('0 flights found', $html);
        $this->assertStringContainsString('No offers for this search', $html);
    }

    public function test_multi_segment_slices_are_reported_as_stops(): void
    {
        $offer = $this->offer([
            'slices' => [[
                'duration' => 'PT12H00M',
                'segments' => [
                    ['origin' => ['iata_code' => 'LHR'], 'destination' => ['iata_code' => 'KEF'], 'departing_at' => '2026-09-01T08:00:00', 'arriving_at' => '2026-09-01T10:00:00'],
                    ['origin' => ['iata_code' => 'KEF'], 'destination' => ['iata_code' => 'JFK'], 'departing_at' => '2026-09-01T12:00:00', 'arriving_at' => '2026-09-01T18:00:00'],
                ],
            ]],
        ]);

        $html = $this->render([$offer]);

        $this->assertStringContainsString('1 stop', $html);
    }
}
