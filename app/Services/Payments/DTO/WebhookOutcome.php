<?php

namespace App\Services\Payments\DTO;

/**
 * A gateway-agnostic reading of one webhook event — PaymentService acts on
 * this, never on Stripe's or PayPal's own event shape directly.
 */
final class WebhookOutcome
{
    public const PAYMENT_SUCCEEDED = 'payment_succeeded';

    public const PAYMENT_FAILED = 'payment_failed';

    public const REFUND_SUCCEEDED = 'refund_succeeded';

    public const UNHANDLED = 'unhandled';

    public function __construct(
        public readonly string $type,
        public readonly ?string $gatewayReference,
        public readonly ?int $amountCents = null,
    ) {}
}
