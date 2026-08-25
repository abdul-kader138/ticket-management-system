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
}
