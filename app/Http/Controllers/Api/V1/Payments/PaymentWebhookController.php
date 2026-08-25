<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhookEvent;
use App\Models\PaymentWebhookEvent;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public — no auth:sanctum, no CSRF (see bootstrap/app.php's
 * validateCsrfTokens exception for this path). Stripe and PayPal are the
 * only expected callers; the signature check below is what actually
 * authenticates the request, not Laravel's own auth layer.
 *
 * Deliberately does nothing but verify + dedupe + dispatch — see
 * App\Jobs\ProcessPaymentWebhookEvent for the actual business logic.
 */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, string $gateway, PaymentGatewayManager $gateways): Response
    {
        $driver = $gateways->resolve($gateway);

        if (! $driver->verifyWebhookSignature($request)) {
            return response('Invalid signature.', 400);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $eventId = $driver->webhookEventId($payload);

        if (! $eventId) {
            return response('Malformed event.', 400);
        }

        $event = PaymentWebhookEvent::firstOrCreate(
            ['gateway' => $gateway, 'event_id' => $eventId],
            ['event_type' => $driver->webhookEventType($payload), 'payload' => $payload],
        );

        if ($event->wasRecentlyCreated) {
            ProcessPaymentWebhookEvent::dispatch($event->id);
        }

        return response('OK', 200);
    }
}
