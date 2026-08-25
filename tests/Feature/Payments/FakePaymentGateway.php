<?php

namespace Tests\Feature\Payments;

use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentGatewayContract;
use App\Services\Payments\DTO\WebhookOutcome;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A minimal PaymentGatewayContract implementation for exercising
 * PaymentService/PaymentWebhookController without calling Stripe or
 * PayPal — see FakePaymentGatewayManager for how tests substitute this in.
 * Deliberately reads a flat, test-only payload shape (not Stripe's or
 * PayPal's real webhook JSON) since the gateway-specific parsing already
 * has its own dedicated coverage.
 */
class FakePaymentGateway implements PaymentGatewayContract
{
    public static bool $configured = true;

    public static bool $signatureValid = true;

    public static bool $shouldThrowOnCreateIntent = false;

    public static array $captureResult = ['status' => 'COMPLETED'];

    public static string $retrieveStatusResult = 'pending';

    public static function reset(): void
    {
        self::$configured = true;
        self::$signatureValid = true;
        self::$shouldThrowOnCreateIntent = false;
        self::$captureResult = ['status' => 'COMPLETED'];
        self::$retrieveStatusResult = 'pending';
    }

    public function code(): string
    {
        return 'fake';
    }

    public function configured(): bool
    {
        return self::$configured;
    }

    public function createIntent(Payment $payment): array
    {
        if (self::$shouldThrowOnCreateIntent) {
            throw new \RuntimeException('Simulated gateway outage.');
        }

        $reference = 'fake_'.Str::random(10);
        $payment->update(['gateway_reference' => $reference]);

        return ['reference' => $reference];
    }

    public function capture(Payment $payment): array
    {
        return self::$captureResult;
    }

    public function refund(Payment $payment, int $amountCents): array
    {
        return ['reference' => 'refund_'.Str::random(10), 'status' => 'succeeded'];
    }

    public function retrieveStatus(Payment $payment): string
    {
        return self::$retrieveStatusResult;
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return self::$signatureValid;
    }

    public function webhookEventId(array $payload): ?string
    {
        return $payload['id'] ?? null;
    }

    public function webhookEventType(array $payload): ?string
    {
        return $payload['outcome_type'] ?? null;
    }

    public function parseWebhookOutcome(array $payload): WebhookOutcome
    {
        return new WebhookOutcome(
            $payload['outcome_type'] ?? WebhookOutcome::UNHANDLED,
            $payload['gateway_reference'] ?? null,
            $payload['amount_cents'] ?? null,
        );
    }
}
