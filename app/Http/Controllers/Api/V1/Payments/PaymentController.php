<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Payments\InitiatePaymentRequest;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Payments\PaymentException;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function store(InitiatePaymentRequest $request, Booking $booking, PaymentService $payments): JsonResponse
    {
        try {
            $result = $payments->initiate($booking, $request->string('gateway')->toString());
        } catch (PaymentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'payment_id' => $result['payment']->id,
            'gateway' => $result['payment']->gateway,
            ...$result['client_data'],
        ], 201);
    }

    /**
     * PayPal only — the SPA calls this once the customer has approved the
     * order on PayPal's site. See PaymentService::capturePayPalOrder() for
     * why the webhook is still what's actually trusted to confirm booking.
     */
    public function capturePaypal(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $this->authorize('view', $payment->payable);

        if ($payment->gateway !== Payment::GATEWAY_PAYPAL) {
            return response()->json(['message' => 'This payment is not a PayPal payment.'], 422);
        }

        $result = $payments->capturePayPalOrder($payment);

        return response()->json($result);
    }
}
