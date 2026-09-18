<?php

namespace Tests\Feature\Flights;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightSearchViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_translated_dropdown_expressions_survive_html_parsing(): void
    {
        $this->withoutVite();

        $html = view('flights.search', [
            'flightApiEnabled' => false,
            'airlines' => [],
            'quotaRemaining' => ['day' => 10, 'month' => 100],
        ])->render();

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $templates = (new DOMXPath($document))->query('//template[@x-for]');
        $expressions = [];
        foreach ($templates as $template) {
            $expression = $template->getAttribute('x-for');
            if (preg_match('/^(p|field) in \[/', $expression)) {
                $expressions[] = $expression;
                $this->assertStringEndsWith(']', trim($expression));
            }
        }

        $this->assertCount(3, $expressions);
        $this->assertStringContainsString("key: 'infants'", $expressions[0]);
        $this->assertStringContainsString("name: 'source'", $expressions[1]);
        $this->assertStringContainsString("name: 'fare_type'", $expressions[2]);
    }
}
