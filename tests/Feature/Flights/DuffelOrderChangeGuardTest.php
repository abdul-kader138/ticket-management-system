<?php

namespace Tests\Feature\Flights;

use App\Models\FlightProvider;
use App\Services\Flights\DuffelApiException;
use App\Services\Flights\DuffelClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DuffelOrderChangeGuardTest extends TestCase
{
    private function client(): DuffelClient
    {
        return new DuffelClient(new FlightProvider([
            'code' => 'duffel',
            'credentials' => ['token' => 'test-token'],
            'is_enabled' => true,
            'base_url' => 'https://api.duffel.com',
            'timeout' => 30,
        ]));
    }

    public function test_confirm_change_offer_is_refused_while_unverified(): void
    {
        config(['flights.duffel.order_change_confirmation_verified' => false]);
        Http::fake();

        $this->expectException(DuffelApiException::class);
        $this->expectExceptionMessage('has not been verified');

        $this->client()->confirmChangeOffer('oco_123');

        Http::assertNothingSent();
    }

    public function test_confirm_change_offer_proceeds_once_marked_verified(): void
    {
        config(['flights.duffel.order_change_confirmation_verified' => true]);
        Http::fake([
            '*/air/order_changes' => Http::response([
                'data' => ['new_total_amount' => '250.00', 'new_total_currency' => 'USD', 'id' => 'ochg_1'],
            ]),
        ]);

        $result = $this->client()->confirmChangeOffer('oco_123');

        $this->assertSame('250.00', $result['new_total_amount']);
        $this->assertSame('USD', $result['currency']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/air/order_changes'));
    }
}
