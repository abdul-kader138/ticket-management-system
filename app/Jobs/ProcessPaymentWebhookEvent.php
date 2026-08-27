<?php

namespace App\Jobs;

use App\Models\PaymentWebhookEvent;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * All the actual business logic (updating Payment/Booking state, calling
 * the flight provider to issue a ticket) runs here, off the request cycle
 * that received the webhook — see docs/ROADMAP.md, Phase 5: "the webhook
 * endpoint is dumb on purpose."
 */
class ProcessPaymentWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $paymentWebhookEventId) {}

    /**
     * Spread the 5 attempts out instead of hammering a gateway (or our own
     * DB) five times in the default ~immediate succession — a webhook that
     * fails to process is usually waiting on something transient.
     *
     * @return array<int, int> seconds before attempts 2..5
     */
    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(PaymentGatewayManager $gateways, PaymentService $payments): void
    {
        $event = PaymentWebhookEvent::find($this->paymentWebhookEventId);

        if (! $event || $event->isProcessed()) {
            return;
        }

        $gateway = $gateways->resolve($event->gateway);
        $outcome = $gateway->parseWebhookOutcome($event->payload);

        $payments->applyWebhookOutcome($outcome, $event->gateway);

        $event->update(['processed_at' => now()]);
    }

    /**
     * Every retry exhausted. Leave `processed_at` null on purpose — the raw
     * event row stays on record as unprocessed so the nightly
     * payments:reconcile safety net (and a human) can still pick it up —
     * and make the failure loud in the audit log rather than letting it
     * disappear into the failed_jobs table unnoticed.
     */
    public function failed(Throwable $exception): void
    {
        Log::stack(['stack', 'audit'])->critical('Payment webhook event permanently failed to process after retries', [
            'payment_webhook_event_id' => $this->paymentWebhookEventId,
            'error' => $exception->getMessage(),
        ]);
    }
}
