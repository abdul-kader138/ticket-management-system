<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Payments\PayPalGateway;
use PHPUnit\Framework\TestCase;

class PayPalGatewayTest extends TestCase
{
    private PayPalGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gateway = new PayPalGateway;
    }

    public function test_parses_a_completed_capture(): void
    {
        $outcome = $this->gateway->parseWebhookOutcome([
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'amount' => ['value' => '49.99'],
                'supplementary_data' => ['related_ids' => ['order_id' => 'order_abc']],
            ],
        ]);

        $this->assertSame(WebhookOutcome::PAYMENT_SUCCEEDED, $outcome->type);
        $this->assertSame('order_abc', $outcome->gatewayReference);
        $this->assertSame(4999, $outcome->amountCents);
    }

    public function test_parses_a_denied_capture(): void
    {
        $outcome = $this->gateway->parseWebhookOutcome([
            'event_type' => 'PAYMENT.CAPTURE.DENIED',
            'resource' => ['supplementary_data' => ['related_ids' => ['order_id' => 'order_xyz']]],
        ]);

        $this->assertSame(WebhookOutcome::PAYMENT_FAILED, $outcome->type);
        $this->assertSame('order_xyz', $outcome->gatewayReference);
    }

    public function test_parses_a_refund(): void
    {
        $outcome = $this->gateway->parseWebhookOutcome([
            'event_type' => 'PAYMENT.CAPTURE.REFUNDED',
            'resource' => [
                'amount' => ['value' => '10.00'],
                'supplementary_data' => ['related_ids' => ['order_id' => 'order_r1']],
            ],
        ]);

        $this->assertSame(WebhookOutcome::REFUND_SUCCEEDED, $outcome->type);
        $this->assertSame(1000, $outcome->amountCents);
    }

    public function test_unrecognized_event_types_are_unhandled(): void
    {
        $outcome = $this->gateway->parseWebhookOutcome(['event_type' => 'CHECKOUT.ORDER.APPROVED']);

        $this->assertSame(WebhookOutcome::UNHANDLED, $outcome->type);
    }

    public function test_reads_event_id_and_type(): void
    {
        $payload = ['id' => 'WH-1', 'event_type' => 'PAYMENT.CAPTURE.COMPLETED'];

        $this->assertSame('WH-1', $this->gateway->webhookEventId($payload));
        $this->assertSame('PAYMENT.CAPTURE.COMPLETED', $this->gateway->webhookEventType($payload));
    }
}
