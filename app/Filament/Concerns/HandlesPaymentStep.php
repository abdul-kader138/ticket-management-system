<?php

namespace App\Filament\Concerns;

use App\Models\Payment;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentService;
use Filament\Notifications\Notification;
use Livewire\Attributes\Computed;

/**
 * The payment leg shared by BookFlight (original purchase) and
 * ChangeBooking (fare difference). Both drive the same
 * resources/views/filament/pages/partials/payment-panel.blade.php and
 * finalise the same way — Stripe by polling the gateway
 * (PaymentService::reconcile), PayPal by an explicit server-side capture.
 *
 * The host page supplies the Payment via currentPayment() and reacts to a
 * settled payment in onPaymentSettled().
 */
trait HandlesPaymentStep
{
    public ?int $paymentId = null;

    public ?string $paymentGateway = null;

    /** client_secret + publishable_key (Stripe) or order_id + approval_url (PayPal). */
    public array $paymentClientData = [];

    abstract protected function currentPayment(): ?Payment;

    abstract protected function onPaymentSettled(Payment $payment): void;

    /**
     * Configured gateways only. #[Computed] so the several reads from the
     * payment-panel blade resolve the Setting lookups once per request.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function availableGateways(): array
    {
        $manager = app(PaymentGatewayManager::class);
        $out = [];

        foreach (['stripe' => 'Card (Stripe)', 'paypal' => 'PayPal'] as $code => $label) {
            try {
                if ($manager->resolve($code)->configured()) {
                    $out[$code] = $label;
                }
            } catch (\Throwable) {
                // Unknown/misconfigured gateway — just don't offer it.
            }
        }

        return $out;
    }

    protected function rememberGatewayIntent(string $gateway, Payment $payment, array $clientData): void
    {
        $this->paymentGateway = $gateway;
        $this->paymentId = $payment->id;
        $this->paymentClientData = $clientData;
    }

    /** PayPal only — the operator has approved the order in the popup. */
    public function capturePaypal(): void
    {
        $payment = $this->currentPayment();

        if (! $payment) {
            return;
        }

        try {
            $result = app(PaymentService::class)->capturePayPalOrder($payment);
        } catch (\Throwable $e) {
            Notification::make()->danger()->title('PayPal capture failed')->body($e->getMessage())->send();

            return;
        }

        $this->settlePayment('PayPal status: '.($result['status'] ?? 'unknown'));
    }

    /**
     * Stripe has no server-side capture — confirmation normally arrives by
     * webhook. Polling the gateway here (reconcile) gives the operator an
     * answer without one, which is also what a sandbox with no public
     * webhook URL needs.
     */
    public function refreshPaymentStatus(): void
    {
        $payment = $this->currentPayment();

        if (! $payment) {
            return;
        }

        try {
            app(PaymentService::class)->reconcile($payment);
        } catch (\Throwable $e) {
            Notification::make()->warning()->title('Could not check payment')->body($e->getMessage())->send();

            return;
        }

        $this->settlePayment('Payment status: '.$payment->fresh()->status);
    }

    protected function settlePayment(string $fallbackMessage): void
    {
        $payment = $this->currentPayment()?->fresh();

        if ($payment?->isSucceeded()) {
            $this->onPaymentSettled($payment);

            return;
        }

        Notification::make()->info()->title($fallbackMessage)->send();
    }
}
