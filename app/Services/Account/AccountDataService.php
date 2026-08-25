<?php

namespace App\Services\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * GDPR data-subject request tooling — see docs/ROADMAP.md, Phase 10.
 */
class AccountDataService
{
    /**
     * Everything the account holds, including their own traveler profiles'
     * passport data — it's their data to see, unlike CustomerResource's
     * write-only passport_number for normal account viewing.
     *
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        return [
            'profile' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'marketing_opt_in' => $user->marketing_opt_in,
                'created_at' => $user->created_at,
            ],
            'traveler_profiles' => $user->travelerProfiles->map(fn ($profile) => [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'date_of_birth' => $profile->date_of_birth?->toDateString(),
                'nationality' => $profile->nationality,
                'passport_number' => $profile->passport_number,
                'passport_expiry' => $profile->passport_expiry?->toDateString(),
            ]),
            'bookings' => $user->bookings->load(['segments', 'passengers'])->map(fn ($booking) => [
                'id' => $booking->id,
                'status' => $booking->status,
                'pnr' => $booking->pnr,
                'currency' => $booking->currency,
                'total_price' => number_format($booking->total_price_cents / 100, 2),
                'created_at' => $booking->created_at,
                'segments' => $booking->segments->map(fn ($s) => [
                    'origin' => $s->origin, 'destination' => $s->destination,
                    'carrier' => $s->carrier_name, 'flight_number' => $s->flight_number,
                    'departs_at' => $s->departs_at,
                ]),
                'passengers' => $booking->passengers->map(fn ($p) => [
                    'first_name' => $p->first_name, 'last_name' => $p->last_name, 'type' => $p->type,
                ]),
            ]),
            'payments' => $user->payments()->get()->map(fn ($payment) => [
                'gateway' => $payment->gateway,
                'status' => $payment->status,
                'amount' => number_format($payment->amount_cents / 100, 2),
                'currency' => $payment->currency,
                'created_at' => $payment->created_at,
            ]),
            'subscriptions' => $user->subscriptions->map(fn ($sub) => [
                'plan' => $sub->subscriptionPlan->name,
                'status' => $sub->status,
                'starts_at' => $sub->starts_at,
                'ends_at' => $sub->ends_at,
            ]),
        ];
    }

    /**
     * Anonymizes rather than hard-deletes: bookings and payments are
     * financial records this app has no standing to destroy on request
     * (accounting/tax retention), and booking_passengers.traveler_profile_id
     * is a restrict-on-delete foreign key precisely so a traveler profile
     * used in a booking can't be dropped out from under it. What actually
     * satisfies an erasure request is scrubbing every directly-identifying
     * field while leaving the transaction history structurally intact.
     */
    public function anonymize(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->travelerProfiles->each(function ($profile) {
                $profile->forceFill([
                    'first_name' => 'Deleted',
                    'last_name' => 'User',
                    'passport_number' => null,
                    'passport_expiry' => null,
                    'nationality' => null,
                ])->save();
            });

            $user->forceFill([
                'first_name' => 'Deleted',
                'last_name' => 'User',
                'email' => "deleted-user-{$user->id}@example.invalid",
                'phone' => null,
                'marketing_opt_in' => false,
                'google_id' => null,
                'avatar' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'password' => Hash::make(Str::random(40)),
            ])->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }
}
