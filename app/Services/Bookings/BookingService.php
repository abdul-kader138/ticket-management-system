<?php

namespace App\Services\Bookings;

use App\Models\Booking;
use App\Models\FlightProvider;
use App\Models\Setting;
use App\Models\TravelerProfile;
use App\Models\User;
use App\Services\Flights\DuffelApiException;
use App\Services\Flights\FlightProviderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Turns a search result offer into a held booking — see docs/ROADMAP.md,
 * Phase 4. Deliberately stops at 'held': moving a booking to 'confirmed'
 * happens only once Phase 5 (payment) actually pays for it and books it
 * with the provider.
 */
class BookingService
{
    // A hold this long by default when the provider's own offer doesn't
    // say otherwise — Duffel offers normally carry their own expires_at.
    private const DEFAULT_HOLD_MINUTES = 30;

    public function __construct(private readonly FlightProviderManager $providers) {}

    /**
     * @param  array<int, array{traveler_profile_id: int, type: string}>  $passengers
     *
     * @throws BookingException
     */
    public function createHold(User $user, string $providerCode, string $offerId, array $passengers): Booking
    {
        if ($passengers === []) {
            throw new BookingException('At least one passenger is required.');
        }

        $provider = FlightProvider::query()->enabled()->where('code', $providerCode)->first();

        if (! $provider) {
            throw new BookingException('That flight provider is not available.');
        }

        $driver = $this->providers->driver($provider);

        if (! $driver->configured()) {
            throw new BookingException('That flight provider is not available.');
        }

        // Re-fetches the offer rather than trusting whatever the client
        // sent — price and availability are only ever taken from the
        // provider's own response, right before the hold is created.
        $offer = $driver->getOffer($offerId)->raw;

        $travelerProfiles = TravelerProfile::query()
            ->whereIn('id', array_column($passengers, 'traveler_profile_id'))
            ->where('user_id', $user->id)
            ->get()
            ->keyBy('id');

        foreach ($passengers as $passenger) {
            if (! $travelerProfiles->has($passenger['traveler_profile_id'])) {
                throw new BookingException('One or more travelers were not found on your account.');
            }
        }

        return DB::transaction(function () use ($user, $provider, $offer, $offerId, $passengers, $travelerProfiles) {
            $booking = Booking::create([
                'user_id' => $user->id,
                'flight_provider_id' => $provider->id,
                'provider_offer_id' => $offerId,
                'status' => Booking::STATUS_HELD,
                'currency' => $offer['total_currency'] ?? 'USD',
                'total_price_cents' => $this->toCents($offer['total_amount'] ?? '0'),
                'cabin_class' => $offer['cabin_class'] ?? null,
                'expires_at' => $this->resolveExpiry($offer),
                // Snapshotted, not looked up live — see docs/ROADMAP.md,
                // Phase 10: which policy applied is fixed at purchase time
                // regardless of later admin edits to the current terms.
                'terms_version' => Setting::get('current_terms_version', 'v1'),
            ]);

            $this->replaceSegments($booking, $offer);

            foreach ($passengers as $passenger) {
                $profile = $travelerProfiles->get($passenger['traveler_profile_id']);

                $booking->passengers()->create([
                    'traveler_profile_id' => $profile->id,
                    'type' => $passenger['type'],
                    'first_name' => $profile->first_name,
                    'last_name' => $profile->last_name,
                    'date_of_birth' => $profile->date_of_birth,
                ]);
            }

            $booking->events()->create([
                'event_type' => Booking::STATUS_HELD,
                'actor_type' => 'user',
                'actor_id' => $user->id,
                'payload' => ['provider_offer_id' => $offerId],
                'created_at' => now(),
            ]);

            return $booking->load(['segments', 'passengers']);
        });
    }

    /**
     * Called by PaymentService once a payment has actually succeeded — the
     * one place a provider order gets created and a booking becomes real
     * (see docs/ROADMAP.md, Phase 5).
     *
     * KNOWN GAP: Duffel order creation also expects title/gender/email/
     * phone per passenger, none of which traveler_profiles collects today
     * (see docs/ROADMAP.md, Phase 1's traveler_profiles design — it only
     * has name/DOB/passport). The placeholders below are a stand-in, not a
     * production-correct mapping; extending traveler_profiles with those
     * fields is a prerequisite for this to work against Duffel for real.
     *
     * @throws DuffelApiException
     */
    public function confirmWithProvider(Booking $booking): void
    {
        $driver = $this->providers->driver($booking->flightProvider);
        $offer = $driver->getOffer($booking->provider_offer_id)->raw;
        $offerPassengers = data_get($offer, 'passengers', []);

        $passengers = $booking->passengers->values()->map(function ($bookingPassenger, $index) use ($offerPassengers, $booking) {
            return [
                'id' => $offerPassengers[$index]['id'] ?? null,
                'type' => $bookingPassenger->type,
                'given_name' => $bookingPassenger->first_name,
                'family_name' => $bookingPassenger->last_name,
                'born_on' => $bookingPassenger->date_of_birth->toDateString(),
                'email' => $booking->user->email,
                'phone_number' => $booking->user->phone,
            ];
        })->all();

        $order = $driver->createOrder($booking->provider_offer_id, $passengers);

        $booking->update(['provider_order_id' => $order->id, 'pnr' => $order->bookingReference]);
        $booking->transitionTo(Booking::STATUS_CONFIRMED, actorType: 'system', payload: [
            'provider_order_id' => $order->id,
        ]);
    }

    /**
     * Sweeps 'held' bookings whose price hold has expired — see
     * App\Console\Commands\ExpireStaleBookingHolds, scheduled every minute.
     */
    public function expireStaleHolds(): int
    {
        $expired = Booking::query()
            ->where('status', Booking::STATUS_HELD)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $booking) {
            $booking->transitionTo(Booking::STATUS_EXPIRED, actorType: 'system');
        }

        return $expired->count();
    }

    /**
     * Replaces a booking's segments from a fresh offer/change-offer
     * payload — used both when a hold is first created and, from
     * BookingChangeService, after a change is confirmed with a new
     * itinerary.
     *
     * @param  array<string, mixed>  $offer
     */
    public function replaceSegments(Booking $booking, array $offer): void
    {
        $booking->segments()->delete();

        $sequence = 0;

        foreach (data_get($offer, 'slices', []) as $slice) {
            foreach (data_get($slice, 'segments', []) as $segment) {
                $booking->segments()->create([
                    'sequence' => $sequence++,
                    'carrier_iata' => data_get($segment, 'operating_carrier.iata_code', data_get($segment, 'marketing_carrier.iata_code')),
                    'carrier_name' => data_get($segment, 'operating_carrier.name', data_get($segment, 'marketing_carrier.name')),
                    'flight_number' => data_get($segment, 'operating_carrier_flight_number', data_get($segment, 'marketing_carrier_flight_number')),
                    'origin' => data_get($segment, 'origin.iata_code', data_get($slice, 'origin.iata_code')),
                    'destination' => data_get($segment, 'destination.iata_code', data_get($slice, 'destination.iata_code')),
                    'departs_at' => data_get($segment, 'departing_at'),
                    'arrives_at' => data_get($segment, 'arriving_at'),
                    'cabin_class' => $offer['cabin_class'] ?? null,
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function resolveExpiry(array $offer): Carbon
    {
        $expiresAt = $offer['expires_at'] ?? null;

        return $expiresAt ? Carbon::parse($expiresAt) : now()->addMinutes(self::DEFAULT_HOLD_MINUTES);
    }

    private function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
