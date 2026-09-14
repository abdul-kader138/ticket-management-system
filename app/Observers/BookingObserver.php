<?php

namespace App\Observers;

use App\Models\Booking;
use App\Models\Setting;
use App\Models\User;
use App\Services\Flights\SearchQuotaService;

/**
 * Maintains User::total_spend_cents, the input to automatic tier
 * assignment (see App\Services\Subscriptions\SubscriptionService and
 * docs/ROADMAP.md, Phase 7). Deliberately never decremented on a later
 * refund/cancellation — a customer's tier reflects lifetime spend, not a
 * running balance; see the migration for the same note.
 *
 * Also grants the Phase 8 referral reward: the first time a referred
 * user's booking confirms, their referrer gets a one-time bonus search
 * allowance (see docs/ROADMAP.md, Phase 8 and User::referralCode()).
 */
class BookingObserver
{
    public function __construct(private readonly SearchQuotaService $quota) {}

    public function updated(Booking $booking): void
    {
        if (! $booking->wasChanged('status') || $booking->status !== Booking::STATUS_CONFIRMED) {
            return;
        }

        $user = $booking->user;
        $user->increment('total_spend_cents', $booking->total_price_cents);

        $this->maybeRewardReferrer($user);
    }

    private function maybeRewardReferrer(User $user): void
    {
        if (! $user->referrer_id) {
            return;
        }

        $confirmedCount = Booking::where('user_id', $user->id)
            ->where('status', Booking::STATUS_CONFIRMED)
            ->count();

        if ($confirmedCount !== 1) {
            return; // Not this user's first confirmed booking.
        }

        $referrer = $user->referrer;

        if (! $referrer) {
            return;
        }

        $reward = (int) Setting::get('referral_reward_bonus_searches', 20);

        $this->quota->grantBonusSearches($referrer, 'month', $reward);

        // Unlike coupon redemptions, referral rewards have no PromotionRedemption
        // row — this is the only durable record that one was granted, needed for
        // support/debugging since the underlying quota bonus lives in cache.
        activity('referral')
            ->performedOn($referrer)
            ->causedBy($user)
            ->withProperties([
                'referred_user_id' => $user->id,
                'bonus_searches' => $reward,
            ])
            ->log('Referral reward granted');
    }
}
