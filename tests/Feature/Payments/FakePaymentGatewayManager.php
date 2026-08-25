<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\Contracts\PaymentGatewayContract;
use App\Services\Payments\PaymentGatewayManager;

/**
 * Substituted into the container in place of the real manager (see
 * PaymentServiceTest/PaymentWebhookControllerTest) — returns the same
 * FakePaymentGateway regardless of the requested code, so tests can use
 * any gateway string (e.g. "fake") without touching Stripe/PayPal.
 */
class FakePaymentGatewayManager extends PaymentGatewayManager
{
    public function __construct() {}

    public function get(string $code): PaymentGatewayContract
    {
        return new FakePaymentGateway;
    }

    public function resolve(string $code): PaymentGatewayContract
    {
        return new FakePaymentGateway;
    }
}
