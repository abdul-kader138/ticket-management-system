<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\Flights\FlightProviderManager;
use App\Services\Payments\PaymentService;

/**
 * See docs/ROADMAP.md, Phase 6. Depends on both BookingService's sibling
 * FlightProviderManager and PaymentService rather than folding into
 * BookingService itself — BookingService is a dependency of PaymentService
 * (for confirmWithProvider()), so putting refund logic there too would
 * create a circular dependency between the two services.
 */
class CancellationService
{
    public function __construct(
        private readonly FlightProviderManager $providers,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @throws BookingException
     */
    public function cancel(Booking $booking, string $actorType, ?int $actorId, string $reason = ''): void
    {
        if (in_array($booking->status, [Booking::STATUS_CANCELLED, Booking::STATUS_REFUNDED, Booking::STATUS_EXPIRED], true)) {
            throw new BookingException('This booking is already cancelled.');
        }

        if ($booking->status === Booking::STATUS_PENDING_PAYMENT) {
            throw new BookingException('Cannot cancel while a payment is in progress. Please wait for it to complete or fail.');
        }

        // Nothing was ever paid — no provider order exists yet either.
        if ($booking->status === Booking::STATUS_HELD) {
            $booking->transitionTo(Booking::STATUS_CANCELLED, $actorType, $actorId, ['reason' => $reason]);

            return;
        }

        // STATUS_CONFIRMED or STATUS_CHANGED from here — a real ticket
        // exists with the provider, so it has to be cancelled there too.
        $driver = $this->providers->driver($booking->flightProvider);
        $result = $driver->cancelOrder($booking->provider_order_id);

        $payment = $booking->payments()->where('status', Payment::STATUS_SUCCEEDED)->latest()->first();

        if ($payment && $result->refundAmount) {
            $refundCents = min(
                (int) round(((float) $result->refundAmount) * 100),
                $payment->amount_cents - $payment->totalRefundedCents(),
            );

            if ($refundCents > 0) {
                $this->payments->refund($payment, $refundCents, $reason ?: 'booking_cancelled');
            }
        }

        // A full refund above may already have moved the booking straight
        // to 'refunded' (see PaymentService::recordRefund) — only take it
        // to 'cancelled' ourselves if that didn't happen, since 'refunded'
        // has no further transitions.
        if ($booking->fresh()->status !== Booking::STATUS_REFUNDED) {
            $booking->transitionTo(Booking::STATUS_CANCELLED, $actorType, $actorId, ['reason' => $reason]);
        }
    }
}
