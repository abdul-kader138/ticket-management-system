<?php

namespace App\Services\Payments;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\UserSubscription;
use App\Services\Bookings\BookingService;
use App\Services\Payments\DTO\WebhookOutcome;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * See docs/ROADMAP.md, Phase 5 (bookings) and Phase 7 (subscriptions).
 * Two entry points from the outside world: initiate()/chargeForPayable()
 * when a customer starts a charge, and applyWebhookOutcome() when a
 * gateway tells us what actually happened — the only path that's ever
 * trusted to confirm a booking or activate a subscription (see
 * markSucceeded()).
 */
class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly BookingService $bookings,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * @return array{payment: Payment, client_data: array<string, mixed>}
     *
     * @throws PaymentException
     */
    public function initiate(Booking $booking, string $gatewayCode): array
    {
        if (! $booking->isHeld()) {
            throw new PaymentException('This booking cannot accept payment right now.');
        }

        if ($booking->hasExpired()) {
            throw new PaymentException('This booking hold has expired. Please search again.');
        }

        $payment = DB::transaction(function () use ($booking, $gatewayCode) {
            $payment = $this->createPendingPayment($booking, $gatewayCode, $booking->total_price_cents, $booking->currency);

            $booking->transitionTo(Booking::STATUS_PENDING_PAYMENT, actorType: 'user', actorId: $booking->user_id);

            return $payment;
        });

        try {
            $clientData = $this->startGatewayIntent($payment);
        } catch (PaymentException $e) {
            $booking->transitionTo(Booking::STATUS_HELD, actorType: 'system', payload: ['reason' => 'gateway_error']);

            throw $e;
        }

        return ['payment' => $payment->fresh(), 'client_data' => $clientData];
    }

    /**
     * A charge against an already-confirmed booking that isn't the original
     * purchase — today that's only a Phase 6 fare-difference on a change,
     * but the shape is generic. Unlike initiate(), the booking's own status
     * is untouched: it's already 'changed'/'confirmed' and stays that way
     * regardless of whether this charge succeeds (see
     * PaymentService::markSucceeded(), which only drives the original
     * held → confirmed transition and safely no-ops for any other status).
     *
     * @return array{payment: Payment, client_data: array<string, mixed>}
     *
     * @throws PaymentException
     */
    public function chargeAdditional(Booking $booking, int $amountCents, string $gatewayCode, string $reason = ''): array
    {
        return $this->chargeForPayable($booking, $amountCents, $booking->currency, $gatewayCode, $reason ?: 'additional_charge');
    }

    /**
     * A charge against any payable (Booking or UserSubscription today) that
     * isn't a Booking's original held → pending_payment purchase — see
     * initiate() for that specific flow, which additionally drives the
     * booking's own status transition. This one leaves the payable's
     * status alone; markSucceeded()/markFailed() below decide what (if
     * anything) to do with it once the payment resolves.
     *
     * @return array{payment: Payment, client_data: array<string, mixed>}
     *
     * @throws PaymentException
     */
    public function chargeForPayable(Model $payable, int $amountCents, string $currency, string $gatewayCode, string $reason = ''): array
    {
        $payment = $this->createPendingPayment($payable, $gatewayCode, $amountCents, $currency, ['reason' => $reason ?: 'charge']);
        $clientData = $this->startGatewayIntent($payment);

        return ['payment' => $payment->fresh(), 'client_data' => $clientData];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function createPendingPayment(Model $payable, string $gatewayCode, int $amountCents, string $currency, array $metadata = []): Payment
    {
        $attempt = Payment::where('payable_type', $payable::class)->where('payable_id', $payable->id)->count() + 1;
        $slug = str($payable::class)->classBasename()->snake();

        return Payment::create([
            'payable_type' => $payable::class,
            'payable_id' => $payable->id,
            'user_id' => $payable->user_id,
            'gateway' => $gatewayCode,
            'status' => Payment::STATUS_PENDING,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'idempotency_key' => "{$slug}-{$payable->id}-{$gatewayCode}-{$attempt}",
            'metadata' => $metadata,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    private function startGatewayIntent(Payment $payment): array
    {
        // The gateway call is a slow external HTTP request — kept outside
        // any DB transaction so it never holds a lock while it runs.
        try {
            return $this->gateways->get($payment->gateway)->createIntent($payment);
        } catch (Throwable $e) {
            $payment->update(['status' => Payment::STATUS_FAILED]);

            throw new PaymentException('Could not start payment: '.$e->getMessage());
        }
    }

    /**
     * PayPal-only: finalizes an order the customer approved client-side.
     * The webhook is still the system of record (see markSucceeded()) —
     * this just lets the SPA show a result immediately instead of polling.
     *
     * @return array<string, mixed>
     */
    public function capturePayPalOrder(Payment $payment): array
    {
        $gateway = $this->gateways->get(Payment::GATEWAY_PAYPAL);
        $result = $gateway->capture($payment);

        if (($result['status'] ?? null) === 'COMPLETED') {
            $this->markSucceeded($payment, $payment->amount_cents);
        }

        return $result;
    }

    /**
     * Called only after PaymentGatewayContract::verifyWebhookSignature()
     * has passed and the event has been deduped against
     * payment_webhook_events — see App\Jobs\ProcessPaymentWebhookEvent.
     */
    public function applyWebhookOutcome(WebhookOutcome $outcome, string $gatewayCode): void
    {
        if ($outcome->type === WebhookOutcome::UNHANDLED || ! $outcome->gatewayReference) {
            return;
        }

        $payment = Payment::where('gateway', $gatewayCode)
            ->where('gateway_reference', $outcome->gatewayReference)
            ->first();

        if (! $payment) {
            Log::stack(['stack', 'audit'])->warning('Payment webhook referenced an unknown payment', [
                'gateway' => $gatewayCode,
                'reference' => $outcome->gatewayReference,
                'type' => $outcome->type,
            ]);

            return;
        }

        match ($outcome->type) {
            WebhookOutcome::PAYMENT_SUCCEEDED => $this->markSucceeded($payment, $outcome->amountCents ?? $payment->amount_cents),
            WebhookOutcome::PAYMENT_FAILED => $this->markFailed($payment),
            WebhookOutcome::REFUND_SUCCEEDED => $this->recordRefund($payment, $outcome->amountCents ?? $payment->amount_cents, 'gateway_initiated'),
            default => null,
        };
    }

    /**
     * Admin/customer-initiated refund (see docs/ROADMAP.md, Phase 6) — the
     * gateway call is synchronous, so the local Refund/Payment/Booking
     * state is updated here immediately rather than waiting on the webhook
     * that will also arrive for it (recordRefund() is dedup-safe either way).
     *
     * @throws PaymentException
     */
    public function refund(Payment $payment, int $amountCents, string $reason = ''): Refund
    {
        if (! $payment->isSucceeded()) {
            throw new PaymentException('Only a succeeded payment can be refunded.');
        }

        $gateway = $this->gateways->get($payment->gateway);
        $result = $gateway->refund($payment, $amountCents);

        return $this->recordRefund($payment, $amountCents, $reason, $result['reference'] ?? null);
    }

    /**
     * Catches a payment stuck 'pending' because its webhook was never
     * delivered — see App\Console\Commands\ReconcilePendingPayments,
     * scheduled nightly. Not the primary confirmation path.
     */
    public function reconcile(Payment $payment): void
    {
        if (! $payment->gateway_reference) {
            return;
        }

        $status = $this->gateways->get($payment->gateway)->retrieveStatus($payment);

        match ($status) {
            'succeeded' => $this->markSucceeded($payment, $payment->amount_cents),
            'failed' => $this->markFailed($payment),
            default => null,
        };
    }

    private function markSucceeded(Payment $payment, int $amountCents): void
    {
        if ($payment->isSucceeded()) {
            return; // Already processed — a duplicate webhook delivery.
        }

        $payment->update(['status' => Payment::STATUS_SUCCEEDED]);

        $payable = $payment->payable;

        if ($payable instanceof Booking && $payable->status === Booking::STATUS_PENDING_PAYMENT) {
            try {
                $this->bookings->confirmWithProvider($payable);
            } catch (Throwable $e) {
                // Money has been captured but the ticket wasn't issued —
                // this must not fail silently. Booking stays
                // 'pending_payment' so it's visibly stuck in the admin
                // resource until someone reconciles it by hand; see
                // docs/ROADMAP.md, Phase 9 for the alerting this really needs.
                Log::stack(['stack', 'audit'])->critical('Payment succeeded but provider order creation failed — needs manual reconciliation', [
                    'booking_id' => $payable->id,
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        if ($payable instanceof UserSubscription && $payable->status === UserSubscription::STATUS_PENDING_PAYMENT) {
            $this->subscriptions->activate($payable);
        }
    }

    private function markFailed(Payment $payment): void
    {
        if ($payment->status === Payment::STATUS_FAILED) {
            return;
        }

        $payment->update(['status' => Payment::STATUS_FAILED]);

        $payable = $payment->payable;

        if ($payable instanceof Booking && $payable->status === Booking::STATUS_PENDING_PAYMENT) {
            $payable->transitionTo(Booking::STATUS_HELD, actorType: 'system', payload: ['reason' => 'payment_failed']);

            return;
        }

        if ($payable instanceof UserSubscription && $payable->status === UserSubscription::STATUS_PENDING_PAYMENT) {
            $this->subscriptions->markFailed($payable);
        }
    }

    private function recordRefund(Payment $payment, int $amountCents, string $reason, ?string $gatewayReference = null): Refund
    {
        if ($payment->totalRefundedCents() >= $amountCents) {
            // Already recorded — either our own refund() call already
            // wrote this row, or a duplicate webhook delivery for one that
            // was.
            return $payment->refunds()->latest()->firstOrFail();
        }

        $refund = $payment->refunds()->create([
            'amount_cents' => $amountCents,
            'currency' => $payment->currency,
            'reason' => $reason,
            'status' => Refund::STATUS_SUCCEEDED,
            'gateway_reference' => $gatewayReference,
        ]);

        $totalRefunded = $payment->totalRefundedCents();
        $payment->update([
            'status' => $totalRefunded >= $payment->amount_cents
                ? Payment::STATUS_REFUNDED
                : Payment::STATUS_PARTIALLY_REFUNDED,
        ]);

        $booking = $payment->payable;

        if (
            $booking instanceof Booking
            && $totalRefunded >= $payment->amount_cents
            && in_array($booking->status, [Booking::STATUS_CONFIRMED, Booking::STATUS_CHANGED], true)
        ) {
            $booking->transitionTo(Booking::STATUS_REFUNDED, actorType: 'system');
        }

        return $refund;
    }
}
