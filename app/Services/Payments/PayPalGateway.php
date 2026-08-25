<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\Setting;
use App\Services\Payments\Contracts\PaymentGatewayContract;
use App\Services\Payments\DTO\WebhookOutcome;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * PayPal deprecated its old Checkout SDKs in favor of calling the REST API
 * (Orders v2, OAuth2 client-credentials) directly — same reasoning as
 * DuffelClient talking straight to Duffel's REST API with no vendor SDK.
 */
class PayPalGateway implements PaymentGatewayContract
{
    public function code(): string
    {
        return Payment::GATEWAY_PAYPAL;
    }

    public function configured(): bool
    {
        return filled($this->clientId()) && filled($this->clientSecret()) && filled($this->webhookId());
    }

    /**
     * @return array<string, mixed>
     */
    public function createIntent(Payment $payment): array
    {
        $response = $this->client()
            ->withHeaders(['PayPal-Request-Id' => $payment->idempotency_key])
            ->post('/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'custom_id' => (string) $payment->id,
                    'amount' => [
                        'currency_code' => $payment->currency,
                        'value' => number_format($payment->amount_cents / 100, 2, '.', ''),
                    ],
                ]],
            ])
            ->throw()
            ->json();

        $payment->update(['gateway_reference' => $response['id']]);

        $approveUrl = collect($response['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

        return ['order_id' => $response['id'], 'approval_url' => $approveUrl];
    }

    /**
     * Finalizes an order the customer has already approved on PayPal's
     * site. The webhook (PAYMENT.CAPTURE.COMPLETED) is still what
     * PaymentService trusts to actually confirm the booking — this just
     * lets the SPA show an immediate result instead of a spinner.
     *
     * @return array<string, mixed>
     */
    public function capture(Payment $payment): array
    {
        $response = $this->client()
            ->withHeaders(['PayPal-Request-Id' => $payment->idempotency_key.'-capture'])
            ->post("/v2/checkout/orders/{$payment->gateway_reference}/capture")
            ->throw()
            ->json();

        $captureId = data_get($response, 'purchase_units.0.payments.captures.0.id');

        if ($captureId) {
            $payment->update(['metadata' => array_merge($payment->metadata ?? [], ['paypal_capture_id' => $captureId])]);
        }

        return ['status' => $response['status'] ?? 'UNKNOWN', 'capture_id' => $captureId];
    }

    /**
     * @return array{reference: ?string, status: string}
     */
    public function refund(Payment $payment, int $amountCents): array
    {
        $captureId = data_get($payment->metadata, 'paypal_capture_id');

        if (! $captureId) {
            throw new PaymentException('Cannot refund a PayPal payment with no recorded capture id.');
        }

        $response = $this->client()
            ->withHeaders(['PayPal-Request-Id' => $payment->idempotency_key.'-refund-'.$amountCents])
            ->post("/v2/payments/captures/{$captureId}/refund", [
                'amount' => [
                    'currency_code' => $payment->currency,
                    'value' => number_format($amountCents / 100, 2, '.', ''),
                ],
            ])
            ->throw()
            ->json();

        return [
            'reference' => $response['id'] ?? null,
            'status' => ($response['status'] ?? '') === 'COMPLETED' ? 'succeeded' : 'pending',
        ];
    }

    public function retrieveStatus(Payment $payment): string
    {
        $response = $this->client()->get("/v2/checkout/orders/{$payment->gateway_reference}")->throw()->json();

        return match ($response['status'] ?? '') {
            'COMPLETED' => 'succeeded',
            'VOIDED' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Calls PayPal's own verification endpoint rather than recomputing the
     * signature locally — PayPal signs with a certificate it rotates, so
     * (unlike Stripe's shared-secret HMAC) this can't be verified offline.
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        try {
            $response = $this->client()->post('/v1/notifications/verify-webhook-signature', [
                'transmission_id' => $request->header('Paypal-Transmission-Id'),
                'transmission_time' => $request->header('Paypal-Transmission-Time'),
                'cert_url' => $request->header('Paypal-Cert-Url'),
                'auth_algo' => $request->header('Paypal-Auth-Algo'),
                'transmission_sig' => $request->header('Paypal-Transmission-Sig'),
                'webhook_id' => $this->webhookId(),
                'webhook_event' => json_decode($request->getContent(), true),
            ])->throw()->json();
        } catch (\Throwable) {
            return false;
        }

        return ($response['verification_status'] ?? null) === 'SUCCESS';
    }

    public function parseWebhookOutcome(array $payload): WebhookOutcome
    {
        $type = $payload['event_type'] ?? '';
        $resource = $payload['resource'] ?? [];
        $orderId = data_get($resource, 'supplementary_data.related_ids.order_id');
        $amountCents = filled(data_get($resource, 'amount.value'))
            ? (int) round(((float) data_get($resource, 'amount.value')) * 100)
            : null;

        return match ($type) {
            'PAYMENT.CAPTURE.COMPLETED' => new WebhookOutcome(WebhookOutcome::PAYMENT_SUCCEEDED, $orderId, $amountCents),
            'PAYMENT.CAPTURE.DENIED' => new WebhookOutcome(WebhookOutcome::PAYMENT_FAILED, $orderId),
            'PAYMENT.CAPTURE.REFUNDED' => new WebhookOutcome(WebhookOutcome::REFUND_SUCCEEDED, $orderId, $amountCents),
            default => new WebhookOutcome(WebhookOutcome::UNHANDLED, null),
        };
    }

    public function webhookEventId(array $payload): ?string
    {
        return $payload['id'] ?? null;
    }

    public function webhookEventType(array $payload): ?string
    {
        return $payload['event_type'] ?? null;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->acceptJson();
    }

    private function baseUrl(): string
    {
        return Setting::get('paypal_mode', 'sandbox') === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * OAuth2 client-credentials tokens are valid ~9 hours; cached for
     * slightly less so this never hands out a token PayPal is about to
     * reject.
     */
    private function accessToken(): string
    {
        return Cache::remember("paypal:oauth-token:{$this->clientId()}", now()->addHours(8), function () {
            $response = Http::asForm()
                ->withBasicAuth($this->clientId(), $this->clientSecret())
                ->baseUrl($this->baseUrl())
                ->post('/v1/oauth2/token', ['grant_type' => 'client_credentials'])
                ->throw()
                ->json();

            return $response['access_token'];
        });
    }

    private function clientId(): ?string
    {
        return Setting::get('paypal_client_id');
    }

    private function clientSecret(): ?string
    {
        return Setting::get('paypal_client_secret');
    }

    private function webhookId(): ?string
    {
        return Setting::get('paypal_webhook_id');
    }
}
