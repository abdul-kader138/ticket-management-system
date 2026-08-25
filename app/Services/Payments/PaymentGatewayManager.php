<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Services\Payments\Contracts\PaymentGatewayContract;

class PaymentGatewayManager
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PayPalGateway $paypal,
    ) {}

    /**
     * @throws PaymentException
     */
    public function get(string $code): PaymentGatewayContract
    {
        $gateway = $this->resolve($code);

        if (! $gateway->configured()) {
            throw new PaymentException(ucfirst($code).' is not configured.');
        }

        return $gateway;
    }

    /**
     * Unlike get(), doesn't require the gateway to be fully configured —
     * a webhook still needs to be verified (and will correctly fail
     * verification) even for a gateway an admin hasn't finished setting
     * up, rather than the endpoint itself throwing.
     *
     * @throws PaymentException
     */
    public function resolve(string $code): PaymentGatewayContract
    {
        return match ($code) {
            Payment::GATEWAY_STRIPE => $this->stripe,
            Payment::GATEWAY_PAYPAL => $this->paypal,
            default => throw new PaymentException("Unknown payment gateway '{$code}'."),
        };
    }
}
