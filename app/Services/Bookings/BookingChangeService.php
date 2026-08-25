<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\Flights\DTO\OfferCollection;
use App\Services\Flights\DTO\SearchCriteria;
use App\Services\Flights\FlightProviderManager;
use App\Services\Payments\PaymentService;

/**
 * See docs/ROADMAP.md, Phase 6. Two-step, matching how a real airline
 * change works: quote the available change offers first (searchOffers()),
 * then apply whichever one the customer picks (applyChange()) — never
 * both in one call, since the customer needs to see and accept the fare
 * difference before it's charged.
 */
class BookingChangeService
{
    public function __construct(
        private readonly FlightProviderManager $providers,
        private readonly BookingService $bookings,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @throws BookingException
     */
    public function searchOffers(Booking $booking, SearchCriteria $newCriteria): OfferCollection
    {
        $this->assertChangeable($booking);

        return $this->providers->driver($booking->flightProvider)->changeOrder($booking->provider_order_id, $newCriteria);
    }

    /**
     * Applies a change offer the customer has already seen and accepted,
     * updates the booking's itinerary/price locally, and — if the new fare
     * costs more — starts a payment for the difference. A cheaper change is
     * applied for free; refunding the difference on a cheaper change is a
     * known gap, not handled here.
     *
     * @return array{payment: ?Payment, client_data: array<string, mixed>}
     *
     * @throws BookingException
     */
    public function applyChange(Booking $booking, string $changeOfferId, User $actor, string $gatewayCode): array
    {
        $this->assertChangeable($booking);

        $driver = $this->providers->driver($booking->flightProvider);
        $confirmed = $driver->confirmChangeOffer($changeOfferId);

        $newTotalCents = (int) round(((float) ($confirmed['new_total_amount'] ?? 0)) * 100);
        $differenceCents = $newTotalCents - $booking->total_price_cents;

        $this->bookings->replaceSegments($booking, $confirmed['raw'] ?? []);
        $booking->update(['total_price_cents' => max($newTotalCents, 0)]);

        $booking->transitionTo(Booking::STATUS_CHANGED, actorType: 'user', actorId: $actor->id, payload: [
            'change_offer_id' => $changeOfferId,
            'difference_cents' => $differenceCents,
        ]);

        if ($differenceCents <= 0) {
            return ['payment' => null, 'client_data' => []];
        }

        return $this->payments->chargeAdditional($booking, $differenceCents, $gatewayCode, 'fare_difference');
    }

    /**
     * @throws BookingException
     */
    private function assertChangeable(Booking $booking): void
    {
        if ($booking->status !== Booking::STATUS_CONFIRMED) {
            throw new BookingException('Only a confirmed booking can be changed.');
        }
    }
}
