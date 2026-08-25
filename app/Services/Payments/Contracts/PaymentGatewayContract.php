<?php

namespace App\Services\Payments\Contracts;

use App\Models\Payment;
use App\Services\Payments\DTO\WebhookOutcome;
use Illuminate\Http\Request;

/**
 * Implemented once per gateway (Stripe, PayPal — see docs/ROADMAP.md, Phase
 * 5) so PaymentService and the booking flow never branch on which one is
 * in use. A subscription payment (Phase 7) reuses the exact same contract.
 */
interface PaymentGatewayContract
{
    public function code(): string;

    public function configured(): bool;

    /**
     * Creates the gateway-side payment object for an already-persisted,
     * still-pending Payment row. Returns whatever the client needs to
     * finish the payment: a Stripe client_secret for Elements, or a PayPal
     * order id + approval URL to redirect to.
     *
     * @return array<string, mixed>
     */
    public function createIntent(Payment $payment): array;

    /**
     * PayPal-specific: finalizes an order the customer has already approved
     * client-side. Stripe confirms card payment client-side instead, so its
     * implementation is a no-op — confirmation there comes only from the
     * webhook (see PaymentService::handleWebhookEvent()).
     *
     * @return array<string, mixed>
     */
    public function capture(Payment $payment): array;

    /**
     * @return array{reference: ?string, status: string}
     */
    public function refund(Payment $payment, int $amountCents): array;

    /**
     * Polls the gateway directly for a payment's current status — used
     * only by the nightly reconciliation sweep (see docs/ROADMAP.md, Phase
     * 5) to catch a payment stuck 'pending' because its webhook was never
     * delivered. Never the primary confirmation path; that's always the
     * webhook (see PaymentService::applyWebhookOutcome()).
     *
     * @return 'succeeded'|'failed'|'pending'
     */
    public function retrieveStatus(Payment $payment): string;

    /**
     * Needs the raw Request (headers + exact body bytes) to check the
     * signature — everything else in this interface works off the already
     *-decoded JSON payload instead, so it can run later inside a queued
     * job that never sees the original Request (see
     * App\Jobs\ProcessPaymentWebhookEvent).
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * The gateway's own idea of "which event is this" — used to dedupe
     * against payment_webhook_events before any business logic runs.
     *
     * @param  array<string, mixed>  $payload
     */
    public function webhookEventId(array $payload): ?string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function webhookEventType(array $payload): ?string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function parseWebhookOutcome(array $payload): WebhookOutcome;
}
