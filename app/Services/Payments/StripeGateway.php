<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Setting;
use App\Services\Payments\Contracts\PaymentGatewayContract;
use App\Services\Payments\DTO\WebhookOutcome;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

class StripeGateway implements PaymentGatewayContract
{
    public function code(): string
    {
        return Payment::GATEWAY_STRIPE;
    }

    public function configured(): bool
    {
        return filled($this->secretKey()) && filled($this->webhookSecret());
    }

    /**
     * @return array<string, mixed>
     */
    public function createIntent(Payment $payment): array
    {
        // The idempotency key travels as a request option (Stripe-Idempotency-Key
        // header), not a body field — a retried request with the same key
        // returns the original PaymentIntent instead of creating a second one.
        $intent = $this->client()->paymentIntents->create([
            'amount' => $payment->amount_cents,
            'currency' => strtolower($payment->currency),
            'metadata' => [
                'payment_id' => (string) $payment->id,
                'payable_type' => $payment->payable_type,
                'payable_id' => (string) $payment->payable_id,
            ],
        ], ['idempotency_key' => $payment->idempotency_key]);

        $payment->update(['gateway_reference' => $intent->id]);

        return [
            'client_secret' => $intent->client_secret,
            'publishable_key' => $this->publishableKey(),
        ];
    }

    /**
     * Stripe confirms the card client-side (Stripe.js/Elements) — there is
     * no server-side capture step to mirror PayPal's, so this is
     * intentionally a no-op. Confirmation only ever comes from the
     * `payment_intent.succeeded` webhook (see PaymentService).
     */
    public function capture(Payment $payment): array
    {
        return [];
    }

    /**
     * @return array{reference: ?string, status: string}
     */
    public function refund(Payment $payment, int $amountCents): array
    {
        $refund = $this->client()->refunds->create([
            'payment_intent' => $payment->gateway_reference,
            'amount' => $amountCents,
        ], ['idempotency_key' => $payment->idempotency_key.'-refund-'.$amountCents]);

        return [
            'reference' => $refund->id,
            'status' => $refund->status === 'succeeded' ? 'succeeded' : 'pending',
        ];
    }

    public function retrieveStatus(Payment $payment): string
    {
        $intent = $this->client()->paymentIntents->retrieve($payment->gateway_reference);

        return match ($intent->status) {
            'succeeded' => 'succeeded',
            'canceled' => 'failed',
            default => 'pending',
        };
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        try {
            Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $this->webhookSecret(),
            );

            return true;
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return false;
        }
    }

    public function parseWebhookOutcome(array $payload): WebhookOutcome
    {
        $type = $payload['type'] ?? '';
        $object = $payload['data']['object'] ?? [];

        return match ($type) {
            'payment_intent.succeeded' => new WebhookOutcome(
                WebhookOutcome::PAYMENT_SUCCEEDED,
                $object['id'] ?? null,
                $object['amount_received'] ?? $object['amount'] ?? null,
            ),
            'payment_intent.payment_failed' => new WebhookOutcome(WebhookOutcome::PAYMENT_FAILED, $object['id'] ?? null),
            'charge.refunded' => new WebhookOutcome(
                WebhookOutcome::REFUND_SUCCEEDED,
                $object['payment_intent'] ?? null,
                $object['amount_refunded'] ?? null,
            ),
            default => new WebhookOutcome(WebhookOutcome::UNHANDLED, null),
        };
    }

    public function webhookEventId(array $payload): ?string
    {
        return $payload['id'] ?? null;
    }

    public function webhookEventType(array $payload): ?string
    {
        return $payload['type'] ?? null;
    }

    private function client(): StripeClient
    {
        return new StripeClient($this->secretKey());
    }

    private function secretKey(): ?string
    {
        return Setting::get('stripe_secret_key');
    }

    private function publishableKey(): ?string
    {
        return Setting::get('stripe_publishable_key');
    }

    private function webhookSecret(): ?string
    {
        return Setting::get('stripe_webhook_secret');
    }
}
