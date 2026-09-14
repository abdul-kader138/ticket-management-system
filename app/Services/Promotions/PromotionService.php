<?php

namespace App\Services\Promotions;

use App\Models\Booking;
use App\Models\Promotion;
use App\Models\PromotionRedemption;
use App\Models\User;
use App\Services\Flights\SearchQuotaService;
use Illuminate\Support\Facades\DB;

/**
 * See docs/ROADMAP.md, Phase 8. Two redemption paths for the two kinds of
 * promotion: redeemForBooking() for a checkout discount (percent/fixed),
 * redeemStandalone() for a free_search_bonus code entered on its own,
 * outside checkout. Referral rewards are a separate, simpler mechanism —
 * see App\Observers\BookingObserver — since they're granted automatically
 * to the referrer rather than typed in as a code by anyone.
 */
class PromotionService
{
    public function __construct(private readonly SearchQuotaService $quota) {}

    /**
     * @throws PromotionException
     */
    public function redeemForBooking(Booking $booking, string $code, User $user): PromotionRedemption
    {
        if (! $booking->isHeld()) {
            throw new PromotionException('A code can only be applied before payment.');
        }

        $code = trim($code);

        return DB::transaction(function () use ($code, $user, $booking) {
            if ($booking->promotionRedemptions()->exists()) {
                throw new PromotionException('A code has already been applied to this booking.');
            }

            $promotion = $this->findRedeemable($code, $user, lock: true);

            if (! in_array($promotion->type, [Promotion::TYPE_PERCENT, Promotion::TYPE_FIXED], true)) {
                throw new PromotionException('This code can\'t be applied at checkout.');
            }

            $discountCents = $this->computeDiscount($promotion, $booking->total_price_cents);

            $redemption = $promotion->redemptions()->create([
                'user_id' => $user->id,
                'booking_id' => $booking->id,
                'discount_cents' => $discountCents,
            ]);

            $booking->update(['total_price_cents' => max(0, $booking->total_price_cents - $discountCents)]);

            return $redemption;
        });
    }

    /**
     * @throws PromotionException
     */
    public function redeemStandalone(User $user, string $code): PromotionRedemption
    {
        $code = trim($code);

        return DB::transaction(function () use ($code, $user) {
            $promotion = $this->findRedeemable($code, $user, lock: true);

            if ($promotion->type !== Promotion::TYPE_FREE_SEARCH_BONUS) {
                throw new PromotionException('This code must be applied at checkout.');
            }

            $this->quota->grantBonusSearches($user, 'day', $promotion->value);

            return $promotion->redemptions()->create(['user_id' => $user->id, 'discount_cents' => 0]);
        });
    }

    /**
     * Loads the promotion and validates it's redeemable by this user. When
     * $lock is true, this must run inside a DB::transaction() — it locks
     * the promotion row for the duration of the transaction so that two
     * concurrent redemptions of the same code can't both pass the
     * usage-limit / per-user-limit checks before either commits.
     *
     * @throws PromotionException
     */
    private function findRedeemable(string $code, User $user, bool $lock = false): Promotion
    {
        $query = Promotion::where('code', $code);

        if ($lock) {
            $query->lockForUpdate();
        }

        $promotion = $query->first();

        if (! $promotion || ! $promotion->isCurrentlyActive()) {
            throw new PromotionException('This code is invalid or has expired.');
        }

        if (! $promotion->hasRemainingUses()) {
            throw new PromotionException('This code has reached its usage limit.');
        }

        $alreadyRedeemed = $promotion->redemptions()->where('user_id', $user->id)->count();

        if ($alreadyRedeemed >= $promotion->per_user_limit) {
            throw new PromotionException('You\'ve already used this code.');
        }

        return $promotion;
    }

    private function computeDiscount(Promotion $promotion, int $totalCents): int
    {
        return match ($promotion->type) {
            Promotion::TYPE_PERCENT => (int) round($totalCents * $promotion->value / 100),
            Promotion::TYPE_FIXED => min($promotion->value, $totalCents),
            default => 0,
        };
    }
}
