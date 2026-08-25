<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\StripeGateway;
use PHPUnit\Framework\TestCase;

class StripeGatewayTest extends TestCase
{
    private StripeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new StripeGateway;
    }

    public function test_parses_a_succeeded_payment_intent(): void
    {
        $outcome = $this->gateway->parseWebhookOutcome([
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => 'pi_123', 'amount_received' => 5000]],
        ]);

        $this->assertSame(WebhookOutcome::PAYMENT_SUCCEEDED, $outcome->type);
        $this->assertSame('pi_123', $outcome->gatewayReference);
        $this->assertSame(5000, $outcome->amountCents);
    }

    public function test_parses_a_failed_payment_intent(): void
    {
        $outcome = $this->gateway->parseWebhookOutcome([
            'type' => 'payment_intent.payment_failed',
            'data' => ['object' => ['id' => 'pi_456']],
        ]);

        $this->assertSame(WebhookOutcome::PAYMENT_FAILED, $outcome->type);
        $this->assertSame('pi_456', $outcome->gatewayReference);
    }

    public function test_parses_a_refund(): void
    {
        $outcome = $this->gateway->parseWebhookOutcome([
            'type' => 'charge.refunded',
            'data' => ['object' => ['payment_intent' => 'pi_789', 'amount_refunded' => 2500]],
        ]);

        $this->assertSame(WebhookOutcome::REFUND_SUCCEEDED, $outcome->type);
        $this->assertSame('pi_789', $outcome->gatewayReference);
        $this->assertSame(2500, $outcome->amountCents);
    }

    public function test_unrecognized_event_types_are_unhandled(): void
    {
        $outcome = $this->gateway->parseWebhookOutcome(['type' => 'customer.created']);

        $this->assertSame(WebhookOutcome::UNHANDLED, $outcome->type);
    }

    public function test_reads_event_id_and_type(): void
    {
        $payload = ['id' => 'evt_1', 'type' => 'payment_intent.succeeded'];

        $this->assertSame('evt_1', $this->gateway->webhookEventId($payload));
        $this->assertSame('payment_intent.succeeded', $this->gateway->webhookEventType($payload));
    }
}
